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
 * Validation of one BMC, from the edit form or an import row. The result is
 * either a Bmc or a message per field, each naming the field it is about.
 * Whether the referenced credential and device exist, and whether the
 * address is already registered, are checked by the caller against the
 * database.
 */
final readonly class BmcInput {
	public const FIELDS = ['name', 'address', 'port', 'poller_id', 'host_id', 'credential_id', 'tls_mode', 'ca_file', 'pin_sha256', 'basic_fallback', 'enabled'];

	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(public ?Bmc $bmc, public array $errors) {
	}

	/**
	 * @param array<string, mixed> $input fields as posted or imported
	 */
	public static function parse(array $input, int $id): self {
		$errors = [];
		$name   = Row::string($input, 'name');

		if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
			$errors['name'] = __('Name: enter 1 to 100 characters without control characters.', 'redfish');
		}

		$address = Address::normalize(Row::string($input, 'address'));

		if ($address === null) {
			$errors['address'] = __('Address: enter a host name or an IP address only, without a scheme, port, path, or user name. Loopback, link-local, multicast, and unspecified addresses are refused.', 'redfish');
		}

		$port = Row::string($input, 'port') === '' ? 443 : Row::int($input, 'port', 0);

		if ($port < 1 || $port > 65535) {
			$errors['port'] = __('Port: enter a TCP port from 1 to 65535.', 'redfish');
		}

		// Phases 1 to 3 collect on the main poller only.
		$poller = Row::string($input, 'poller_id') === '' ? Bmc::MAIN_POLLER : Row::int($input, 'poller_id', 0);

		if ($poller !== Bmc::MAIN_POLLER) {
			$errors['poller_id'] = __('Poller: only the main poller can collect BMCs in this release.', 'redfish');
		}

		$host       = Row::int($input, 'host_id', -1, 0);
		$credential = Row::int($input, 'credential_id', 0, 0);

		if ($host < 0) {
			$errors['host_id'] = __('Device: choose a Cacti device or none.', 'redfish');
		}

		if ($credential < 1) {
			$errors['credential_id'] = __('Credential: choose a stored credential.', 'redfish');
		}

		$mode = TlsMode::tryFrom(Row::string($input, 'tls_mode'));
		$ca   = Row::string($input, 'ca_file');
		$pin  = Row::string($input, 'pin_sha256');

		if ($mode === null) {
			$errors['tls_mode'] = __('TLS Verification: choose one of the listed modes.', 'redfish');
		}

		if ($mode === TlsMode::CaBundle && !SecureFile::pathAcceptable($ca)) {
			$errors['ca_file'] = __('CA Bundle File: enter an absolute path without control characters, at most 512 characters.', 'redfish');
		}

		$normalizedPin = Bmc::normalizePin($pin);

		if ($mode === TlsMode::Pin && $normalizedPin === null) {
			$errors['pin_sha256'] = __('Public Key Pin: enter the base64 SHA-256 hash of the BMC public key, optionally prefixed with sha256//.', 'redfish');
		}

		if ($errors !== [] || $address === null || $mode === null) {
			return new self(null, $errors);
		}

		return new self(new Bmc(
			id: $id,
			name: $name,
			address: $address,
			port: $port,
			pollerId: $poller,
			hostId: $host,
			credentialId: $credential,
			tlsMode: $mode,
			caFile: $mode === TlsMode::CaBundle ? $ca : '',
			pin: $mode === TlsMode::Pin ? (string) $normalizedPin : '',
			basicFallback: Row::bool($input, 'basic_fallback'),
			adminState: Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled,
		), []);
	}
}
