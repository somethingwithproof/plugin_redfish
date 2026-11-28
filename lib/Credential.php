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
 * A stored BMC credential: a user name in the clear and a reference to its
 * encrypted password. The revision rises whenever either changes, which is
 * how a BMC held in auth_failed learns that a new credential may be tried.
 */
final readonly class Credential {
	/**
	 * Initialize a new Credential instance.
	 *
	 * @param int $id
	 * @param string $name
	 * @param string $username
	 * @param int $revision
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $username,
		public int $revision,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id = Row::int($row, 'id', 0, 1);

		if ($id === 0) {
			return null;
		}

		return new self($id, Row::string($row, 'name'), Row::string($row, 'username'), Row::int($row, 'revision', 0, 0));
	}
}
