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
 * Pure read-only IPMI command builder (REQ-IPMI-002, REQ-IPMI-003).
 *
 * Commands are constructed strictly as explicit argv arrays for proc_open()
 * with zero shell interpolation. Passwords are NEVER placed on argv: callers
 * choose either IPMITOOL_PASSWORD (-E) or a mode-0600 password file (-f).
 * Only explicitly allowlisted read-only subcommands may be built.
 */
final class IpmiCommand {
	/**
	 * Op dcmi power constant.
	 */
	public const OP_DCMI_POWER      = 'dcmi_power';
	/**
	 * Op sdr temperature constant.
	 */
	public const OP_SDR_TEMPERATURE = 'sdr_temperature';
	/**
	 * Op sdr fan constant.
	 */
	public const OP_SDR_FAN         = 'sdr_fan';
	/**
	 * Op sdr psu constant.
	 */
	public const OP_SDR_PSU         = 'sdr_psu';
	/**
	 * Op chassis status constant.
	 */
	public const OP_CHASSIS_STATUS  = 'chassis_status';

	/**
	 * Auth env constant.
	 */
	public const AUTH_ENV  = 'env';
	/**
	 * Auth file constant.
	 */
	public const AUTH_FILE = 'file';

	/**
	 * Strictly read-only ipmitool subcommands (REQ-IPMI-003).
	 * Mutating commands (dcmi power set_limit, power off/reset, user, lan set)
	 * are intentionally absent and always refused.
	 *
	 * @var array<string, list<string>>
	 */
	public const ALLOWLIST = [
		self::OP_DCMI_POWER      => ['dcmi', 'power', 'reading'],
		self::OP_SDR_TEMPERATURE => ['sdr', 'type', 'Temperature'],
		self::OP_SDR_FAN         => ['sdr', 'type', 'Fan'],
		self::OP_SDR_PSU         => ['sdr', 'type', 'Power Supply'],
		self::OP_CHASSIS_STATUS  => ['chassis', 'status'],
	];

	/**
	 * Build an explicit argv array for proc_open() without a shell.
	 *
	 * @return list<string>
	 * @throws ClientError
	 */
	public static function argv(
		string $binary,
		string $address,
		string $username,
		string $operation,
		string $authMode = self::AUTH_ENV,
		string $passwordFile = '',
		int $timeoutSeconds = 10,
	): array {
		if (!isset(self::ALLOWLIST[$operation])) {
			throw new ClientError(FailureClass::OperationRefused);
		}

		$cleanBinary = trim($binary);

		if ($cleanBinary === '' || preg_match('/[\x00-\x1f\x7f\s]/', $cleanBinary) === 1 || str_starts_with($cleanBinary, '-')) {
			throw new ClientError(FailureClass::OperationRefused);
		}

		$cleanAddress = Address::normalize($address);

		if ($cleanAddress === null || str_starts_with($cleanAddress, '-')) {
			throw new ClientError(FailureClass::AddressRefused);
		}

		if ($username === '' || strlen($username) > 64 || preg_match('/[\x00-\x1f\x7f]/', $username) === 1) {
			throw new ClientError(FailureClass::CredentialUnavailable);
		}

		$timeout = max(1, min(60, $timeoutSeconds));
		$argv    = [
			$cleanBinary,
			'-I', 'lanplus',
			'-N', (string) $timeout,
			'-R', '1',
			'-H', $cleanAddress,
			'-U', $username,
		];

		if ($authMode === self::AUTH_FILE) {
			if ($passwordFile === '' || preg_match('/[\x00-\x1f\x7f]/', $passwordFile) === 1) {
				throw new ClientError(FailureClass::CredentialUnavailable);
			}

			$argv[] = '-f';
			$argv[] = $passwordFile;
		} elseif ($authMode === self::AUTH_ENV) {
			$argv[] = '-E';
		} else {
			throw new ClientError(FailureClass::OperationRefused);
		}

		foreach (self::ALLOWLIST[$operation] as $arg) {
			$argv[] = $arg;
		}

		return $argv;
	}
}
