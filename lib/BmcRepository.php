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
	private const DELETE_ATTEMPTS = 3;

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

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

	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_bmc WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * The name of another BMC registered at the same address and port, or
	 * null (REQ-BMC-005). Addresses are stored normalized, so equal text is
	 * an equal address.
	 */
	public function addressTakenBy(string $address, int $port, int $exceptId): ?string {
		$row = db_fetch_row_prepared('SELECT name FROM plugin_redfish_bmc WHERE address = ? AND port = ? AND id != ? LIMIT 1', [$address, $port, $exceptId], true, $this->connection);

		if (!is_array($row) || $row === []) {
			return null;
		}

		$name = Row::string($row, 'name');

		return $name === '' ? '?' : $name;
	}

	public function credentialExists(int $credentialId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_credential WHERE id = ?', [$credentialId], '', true, $this->connection) === 1;
	}

	public function hostExists(int $hostId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', [$hostId], '', true, $this->connection) === 1;
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
	 * ids and never a secret (REQ-LIFE-004).
	 *
	 * @return list<array<string, string|int>>
	 */
	public function export(): array {
		$out   = [];
		$names = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, name FROM plugin_redfish_credential', [], true, $this->connection)) as $row) {
			$names[Row::int($row, 'id')] = Row::string($row, 'name');
		}

		foreach ($this->all() as $bmc) {
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
	public function credentialIds(): array {
		$ids = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT id, name FROM plugin_redfish_credential', [], true, $this->connection)) as $row) {
			$ids[Row::string($row, 'name')] = Row::int($row, 'id');
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

	private function write(Bmc $bmc, ?Bmc $before): int {
		$row = $bmc->toRow();
		$now = $this->clock->now();

		if ($before === null) {
			$columns = array_keys($row);
			$ok      = db_execute_prepared('INSERT INTO plugin_redfish_bmc (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')', array_merge(array_values($row), [$now, $now]), true, $this->connection);

			return $ok ? (int) db_fetch_insert_id($this->connection) : 0;
		}

		$set = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($row)));
		$ok  = db_execute_prepared("UPDATE plugin_redfish_bmc SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($row), [$now, $bmc->id]), true, $this->connection);

		return $ok ? $bmc->id : 0;
	}

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

	private function inTransaction(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
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
