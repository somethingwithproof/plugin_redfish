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
 * Resolves a BMC's user name and password from the vault, and stores new
 * passwords (REQ-CRED-004, ADR 0009). The key is loaded only when a value is
 * encrypted or decrypted, so pages that only list credentials work without
 * it. A credential that cannot be resolved is an error; there is no fallback
 * to another credential or a default (REQ-CRED-005).
 */
final class CredentialStore {
	public const MAX_PASSWORD_BYTES = 1024;

	private ?Vault $vault = null;

	public function __construct(
		private readonly CredentialRepository $credentials,
		private readonly string $keyFile,
	) {
	}

	/**
	 * @return array{credential: Credential, password: Secret}
	 * @throws CredentialError
	 */
	public function resolve(int $credentialId): array {
		$credential = $credentialId > 0 ? $this->credentials->find($credentialId) : null;

		if ($credential === null) {
			throw CredentialError::because('the BMC references no stored credential');
		}

		if ($credential->username === '') {
			throw CredentialError::because('the credential has no user name');
		}

		$entry = $this->credentials->loadSecret($credential->id, CredentialRepository::PASSWORD);

		if ($entry === null) {
			throw CredentialError::because('no password is stored for the credential');
		}

		return ['credential' => $credential, 'password' => $this->vault()->decrypt($entry, $credential->id, CredentialRepository::PASSWORD)];
	}

	public function hasPassword(int $credentialId): bool {
		return $this->credentials->hasSecret($credentialId, CredentialRepository::PASSWORD);
	}

	/**
	 * @throws CredentialError
	 */
	public function storePassword(int $credentialId, Secret $password, int $userId): void {
		if ($password->isEmpty() || strlen($password->reveal()) > self::MAX_PASSWORD_BYTES) {
			throw CredentialError::because('the password is empty or too long');
		}

		$entry = $this->vault()->encrypt($password, $credentialId, CredentialRepository::PASSWORD);

		if (!$this->credentials->storeSecret($credentialId, CredentialRepository::PASSWORD, $entry, $userId)) {
			throw CredentialError::because('the password could not be stored');
		}
	}

	/**
	 * Whether the vault key file can be read, for the health page. The
	 * message never contains key material.
	 */
	public function keyProblem(): ?string {
		try {
			$this->vault();

			return null;
		} catch (CredentialError $e) {
			return $e->getMessage();
		}
	}

	/**
	 * @throws CredentialError
	 */
	private function vault(): Vault {
		return $this->vault ??= new Vault(VaultKey::fromFile($this->keyFile));
	}
}
