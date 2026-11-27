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
 * Database-backed Cacti realm authorization lookup for explicit user IDs.
 *
 * Checks both direct user grants (user_auth_realm) and enabled group grants
 * (user_auth_group_realm) without relying on PHP session state.
 */
final class RealmRepository {
	/**
	 * Initialize a new RealmRepository instance.
	 *
	 * @param \PDO|false $connection Cacti database connection handle or false for default
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Report whether $userId holds any plugin_redfish realm covering one of $files.
	 *
	 * @param int          $userId Cacti user ID
	 * @param list<string> $files  plugin page filenames registered in plugin_realms
	 * @return bool
	 */
	public function userHasAnyFile(int $userId, array $files): bool {
		if ($userId <= 0 || $files === []) {
			return false;
		}

		$rows = db_fetch_assoc_prepared(
			'SELECT id, file FROM plugin_realms WHERE plugin = ?',
			['redfish'],
			true,
			$this->connection
		);

		$realmIds = [];

		foreach (Row::rows($rows) as $row) {
			$id = Row::int($row, 'id', 0, 0);

			if ($id <= 0) {
				continue;
			}

			$registered = array_map('trim', explode(',', Row::string($row, 'file')));

			foreach ($files as $file) {
				if (in_array($file, $registered, true)) {
					$realmIds[] = $id + 100;

					break;
				}
			}
		}

		foreach (array_values(array_unique($realmIds)) as $realmId) {
			$found = (int) db_fetch_cell_prepared(
				"SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?
				UNION
				SELECT uagr.realm_id FROM user_auth_group_realm AS uagr
				INNER JOIN user_auth_group AS uag ON uag.id = uagr.group_id
				INNER JOIN user_auth_group_members AS uagm ON uag.id = uagm.group_id
				WHERE uag.enabled = 'on' AND uagr.realm_id = ? AND uagm.user_id = ?",
				[$userId, $realmId, $realmId, $userId],
				'',
				true,
				$this->connection
			);

			if ($found > 0) {
				return true;
			}
		}

		return false;
	}
}
