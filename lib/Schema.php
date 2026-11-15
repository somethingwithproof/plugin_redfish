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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-002). Every migration is
 * written to be safe to repeat, so one interrupted part way is completed by
 * the next run (REQ-LIFE-001). No migration contacts a BMC.
 */
final class Schema {
	public const VERSION = 2;

	public const HOOKS = [
		'api_device_new'       => 'redfish_api_device_new',
		'config_arrays'        => 'redfish_config_arrays',
		'config_settings'      => 'redfish_config_settings',
		'device_remove'        => 'redfish_device_remove',
		'draw_navigation_text' => 'redfish_draw_navigation_text',
		'graph_buttons'        => 'redfish_graph_buttons',
		'poller_bottom'        => 'redfish_poller_bottom',
	];

	private const PLUGIN  = 'redfish';
	private const SETTING = 'redfish_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [self::SETTING], '', true, $this->connection);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', [self::SETTING, (string) $version], true, $this->connection);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared('UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?', [$version, $author, $homepage, self::PLUGIN], true, $this->connection);
	}

	public function pluginEnabled(): bool {
		return (int) db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory = ?', [self::PLUGIN], '', true, $this->connection) === 1;
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared("SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)", array_merge([self::PLUGIN], array_keys(self::HOOKS)), '', true, $this->connection);
		$realms = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', [self::PLUGIN], '', true, $this->connection);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables on uninstall, and keep the
	 * schema version so a reinstall reuses them.
	 */
	public function untrackTables(): bool {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], true, $this->connection);

		return $ok !== false && (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", [self::PLUGIN], '', true, $this->connection) === 0;
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
			2 => fn (): bool => $this->addMemberBaseline(),
		];
	}

	/**
	 * Version 2: the host_id key on plugin_redfish_bmc, at most one BMC per
	 * Cacti device, and the expected flag on plugin_redfish_member. A member
	 * present today is expected, so an upgrade starts from the members the
	 * BMC reports now. Each step checks first, so a repeated or interrupted
	 * run is safe.
	 */
	private function addMemberBaseline(): bool {
		if (!$this->indexExists('plugin_redfish_bmc', 'host_id') && !db_execute_prepared('ALTER TABLE plugin_redfish_bmc ADD KEY host_id (host_id)', [], true, $this->connection)) {
			return false;
		}

		if (!$this->indexExists('plugin_redfish_bmc', 'host_owner') && !$this->addHostOwner()) {
			return false;
		}

		if (!$this->columnExists('plugin_redfish_member', 'expected')) {
			if (!db_execute_prepared("ALTER TABLE plugin_redfish_member ADD COLUMN expected varchar(3) NOT NULL DEFAULT '' AFTER presence", [], true, $this->connection)) {
				return false;
			}

			if (!db_execute_prepared("UPDATE plugin_redfish_member SET expected = 'on' WHERE presence = 'present'", [], true, $this->connection)) {
				return false;
			}
		}

		return $this->indexExists('plugin_redfish_bmc', 'host_id') && $this->columnExists('plugin_redfish_member', 'expected');
	}

	/**
	 * One BMC per device, held by the database: host_owner is host_id, or
	 * NULL for an unmapped BMC, and NULLs do not collide in a unique key.
	 * Before the key, a device that carries several BMCs keeps the lowest
	 * BMC id; the others are unmapped and logged.
	 */
	private function addHostOwner(): bool {
		foreach (Row::rows(db_fetch_assoc_prepared('SELECT DISTINCT b.id, b.host_id FROM plugin_redfish_bmc AS b INNER JOIN plugin_redfish_bmc AS f ON f.host_id = b.host_id AND f.id < b.id WHERE b.host_id > 0 ORDER BY b.id', [], true, $this->connection)) as $row) {
			if (!db_execute_prepared('UPDATE plugin_redfish_bmc SET host_id = 0 WHERE id = ?', [Row::int($row, 'id')], true, $this->connection)) {
				return false;
			}

			Log::warning(sprintf('BMC %d was unmapped from device %d, which another BMC already carries', Row::int($row, 'id'), Row::int($row, 'host_id')));
		}

		if (!$this->columnExists('plugin_redfish_bmc', 'host_owner') && !db_execute_prepared('ALTER TABLE plugin_redfish_bmc ADD COLUMN host_owner int(10) unsigned AS (NULLIF(host_id, 0)) STORED', [], true, $this->connection)) {
			return false;
		}

		return (bool) db_execute_prepared('ALTER TABLE plugin_redfish_bmc ADD UNIQUE KEY host_owner (host_owner)', [], true, $this->connection);
	}

	private function columnExists(string $table, string $column): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column], '', true, $this->connection) > 0;
	}

	private function indexExists(string $table, string $index): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index], '', true, $this->connection) > 0;
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared("INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')", [self::PLUGIN, $table, self::PLUGIN, $table], true, $this->connection);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], '', true, $this->connection) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions
	 * (REQ-CODE-011). Times are Unix seconds. No table holds a secret in the
	 * clear: passwords are ciphertext in plugin_redfish_secret, and session
	 * tokens are never stored (REQ-CRED-006).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb = ['type' => 'InnoDB', 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic'];
		$id     = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false];
		$count  = static fn (string $name): array => ['name' => $name, 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
		$text   = static fn (string $name, int $length, string $default = ''): array => ['name' => $name, 'type' => "varchar($length)", 'NULL' => false, 'default' => $default];
		$real   = static fn (string $name): array => ['name' => $name, 'type' => 'double', 'NULL' => false, 'default' => '0'];

		return [
			'plugin_redfish_bmc' => $innodb + [
				'comment' => 'Registered BMCs',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('name', 100),
					// A validated host name or IP address, never a URL (REQ-BMC-002).
					$text('address', 253),
					$id('port') + ['default' => '443'],
					$text('protocols', 32, 'redfish'),
					$id('poller_id') + ['default' => '1'],
					$count('host_id'),
					// A reference to plugin_redfish_credential, never a secret.
					$count('credential_id'),
					$text('tls_mode', 16, 'system'),
					$text('ca_file', 512),
					$text('pin_sha256', 64),
					$text('basic_fallback', 3),
					$text('admin_state', 16, 'enabled'),
					$count('created_at'),
					$count('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']], ['name' => 'address_port', 'columns' => ['address', 'port']]],
				// host_id: the script server finds a device's BMC on every poll.
				'keys'        => [['name' => 'poller_id', 'columns' => ['poller_id']], ['name' => 'credential_id', 'columns' => ['credential_id']], ['name' => 'host_id', 'columns' => ['host_id']]],
			],
			'plugin_redfish_credential' => $innodb + [
				'comment' => 'BMC credentials; the password is in plugin_redfish_secret',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('name', 100),
					$text('username', 255),
					// Raised whenever the password or username changes, so a BMC
					// held in auth_failed may try the new credential once.
					$count('revision'),
					$count('created_at'),
					$count('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']]],
			],
			// Vault entries: AES-256-GCM with the credential bound in the additional data.
			'plugin_redfish_secret' => $innodb + [
				'comment' => 'Encrypted BMC passwords',
				'columns' => [
					$id('credential_id'),
					$text('purpose', 32, 'password'),
					['name' => 'nonce', 'type' => 'varbinary(12)', 'NULL' => false, 'default' => ''],
					['name' => 'ciphertext', 'type' => 'blob', 'NULL' => false],
					['name' => 'tag', 'type' => 'varbinary(16)', 'NULL' => false, 'default' => ''],
					$text('key_id', 64),
					$count('updated_at'),
				],
				'primary' => 'credential_id`,`purpose',
			],
			// What discovery found on each BMC and which resource supplies each reading (REQ-DISC-002).
			'plugin_redfish_discovery' => $innodb + [
				'comment' => 'Redfish discovery result per BMC',
				'columns' => [
					$id('bmc_id'),
					$text('fingerprint', 64),
					$text('redfish_version', 32),
					$text('vendor', 64),
					$text('product', 128),
					$text('family', 32),
					$text('features', 128),
					$text('sessions_path', 255),
					$text('generation', 16),
					$count('chassis'),
					$count('targets'),
					['name' => 'plan', 'type' => 'mediumtext', 'NULL' => false],
					$text('status', 16, 'never'),
					$text('stale', 3),
					$count('discovered_at'),
				],
				'primary' => 'bmc_id',
			],
			// Stable member identities: chassis Id, kind, and member Id (REQ-DISC-005).
			'plugin_redfish_member' => $innodb + [
				'comment' => 'Fans, sensors, and supplies found on each BMC',
				'columns' => [
					$id('bmc_id'),
					$text('member_key', 191),
					$text('kind', 16),
					$text('chassis_id', 64),
					$text('name', 255),
					$text('source', 32),
					$text('presence', 16, 'present'),
					// 'on' once seen present, until an administrator accepts
					// the BMC's members again with Add Graphs; an expected
					// member that is not present counts as failed.
					$text('expected', 3),
					$count('first_seen'),
					$count('last_seen'),
				],
				'primary' => 'bmc_id`,`member_key',
			],
			// Latest normalized reading per member and metric. Freshness is
			// derived at read time, so a stale row cannot look current.
			'plugin_redfish_reading' => $innodb + [
				'comment' => 'Latest normalized reading per BMC, member, and metric',
				'columns' => [
					$id('bmc_id'),
					$text('member_key', 191),
					$text('metric', 32),
					['name' => 'value', 'type' => 'double', 'NULL' => true],
					$text('unit', 16),
					$text('source', 32),
					$text('rule', 32),
					$text('state', 32),
					$count('observed_at'),
				],
				'primary' => 'bmc_id`,`member_key`,`metric',
			],
			'plugin_redfish_health' => $innodb + [
				'comment' => 'Collection status per BMC',
				'columns' => [
					$id('bmc_id'),
					$count('run_started'),
					$count('run_finished'),
					$real('run_duration'),
					$text('reachability', 16, 'never'),
					$text('failure_class', 32),
					$count('last_success'),
					$text('generation', 16),
					$text('auth_mode', 16),
					$count('requests'),
					$real('latency_ms'),
					$count('failures'),
					$count('backoff_until'),
					$count('auth_credential_id'),
					$count('auth_revision'),
					// Set before a session POST and cleared after its DELETE, so
					// a session a crash or a lost response left open is swept
					// on the next run.
					$text('session_pending', 3),
					// The path of the last session this plugin created and has
					// not yet deleted. Only this session is ever swept; a
					// session whose login response was lost cannot be named
					// and is left to the BMC's session timeout.
					$text('session_uri', 255),
					$count('sessions_lost'),
					$count('links_refused'),
				],
				'primary' => 'bmc_id',
			],
			'plugin_redfish_lease' => $innodb + [
				'comment' => 'Per-BMC collector leases',
				'columns' => [
					$id('bmc_id'),
					$text('holder', 64),
					$count('acquired_at'),
					$count('expires_at'),
				],
				'primary' => 'bmc_id',
			],
			'plugin_redfish_run' => $innodb + [
				'comment' => 'Last collector run per poller',
				'columns' => [
					$id('poller_id'),
					$count('run_started'),
					$count('run_finished'),
					$real('run_duration'),
					$count('bmcs'),
					$count('collected'),
					$count('skipped'),
					$count('requests'),
					$count('workers'),
					$text('stopped_early', 3),
				],
				'primary' => 'poller_id',
			],
			// Registry changes: who, when, and which fields, never their values.
			'plugin_redfish_audit' => $innodb + [
				'comment' => 'BMC and credential change log',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('object', 16),
					$count('object_id'),
					$count('user_id'),
					$count('changed_at'),
					$text('action', 16),
					$text('fields', 1024),
				],
				'primary' => 'id',
				'keys'    => [['name' => 'object', 'columns' => ['object', 'object_id', 'changed_at']]],
			],
		];
	}
}
