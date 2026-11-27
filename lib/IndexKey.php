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
 * The data query index of a member: its stable member key (chassis Id, kind,
 * member Id; REQ-DISC-005), made safe for Cacti. Cacti keeps each data
 * source keyed by this value and re-maps or orphans data sources by it, so
 * it must never be a list position (REQ-DQ-002, docs/research/data-query.md).
 *
 * The value passes through Cacti's `|query_*|` substitution, its `!` output
 * delimiter, and a 191-character cache column. A key made only of safe
 * characters is used as it is. Any other key has each unsafe character
 * replaced and a digest of the original appended, so two keys that differ
 * only in unsafe characters never share an index, and the same key always
 * gives the same index.
 */
final class IndexKey {
	/**
	 * Max constant.
	 */
	public const MAX = 120;

	/**
	 * Safe character mask for fast strspn() checks without PCRE overhead.
	 */
	private const SAFE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:/-';

	/**
	 * Plausible index character mask (SAFE_CHARS plus tilde digest separator).
	 */
	private const PLAUSIBLE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:/~-';

	/**
	 * Of.
	 *
	 * @param string $memberKey
	 * @return string
	 */
	public static function of(string $memberKey): string {
		$len = strlen($memberKey);

		if ($len > 0 && $len <= self::MAX && strspn($memberKey, self::SAFE_CHARS) === $len) {
			return $memberKey;
		}

		$clean = substr((string) preg_replace('/[^A-Za-z0-9._:\/-]/', '_', $memberKey), 0, self::MAX - 9);

		return $clean . '~' . substr(hash('sha256', $memberKey), 0, 8);
	}

	/**
	 * Whether a value received from Cacti could be an index at all, checked
	 * before it is compared with anything.
	 */
	public static function plausible(string $index): bool {
		$len = strlen($index);

		return $len > 0 && $len <= self::MAX && strspn($index, self::PLAUSIBLE_CHARS) === $len;
	}
}
