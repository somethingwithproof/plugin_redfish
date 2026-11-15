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
 * Collection status per BMC and per collector run. Writes from the
 * collector are guarded by the BMC's lease in the same statement, so a run
 * that lost its lease cannot overwrite the newer holder's status.
 */
final class HealthRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * The stored status, a fresh one when none is stored, or null when the
	 * read failed: a failed read must not look like a BMC with no history,
	 * which would forget a held authentication failure.
	 */
	public function find(int $bmcId): ?BmcHealth {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_health WHERE bmc_id = ?', [$bmcId], true, $this->connection);

		if ($row === false) {
			return null;
		}

		return is_array($row) && $row !== [] ? BmcHealth::fromRow($row) : new BmcHealth($bmcId);
	}

	/**
	 * Record that a session POST is about to be sent, before it is sent, so
	 * a crash or a lost response leaves the marker for the next run.
	 */
	public function markSessionPending(int $bmcId, string $holder, int $now): bool {
		db_execute_prepared('INSERT IGNORE INTO plugin_redfish_health (bmc_id) SELECT ? FROM plugin_redfish_lease WHERE bmc_id = ? AND holder = ? AND expires_at >= ?', [$bmcId, $bmcId, $holder, $now], true, $this->connection);
		$ok = db_execute_prepared("UPDATE plugin_redfish_health h INNER JOIN plugin_redfish_lease l ON l.bmc_id = h.bmc_id SET h.session_pending = 'on' WHERE h.bmc_id = ? AND l.holder = ? AND l.expires_at >= ?", [$bmcId, $holder, $now], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_redfish_health WHERE bmc_id = ? AND session_pending = 'on'", [$bmcId], '', true, $this->connection) === 1;
	}

	/**
	 * Record the session the run just created, while the run holds the
	 * lease, so a crash before logout leaves its path for the next run to
	 * delete. The pending marker is cleared: the session is now known.
	 */
	public function recordSession(int $bmcId, string $holder, string $path, int $now): bool {
		db_execute_prepared("UPDATE plugin_redfish_health h INNER JOIN plugin_redfish_lease l ON l.bmc_id = h.bmc_id SET h.session_uri = ?, h.session_pending = '' WHERE h.bmc_id = ? AND l.holder = ? AND l.expires_at >= ?", [$path, $bmcId, $holder, $now], true, $this->connection);

		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_health WHERE bmc_id = ? AND session_uri = ?', [$bmcId, $path], '', true, $this->connection) === 1;
	}

	/**
	 * Clear backoff, a held authentication failure, and the lost-session
	 * budget: an administrator edited the BMC or asked for a retry.
	 */
	public function clearHolds(int $bmcId): void {
		db_execute_prepared('UPDATE plugin_redfish_health SET failures = 0, backoff_until = 0, sessions_lost = 0, auth_credential_id = 0, auth_revision = 0 WHERE bmc_id = ?', [$bmcId], true, $this->connection);
	}

	/**
	 * @param array{run_started: int, run_finished: int, run_duration: float, bmcs: int, collected: int, skipped: int, requests: int, workers: int, stopped_early: bool} $run
	 */
	public function saveRun(int $pollerId, array $run): void {
		db_execute_prepared('REPLACE INTO plugin_redfish_run (poller_id, run_started, run_finished, run_duration, bmcs, collected, skipped, requests, workers, stopped_early) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
			$pollerId, $run['run_started'], $run['run_finished'], round($run['run_duration'], 3), $run['bmcs'], $run['collected'], $run['skipped'], $run['requests'], $run['workers'], $run['stopped_early'] ? 'on' : '',
		], true, $this->connection);
	}

	/**
	 * @return list<array{poller_id: int, run_started: int, run_duration: float, bmcs: int, collected: int, skipped: int, requests: int, workers: int, stopped_early: bool}>
	 */
	public function runs(): array {
		$runs = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_run ORDER BY poller_id', [], true, $this->connection)) as $row) {
			$runs[] = [
				'poller_id'     => Row::int($row, 'poller_id', 0, 0),
				'run_started'   => Row::int($row, 'run_started', 0, 0),
				'run_duration'  => Row::float($row, 'run_duration') ?? 0.0,
				'bmcs'          => Row::int($row, 'bmcs', 0, 0),
				'collected'     => Row::int($row, 'collected', 0, 0),
				'skipped'       => Row::int($row, 'skipped', 0, 0),
				'requests'      => Row::int($row, 'requests', 0, 0),
				'workers'       => Row::int($row, 'workers', 0, 0),
				'stopped_early' => Row::bool($row, 'stopped_early'),
			];
		}

		return $runs;
	}

	public function saveRunFinish(int $pollerId, int $bmcs, int $finished, float $duration): void {
		db_execute_prepared('UPDATE plugin_redfish_run SET bmcs = ?, run_finished = ?, run_duration = ? WHERE poller_id = ?', [$bmcs, $finished, round($duration, 3), $pollerId], true, $this->connection);
	}

	/**
	 * Workers add their counts to the run row their master created.
	 *
	 * @param array{bmcs: int, collected: int, skipped: int, requests: int, stopped_early: bool} $counts
	 */
	public function addToRun(int $pollerId, array $counts): void {
		db_execute_prepared("UPDATE plugin_redfish_run SET collected = collected + ?, skipped = skipped + ?, requests = requests + ?, stopped_early = IF(? = 'on', 'on', stopped_early) WHERE poller_id = ?", [
			$counts['collected'], $counts['skipped'], $counts['requests'], $counts['stopped_early'] ? 'on' : '', $pollerId,
		], true, $this->connection);
	}
}
