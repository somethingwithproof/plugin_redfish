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
 * Two steps around a real collector run against DMTF's Redfish Mockup Server,
 * run from the Cacti root as the poller user:
 *
 *   php plugins/redfish/tests/bin/mockup-check.php seed   <name> <address> <port> <ca-file>
 *   php plugins/redfish/poller_redfish.php --poller=1 --force
 *   php plugins/redfish/tests/bin/mockup-check.php assert <name> <profile>
 *   php plugins/redfish/tests/bin/mockup-check.php cacti  <name>
 *
 * seed stores a credential through the vault and registers the BMC with the
 * CA bundle trust mode, so the production client (no loopback allowance, TLS
 * verified against the test CA) is the one under test. assert reads what the
 * collector committed. Profiles: subsystem, legacy, hpe-gen11. cacti
 * creates the BMC's Cacti device through the plugin and checks that the
 * data queries index what the collector found and that the summary input
 * serves its power reading.
 * Disposable databases only. Exits non-zero on any failed expectation.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable\n");
	exit(2);
}

redfish_boot();

use Somethingwithproof\Redfish\BmcInput;
use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\SystemClock;

// The mock server does not check credentials; these only have to be present.
const MOCKUP_USER     = 'cacti';
const MOCKUP_PASSWORD = 'mockup-not-a-secret';

// Expectations per profile: readings that must exist with a value, each as
// member_key|metric => source; members that must exist; metrics that must not
// come from the legacy source.
const MOCKUP_PROFILES = array(
	// public-rackmount1 as shipped: every reading from the subsystem model.
	'subsystem' => array(
		'readings' => array(
			'1U/chassis/1U|power_watts'            => 'redfish.environment',
			'1U/chassis/1U|ambient_celsius'        => 'redfish.sensor',
			'1U/temperature/IntakeTemp|temperature_celsius'  => 'redfish.sensor',
			'1U/temperature/ExhaustTemp|temperature_celsius' => 'redfish.sensor',
			'1U/fan/Bay1|fan_speed'                => 'redfish.sensor',
			'1U/fan/Bay2|fan_speed'                => 'redfish.sensor',
			'1U/fan/CPU1|fan_speed'                => 'redfish.sensor',
			'1U/fan/CPU2|fan_speed'                => 'redfish.sensor',
			'1U/psu/Bay1|psu_input_watts'          => 'redfish.sensor',
			'node|power_watts'                     => 'redfish.environment',
			'node|ambient_celsius'                 => 'redfish.sensor',
		),
		'members'  => array('1U/chassis/1U', '1U/fan/Bay1', '1U/fan/CPU2', '1U/psu/Bay1', '1U/psu/Bay2', 'node'),
		'no_legacy' => true,
	),
	// The same mockup with EnvironmentMetrics, ThermalSubsystem, and
	// PowerSubsystem links removed: every reading falls back to Thermal and
	// Power (ADR 0004, ADR 0013).
	'legacy' => array(
		'readings' => array(
			'1U/chassis/1U|power_watts'               => 'redfish.legacy',
			'1U/temperature/CPU1 Temp|temperature_celsius'        => 'redfish.legacy',
			'1U/temperature/Chassis Intake Temp|temperature_celsius' => 'redfish.legacy',
			'1U/fan/BaseBoard System Fan|fan_speed'   => 'redfish.legacy',
			'1U/psu/Power Supply Bay|psu_output_watts' => 'redfish.legacy',
			'node|power_watts'                        => 'redfish.legacy',
		),
		'members'  => array('1U/chassis/1U', '1U/fan/BaseBoard System Fan', '1U/psu/Power Supply Bay', 'node'),
		'no_legacy' => false,
	),
	// HPE iLO Gen11 capture (DL380a): thermal and fans and PSUs from the
	// subsystem model, chassis power from the legacy Power resource, in one
	// run (ADR 0013).
	'hpe-gen11' => array(
		'readings' => array(
			'1/chassis/1|ambient_celsius' => 'redfish.sensor',
			'1/fan/0|fan_speed'           => 'redfish.sensor',
			'1/fan/5|fan_speed'           => 'redfish.sensor',
			'1/chassis/1|power_watts'     => 'redfish.legacy',
			'node|power_watts'            => 'redfish.legacy',
			'node|ambient_celsius'        => 'redfish.sensor',
		),
		'members'  => array('1/chassis/1', '1/fan/0', '1/fan/5', '1/psu/0', '1/psu/3', 'node'),
		'no_legacy' => false,
	),
);

function mockup_key_file(): string {
	// A key file must sit in a directory only its owner can write.
	$dir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

	if (!is_dir($dir)) {
		mkdir($dir, 0700);
	}

	$file = $dir . '/redfish-mockup.key';

	if (!is_file($file)) {
		file_put_contents($file, bin2hex(random_bytes(32)));
		chmod($file, 0600);
	}

	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($file));

	return $file;
}

function mockup_fail(string $message): void {
	print 'FAIL ' . $message . PHP_EOL;
	$GLOBALS['mockup_failures']++;
}

function mockup_pass(string $message): void {
	print 'PASS ' . $message . PHP_EOL;
}

$mockup_failures = 0;
$mockup_action   = $argv[1] ?? '';
$mockup_clock    = new SystemClock();

if ($mockup_action === 'seed' && count($argv) === 6) {
	[, , $name, $address, $port, $ca] = $argv;

	$credentials = new CredentialRepository(false, $mockup_clock);
	$credential  = $credentials->save(0, 'mockup-' . $name, MOCKUP_USER, 1);

	(new CredentialStore($credentials, mockup_key_file()))->storePassword($credential, new Secret(MOCKUP_PASSWORD), 1);

	$input = array('name' => $name, 'address' => $address, 'port' => $port, 'poller_id' => '1', 'host_id' => '0', 'credential_id' => (string) $credential, 'tls_mode' => 'ca_bundle', 'ca_file' => $ca, 'enabled' => 'on');
	$parsed = BmcInput::parse($input, 0);

	if ($parsed->bmc === null) {
		fwrite(STDERR, 'BMC input refused: ' . json_encode($parsed->errors) . PHP_EOL);
		exit(1);
	}

	print 'seeded BMC ' . (new BmcRepository(false, $mockup_clock))->save($parsed->bmc, 1) . PHP_EOL;
	exit(0);
}

if ($mockup_action === 'assert' && count($argv) === 4 && isset(MOCKUP_PROFILES[$argv[3]])) {
	$bmc = (int) db_fetch_cell_prepared('SELECT id FROM plugin_redfish_bmc WHERE name = ?', array($argv[2]));
	$p   = MOCKUP_PROFILES[$argv[3]];

	if ($bmc < 1) {
		fwrite(STDERR, "No BMC named {$argv[2]}\n");
		exit(1);
	}

	$health = db_fetch_row_prepared('SELECT reachability, failure_class, auth_mode, failures FROM plugin_redfish_health WHERE bmc_id = ?', array($bmc));
	print 'health: ' . json_encode($health) . PHP_EOL;

	// A session login against the shimmed server, with no failure class.
	($health['reachability'] ?? '') === 'reachable' && ($health['auth_mode'] ?? '') === 'session' && ($health['failure_class'] ?? '') === ''
		? mockup_pass('collector reached the BMC with a session')
		: mockup_fail('collector run was not a clean session run');

	$rows = array();

	foreach (db_fetch_assoc_prepared('SELECT member_key, metric, value, source FROM plugin_redfish_reading WHERE bmc_id = ?', array($bmc)) as $row) {
		$rows[$row['member_key'] . '|' . $row['metric']] = $row;
	}

	foreach ($p['readings'] as $key => $source) {
		isset($rows[$key]) && $rows[$key]['value'] !== null && $rows[$key]['source'] === $source
			? mockup_pass("$key from $source")
			: mockup_fail("$key expected a value from $source, got " . json_encode($rows[$key] ?? null));
	}

	$members = array_column(db_fetch_assoc_prepared("SELECT member_key FROM plugin_redfish_member WHERE bmc_id = ? AND presence <> 'gone'", array($bmc)), 'member_key');

	foreach ($p['members'] as $member) {
		in_array($member, $members, true) ? mockup_pass("member $member") : mockup_fail("member $member missing");
	}

	if ($p['no_legacy']) {
		$legacy = array_filter($rows, static fn (array $r): bool => $r['source'] === 'redfish.legacy');
		$legacy === array() ? mockup_pass('no reading came from the legacy resources') : mockup_fail('legacy readings present: ' . implode(', ', array_keys($legacy)));
	}

	// The plugin never leaves its session behind (REQ-RF-004): the mock
	// server's collection is empty again, which the CI job checks over HTTP.
	exit($mockup_failures === 0 ? 0 : 1);
}

if ($mockup_action === 'cacti' && count($argv) === 3) {
	$bmc = (new BmcRepository(false, $mockup_clock))->find((int) db_fetch_cell_prepared('SELECT id FROM plugin_redfish_bmc WHERE name = ?', array($argv[2])));

	if ($bmc === null) {
		fwrite(STDERR, "No BMC named {$argv[2]}\n");
		exit(1);
	}

	$host    = redfish_provisioner()->create($bmc, 1);
	$objects = redfish_cacti_objects();

	$host > 0 ? mockup_pass("device $host created") : mockup_fail('no device was created');

	foreach (\Somethingwithproof\Redfish\TemplateInstaller::QUERIES as $query => [$kind]) {
		$want = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_member WHERE bmc_id = ? AND kind = ?', array($bmc->id, $kind));
		$got  = count($objects->cachedIndexes($host, $objects->queryId($query)));
		$got === $want && ($want > 0 || $kind !== 'fan') ? mockup_pass("$query indexes all $want members") : mockup_fail("$query indexes $got of $want members");
	}

	$summary = redfish_summary_script()->answer($host);
	preg_match('/^power_watts:\d/', $summary) === 1 && preg_match('/ age:\d+$/', $summary) === 1 ? mockup_pass('summary: ' . $summary) : mockup_fail('summary: ' . $summary);

	exit($mockup_failures === 0 ? 0 : 1);
}

fwrite(STDERR, "usage: mockup-check.php seed <name> <address> <port> <ca-file> | assert <name> subsystem|legacy|hpe-gen11 | cacti <name>\n");
exit(2);
