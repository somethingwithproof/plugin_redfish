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
	 * Control bytes and slash mask rejected in raw member key segments.
	 */
	private const UNSAFE_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f/";

	/**
	 * The key for a member, or null when an identifier is unusable. Each
	 * part loses control characters and slashes and is capped, so a key
	 * always fits the 191-character column.
	 */
	public static function key(string $chassis, string $kind, string $id): ?string {
		$c = self::cleanPart($chassis);
		$i = self::cleanPart($id);

		return $c === '' || $i === '' ? null : $c . '/' . $kind . '/' . $i;
	}

	/**
	 * Sanitize one key segment using a fast strcspn() check before PCRE fallback.
	 *
	 * @param string $part raw chassis or member identifier
	 * @return string sanitized segment capped at 60 bytes
	 */
	private static function cleanPart(string $part): string {
		$len = strlen($part);

		if (strcspn($part, self::UNSAFE_CHARS) === $len) {
			return substr(trim($part), 0, 60);
		}

		return substr(trim((string) preg_replace('#[\x00-\x1f\x7f/]+#', '_', $part)), 0, 60);
	}
}
