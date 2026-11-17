<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_redfish, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_redfish
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Redfish;

/**
 * The reading cache. One BMC's results are written in one transaction, so
 * an interrupted run leaves either the previous cache or the new one, never
 * a mix (REQ-COLL-005). The transaction locks the BMC's lease row first and
 * writes nothing unless this worker still holds it.
 */
final class ReadingRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @param ?DiscoveryPlan $plan        a plan discovered in this run, to store
	 * @param ?Observation   $observation what this run read
	 * @param bool           $complete    every target was read: members not seen are gone
	 * @param bool           $markStale   rediscover on the next run
	 */
	public function commit(int $bmcId, string $holder, int $now, BmcHealth $health, ?DiscoveryPlan $plan, ?Observation $observation, bool $complete, bool $markStale): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$held = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_lease WHERE bmc_id = ? AND holder = ? AND expires_at >= ? FOR UPDATE', [$bmcId, $holder, $now], '', true, $this->connection) === 1;
		$ok   = $held;

		if ($ok && $plan !== null) {
			$ok = (bool) db_execute_prepared('REPLACE INTO plugin_redfish_discovery (bmc_id, fingerprint, redfish_version, vendor, product, family, features, sessions_path, generation, chassis, targets, plan, status, stale, discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
				$bmcId, $plan->fingerprint, $plan->redfishVersion, $plan->vendor, $plan->product, $plan->family, implode(',', $plan->features), $plan->sessionsPath,
				$plan->generation(), count($plan->chassis), count($plan->targets), $plan->planJson(), 'ok', '', $now,
			], true, $this->connection);
		}

		if ($ok && $markStale) {
			$ok = (bool) db_execute_prepared("UPDATE plugin_redfish_discovery SET stale = 'on' WHERE bmc_id = ?", [$bmcId], true, $this->connection);
		}

		foreach ($ok && $observation !== null ? $observation->members : [] as $member) {
			// A member seen present stays expected until it is accepted again.
			$ok = $ok && db_execute_prepared("INSERT INTO plugin_redfish_member (bmc_id, member_key, kind, chassis_id, name, source, presence, expected, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE kind = VALUES(kind), chassis_id = VALUES(chassis_id), name = VALUES(name), source = VALUES(source), presence = VALUES(presence), expected = IF(VALUES(presence) = 'present', 'on', expected), last_seen = VALUES(last_seen)", [
				$bmcId, $member->key, $member->kind, substr($member->chassis, 0, 64), $member->name, $member->source, $member->absent ? 'absent' : 'present', $member->absent ? '' : 'on', $now, $now,
			], true, $this->connection);
		}

		foreach ($ok && $observation !== null ? $observation->readings : [] as $reading) {
			$ok = $ok && db_execute_prepared('REPLACE INTO plugin_redfish_reading (bmc_id, member_key, metric, value, unit, source, rule, state, observed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
				$bmcId, $reading->member, $reading->metric, $reading->value, $reading->unit, $reading->source, substr($reading->rule, 0, 32), substr($reading->state, 0, 32), $now,
			], true, $this->connection);
		}

		if ($ok && $complete) {
			$ok = (bool) db_execute_prepared("UPDATE plugin_redfish_member SET presence = 'gone' WHERE bmc_id = ? AND last_seen < ?", [$bmcId, $now], true, $this->connection);
		}

		if ($ok) {
			$row     = $health->toRow();
			$columns = array_keys($row);
			$update  = implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", array_slice($columns, 1)));
			$ok      = (bool) db_execute_prepared('INSERT INTO plugin_redfish_health (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ") ON DUPLICATE KEY UPDATE $update", array_values($row), true, $this->connection);
		}

		if (!$ok || !db_commit_transaction($this->connection)) {
			db_rollback_transaction($this->connection);

			return false;
		}

		return true;
	}

	/**
	 * Cached readings of one BMC, for the health page.
	 *
	 * @return list<array{member: string, metric: string, value: ?float, unit: string, rule: string, observed_at: int}>
	 */
	public function forBmc(int $bmcId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT member_key, metric, value, unit, rule, observed_at FROM plugin_redfish_reading WHERE bmc_id = ? ORDER BY member_key, metric', [$bmcId], true, $this->connection)) as $row) {
			$out[] = [
				'member'      => Row::string($row, 'member_key'),
				'metric'      => Row::string($row, 'metric'),
				'value'       => Row::float($row, 'value'),
				'unit'        => Row::string($row, 'unit'),
				'rule'        => Row::string($row, 'rule'),
				'observed_at' => Row::int($row, 'observed_at', 0, 0),
			];
		}

		return $out;
	}

	/**
	 * Member counts by kind and presence.
	 *
	 * @return array<string, int> "kind presence" => count
	 */
	public function memberCounts(int $bmcId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT kind, presence, COUNT(*) AS n FROM plugin_redfish_member WHERE bmc_id = ? GROUP BY kind, presence ORDER BY kind, presence', [$bmcId], true, $this->connection)) as $row) {
			$out[Row::string($row, 'kind') . ' ' . Row::string($row, 'presence')] = Row::int($row, 'n', 0, 0);
		}

		return $out;
	}
}
