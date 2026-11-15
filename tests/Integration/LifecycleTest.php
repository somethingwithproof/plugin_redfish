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

// The version INFO declares, which Cacti records as the installed version.
define('REDFISH_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const REDFISH_TABLES = [
	'plugin_redfish_audit',
	'plugin_redfish_bmc',
	'plugin_redfish_credential',
	'plugin_redfish_discovery',
	'plugin_redfish_health',
	'plugin_redfish_lease',
	'plugin_redfish_member',
	'plugin_redfish_reading',
	'plugin_redfish_run',
	'plugin_redfish_secret',
];

const REDFISH_HOOKS = ['api_device_new', 'config_arrays', 'config_settings', 'device_remove', 'draw_navigation_text', 'graph_buttons', 'poller_bottom'];

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and both realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(REDFISH_VERSION)
		->and($state['schema'])->toEqual('1')
		->and(array_keys($state['tables']))->toBe(REDFISH_TABLES)
		->and($state['tracked'])->toBe(REDFISH_TABLES)
		->and(array_keys($state['hooks']))->toBe(REDFISH_HOOKS)
		->and($state['realms'])->toEqual([
			'redfish_bmcs.php,redfish_credentials.php' => 'Redfish Admin',
			'redfish_health.php'                       => 'Redfish View',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping data on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables']['plugin_redfish_credential'])->toBe(1);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(REDFISH_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(REDFISH_TABLES);
	}

	expect($first['tables']['plugin_redfish_credential'])->toBe(1);
});

it('re-registers hooks and realms that went missing', function () {
	lifecycle('install');

	$missing = lifecycle('drop_registrations');
	expect($missing['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(REDFISH_HOOKS)
		->and($state['realms'])->toHaveCount(2);
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_redfish_health');
	lifecycle('drop_table', 'plugin_redfish_audit');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(REDFISH_TABLES)
		->and($state['tables']['plugin_redfish_credential'])->toBe(1)
		->and($state['schema'])->toEqual('1')
		->and($state['plugin']['version'])->toEqual(REDFISH_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	$finished = lifecycle('upgrade');
	expect($finished['schema'])->toEqual('1');
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(REDFISH_TABLES)
		->and($kept['tables']['plugin_redfish_credential'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	// A reinstall reuses the kept tables and their rows.
	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_redfish_credential'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(REDFISH_TABLES);

	lifecycle('set_setting', 'redfish_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('stores the password only as ciphertext and audits every change', function () {
	lifecycle('install');

	$r = lifecycle('credential_flow')['result'];

	expect($r['read_back'])->toBeTrue()
		->and($r['leaks'])->toBe([])
		// A changed user name raises the revision, which releases an auth hold.
		->and($r['revision'])->toBe(3)
		->and($r['in_use_refused'])->toBeTrue()
		->and($r['deleted'])->toBeTrue()
		->and($r['unverified_log'])->toBeTrue()
		->and($r['audit'])->toBe(['credential:create', 'credential:secret', 'credential:update', 'bmc:create', 'bmc:update', 'bmc:delete', 'credential:delete']);
});

it('collects from the fake BMC, schedules, backs off, leaves an unnamed lost session alone, and deletes cleanly', function () {
	lifecycle('install');

	$r = lifecycle('collector_flow')['result'];

	expect($r['run1'])->toMatchArray(['bmcs' => 1, 'collected' => 1, 'skipped' => 0, 'stopped_early' => false])
		->and($r['health1'])->toMatchArray(['reachability' => 'reachable', 'failure_class' => '', 'auth_mode' => 'session', 'failures' => '0', 'session_pending' => '', 'generation' => 'subsystem'])
		->and($r['counts1']['discovery'])->toBe(1)
		->and($r['counts1']['reading'])->toBeGreaterThan(10)
		->and($r['power'])->toEqual(['value' => '312', 'unit' => 'W', 'source' => 'redfish.environment'])
		->and($r['inlet'])->toEqual(['value' => '22', 'unit' => 'Cel', 'source' => 'redfish.sensor'])
		->and($r['fan'])->toEqual(['value' => '8040', 'unit' => 'RPM', 'source' => 'redfish.sensor'])
		// Read-only: GET, and only the session POST and DELETE; the session is gone.
		->and($r['sessions1'])->toBe([])
		->and($r['methods'])->toBe(['GET', 'POST', 'DELETE'])
		->and($r['early'])->toMatchArray(['collected' => 0, 'skipped' => 1])
		->and($r['due'])->toMatchArray(['collected' => 1, 'skipped' => 0])
		->and($r['backoff'])->toBe([[1, 300, 'throttled'], [2, 600, 'throttled'], [3, 1200, 'throttled']])
		->and($r['in_backoff'])->toMatchArray(['collected' => 0, 'skipped' => 1])
		->and($r['readings_kept'])->toBeTrue()
		->and($r['lost'])->toMatchArray(['session_pending' => 'on', 'sessions_lost' => '1', 'failure_class' => 'session_lost'])
		->and($r['lost_sessions'])->toBe(1)
		->and($r['swept'])->toMatchArray(['session_pending' => '', 'session_uri' => '', 'sessions_lost' => '0', 'reachability' => 'reachable'])
		// Only sessions the plugin recorded are deleted: the lost one stays
		// for the BMC's timeout.
		->and($r['swept_sessions'])->toBe(1)
		->and($r['nowhere'])->toMatchArray(['reachability' => 'unreachable', 'failure_class' => 'dns_failed'])
		->and($r['page_lists'])->toBeTrue()
		->and($r['leaks'])->toBe([])
		->and($r['deleted'])->toBeTrue()
		->and($r['counts_after'])->toBe(['reading' => 0, 'member' => 0, 'discovery' => 0, 'health' => 0, 'lease' => 0])
		->and($r['host_kept'])->toBe(1);
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_redfish_credential'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'redfish_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(2)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('exits at once when a collector already runs on the poller', function () {
	lifecycle('install');
	lifecycle('enable');

	$r = lifecycle('collector_second')['result'];

	expect($r['rc'])->toBe(0)
		->and($r['seconds'])->toBeLessThan(5.0)
		->and($r['leases'])->toBe(0)
		->and($r['leases_when_free'])->toBe(1)
		->and($r['config_failure'])->toBe('credential_unavailable');
});

it('leaves a consistent cache when a run is killed mid-BMC, and the next run deletes the session it recorded', function () {
	lifecycle('install');

	$r = lifecycle('collector_kill')['result'];

	expect($r['victim_rc'])->not->toBe(0)
		// The dead run still holds its lease, recorded its session, and wrote no reading.
		->and($r['lease'])->toBe('victim')
		->and($r['health']['session_pending'] ?? null)->toBe('')
		->and($r['health']['session_uri'] ?? '')->toStartWith('/redfish/v1/SessionService/Sessions/')
		->and($r['readings'])->toBe(0)
		->and($r['sessions'])->toBe(1)
		->and($r['while_leased'])->toMatchArray(['collected' => 0, 'skipped' => 1])
		->and($r['after_expiry'])->toMatchArray(['collected' => 1, 'skipped' => 0])
		->and($r['final'])->toMatchArray(['reachability' => 'reachable', 'session_pending' => '', 'session_uri' => ''])
		->and($r['final_count'])->toBeTrue()
		->and($r['final_open'])->toBe(0);
});
