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
 * One registered BMC (REQ-BMC-001). Holds a credential reference, never a
 * secret.
 */
final readonly class Bmc {
	public const MAIN_POLLER = 1;

	public function __construct(
		public int $id,
		public string $name,
		public string $address,
		public int $port,
		public int $pollerId,
		public int $hostId,
		public int $credentialId,
		public TlsMode $tlsMode,
		public string $caFile,
		public string $pin,
		public bool $basicFallback,
		public AdminState $adminState,
	) {
	}

	/**
	 * A row of plugin_redfish_bmc, or null when the row cannot be collected
	 * safely: no id, or an address that no longer passes Address::normalize()
	 * unchanged (a hand-edited row cannot point the collector at loopback).
	 * An unknown TLS mode reads as System, which verifies, and never as
	 * Unverified. Other loose values fall back to the defaults pinned by
	 * tests/Unit/BmcTest.php.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id      = Row::int($row, 'id', 0, 1);
		$address = Row::string($row, 'address');

		if ($id === 0 || $address === '' || Address::normalize($address) !== $address) {
			return null;
		}

		$port = Row::int($row, 'port', 443, 1);

		return new self(
			id: $id,
			name: Row::string($row, 'name'),
			address: $address,
			port: $port > 65535 ? 443 : $port,
			pollerId: Row::int($row, 'poller_id', self::MAIN_POLLER, 1),
			hostId: Row::int($row, 'host_id', 0, 0),
			credentialId: Row::int($row, 'credential_id', 0, 0),
			tlsMode: TlsMode::tryFrom(Row::string($row, 'tls_mode')) ?? TlsMode::System,
			caFile: Row::string($row, 'ca_file'),
			pin: self::normalizePin(Row::string($row, 'pin_sha256')) ?? '',
			basicFallback: Row::bool($row, 'basic_fallback'),
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
		);
	}

	/**
	 * A SHA-256 public key pin as base64, from either that form or libcurl's
	 * "sha256//" form, or null when the text is not one.
	 */
	public static function normalizePin(string $pin): ?string {
		$pin = trim($pin);

		if (str_starts_with($pin, 'sha256//')) {
			$pin = substr($pin, 8);
		}

		if (preg_match('#^[A-Za-z0-9+/]{43}=$#', $pin) !== 1) {
			return null;
		}

		$raw = base64_decode($pin, true);

		return is_string($raw) && strlen($raw) === 32 ? $pin : null;
	}

	/**
	 * Column values for plugin_redfish_bmc, without id and timestamps.
	 *
	 * @return array<string, string|int>
	 */
	public function toRow(): array {
		return [
			'name'           => $this->name,
			'address'        => $this->address,
			'port'           => $this->port,
			'protocols'      => 'redfish',
			'poller_id'      => $this->pollerId,
			'host_id'        => $this->hostId,
			'credential_id'  => $this->credentialId,
			'tls_mode'       => $this->tlsMode->value,
			'ca_file'        => $this->caFile,
			'pin_sha256'     => $this->pin,
			'basic_fallback' => $this->basicFallback ? 'on' : '',
			'admin_state'    => $this->adminState->value,
		];
	}

	/**
	 * Names of the columns that differ from $before, for the audit log.
	 *
	 * @return list<string>
	 */
	public function changedFields(?self $before): array {
		$now  = $this->toRow();
		$then = $before?->toRow() ?? [];

		return array_keys(array_filter($now, static fn ($value, $key) => !array_key_exists($key, $then) || $then[$key] !== $value, ARRAY_FILTER_USE_BOTH));
	}

	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}

	public function verifiesTls(): bool {
		return $this->tlsMode !== TlsMode::Unverified;
	}

	/**
	 * The origin every request of this BMC goes to.
	 */
	public function origin(): string {
		return 'https://' . Address::urlHost($this->address) . ($this->port === 443 ? '' : ':' . $this->port);
	}
}
