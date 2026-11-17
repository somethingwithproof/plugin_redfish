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
 * Thold alerting and Maint suppression on the Redfish Node Summary data
 * source, end to end, with Thold and Maint installed unchanged at
 * plugins/thold and plugins/maint (issues #47 and #48, REQ-ALERT-010 to
 * REQ-ALERT-013).
 *
 * tests/bin/alert-replay.sh drives it: for each step in turn it runs
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/alert-flow.php <step>
 *   php poller.php --force
 *   php plugins/thold/poller_thold.php
 *
 * Each step first checks what Thold made of the poll before it, then has
 * the collector read the fake BMC once, serving a fixture written here from
 * the shape of tests/fixtures/dell-subsystem.json. The thresholds are saved
 * through Thold's own save_thold(), as its edit page does; the maintenance
 * schedule is the two rows Maint's page saves.
 *
 * A BMC that stops answering is played by moving its cache an hour into
 * the past: exactly what an hour without a successful read leaves behind.
 *
 * Every check prints PASS or FAIL; EVIDENCE lines show the Thold state and
 * log rows each check relied on. Must parse on PHP 8.0. State between steps
 * is kept in Cacti's settings table under redfish_alert_*.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

const ALERT_STEPS = array('setup', 'warmup', 'baseline', 'hot', 'held', 'cool', 'inlet', 'inlet_ok', 'fan_failed', 'fan_ok', 'fan_pulled', 'fan_accepted', 'psu_failed', 'psu_ok', 'silent', 'answering', 'maint', 'maint2', 'unmaint', 'final');

// Thold's log statuses (plugins/thold/includes/settings.php), defined there
// only while Thold's config hook runs.
const ALERT_NOTIFY  = 4;
const ALERT_RESTORE = 5;

/** The thresholds: summary value => high threshold. */
const ALERT_THOLDS = array(
	'power_watts'   => '400',
	'inlet_celsius' => '30',
	'fans_bad'      => '0.5',
	'psus_bad'      => '0.5',
	'age'           => '600',
);

if (isset($argv[1]) && $argv[1] === 'list') {
	print implode(' ', ALERT_STEPS) . PHP_EOL;
	exit(0);
}

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable' || !isset($argv[1]) || !in_array($argv[1], ALERT_STEPS, true)) {
	fwrite(STDERR, 'Set REDFISH_TEST_DATABASE=disposable and pass one of: ' . implode(' ', ALERT_STEPS) . PHP_EOL);
	exit(2);
}

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

require_once($config['base_path'] . '/plugins/thold/thold_functions.php');
require_once($config['base_path'] . '/plugins/thold/includes/arrays.php');
require_once($config['base_path'] . '/plugins/redfish/tests/Support/FakeBmc.php');

use Somethingwithproof\Redfish\BmcInput;
use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\ClientFactory;
use Somethingwithproof\Redfish\Collector;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\DiscoveryRepository;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\LeaseRepository;
use Somethingwithproof\Redfish\ReadingRepository;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\SystemClock;

$failures = 0;

function alert_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function alert_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('redfish_alert_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('redfish_alert_' . $name));
}

function alert_key_file() {
	$dir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

	if (!is_dir($dir)) {
		mkdir($dir, 0700);
	}

	$file = $dir . '/redfish-alert-flow.key';

	if (!is_file($file)) {
		file_put_contents($file, bin2hex(random_bytes(32)));
		chmod($file, 0600);
	}

	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($file));

	return $file;
}

/**
 * The Dell fixture with the given node power and inlet and the health of
 * one fan and the second supply, or that fan pulled; every other member
 * is healthy.
 */
function alert_fixture($power, $inlet, $fanHealth = 'OK', $psuHealth = 'OK', $fanPulled = false) {
	$f      = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/dell-subsystem.json'), true);
	$c      = '/redfish/v1/Chassis/System.Embedded.1';
	$f[$c . '/EnvironmentMetrics']['PowerWatts']['Reading']                                            = $power;
	$f[$c . '/ThermalSubsystem/ThermalMetrics']['TemperatureSummaryCelsius']['Intake']['Reading']     = $inlet;
	$f[$c . '/ThermalSubsystem/Fans/Fan.Embedded.1A']['Status']['Health']                              = $fanHealth;
	// The capture has a second fan in Warning; the replay starts healthy.
	$f[$c . '/ThermalSubsystem/Fans/Fan.Embedded.3A']['Status']['Health']                              = 'OK';
	$f[$c . '/PowerSubsystem/PowerSupplies/PSU.Slot.2']['Status']['Health']                            = $psuHealth;

	// A pulled fan: the BMC still lists the slot, as Absent with no health.
	if ($fanPulled) {
		$f[$c . '/ThermalSubsystem/Fans/Fan.Embedded.1A']['Status'] = array('State' => 'Absent');
	}

	foreach ($f as $path => $resource) {
		if (is_array($resource) && isset($resource['Id']) && $resource['Id'] === 'SystemBoardInletTemp') {
			$f[$path]['Reading'] = $inlet;
		}
	}

	$file = realpath(sys_get_temp_dir()) . '/redfish-alert-fixture.json';
	file_put_contents($file, json_encode($f, JSON_UNESCAPED_SLASHES));

	return $file;
}

/**
 * One collection from a fake BMC serving the fixture. The BMC is enabled
 * only for it, so the collector the poller starts never finds a BMC whose
 * fake server has gone with this step.
 */
function alert_collect($power, $inlet, $fanHealth = 'OK', $psuHealth = 'OK', $fanPulled = false) {
	$fake  = new FakeBmc(alert_fixture($power, $inlet, $fanHealth, $psuHealth, $fanPulled));
	$clock = new SystemClock();
	$id    = (int) alert_state('bmc');
	$input = array('name' => 'alert-r660', 'address' => 'bmc.test', 'port' => (string) $fake->port, 'poller_id' => '1', 'host_id' => (string) (int) alert_state('host'), 'credential_id' => alert_state('credential'), 'tls_mode' => 'ca_bundle', 'ca_file' => FakeBmc::certs() . '/ca.pem', 'enabled' => 'on');
	$bmc   = BmcInput::parse($input, $id)->bmc;
	$id    = redfish_bmcs()->save($bmc, 1);
	alert_state('bmc', $id);

	$run = (new Collector(
		new BmcRepository(false, $clock),
		new CredentialStore(new CredentialRepository(false, $clock), alert_key_file()),
		new LeaseRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false),
		new ReadingRepository(false),
		new ClientFactory(FakeBmc::resolver(), 2.0, 5.0, 1048576, true),
		redfish_settings(),
		$clock,
		'alert-flow'
	))->run(1, 240, true);

	db_execute_prepared("UPDATE plugin_redfish_bmc SET admin_state = 'disabled' WHERE id = ?", array($id));
	alert_expect($run['collected'] === 1, "the collector read the fake BMC (power $power W, inlet $inlet C, fan " . ($fanPulled ? 'pulled' : $fanHealth) . ", supply $psuHealth)");
}

function alert_thold($name) {
	return db_fetch_row_prepared('SELECT id, thold_alert, thold_fail_count, lastread, oldvalue FROM thold_data WHERE id = ?', array((int) alert_state('thold_' . $name)));
}

function alert_since($name) {
	return array_map('intval', array_column(db_fetch_assoc_prepared('SELECT status FROM plugin_thold_log WHERE threshold_id = ? AND id > ? ORDER BY id', array((int) alert_state('thold_' . $name), (int) alert_state('log'))), 'status'));
}

function alert_evidence($step) {
	foreach (array_keys(ALERT_THOLDS) as $name) {
		$t = alert_thold($name);

		if (is_array($t) && isset($t['id'])) {
			print "EVIDENCE $step $name alert=" . $t['thold_alert'] . ' fail_count=' . $t['thold_fail_count'] . " lastread='" . $t['lastread'] . "'" . PHP_EOL;
		}
	}

	foreach (db_fetch_assoc_prepared('SELECT l.id, l.status, l.current, t.data_source_name FROM plugin_thold_log AS l INNER JOIN thold_data AS t ON t.id = l.threshold_id WHERE l.id > ? ORDER BY l.id', array((int) alert_state('log'))) as $row) {
		print "EVIDENCE $step log id=" . $row['id'] . ' ' . $row['data_source_name'] . ' status=' . $row['status'] . " current='" . $row['current'] . "'" . PHP_EOL;
	}
}

/**
 * $alert is whether the threshold is breached now, $logged the Thold log
 * statuses the last poll added for it.
 */
function alert_check($step, $name, $alert, array $logged) {
	$t   = alert_thold($name);
	$got = alert_since($name);

	alert_expect(((int) $t['thold_alert'] !== 0) === $alert && $got === $logged, "$step: $name " . ($alert ? 'breached' : 'normal') . ' with log ' . json_encode($logged) . ' (alert=' . $t['thold_alert'] . ', fail_count=' . $t['thold_fail_count'] . ', log ' . json_encode($got) . ')');
}

function alert_quiet($step, array $names) {
	foreach ($names as $name) {
		alert_check($step, $name, false, array());
	}
}

/** One high threshold, saved the way Thold's edit page saves it. */
function alert_create_thold($hostId, $name, $hi) {
	$row = db_fetch_row_prepared('SELECT dtr.id AS dtr, dtr.local_data_id, gti.local_graph_id
		FROM data_template_rrd AS dtr
		INNER JOIN data_local AS dl ON dl.id = dtr.local_data_id
		INNER JOIN graph_templates_item AS gti ON gti.task_item_id = dtr.id
		WHERE dl.host_id = ? AND dtr.data_source_name = ?
		ORDER BY gti.local_graph_id LIMIT 1', array($hostId, $name));

	if (!is_array($row) || !isset($row['dtr'])) {
		return 0;
	}

	$_REQUEST = array();
	$fields   = array(
		'host_id' => $hostId, 'local_data_id' => $row['local_data_id'], 'local_graph_id' => $row['local_graph_id'],
		'data_template_rrd_id' => $row['dtr'], 'thold_type' => '0', 'thold_hi' => $hi, 'thold_low' => '',
		'thold_fail_trigger' => '1', 'thold_warning_hi' => '', 'thold_warning_low' => '',
		'thold_warning_fail_trigger' => '', 'repeat_alert' => '0', 'data_type' => '0', 'cdef' => '0',
		'thold_per_enabled' => 'on', 'name' => 'redfish ' . $name, 'notify_alert' => '0', 'notify_warning' => '0',
		'bl_type' => '0', 'syslog_facility' => '0', 'syslog_priority' => '0', 'time_fail_trigger' => '',
		'time_fail_length' => '', 'time_warning_fail_trigger' => '', 'time_warning_fail_length' => '',
		'bl_ref_time_range' => '', 'bl_fail_trigger' => '', 'decimals' => '2',
	);

	foreach ($fields as $field => $value) {
		set_request_var($field, $value);
	}

	$id = save_thold();

	return is_numeric($id) ? (int) $id : 0;
}

/**
 * @return array{0: int, 1: string} last update time and the named value
 */
function alert_rrd($name) {
	$path = get_data_source_path((int) db_fetch_cell_prepared('SELECT local_data_id FROM thold_data WHERE id = ?', array((int) alert_state('thold_' . $name))), true);
	$out  = (string) shell_exec('rrdtool lastupdate ' . escapeshellarg($path) . ' 2>&1');
	$rows = preg_split('/\R/', trim($out));

	if (count($rows) < 3) {
		return array(0, 'none');
	}

	$map = array_combine(preg_split('/\s+/', trim($rows[0])), preg_split('/\s+/', trim(substr($rows[2], strpos($rows[2], ':') + 1))));

	return array((int) $rows[2], isset($map[$name]) ? $map[$name] : 'none');
}

$step = $argv[1];

if ($step !== 'setup') {
	alert_evidence($step);
}

switch ($step) {
	case 'setup':
		alert_expect(api_plugin_is_enabled('thold') && api_plugin_is_enabled('maint'), 'Thold and Maint are installed and enabled');

		foreach (redfish_bmcs()->all() as $old) {
			redfish_bmcs()->delete($old->id, 1);
		}

		$credentials = new CredentialRepository(false, new SystemClock());
		$credential  = $credentials->save(0, 'alert-' . bin2hex(random_bytes(3)), FakeBmc::USER, 1);
		(new CredentialStore($credentials, alert_key_file()))->storePassword($credential, new Secret(FakeBmc::PASSWORD), 1);
		alert_state('credential', $credential);
		// A replay that stopped after creating its device must start over
		// with no device, or the new BMC would claim the old one.
		alert_state('bmc', 0);
		alert_state('host', 0);
		alert_state('log', 0);
		alert_collect(312, 22);

		$hostId = redfish_provisioner()->create(redfish_bmcs()->find((int) alert_state('bmc')), 1);
		alert_expect($hostId > 0, 'the BMC device was created');
		alert_state('host', $hostId);

		foreach (ALERT_THOLDS as $name => $hi) {
			$id = alert_create_thold($hostId, $name, $hi);
			alert_expect($id > 0, "Thold saved a high threshold on $name above $hi");
			alert_state('thold_' . $name, $id);
		}

		alert_collect(312, 22);

		break;
	case 'warmup':
		alert_expect((int) db_fetch_cell_prepared('SELECT status FROM host WHERE id = ?', array((int) alert_state('host'))) === 3, 'the BMC device is up, so Thold checks its thresholds');
		alert_expect(is_numeric(alert_thold('power_watts')['lastread']) && is_numeric(alert_thold('age')['lastread']), 'Thold read the summary values from the poll');
		alert_collect(312, 22);

		break;
	case 'baseline':
		alert_quiet($step, array_keys(ALERT_THOLDS));
		alert_collect(512, 22);

		break;
	case 'hot':
		alert_check($step, 'power_watts', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('inlet_celsius', 'fans_bad', 'psus_bad', 'age'));
		alert_state('hot_at', alert_rrd('power_watts')[0]);

		// No collection before the next poll: Cacti polls the cache on its
		// own interval, so the same reading is reported again.
		break;
	case 'held':
		alert_check($step, 'power_watts', true, array());
		[$at, $power] = alert_rrd('power_watts');
		alert_expect($power === '512' && $at > (int) alert_state('hot_at'), "held: with no new read the poll reports the same reading again at a later time ($power W at $at), and Thold does not notify twice (REQ-ALERT-013)");
		alert_collect(312, 22);

		break;
	case 'cool':
		alert_check($step, 'power_watts', false, array(ALERT_RESTORE));
		alert_collect(312, 35);

		break;
	case 'inlet':
		alert_check($step, 'inlet_celsius', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('power_watts', 'fans_bad', 'psus_bad', 'age'));
		alert_collect(312, 22);

		break;
	case 'inlet_ok':
		alert_check($step, 'inlet_celsius', false, array(ALERT_RESTORE));
		alert_collect(312, 22, 'Critical');

		break;
	case 'fan_failed':
		alert_check($step, 'fans_bad', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('power_watts', 'inlet_celsius', 'psus_bad', 'age'));
		alert_collect(312, 22);

		break;
	case 'fan_ok':
		alert_check($step, 'fans_bad', false, array(ALERT_RESTORE));
		alert_collect(312, 22, 'OK', 'OK', true);

		break;
	case 'fan_pulled':
		// A fan that was present and is now reported Absent has failed.
		alert_check($step, 'fans_bad', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('power_watts', 'inlet_celsius', 'psus_bad', 'age'));

		// Add Graphs accepts the BMC's members as they are now.
		alert_expect(redfish_bmcs()->acceptMembers((int) alert_state('bmc'), 1), 'fan_pulled: an administrator accepts the members without the pulled fan');
		alert_collect(312, 22, 'OK', 'OK', true);

		break;
	case 'fan_accepted':
		alert_check($step, 'fans_bad', false, array(ALERT_RESTORE));
		alert_collect(312, 22, 'OK', 'Critical');

		break;
	case 'psu_failed':
		alert_check($step, 'psus_bad', true, array(ALERT_NOTIFY));
		alert_quiet($step, array('power_watts', 'inlet_celsius', 'fans_bad', 'age'));
		alert_collect(312, 22);

		break;
	case 'psu_ok':
		alert_check($step, 'psus_bad', false, array(ALERT_RESTORE));
		alert_state('age_before', alert_thold('age')['lastread']);

		// The BMC stops answering for an hour.
		$bmc = (int) alert_state('bmc');
		db_execute_prepared('UPDATE plugin_redfish_reading SET observed_at = observed_at - 3600 WHERE bmc_id = ?', array($bmc));
		db_execute_prepared('UPDATE plugin_redfish_health SET last_success = last_success - 3600 WHERE bmc_id = ?', array($bmc));

		break;
	case 'silent':
		alert_check($step, 'age', true, array(ALERT_NOTIFY));
		$age = alert_thold('age')['lastread'];
		alert_expect(is_numeric($age) && (float) $age >= (float) alert_state('age_before') + 3600, "silent: age keeps growing while the BMC is silent ($age s) (REQ-ALERT-011)");
		alert_expect(alert_rrd('power_watts')[1] === 'U' && alert_rrd('fans_bad')[1] === 'U', 'silent: the stale values are polled as U, never as their last value or 0');

		// Thold 1.8.2 fails with a TypeError on a thresholded COUNTER or
		// DERIVE source that reads U, and Cacti then disables Thold. Every
		// value thresholded here is a GAUGE, and the U poll left Thold
		// enabled with nothing logged.
		$types = array();

		foreach (array_keys(ALERT_THOLDS) as $name) {
			$types[$name] = (int) db_fetch_cell_prepared('SELECT dtr.data_source_type_id FROM thold_data AS td INNER JOIN data_template_rrd AS dtr ON dtr.id = td.data_template_rrd_id WHERE td.id = ?', array((int) alert_state('thold_' . $name)));
		}

		$log = (string) @file_get_contents($config['base_path'] . '/log/cacti.log');
		alert_expect(array_unique(array_values($types)) === array(1), 'silent: every thresholded value is a GAUGE: ' . json_encode($types));
		alert_expect(api_plugin_is_enabled('thold') && preg_match('/TypeError|Uncaught|PHP Fatal|Disabling plugin/i', $log) !== 1, 'silent: thresholded values reading U raise no PHP error and leave Thold enabled');
		alert_collect(312, 22);

		break;
	case 'answering':
		alert_check($step, 'age', false, array(ALERT_RESTORE));

		// A one-off Maint schedule for the device, from a minute ago to an
		// hour from now, as Maint's schedule page saves it.
		db_execute_prepared("INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval) VALUES ('on', 'redfish replay', 1, ?, ?, 0)", array(time() - 60, time() + 3600));
		$schedule = (int) db_fetch_insert_id();
		db_execute_prepared('INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)', array((int) alert_state('host'), $schedule));
		alert_state('schedule', $schedule);
		include_once($config['base_path'] . '/plugins/maint/functions.php');
		alert_expect(plugin_maint_check_cacti_host((int) alert_state('host')), 'Maint reports the BMC device in maintenance');
		alert_collect(512, 22);

		break;
	case 'maint':
	case 'maint2':
		alert_check($step, 'power_watts', true, array());

		$t = alert_thold('power_watts');
		[$at, $power] = alert_rrd('power_watts');
		alert_expect((int) $t['thold_fail_count'] === 0 && $power === '512' && $at >= (int) alert_state('maint_at'), "$step: in maintenance the breach is not counted or notified while collection and the RRD continue (power_watts=$power at $at)");
		alert_state('maint_at', $at + 1);

		if ($step === 'maint2') {
			db_execute_prepared('DELETE FROM plugin_maint_hosts WHERE schedule = ?', array((int) alert_state('schedule')));
			db_execute_prepared('DELETE FROM plugin_maint_schedules WHERE id = ?', array((int) alert_state('schedule')));
		}

		alert_collect(512, 22);

		break;
	case 'unmaint':
		alert_check($step, 'power_watts', true, array(ALERT_NOTIFY));
		alert_collect(312, 22);

		break;
	case 'final':
		alert_check($step, 'power_watts', false, array(ALERT_RESTORE));
		alert_quiet($step, array('inlet_celsius', 'fans_bad', 'psus_bad', 'age'));
		alert_expect(api_plugin_is_enabled('thold') && api_plugin_is_enabled('maint'), 'final: Thold and Maint are still enabled');

		foreach (db_fetch_assoc('SELECT l.id, l.status, l.current, t.data_source_name FROM plugin_thold_log AS l INNER JOIN thold_data AS t ON t.id = l.threshold_id ORDER BY l.id') as $row) {
			print 'EVIDENCE log ' . $row['id'] . ' ' . $row['data_source_name'] . ' status=' . $row['status'] . " current='" . $row['current'] . "'" . PHP_EOL;
		}

		foreach (db_fetch_assoc('SELECT id FROM thold_data') as $row) {
			thold_api_thold_remove((int) $row['id']);
		}

		foreach (redfish_bmcs()->all() as $old) {
			redfish_bmcs()->delete($old->id, 1);
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'redfish\\_alert\\_%'");

		break;
}

if ($step !== 'final') {
	alert_state('log', (int) db_fetch_cell('SELECT COALESCE(MAX(id), 0) FROM plugin_thold_log'));
}

exit($failures > 0 ? 1 : 0);
