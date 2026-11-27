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
 * AES-256-GCM encryption of stored BMC passwords (REQ-CRED-004, ADR 0009).
 *
 * Each value gets a fresh random 96-bit nonce. The additional data names the
 * credential and the purpose of the value, so a ciphertext copied to another
 * credential's row fails to decrypt instead of being used.
 */
final class Vault {
	/**
	 * Nonce bytes constant.
	 */
	public const NONCE_BYTES = 12;
	/**
	 * Tag bytes constant.
	 */
	public const TAG_BYTES   = 16;

	/**
	 * Cipher constant.
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Initialize a new Vault instance.
	 *
	 * @param VaultKey $key
	 */
	public function __construct(private readonly VaultKey $key) {
	}

	/**
	 * Encrypt.
	 *
	 * @param Secret $secret
	 * @param int $credentialId
	 * @param string $purpose
	 * @return VaultEntry
	 * @throws CredentialError
	 */
	public function encrypt(Secret $secret, int $credentialId, string $purpose): VaultEntry {
		$nonce = random_bytes(self::NONCE_BYTES);
		$tag   = '';

		$ciphertext = openssl_encrypt($secret->reveal(), self::CIPHER, $this->key->bytes(), OPENSSL_RAW_DATA, $nonce, $tag, self::additionalData($credentialId, $purpose), self::TAG_BYTES);

		if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
			throw CredentialError::because('the password could not be encrypted');
		}

		return new VaultEntry($nonce, $ciphertext, $tag, $this->key->id());
	}

	/**
	 * @throws CredentialError
	 */
	public function decrypt(VaultEntry $entry, int $credentialId, string $purpose): Secret {
		if ($entry->keyId !== $this->key->id()) {
			throw CredentialError::because('the password was stored with a different vault key; enter it again');
		}

		$plain = openssl_decrypt($entry->ciphertext, self::CIPHER, $this->key->bytes(), OPENSSL_RAW_DATA, $entry->nonce, $entry->tag, self::additionalData($credentialId, $purpose));

		if ($plain === false) {
			throw CredentialError::because('the stored password failed its integrity check');
		}

		return new Secret($plain);
	}

	/**
	 * Additional data.
	 *
	 * @param int $credentialId
	 * @param string $purpose
	 * @return string
	 */
	private static function additionalData(int $credentialId, string $purpose): string {
		return 'plugin_redfish/vault/v1/credential/' . $credentialId . '/' . $purpose;
	}
}
