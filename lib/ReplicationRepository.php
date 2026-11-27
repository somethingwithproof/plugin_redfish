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
 * Prepared-SQL persistence for remote collector configuration replication.
 *
 * Replicates only non-secret configuration tables (BMCs, credential metadata,
 * racks, and rack members). Vault key files remain strictly local on each
 * poller, and raw secrets are never replicated here.
 */
final class ReplicationRepository {
	/**
	 * Non-secret configuration tables replicated to remote pollers.
	 * plugin_redfish_secret is intentionally excluded by default so vault
	 * key material stays local and secrets are never broadcast to pollers
	 * that do not own the BMC.
	 */
	public const CONFIG_TABLES = [
		'plugin_redfish_bmc',
		'plugin_redfish_credential',
		'plugin_redfish_rack',
		'plugin_redfish_rack_member',
	];

	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Load non-secret BMC rows assigned to a specific poller (or all if 0).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function bmcRows(int $pollerId): array {
		if ($pollerId > 0) {
			return Row::rows(db_fetch_assoc_prepared(
				'SELECT id, name, address, port, protocols, poller_id, host_id, credential_id, tls_mode, ca_file, pin_sha256, basic_fallback, admin_state, created_at, updated_at FROM plugin_redfish_bmc WHERE poller_id = ? ORDER BY id',
				[$pollerId],
				true,
				$this->connection
			));
		}

		return Row::rows(db_fetch_assoc_prepared(
			'SELECT id, name, address, port, protocols, poller_id, host_id, credential_id, tls_mode, ca_file, pin_sha256, basic_fallback, admin_state, created_at, updated_at FROM plugin_redfish_bmc ORDER BY id',
			[],
			true,
			$this->connection
		));
	}

	/**
	 * Load non-secret credential metadata (never ciphertext or vault key material)
	 * referenced by BMCs on the target poller.
	 *
	 * @param list<int> $credentialIds
	 * @return list<array<string, mixed>>
	 */
	public function credentialMetadataRows(array $credentialIds): array {
		$ids = array_values(array_unique(array_filter($credentialIds, static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$in = implode(', ', array_fill(0, count($ids), '?'));

		return Row::rows(db_fetch_assoc_prepared(
			"SELECT id, name, username, revision, created_at, updated_at FROM plugin_redfish_credential WHERE id IN ($in) ORDER BY id",
			$ids,
			true,
			$this->connection
		));
	}

	/**
	 * Load rack definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rackRows(): array {
		return Row::rows(db_fetch_assoc_prepared(
			'SELECT id, site_id, name, power_budget_watts, min_coverage_pct, notes, created_at, updated_at FROM plugin_redfish_rack ORDER BY id',
			[],
			true,
			$this->connection
		));
	}

	/**
	 * Load rack member assignments.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rackMemberRows(): array {
		return Row::rows(db_fetch_assoc_prepared(
			'SELECT host_id, rack_id, bmc_id, unit_position, added_at FROM plugin_redfish_rack_member ORDER BY host_id',
			[],
			true,
			$this->connection
		));
	}
}
