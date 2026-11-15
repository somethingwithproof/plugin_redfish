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
 * Credentials, their vault entries, and the audit log of both. The audit
 * names the fields that changed, never their values (REQ-CRED-002).
 */
final class CredentialRepository {
	public const PASSWORD = 'password';

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function find(int $id): ?Credential {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_credential WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Credential::fromRow($row) : null;
	}

	/**
	 * @return list<Credential>
	 */
	public function all(): array {
		$credentials = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_credential ORDER BY name', [], true, $this->connection)) as $row) {
			$credential = Credential::fromRow($row);

			if ($credential !== null) {
				$credentials[] = $credential;
			}
		}

		return $credentials;
	}

	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_credential WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Insert or update the name and user name, returning the id, or 0 when
	 * the write failed. A changed user name raises the revision.
	 */
	public function save(int $id, string $name, string $username, int $userId): int {
		$now    = $this->clock->now();
		$before = $id > 0 ? $this->find($id) : null;

		if ($id > 0 && $before === null) {
			return 0;
		}

		if ($before === null) {
			$ok = db_execute_prepared('INSERT INTO plugin_redfish_credential (name, username, revision, created_at, updated_at) VALUES (?, ?, 1, ?, ?)', [$name, $username, $now, $now], true, $this->connection);
			$id = $ok ? (int) db_fetch_insert_id($this->connection) : 0;

			if ($id > 0) {
				$this->audit($id, $userId, 'create', ['name', 'username']);
			}

			return $id;
		}

		$changed = array_keys(array_filter(['name' => $before->name !== $name, 'username' => $before->username !== $username]));

		if ($changed === []) {
			return $id;
		}

		$ok = db_execute_prepared('UPDATE plugin_redfish_credential SET name = ?, username = ?, revision = revision + ?, updated_at = ? WHERE id = ?', [$name, $username, in_array('username', $changed, true) ? 1 : 0, $now, $id], true, $this->connection);

		if (!$ok) {
			return 0;
		}

		$this->audit($id, $userId, 'update', $changed);

		return $id;
	}

	/**
	 * Store a new vault entry and raise the revision in one transaction.
	 */
	public function storeSecret(int $credentialId, string $purpose, VaultEntry $entry, int $userId): bool {
		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = db_execute_prepared('REPLACE INTO plugin_redfish_secret (credential_id, purpose, nonce, ciphertext, tag, key_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$credentialId, $purpose, $entry->nonce, $entry->ciphertext, $entry->tag, $entry->keyId, $this->clock->now()], true, $this->connection)
			&& db_execute_prepared('UPDATE plugin_redfish_credential SET revision = revision + 1, updated_at = ? WHERE id = ?', [$this->clock->now(), $credentialId], true, $this->connection)
			&& db_affected_rows($this->connection) === 1;

		if (!$ok || !db_commit_transaction($this->connection)) {
			db_rollback_transaction($this->connection);

			return false;
		}

		$this->audit($credentialId, $userId, 'secret', [$purpose]);

		return true;
	}

	public function hasSecret(int $credentialId, string $purpose): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_secret WHERE credential_id = ? AND purpose = ?', [$credentialId, $purpose], '', true, $this->connection) === 1;
	}

	public function loadSecret(int $credentialId, string $purpose): ?VaultEntry {
		$row = db_fetch_row_prepared('SELECT nonce, ciphertext, tag, key_id FROM plugin_redfish_secret WHERE credential_id = ? AND purpose = ?', [$credentialId, $purpose], true, $this->connection);

		return is_array($row) && $row !== [] ? VaultEntry::fromRow($row) : null;
	}

	/**
	 * How many BMCs reference the credential. One in use cannot be deleted,
	 * because a BMC must never silently lose its credential (REQ-CRED-005).
	 */
	public function usage(int $credentialId): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_bmc WHERE credential_id = ?', [$credentialId], '', true, $this->connection);
	}

	public function delete(int $id, int $userId): bool {
		if ($this->find($id) === null || $this->usage($id) > 0 || !db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = db_execute_prepared('DELETE FROM plugin_redfish_secret WHERE credential_id = ?', [$id], true, $this->connection)
			&& db_execute_prepared('DELETE FROM plugin_redfish_credential WHERE id = ? AND NOT EXISTS (SELECT 1 FROM plugin_redfish_bmc WHERE credential_id = ?)', [$id, $id], true, $this->connection)
			&& db_affected_rows($this->connection) === 1;

		if (!$ok || !db_commit_transaction($this->connection)) {
			db_rollback_transaction($this->connection);

			return false;
		}

		$this->audit($id, $userId, 'delete', []);

		return true;
	}

	/**
	 * @param list<string> $fields
	 */
	private function audit(int $id, int $userId, string $action, array $fields): void {
		db_execute_prepared('INSERT INTO plugin_redfish_audit (object, object_id, user_id, changed_at, action, fields) VALUES (?, ?, ?, ?, ?, ?)', ['credential', $id, $userId, $this->clock->now(), $action, implode(',', $fields)], true, $this->connection);
	}
}
