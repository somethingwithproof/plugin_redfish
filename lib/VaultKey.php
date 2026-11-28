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
 * The vault key, read from a file the administrator creates. The plugin never
 * creates or writes it. The file holds 64 hexadecimal characters, optionally
 * followed by one newline, or exactly 32 random bytes. Thirty-two printable
 * ASCII characters are refused: that is a typed passphrase, not a key, and
 * would leave far less than 256 bits of key.
 */
final class VaultKey {
	/**
	 * Initialize a new VaultKey instance.
	 *
	 * @param Secret $key
	 */
	private function __construct(private readonly Secret $key) {
	}

	/**
	 * @throws CredentialError
	 */
	public static function fromFile(string $path): self {
		$content = SecureFile::read($path, 65);

		$hex = str_ends_with($content, "\n") ? substr($content, 0, -1) : $content;

		if (strlen($hex) === 64 && ctype_xdigit($hex)) {
			return new self(new Secret((string) hex2bin($hex)));
		}

		if (strlen($content) === 32) {
			if (preg_match('/^[\x20-\x7e]{32}$/', $content) === 1) {
				throw CredentialError::because('the key file holds 32 printable characters, which look like a passphrase; write 64 hexadecimal characters (openssl rand -hex 32) or 32 random bytes');
			}

			return new self(new Secret($content));
		}

		throw CredentialError::because('the key file must hold 64 hexadecimal characters or exactly 32 random bytes');
	}

	/**
	 * From bytes.
	 *
	 * @return self
	 * @throws CredentialError
	 */
	public static function fromBytes(#[\SensitiveParameter] string $bytes): self {
		if (strlen($bytes) !== 32) {
			throw CredentialError::because('a vault key is 32 bytes');
		}

		return new self(new Secret($bytes));
	}

	/**
	 * Bytes.
	 *
	 * @return string
	 */
	public function bytes(): string {
		return $this->key->reveal();
	}

	/**
	 * Identifies the key a ciphertext was written with, without revealing it,
	 * so a replaced key file is reported as such rather than as corruption.
	 */
	public function id(): string {
		return substr(hash_hmac('sha256', 'plugin_redfish vault key id', $this->key->reveal()), 0, 16);
	}

	/**
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return ['key' => '[redacted]'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		throw new \LogicException('A vault key cannot be serialized');
	}
}
