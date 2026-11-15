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

// Test code: refuse to run outside the command line.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	exit(1);
}

/*
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/redfish/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('redfish');

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_redfish\\_%'") as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'redfish\\_%'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'redfish'");

		break;
	case 'install':
		api_plugin_install('redfish');

		break;
	case 'enable':
		api_plugin_enable('redfish');

		break;
	case 'disable':
		api_plugin_disable('redfish');

		break;
	case 'uninstall':
		api_plugin_uninstall('redfish');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/redfish/setup.php');
		$before = (int) @filesize(cacti_log_file());
		$result = plugin_redfish_upgrade();
		clearstatcache();
		// What this upgrade added to the log, for tests that expect a line.
		$logged = (string) @file_get_contents(cacti_log_file(), false, null, $before);

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/redfish/setup.php');
		redfish_boot();
		$result = (new \Somethingwithproof\Redfish\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'redfish'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'schema_v1':
		// The milestone 2 schema: no expected column, no host_id key.
		db_execute('ALTER TABLE plugin_redfish_member DROP COLUMN expected');
		db_execute('ALTER TABLE plugin_redfish_bmc DROP KEY host_id');
		db_execute('ALTER TABLE plugin_redfish_bmc DROP KEY host_owner');
		db_execute('ALTER TABLE plugin_redfish_bmc DROP COLUMN host_owner');
		db_execute("INSERT INTO plugin_redfish_member (bmc_id, member_key, kind, presence) VALUES (1, '1/fan/A', 'fan', 'present'), (1, '1/fan/B', 'fan', 'absent')");
		// Two BMCs on one device, which the old schema allowed.
		db_execute("INSERT INTO plugin_redfish_bmc (id, name, address, port, poller_id, host_id, credential_id, created_at, updated_at) VALUES (1, 'old-a', '192.0.2.1', 443, 1, 50, 0, 1, 1), (2, 'old-b', '192.0.2.2', 443, 1, 50, 0, 1, 1), (3, 'old-c', '192.0.2.3', 443, 1, 0, 0, 1, 1), (4, 'old-d', '192.0.2.4', 443, 1, 0, 0, 1, 1)");
		db_execute("REPLACE INTO settings (name, value) VALUES ('redfish_schema_version', '1')");

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'redfish'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'redfish'");

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'seed':
		// A credential row, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_redfish_credential (name, username) VALUES ('Seed', 'monitor')");

		break;
	case 'credential_flow':
		// Create a credential, store its password, register, edit, and
		// delete a BMC through the repositories, read the password back,
		// then search every plugin table and the Cacti log for it
		// (REQ-CRED-002).
		include_once($config['base_path'] . '/plugins/redfish/setup.php');
		redfish_boot();

		$secret  = 'SENTINEL-' . bin2hex(random_bytes(16));
		// A key file must sit in a directory only its owner can write.
		$keyDir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

		if (!is_dir($keyDir)) {
			mkdir($keyDir, 0700);
		}

		$keyFile = $keyDir . '/redfish-test-' . getmypid() . '.key';
		file_put_contents($keyFile, bin2hex(random_bytes(32)));
		chmod($keyFile, 0600);

		$clock       = new \Somethingwithproof\Redfish\SystemClock();
		$credentials = new \Somethingwithproof\Redfish\CredentialRepository(false, $clock);
		$bmcs        = new \Somethingwithproof\Redfish\BmcRepository(false, $clock);
		$store       = new \Somethingwithproof\Redfish\CredentialStore($credentials, $keyFile);

		$cid = $credentials->save(0, 'Flow', 'monitor', 1);
		$store->storePassword($cid, new \Somethingwithproof\Redfish\Secret($secret), 1);
		$credentials->save($cid, 'Flow', 'monitor2', 1);
		$revision = $credentials->find($cid)->revision;

		$input = array('name' => 'flow-bmc', 'address' => '192.0.2.20', 'port' => '443', 'poller_id' => '1', 'host_id' => '0', 'credential_id' => (string) $cid, 'tls_mode' => 'system', 'enabled' => 'on');
		$id    = $bmcs->save(\Somethingwithproof\Redfish\BmcInput::parse($input, 0)->bmc, 1);
		$bmcs->save(\Somethingwithproof\Redfish\BmcInput::parse(array('tls_mode' => 'unverified') + $input, $id)->bmc, 1);

		$readBack = $store->resolve($cid)['password']->reveal() === $secret;
		$inUse    = $credentials->delete($cid, 1);
		$leaks    = array();

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_redfish\\_%'") as $table) {
			foreach (db_fetch_assoc('SELECT * FROM `' . $table['t'] . '`') as $row) {
				if (strpos(implode("\0", array_map('strval', $row)), $secret) !== false) {
					$leaks[] = $table['t'];
				}
			}
		}

		$log = (string) @file_get_contents(cacti_log_file());

		if (strpos($log, $secret) !== false) {
			$leaks[] = 'cacti.log';
		}

		$bmcs->delete($id, 1);
		$deleted = $credentials->delete($cid, 1);
		unlink($keyFile);

		$result = array(
			'read_back'      => $readBack,
			'revision'       => $revision,
			'in_use_refused' => !$inUse,
			'deleted'        => $deleted,
			'leaks'          => $leaks,
			'unverified_log' => strpos($log, 'TLS verification') !== false,
			'audit'          => array_map(static fn (array $r): string => $r['object'] . ':' . $r['action'], db_fetch_assoc('SELECT object, action FROM plugin_redfish_audit ORDER BY id')),
		);

		break;
	case 'collector_flow':
	case 'collector_second':
	case 'collector_victim':
	case 'collector_kill':
		include($config['base_path'] . '/plugins/redfish/tests/bin/collector-flow.php');

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when redfish_boot() refuses: the PHP 8.0
		// fallback, then core drops whatever is still tracked.
		include_once($config['base_path'] . '/plugins/redfish/include/unbooted-uninstall.php');
		redfish_uninstall_unbooted();
		api_plugin_db_changes_remove('redfish');
		api_plugin_remove_hooks('redfish');
		api_plugin_remove_realms('redfish');
		db_execute("DELETE FROM plugin_config WHERE directory = 'redfish'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'redfishviewer'");
		db_execute("INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('redfishviewer', '', 0, 'viewer', 'on')");
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'redfish'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'redfish'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/redfish/setup.php');
		plugin_redfish_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?', array($viewer)));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_redfish\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'redfish'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'redfish_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'redfish' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'redfish' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'redfish' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'redfish' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
	'logged'  => $logged ?? '',
	'v2'      => array(
		'host_owner' => (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_redfish_bmc' AND INDEX_NAME = 'host_owner' AND NON_UNIQUE = 0") > 0,
		'hosts'    => array_map('intval', array_column(db_fetch_assoc('SELECT id, host_id FROM plugin_redfish_bmc ORDER BY id'), 'host_id', 'id')),
		'host_key' => (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_redfish_bmc' AND INDEX_NAME = 'host_id'") > 0,
		'expected' => (int) db_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_redfish_member' AND COLUMN_NAME = 'expected'") > 0
			? array_column(db_fetch_assoc("SELECT member_key, expected FROM plugin_redfish_member ORDER BY member_key"), 'expected', 'member_key') : null,
	),
), JSON_PRETTY_PRINT) . PHP_EOL;
