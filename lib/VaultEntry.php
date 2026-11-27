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
 * One encrypted vault value as stored in plugin_redfish_secret.
 */
final readonly class VaultEntry {
	/**
	 * Initialize a new VaultEntry instance.
	 *
	 * @param string $nonce
	 * @param string $ciphertext
	 * @param string $tag
	 * @param string $keyId
	 */
	public function __construct(
		public string $nonce,
		public string $ciphertext,
		public string $tag,
		public string $keyId,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$nonce      = $row['nonce'] ?? null;
		$ciphertext = $row['ciphertext'] ?? null;
		$tag        = $row['tag'] ?? null;

		if (!is_string($nonce) || strlen($nonce) !== Vault::NONCE_BYTES || !is_string($ciphertext) || !is_string($tag) || strlen($tag) !== Vault::TAG_BYTES) {
			return null;
		}

		return new self($nonce, $ciphertext, $tag, Row::string($row, 'key_id'));
	}
}
