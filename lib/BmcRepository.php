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
 * The BMC registry and its audit log. Every change writes an audit row with
 * the user, the time, and the changed columns, never their values.
 */
final class BmcRepository {
	/**
	 * Delete attempts constant.
	 */
	private const DELETE_ATTEMPTS = 3;
	/**
	 * Delete savepoint constant.
	 */
	private const DELETE_SAVEPOINT = 'redfish_delete';

	/**
	 * Initialize a new BmcRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Find.
	 *
	 * @param int $id
	 * @return Bmc|null
	 */
	public function find(int $id): ?Bmc {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_bmc WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) && $row !== [] ? Bmc::fromRow($row) : null;
	}

	/**
	 * @return list<Bmc>
	 */
	public function all(): array {
		return $this->bmcs(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_bmc ORDER BY name', [], true, $this->connection));
	}

	/**
	 * Enabled BMCs of one poller, for that poller's collector (REQ-COLL-006).
	 *
	 * @return list<Bmc>
	 */
	public function enabledForPoller(int $pollerId): array {
		return $this->bmcs(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_bmc WHERE poller_id = ? AND admin_state = ? ORDER BY id', [$pollerId, AdminState::Enabled->value], true, $this->connection));
	}

	/**
	 * Name taken.
	 *
	 * @param string $name
	 * @param int $exceptId
	 * @return bool
	 */
	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_bmc WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Another BMC registered at the same address and port, or null
	 * (REQ-BMC-005). Addresses are stored normalized, so equal text is an
	 * equal address.
	 */
	public function addressHolder(string $address, int $port, int $exceptId): ?Bmc {
		$id = (int) db_fetch_cell_prepared('SELECT id FROM plugin_redfish_bmc WHERE address = ? AND port = ? AND id != ? LIMIT 1', [$address, $port, $exceptId], '', true, $this->connection);

		return $id > 0 ? $this->find($id) : null;
	}

	/**
	 * The name to show for another BMC at this address: its name when the
	 * user may see it, otherwise a generic label, so a hidden BMC is never
	 * named.
	 */
	public function addressTakenBy(string $address, int $port, int $exceptId, DeviceScope $scope): ?string {
		$holder = $this->addressHolder($address, $port, $exceptId);

		if ($holder === null) {
			return null;
		}

		return $scope->allowsBmc($holder) && $holder->name !== '' ? $holder->name : __('another BMC', 'redfish');
	}

	/**
	 * Credential exists.
	 *
	 * @param int $credentialId
	 * @return bool
	 */
	public function credentialExists(int $credentialId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_credential WHERE id = ?', [$credentialId], '', true, $this->connection) === 1;
	}

	/**
	 * Device ids of the BMCs that use a credential, 0 for unmapped ones.
	 *
	 * @return list<int>
	 */
	public function credentialHosts(int $credentialId): array {
		return array_map(static fn (array $row): int => Row::int($row, 'host_id'), Row::rows(db_fetch_assoc_prepared('SELECT host_id FROM plugin_redfish_bmc WHERE credential_id = ?', [$credentialId], true, $this->connection)));
	}

	/**
	 * Host exists.
	 *
	 * @param int $hostId
	 * @return bool
	 */
	public function hostExists(int $hostId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', [$hostId], '', true, $this->connection) === 1;
	}

	/**
	 * The BMC mapped to a Cacti device, or null. A device carries at most one
	 * BMC, because the script server finds a BMC by its device.
	 */
	public function findByHost(int $hostId, int $exceptId = 0): ?Bmc {
		$id = $hostId > 0 ? (int) db_fetch_cell_prepared('SELECT id FROM plugin_redfish_bmc WHERE host_id = ? AND id != ? ORDER BY id LIMIT 1', [$hostId, $exceptId], '', true, $this->connection) : 0;

		return $id > 0 ? $this->find($id) : null;
	}

	/**
	 * Map an unmapped BMC to the device the provisioner just created, with
	 * an audit entry. Compare and set: false when the BMC was mapped in the
	 * meantime or another BMC holds the device, and nothing changes then.
	 */
	public function mapHost(int $bmcId, int $hostId, int $userId): bool {
		if ($hostId <= 0 || $this->findByHost($hostId, $bmcId) !== null) {
			return false;
		}

		$ok = db_execute_prepared('UPDATE plugin_redfish_bmc SET host_id = ?, updated_at = ? WHERE id = ? AND host_id = 0', [$hostId, $this->clock->now(), $bmcId], true, $this->connection)
			&& db_affected_rows($this->connection) === 1;

		if ($ok) {
			$this->audit($bmcId, $userId, 'update', ['host_id']);
		}

		return $ok;
	}

	/**
	 * Accept the BMC's members as they are now: members present stay
	 * expected, and members no longer present stop counting as failed.
	 * Audited, as an administrator's decision.
	 */
	public function acceptMembers(int $bmcId, int $userId): bool {
		$ok = (bool) db_execute_prepared("UPDATE plugin_redfish_member SET expected = IF(presence = 'present', 'on', '') WHERE bmc_id = ?", [$bmcId], true, $this->connection);

		if ($ok) {
			$this->audit($bmcId, $userId, 'update', ['members']);
		}

		return $ok;
	}

	/**
	 * device_remove hook: BMCs of deleted devices become unmapped. The BMC,
	 * its credential, and its cache stay (REQ-DEV-003).
	 *
	 * @param list<int> $hostIds
	 */
	public function unmapHosts(array $hostIds): int {
		$hostIds = array_values(array_filter($hostIds, static fn (int $id): bool => $id > 0));

		if ($hostIds === []) {
			return 0;
		}

		$in  = implode(', ', array_fill(0, count($hostIds), '?'));
		$ids = array_map(static fn (array $row): int => Row::int($row, 'id'), Row::rows(db_fetch_assoc_prepared("SELECT id FROM plugin_redfish_bmc WHERE host_id IN ($in)", $hostIds, true, $this->connection)));
		$cleared = 0;

		// Compare and set: a BMC moved to a surviving device since the
		// SELECT keeps that mapping, and only cleared mappings are audited.
		foreach ($ids as $id) {
			if (db_execute_prepared("UPDATE plugin_redfish_bmc SET host_id = 0, updated_at = ? WHERE id = ? AND host_id IN ($in)", array_merge([$this->clock->now(), $id], $hostIds), true, $this->connection) && db_affected_rows($this->connection) === 1) {
				$this->audit($id, 0, 'unmap', ['host_id']);
				$cleared++;
			}
		}

		return $cleared;
	}

	/**
	 * Device choices for the edit form: id => description.
	 *
	 * @return array<int, string>
	 */
	public function hostChoices(): array {
		$choices = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, description, hostname FROM host ORDER BY description', [], true, $this->connection)) as $row) {
			$choices[Row::int($row, 'id')] = Row::string($row, 'description') . ' (' . Row::string($row, 'hostname') . ')';
		}

		return $choices;
	}

	/**
	 * Insert or update, returning the id, or 0 when the write failed. A change
	 * of TLS mode is logged, as unverified TLS must be (REQ-TLS-003).
	 */
	public function save(Bmc $bmc, int $userId): int {
		$before = $bmc->id > 0 ? $this->find($bmc->id) : null;

		if ($bmc->id > 0 && $before === null) {
			return 0;
		}

		$id = $this->write($bmc, $before);

		if ($id > 0) {
			$this->afterWrite($id, $bmc, $before, $userId);
		}

		return $id;
	}

	/**
	 * Insert every BMC or none (REQ-BMC-004). The caller has validated each row.
	 *
	 * @param list<Bmc> $bmcs new BMCs, id 0
	 */
	public function import(array $bmcs, int $userId): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$ids = [];

		foreach ($bmcs as $bmc) {
			$id = $this->write($bmc, null);

			if ($id === 0) {
				db_rollback_transaction($this->connection);

				return false;
			}

			$ids[$id] = $bmc;
		}

		if (!db_commit_transaction($this->connection)) {
			db_rollback_transaction($this->connection);

			return false;
		}

		foreach ($ids as $id => $bmc) {
			$this->afterWrite($id, $bmc, null, $userId, 'import');
		}

		return true;
	}

	/**
	 * Remove the BMC and everything cached for it, which stops its collection
	 * (REQ-BMC-006). The mapped Cacti device is never touched.
	 *
	 * All or nothing. InnoDB rolls back a transaction on deadlock and Cacti
	 * then retries the failed statement outside any transaction, so the
	 * transaction is checked after each statement and the delete reruns if it
	 * ended. Every statement is idempotent and the BMC row goes last.
	 */
	public function delete(int $id, int $userId): bool {
		if ($this->find($id) === null) {
			return false;
		}

		$statements = [];

		foreach (['plugin_redfish_reading', 'plugin_redfish_member', 'plugin_redfish_discovery', 'plugin_redfish_health', 'plugin_redfish_lease'] as $table) {
			$statements[] = ["DELETE FROM $table WHERE bmc_id = ?", [$id]];
		}

		$statements[] = ['DELETE FROM plugin_redfish_bmc WHERE id = ?', [$id]];

		for ($attempt = 0; $attempt < self::DELETE_ATTEMPTS; $attempt++) {
			if (!db_begin_transaction($this->connection)) {
				return false;
			}

			if (!db_execute_prepared('SAVEPOINT ' . self::DELETE_SAVEPOINT, [], true, $this->connection)) {
				db_rollback_transaction($this->connection);

				return false;
			}

			$intact = true;

			foreach ($statements as [$sql, $params]) {
				if (!db_execute_prepared($sql, $params, true, $this->connection) || !$this->inTransaction()) {
					$intact = false;

					break;
				}
			}

			if ($intact && db_commit_transaction($this->connection)) {
				$this->audit($id, $userId, 'delete', []);

				return true;
			}

			if ($this->inTransaction()) {
				db_rollback_transaction($this->connection);
			}
		}

		return false;
	}

	/**
	 * Export rows: the registry as stored, with credential names instead of
	 * ids and never a secret (REQ-LIFE-004), limited to the BMCs the scope
	 * lets the user see.
	 *
	 * @return list<array<string, string|int>>
	 */
	public function export(DeviceScope $scope): array {
		$out   = [];
		$names = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, name FROM plugin_redfish_credential', [], true, $this->connection)) as $row) {
			$names[Row::int($row, 'id')] = Row::string($row, 'name');
		}

		foreach ($scope->filter($this->all()) as $bmc) {
			$row = $bmc->toRow();
			unset($row['protocols']);
			$row['credential'] = $names[$bmc->credentialId] ?? '';
			unset($row['credential_id']);
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Credential name => id, for import rows, which name a credential.
	 *
	 * @return array<string, int>
	 */
	public function credentialIds(DeviceScope $scope): array {
		$ids = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, name FROM plugin_redfish_credential', [], true, $this->connection)) as $row) {
			$id = Row::int($row, 'id');

			// A credential the user may not bind reads as unknown.
			if ($scope->allowsCredential($this, $id)) {
				$ids[Row::string($row, 'name')] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @return list<array{object_id: int, user_id: int, changed_at: int, action: string, fields: list<string>}>
	 */
	public function auditLog(int $bmcId, int $limit = 50): array {
		$entries = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_audit WHERE object = ? AND object_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), ['bmc', $bmcId], true, $this->connection)) as $row) {
			$fields = Row::string($row, 'fields');

			$entries[] = [
				'object_id'  => Row::int($row, 'object_id'),
				'user_id'    => Row::int($row, 'user_id'),
				'changed_at' => Row::int($row, 'changed_at'),
				'action'     => Row::string($row, 'action'),
				'fields'     => $fields === '' ? [] : explode(',', $fields),
			];
		}

		return $entries;
	}

	/**
	 * Write.
	 *
	 * @param Bmc $bmc
	 * @param Bmc|null $before
	 * @return int
	 */
	private function write(Bmc $bmc, ?Bmc $before): int {
		$row = $bmc->toRow();
		$now = $this->clock->now();

		// One BMC per device; the host_owner unique key backs this up when
		// two writes race, and the write then fails instead of doubling up.
		if ($bmc->hostId > 0 && $this->findByHost($bmc->hostId, $bmc->id) !== null) {
			return 0;
		}

		if ($before === null) {
			$columns = array_keys($row);
			$ok      = db_execute_prepared('INSERT INTO plugin_redfish_bmc (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')', array_merge(array_values($row), [$now, $now]), true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		$set = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($row)));
		$ok  = db_execute_prepared("UPDATE plugin_redfish_bmc SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($row), [$now, $bmc->id]), true, $this->connection);

		return $ok ? $bmc->id : 0;
	}

	/**
	 * After write.
	 *
	 * @param int $id
	 * @param Bmc $bmc
	 * @param Bmc|null $before
	 * @param int $userId
	 * @param string $create
	 * @return void
	 */
	private function afterWrite(int $id, Bmc $bmc, ?Bmc $before, int $userId, string $create = 'create'): void {
		$changed = $bmc->changedFields($before);

		if ($changed !== []) {
			$this->audit($id, $userId, $before === null ? $create : 'update', $changed);
		}

		if ($before?->tlsMode !== $bmc->tlsMode && ($bmc->tlsMode === TlsMode::Unverified || $before?->tlsMode === TlsMode::Unverified)) {
			Log::warning(sprintf('BMC %d (%s): TLS verification set to %s by user %d', $id, $bmc->name, $bmc->tlsMode->value, $userId));
		}

		// An administrator who edits a BMC has looked at it: backoff, a held
		// authentication failure, and the lost-session budget start over.
		if ($before !== null && $changed !== []) {
			db_execute_prepared('UPDATE plugin_redfish_health SET failures = 0, backoff_until = 0, sessions_lost = 0, auth_credential_id = 0, auth_revision = 0 WHERE bmc_id = ?', [$id], true, $this->connection);
		}

		// A new address, port, or trust setting means a new BMC as far as
		// the cache is concerned: rediscover before trusting old targets.
		if ($before !== null && ($before->address !== $bmc->address || $before->port !== $bmc->port || $before->tlsMode !== $bmc->tlsMode || $before->pin !== $bmc->pin || $before->caFile !== $bmc->caFile)) {
			db_execute_prepared("UPDATE plugin_redfish_discovery SET stale = 'on' WHERE bmc_id = ?", [$id], true, $this->connection);
		}
	}

	/**
	 * Whether the transaction begun by delete() is still open. A savepoint
	 * lives only as long as its transaction, so releasing it succeeds only
	 * while the transaction does, and it is set again for the next check.
	 * This works on MariaDB and MySQL alike; @@in_transaction is MariaDB only.
	 */
	private function inTransaction(): bool {
		if (!db_execute_prepared('RELEASE SAVEPOINT ' . self::DELETE_SAVEPOINT, [], false, $this->connection)) {
			return false;
		}

		return (bool) db_execute_prepared('SAVEPOINT ' . self::DELETE_SAVEPOINT, [], true, $this->connection);
	}

	/**
	 * @param list<string> $fields
	 */
	private function audit(int $id, int $userId, string $action, array $fields): void {
		db_execute_prepared('INSERT INTO plugin_redfish_audit (object, object_id, user_id, changed_at, action, fields) VALUES (?, ?, ?, ?, ?, ?)', ['bmc', $id, $userId, $this->clock->now(), $action, implode(',', $fields)], true, $this->connection);
	}

	/**
	 * @return list<Bmc>
	 */
	private function bmcs(mixed $rows): array {
		$bmcs = [];

		foreach (Row::rows($rows) as $row) {
			$bmc = Bmc::fromRow($row);

			if ($bmc === null) {
				Log::warning(sprintf('BMC row %d has an address that no longer validates and was skipped', Row::int($row, 'id')));

				continue;
			}

			$bmcs[] = $bmc;
		}

		return $bmcs;
	}
}
