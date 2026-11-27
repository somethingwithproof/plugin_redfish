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
 * Read-only lookup of Cacti Maint plugin maintenance schedules for mapped
 * devices. Safely returns empty results when plugin_maint is not installed.
 */
final class MaintRepository {
	/**
	 * Initialize a new MaintRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Is installed.
	 *
	 * @return bool
	 */
	public function isInstalled(): bool {
		$count = (int) db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('plugin_maint_schedules', 'plugin_maint_hosts')",
			[],
			'',
			true,
			$this->connection
		);

		return $count === 2;
	}

	/**
	 * Load enabled Maint schedules attached to a Cacti host.
	 *
	 * @return list<array{id: int, name: string, mtype: int, stime: int, etime: int, minterval: int}>
	 */
	public function schedulesForHost(int $hostId): array {
		if ($hostId <= 0 || !$this->isInstalled()) {
			return [];
		}

		$rows = Row::rows(db_fetch_assoc_prepared(
			"SELECT s.id, s.name, s.mtype, s.stime, s.etime, s.minterval
				FROM plugin_maint_schedules AS s
				INNER JOIN plugin_maint_hosts AS h ON h.schedule = s.id
				WHERE h.type = 1 AND h.host = ? AND s.enabled = 'on'
				ORDER BY s.id",
			[$hostId],
			true,
			$this->connection
		));

		$out = [];

		foreach ($rows as $row) {
			$out[] = [
				'id'        => Row::int($row, 'id', 0, 0),
				'name'      => Row::string($row, 'name'),
				'mtype'     => Row::int($row, 'mtype', 1, 1),
				'stime'     => Row::int($row, 'stime', 0, 0),
				'etime'     => Row::int($row, 'etime', 0, 0),
				'minterval' => Row::int($row, 'minterval', 0, 0),
			];
		}

		return $out;
	}
}
