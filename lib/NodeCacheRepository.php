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
 * Reads the cache of the BMC mapped to a Cacti device, for the script
 * server. Three prepared reads per device; the callers keep the result for
 * a short time, because the script server asks for one value at a time.
 */
final class NodeCacheRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function forHost(int $hostId): ?NodeCache {
		if ($hostId <= 0) {
			return null;
		}

		return $this->load('b.host_id = ?', $hostId);
	}

	public function forBmc(int $bmcId): ?NodeCache {
		return $bmcId > 0 ? $this->load('b.id = ?', $bmcId) : null;
	}

	/**
	 * Members the cache holds for a BMC, by kind, for the provisioning plan.
	 *
	 * @return array<string, int>
	 */
	public function memberCounts(int $bmcId): array {
		$counts = [];

		foreach ($this->forBmc($bmcId)->members ?? [] as $member) {
			$counts[$member['kind']] = ($counts[$member['kind']] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * @param 'b.host_id = ?'|'b.id = ?' $where
	 */
	private function load(string $where, int $id): ?NodeCache {
		$row = db_fetch_row_prepared("SELECT b.id, b.created_at, COALESCE(h.last_success, 0) AS last_success FROM plugin_redfish_bmc AS b LEFT JOIN plugin_redfish_health AS h ON h.bmc_id = b.id WHERE $where ORDER BY b.id LIMIT 1", [$id], true, $this->connection);

		if (!is_array($row) || Row::int($row, 'id', 0, 1) === 0) {
			return null;
		}

		$bmcId   = Row::int($row, 'id', 0, 1);
		$members = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT member_key, kind, name, chassis_id, presence FROM plugin_redfish_member WHERE bmc_id = ?', [$bmcId], true, $this->connection)) as $m) {
			$members[] = ['key' => Row::string($m, 'member_key'), 'kind' => Row::string($m, 'kind'), 'name' => Row::string($m, 'name'), 'chassis' => Row::string($m, 'chassis_id'), 'presence' => Row::string($m, 'presence')];
		}

		$readings = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT member_key, metric, value, unit, observed_at FROM plugin_redfish_reading WHERE bmc_id = ?', [$bmcId], true, $this->connection)) as $r) {
			$readings[Row::string($r, 'member_key')][Row::string($r, 'metric')] = ['value' => Row::float($r, 'value'), 'unit' => Row::string($r, 'unit'), 'observed_at' => Row::int($r, 'observed_at', 0, 0)];
		}

		return new NodeCache($bmcId, Row::int($row, 'created_at', 0, 0), Row::int($row, 'last_success', 0, 0), $members, $readings);
	}
}
