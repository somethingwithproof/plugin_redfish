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
 * A fan, temperature sensor, power supply, chassis, or the node, under a key
 * built from stable identifiers: chassis Id, kind, and member Id, never a
 * list position (REQ-DISC-005).
 */
final readonly class Member {
	/**
	 * Node constant.
	 */
	public const NODE = 'node';

	/**
	 * Initialize a new Member instance.
	 *
	 * @param string $key
	 * @param string $kind
	 * @param string $chassis
	 * @param string $name
	 * @param string $source
	 * @param bool $absent
	 */
	public function __construct(
		public string $key,
		public string $kind,
		public string $chassis,
		public string $name,
		public string $source,
		public bool $absent = false,
	) {
	}

	/**
	 * The key for a member, or null when an identifier is unusable. Each
	 * part loses control characters and slashes and is capped, so a key
	 * always fits the 191-character column.
	 */
	public static function key(string $chassis, string $kind, string $id): ?string {
		$clean = static fn (string $part): string => substr(trim((string) preg_replace('#[\x00-\x1f\x7f/]+#', '_', $part)), 0, 60);
		$c     = $clean($chassis);
		$i     = $clean($id);

		return $c === '' || $i === '' ? null : $c . '/' . $kind . '/' . $i;
	}
}
