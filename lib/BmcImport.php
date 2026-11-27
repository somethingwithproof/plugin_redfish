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
 * CSV import of BMCs (REQ-BMC-004). The file names credentials by name and
 * never carries a secret: a column whose name looks like a password,
 * secret, token, or key rejects the whole file before any row is read.
 * Every row is validated, every error is reported with its line number, and
 * nothing is saved unless every row is valid.
 */
final class BmcImport {
	/**
	 * Max bytes constant.
	 */
	public const MAX_BYTES = 1048576;
	/**
	 * Max rows constant.
	 */
	public const MAX_ROWS  = 1000;

	/**
	 * Columns constant.
	 */
	public const COLUMNS = ['name', 'address', 'port', 'credential', 'tls_mode', 'ca_file', 'pin_sha256', 'basic_fallback', 'enabled', 'host_id'];

	/**
	 * @param list<Bmc>    $bmcs
	 * @param list<string> $errors
	 */
	private function __construct(public array $bmcs, public array $errors) {
	}

	/**
	 * @param array<string, int> $credentials credential name => id
	 * @param callable(string, int): ?string $addressTaken name of a BMC already at the address and port
	 * @param callable(string): bool $nameTaken
	 * @param callable(int): bool $hostExists
	 * @param callable(int): ?string $hostTaken name of a BMC already on the device
	 */
	public static function parse(string $csv, array $credentials, callable $addressTaken, callable $nameTaken, callable $hostExists, ?callable $hostTaken = null): self {
		if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
			return new self([], [__('The file is empty or larger than 1 MiB.', 'redfish')]);
		}

		$handle = fopen('php://memory', 'r+');

		if ($handle === false) {
			return new self([], [__('The file could not be read.', 'redfish')]);
		}

		fwrite($handle, $csv);
		rewind($handle);

		try {
			return self::rows($handle, $credentials, $addressTaken, $nameTaken, $hostExists, $hostTaken ?? static fn (int $host): ?string => null);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * @param resource           $handle
	 * @param array<string, int> $credentials
	 */
	private static function rows($handle, array $credentials, callable $addressTaken, callable $nameTaken, callable $hostExists, callable $hostTaken): self {
		$header = fgetcsv($handle, 0, ',', '"', '');

		if (!is_array($header)) {
			return new self([], [__('The file has no header row.', 'redfish')]);
		}

		$header = array_map(static fn ($h): string => strtolower(trim((string) $h, " \t\xEF\xBB\xBF")), $header);

		foreach ($header as $column) {
			if (preg_match('/pass|secret|token|key|auth/', $column) === 1) {
				return new self([], [__('The file has a column named "%s". Import files must not carry secrets; name a stored credential in the credential column instead. Nothing was imported.', $column, 'redfish')]);
			}
		}

		$unknown = array_diff($header, self::COLUMNS);

		if ($unknown !== [] || count(array_unique($header)) !== count($header) || !in_array('name', $header, true) || !in_array('address', $header, true) || !in_array('credential', $header, true)) {
			return new self([], [__('The header must name only these columns, each once, including name, address, and credential: %s.', implode(', ', self::COLUMNS), 'redfish')]);
		}

		$bmcs      = [];
		$errors    = [];
		$addresses = [];
		$names     = [];
		$hosts     = [];
		$line      = 1;

		while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$line++;

			if ($fields === [null]) {
				continue;
			}

			if (count($bmcs) + count($errors) >= self::MAX_ROWS) {
				$errors[] = __('The file has more than %d rows.', self::MAX_ROWS, 'redfish');

				break;
			}

			if (count($fields) !== count($header)) {
				$errors[] = __('Line %d: expected %d fields, found %d.', $line, count($header), count($fields), 'redfish');

				continue;
			}

			$row = array_combine($header, array_map(static fn ($v): string => trim((string) $v), $fields));
			$credentialName = $row['credential'];
			$input = [
				'name'           => $row['name'],
				'address'        => $row['address'],
				'port'           => $row['port'] ?? '',
				'poller_id'      => (string) Bmc::MAIN_POLLER,
				'host_id'        => ($row['host_id'] ?? '') === '' ? '0' : $row['host_id'],
				'credential_id'  => (string) ($credentials[$credentialName] ?? 0),
				'tls_mode'       => ($row['tls_mode'] ?? '') === '' ? TlsMode::System->value : $row['tls_mode'],
				'ca_file'        => $row['ca_file'] ?? '',
				'pin_sha256'     => $row['pin_sha256'] ?? '',
				'basic_fallback' => $row['basic_fallback'] ?? '',
				'enabled'        => ($row['enabled'] ?? '') === '' ? 'on' : $row['enabled'],
			];

			$parsed = BmcInput::parse($input, 0);

			foreach ($parsed->errors as $field => $message) {
				$errors[] = __('Line %d: %s', $line, $field === 'credential_id' && $credentialName !== '' ? __('Credential: no stored credential is named "%s".', $credentialName, 'redfish') : $message, 'redfish');
			}

			$bmc = $parsed->bmc;

			if ($bmc === null) {
				continue;
			}

			$where = $bmc->address . ' ' . $bmc->port;
			$taken = $addressTaken($bmc->address, $bmc->port);

			if ($taken !== null || isset($addresses[$where])) {
				$errors[] = __('Line %d: the address %s port %d is already registered as %s.', $line, $bmc->address, $bmc->port, $taken ?? __('line %d', $addresses[$where], 'redfish'), 'redfish');

				continue;
			}

			if ($nameTaken($bmc->name) || isset($names[$bmc->name])) {
				$errors[] = __('Line %d: the name %s is already used.', $line, $bmc->name, 'redfish');

				continue;
			}

			if ($bmc->hostId > 0 && !$hostExists($bmc->hostId)) {
				$errors[] = __('Line %d: no Cacti device has ID %d.', $line, $bmc->hostId, 'redfish');

				continue;
			}

			// One BMC per device, against the registry and within the file.
			if ($bmc->hostId > 0) {
				$holder = $hostTaken($bmc->hostId);

				if ($holder !== null || isset($hosts[$bmc->hostId])) {
					$errors[] = __('Line %d: device %d already has the BMC %s.', $line, $bmc->hostId, $holder ?? __('of line %d', $hosts[$bmc->hostId], 'redfish'), 'redfish');

					continue;
				}

				$hosts[$bmc->hostId] = $line;
			}

			$addresses[$where] = $line;
			$names[$bmc->name] = $line;
			$bmcs[]            = $bmc;
		}

		if ($bmcs === [] && $errors === []) {
			$errors[] = __('The file has no BMC rows.', 'redfish');
		}

		return new self($errors === [] ? $bmcs : [], $errors);
	}
}
