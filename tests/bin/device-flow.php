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
 * End-to-end device flow on a disposable Cacti with the plugin installed and
 * enabled, around real poller runs (issues #44 to #46 and #49):
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/device-flow.php setup
 *   php poller.php --force; sleep 11; php poller.php --force
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/device-flow.php check
 *   php poller.php --force; sleep 11; php poller.php --force
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/device-flow.php later
 *
 * setup: a second template install changes no row; three BMCs with 4, 8,
 * and 16 fans (the fake BMC serving fixtures written here from the shape
 * of tests/fixtures/legacy.json) are collected; the plugin creates a device
 * for each and a graph per member; a fourth BMC is attached to an existing
 * device; a user whose device permissions would hide a new device gets
 * none. One fan data source gets a 10 second step so the gap check below
 * needs seconds, not a quarter hour; everything else is as shipped.
 *
 * check: the poller wrote the cached values into the RRDs. A firmware
 * change removes a fan and adds another; after a collection and a reindex
 * the removed fan keeps its index, data source, and graph and reads U, and
 * the new fan gets a graph.
 *
 * later: the removed fan's RRD holds unknown, and rrdtool xport shows a gap
 * (NaN), not zero; deleting a device unmaps only its own BMC.
 *
 * Must parse on PHP 8.0. State between phases is kept in Cacti's settings
 * table under redfish_e2e_*. Nothing here contacts a real BMC: bmc.test
 * resolves to the fake BMC on loopback through a test resolver.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable' || !isset($argv[1]) || !in_array($argv[1], array('setup', 'check', 'later'), true)) {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable and pass setup, check, or later\n");
	exit(2);
}

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

// Load the script server functions without running their CLI branch.
$called_by_script_server = true;
require_once($config['base_path'] . '/plugins/redfish/scripts/ss_redfish_members.php');
require_once($config['base_path'] . '/plugins/redfish/scripts/ss_redfish_summary.php');
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
use Somethingwithproof\Redfish\TemplateInstaller as T;

$failures = 0;

function flow_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function flow_state($name, $value = null) {
	if ($value !== null) {
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array('redfish_e2e_' . $name, (string) $value));
	}

	return (string) db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('redfish_e2e_' . $name));
}

function flow_counts() {
	$out = array();

	foreach (array('host_template', 'data_template', 'data_template_rrd', 'graph_templates', 'graph_templates_item', 'graph_template_input', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'data_input', 'data_input_fields', 'graph_templates_gprint') as $table) {
		$out[$table] = (int) db_fetch_cell("SELECT COUNT(*) FROM `$table`");
	}

	return $out;
}

/**
 * A legacy Thermal and Power fixture with the given fans, in the shape of
 * tests/fixtures/legacy.json, written to a file the fake BMC serves.
 *
 * @param list<string> $fans fan member ids
 */
function flow_fixture($name, array $fans) {
	$base    = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/legacy.json'), true);
	$entries = array();

	foreach ($fans as $i => $id) {
		$entries[] = array('MemberId' => $id, 'Name' => 'Fan ' . $id, 'Reading' => 30 + $i, 'ReadingUnits' => 'Percent', 'Status' => array('State' => 'Enabled', 'Health' => 'OK'));
	}

	$base['/redfish/v1/Chassis/1/Thermal']['Fans'] = $entries;
	$file = realpath(sys_get_temp_dir()) . '/redfish-flow-' . $name . '.json';
	file_put_contents($file, json_encode($base, JSON_UNESCAPED_SLASHES));

	return $file;
}

function flow_fans($count) {
	$fans = array();

	for ($i = 1; $i <= $count; $i++) {
		$fans[] = sprintf('Fan%02d', $i);
	}

	return $fans;
}

function flow_key_file() {
	$dir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

	if (!is_dir($dir)) {
		mkdir($dir, 0700);
	}

	$file = $dir . '/redfish-device-flow.key';

	if (!is_file($file)) {
		file_put_contents($file, bin2hex(random_bytes(32)));
		chmod($file, 0600);
	}

	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($file));

	return $file;
}

function flow_collect() {
	$clock    = new SystemClock();
	$resolver = FakeBmc::resolver();

	return (new Collector(
		new BmcRepository(false, $clock),
		new CredentialStore(new CredentialRepository(false, $clock), flow_key_file()),
		new LeaseRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false),
		new ReadingRepository(false),
		new ClientFactory($resolver, 2.0, 5.0, 1048576, true),
		redfish_settings(),
		$clock,
		'device-flow'
	))->run(1, 240, true);
}

function flow_bmc($name, $port, $credential, $host = 0, $id = 0) {
	$input = array('name' => $name, 'address' => 'bmc.test', 'port' => (string) $port, 'poller_id' => '1', 'host_id' => (string) $host, 'credential_id' => (string) $credential, 'tls_mode' => 'ca_bundle', 'ca_file' => FakeBmc::certs() . '/ca.pem', 'enabled' => 'on');
	$bmc   = BmcInput::parse($input, $id)->bmc;

	if ($bmc === null) {
		throw new RuntimeException('BMC input refused: ' . json_encode(BmcInput::parse($input, $id)->errors));
	}

	return redfish_bmcs()->save($bmc, 1);
}

function flow_ds($hostId, $templateName, $index) {
	return (int) db_fetch_cell_prepared('SELECT dl.id FROM data_local AS dl INNER JOIN data_template AS dt ON dt.id = dl.data_template_id WHERE dl.host_id = ? AND dt.hash = ? AND dl.snmp_index = ? ORDER BY dl.id LIMIT 1', array($hostId, T::hash('data_template', $templateName), $index));
}

function flow_rrd($localDataId) {
	$path = get_data_source_path($localDataId, true);
	$out  = shell_exec('rrdtool lastupdate ' . escapeshellarg($path) . ' 2>&1');
	$out  = is_string($out) ? $out : '';
	$rows = preg_split('/\R/', trim($out));

	if (count($rows) < 3) {
		return array('path' => $path, 'error' => $out);
	}

	$names  = preg_split('/\s+/', trim($rows[0]));
	$values = preg_split('/\s+/', trim(substr($rows[2], strpos($rows[2], ':') + 1)));

	return array('path' => $path, 'at' => (int) $rows[2], 'values' => array_combine($names, $values));
}

$installer = redfish_template_installer();
$bmcs      = redfish_bmcs();
$objects   = redfish_cacti_objects();

if ($argv[1] === 'setup') {
	flow_expect($installer->isCurrent() && $installer->failure() === '', 'the plugin install imported the templates and the query files');

	$before = flow_counts();
	flow_expect($installer->install() && flow_counts() === $before, 'a second template install changes no Cacti row');

	foreach (T::QUERIES as $file) {
		flow_expect(is_file($config['base_path'] . '/resource/script_server/' . $file[1]), 'the query file ' . $file[1] . ' is installed in Cacti\'s resource tree');
	}

	// The installer owns only the files it wrote, and never writes through
	// a link. Each case is undone before the next.
	$dir   = $config['base_path'] . '/resource/script_server';
	$owned = json_decode((string) read_config_option(T::FILES_SETTING, true), true);
	flow_expect(is_array($owned) && count($owned) === count(T::QUERIES), 'the installer recorded each query file it wrote: ' . json_encode($owned));

	$first = T::QUERIES[array_key_first(T::QUERIES)][1];
	set_config_option(T::FILES_SETTING, '');
	flow_expect($installer->install() && (array) json_decode((string) read_config_option(T::FILES_SETTING, true), true) === array(), 'identical files it did not write are left unclaimed, so uninstall keeps them');
	foreach (T::QUERIES as $file) {
		unlink("$dir/{$file[1]}");
	}

	flow_expect($installer->install() && count((array) json_decode((string) read_config_option(T::FILES_SETTING, true), true)) === count(T::QUERIES), 'files it writes again are claimed again');

	$decoy = tempnam(sys_get_temp_dir(), 'redfish-decoy-');
	file_put_contents($decoy, 'decoy');
	rename("$dir/$first", "$dir/$first.saved");
	symlink($decoy, "$dir/$first");
	$linked = !$installer->install() && strpos($installer->failure(), 'symbolic link') !== false && file_get_contents($decoy) === 'decoy';
	unlink("$dir/$first");
	rename("$dir/$first.saved", "$dir/$first");
	unlink($decoy);
	flow_expect($linked && $installer->install() && $installer->failure() === '', 'a query file replaced by a link is refused and not written through, and a reinstall recovers');

	$queryGraph = (int) db_fetch_cell("SELECT id FROM snmp_query_graph WHERE name = 'Fan Speed RPM' AND snmp_query_id = " . $objects->queryId('Redfish Fans'));
	$graphId    = (int) db_fetch_cell_prepared('SELECT graph_template_id FROM snmp_query_graph WHERE id = ?', array($queryGraph));
	db_execute_prepared('UPDATE snmp_query_graph SET graph_template_id = 0 WHERE id = ?', array($queryGraph));
	$broken = !$installer->present() && redfish_provisioner()->refusal() !== null;
	db_execute_prepared('UPDATE snmp_query_graph SET graph_template_id = ? WHERE id = ?', array($graphId, $queryGraph));
	flow_expect($queryGraph > 0 && $broken && $installer->present() && redfish_provisioner()->refusal() === null, 'a data query graph that lost its graph template stops provisioning until it is back');

	// The Dell iDRAC and HPE iLO packages Cacti ships, imported beside the
	// plugin's templates: no object of either shares a name with one of
	// the plugin's, and the plugin's objects keep their hashes.
	require_once($config['base_path'] . '/lib/import.php');

	foreach (array('Dell_iDrac.xml.gz', 'HPE_iLO.xml.gz') as $package) {
		ob_start();
		import_package($config['base_path'] . '/install/templates/' . $package, $objects->defaultProfileId(), false, false, false, false, false);
		ob_end_clean();
	}

	$clash = array();

	foreach (array('host_template' => 'name', 'data_template' => 'name', 'graph_templates' => 'name', 'snmp_query' => 'name', 'data_input' => 'name', 'graph_templates_gprint' => 'name') as $table => $column) {
		foreach (db_fetch_assoc("SELECT `$column` AS n, COUNT(*) AS c FROM `$table` WHERE `$column` LIKE '%Redfish%' GROUP BY `$column` HAVING c > 1") as $row) {
			$clash[] = $table . ':' . $row['n'];
		}
	}

	$packages = (int) db_fetch_cell("SELECT COUNT(*) FROM host_template WHERE name LIKE '%iDrac%' OR name LIKE '%iLO%'");
	flow_expect($packages >= 2 && $clash === array() && $installer->present(), 'the Dell iDRAC and HPE iLO packages import beside the plugin templates with no shared name: ' . json_encode(array($packages, $clash)));

	foreach ($bmcs->all() as $old) {
		$bmcs->delete($old->id, 1);
	}

	$credentials = new CredentialRepository(false, new SystemClock());
	$credential  = $credentials->save(0, 'device-flow-' . bin2hex(random_bytes(3)), FakeBmc::USER, 1);
	(new CredentialStore($credentials, flow_key_file()))->storePassword($credential, new Secret(FakeBmc::PASSWORD), 1);

	$fakes = array();
	$ids   = array();

	foreach (array(4, 8, 16) as $count) {
		$fakes[$count] = new FakeBmc(flow_fixture('fans' . $count, flow_fans($count)));
		$ids[$count]   = flow_bmc('r' . $count, $fakes[$count]->port, $credential);
	}

	$run = flow_collect();
	flow_expect($run['collected'] === 3, 'the collector read the three BMCs: ' . json_encode($run));

	$provisioner = redfish_provisioner();
	$hosts       = array();

	foreach ($ids as $count => $id) {
		$bmc  = $bmcs->find($id);
		$plan = $provisioner->plan($bmc, (new \Somethingwithproof\Redfish\NodeCacheRepository(false))->memberCounts($id));
		flow_expect($provisioner->refusal() === null && count($plan) === 8, "the plan for $count fans lists the device, three queries, and four summary graphs");

		$hosts[$count] = $provisioner->create($bmc, 1);
		$host          = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($hosts[$count]));
		$ht            = (int) db_fetch_cell_prepared('SELECT id FROM host_template WHERE hash = ?', array(T::hash('host_template', T::DEVICE_TEMPLATE)));

		flow_expect($hosts[$count] > 0 && is_array($host) && (int) $host['host_template_id'] === $ht && (int) $host['availability_method'] === 0 && $host['hostname'] === 'bmc-' . $id . '.redfish.invalid' && strpos(json_encode($host), 'bmc.test') === false, "the device for $count fans uses the Redfish BMC template, no availability check, and a reserved host name");
		flow_expect($bmcs->find($id)->hostId === $hosts[$count], "the BMC with $count fans is mapped to its device");

		$queryId = $objects->queryId('Redfish Fans');
		$indexes = $objects->cachedIndexes($hosts[$count], $queryId);
		$want    = array_map(function ($f) { return '1/fan/' . $f; }, flow_fans($count));
		sort($want, SORT_STRING);
		flow_expect($indexes === $want, "the fan query indexes all $count fans by stable key");

		$fanGraphs = count($objects->graphedIndexes($hosts[$count], $queryId, $objects->graphTemplateId('Redfish - Fan Speed Percent')));
		$summary   = 0;

		foreach (T::SUMMARY_GRAPHS as $name) {
			$summary += $objects->graphCount($hosts[$count], $objects->graphTemplateId($name));
		}

		flow_expect($fanGraphs === $count && $summary === 4, "a percent graph per fan and the four summary graphs ($fanGraphs fan graphs, $summary summary graphs)");
	}

	$host8 = $hosts[8];
	$temps = $objects->cachedIndexes($host8, $objects->queryId('Redfish Temperatures'));
	$psus  = $objects->cachedIndexes($host8, $objects->queryId('Redfish Power Supplies'));
	flow_expect(count($temps) === 2 && count($psus) === 2, 'the temperature and supply queries index every member: ' . json_encode(array($temps, $psus)));

	$again = $provisioner->attach($ids[8], $host8, 1);
	flow_expect($again === 0, 'attaching again adds no graph');

	// A fan whose RRD gets a short step, for the gap check in "later".
	$removed = flow_ds($host8, 'Redfish - Fan Speed Percent', '1/fan/Fan03');
	db_execute_prepared('UPDATE data_template_data SET rrd_step = 10 WHERE local_data_id = ?', array($removed));
	db_execute_prepared('UPDATE data_template_rrd SET rrd_heartbeat = 30 WHERE local_data_id = ?', array($removed));
	push_out_host($host8, 0);

	// A BMC attached to a server device that already exists.
	$existing = (int) api_device_save(0, 0, 'existing server', 'existing.example.invalid', '', 0, '', '', 161, 500, '', 0, 1, 23, 400, 1, '', '', '', '', '', '', 10, 1, 1, 0);
	$fakes['x'] = new FakeBmc(flow_fixture('existing', flow_fans(2)));
	$xId        = flow_bmc('rx', $fakes['x']->port, $credential, $existing);
	flow_collect();
	$added      = $provisioner->attach($xId, $existing, 1);
	$xState     = array('template' => (int) db_fetch_cell_prepared('SELECT host_template_id FROM host WHERE id = ?', array($existing)), 'hostname' => db_fetch_cell_prepared('SELECT hostname FROM host WHERE id = ?', array($existing)), 'queries' => (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ?', array($existing)));
	flow_expect($added === 2 + 2 + 2 + 4 && $xState === array('template' => 0, 'hostname' => 'existing.example.invalid', 'queries' => 3), 'attaching to an existing device adds queries and graphs and changes nothing else: ' . json_encode(array($added, $xState)));

	// A user whose device permissions would hide the new device gets none.
	db_execute("DELETE FROM user_auth WHERE username = 'rf-hidden'");
	db_execute("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES ('rf-hidden', '', 0, 'rf-hidden', '', '', 2, 1, 2, 2, 'on')");
	$hidden     = (int) db_fetch_insert_id();
	$fakes['h'] = new FakeBmc(flow_fixture('hidden', flow_fans(1)));
	$hId        = flow_bmc('rh', $fakes['h']->port, $credential);
	$devices    = (int) db_fetch_cell('SELECT COUNT(*) FROM host');
	$error      = null;
	$made       = $provisioner->create($bmcs->find($hId), $hidden, $error);
	flow_expect($made === 0 && (int) db_fetch_cell('SELECT COUNT(*) FROM host') === $devices && $bmcs->find($hId)->hostId === 0 && is_string($error) && $error !== '', 'a device the creating user could not see is removed again and the BMC stays unmapped');
	flow_expect(preg_match('/REDFISH WARNING: Device \d+ for BMC ' . $hId . ' was removed again: user ' . $hidden . ' may not see it/m', (string) @file_get_contents($config['base_path'] . '/log/cacti.log')) === 1, 'the removal is logged as a warning');

	$answer = ss_redfish_summary($host8);
	flow_expect(strpos($answer, 'power_watts:250 ') === 0 && strpos($answer, 'fans_bad:0 ') !== false && preg_match('/ age:\d+$/', $answer) === 1, 'the summary input answers current values: ' . $answer);
	flow_expect(ss_redfish_members($host8, 'fan', 'get', 'fan_percent', '1/fan/Fan03') === '32', 'the member query answers a fan from the cache');

	// The fake BMCs stop with this script. Disabled BMCs keep their cache
	// and are served, but the collector the poller starts leaves them be.
	db_execute("UPDATE plugin_redfish_bmc SET admin_state = 'disabled'");

	flow_state('bmc8', $ids[8]);
	flow_state('host8', $host8);
	flow_state('host4', $hosts[4]);
	flow_state('existing', $existing);
	flow_state('bmcx', $xId);
	flow_state('credential', $credential);
	flow_state('removed_ds', $removed);
	flow_state('kept_ds', flow_ds($host8, 'Redfish - Fan Speed Percent', '1/fan/Fan01'));
} elseif ($argv[1] === 'check') {
	$host8   = (int) flow_state('host8');
	$bmc8    = (int) flow_state('bmc8');
	$removed = (int) flow_state('removed_ds');
	$kept    = flow_rrd((int) flow_state('kept_ds'));
	$fan3    = flow_rrd($removed);
	$sum     = array('values' => array());
	$sumRrds = array();

	// Each summary graph has its own data source holding the values it draws.
	foreach (db_fetch_assoc_prepared('SELECT dl.id FROM data_local AS dl INNER JOIN data_template AS dt ON dt.id = dl.data_template_id WHERE dl.host_id = ? AND dt.hash = ? ORDER BY dl.id', array($host8, T::hash('data_template', T::SUMMARY))) as $row) {
		$one       = flow_rrd((int) $row['id']);
		$sum['values'] += isset($one['values']) ? $one['values'] : array();
		$sumRrds[] = $one['path'];
	}

	flow_state('fan3_known_at', isset($fan3['at']) ? $fan3['at'] : 0);
	flow_expect(isset($kept['values'], $fan3['values']) && $kept['values']['fan_percent'] === '30' && $kept['values']['fan_health'] === '0' && $fan3['values']['fan_percent'] === '32', 'the poller wrote the cached fan values into their RRDs: ' . json_encode(array($kept, $fan3)));
	flow_expect(count($sumRrds) === 4 && ($sum['values']['power_watts'] ?? '') === '250' && ($sum['values']['inlet_celsius'] ?? '') === 'U' && ($sum['values']['fans_bad'] ?? '') === '0' && isset($sum['values']['age']) && $sum['values']['age'] !== 'U', 'the poller wrote the summary into its RRD, with the inlet this BMC does not report as unknown: ' . json_encode($sum));

	$info = '';

	foreach ($sumRrds as $path) {
		$info .= (string) shell_exec('rrdtool info ' . escapeshellarg($path));
	}

	flow_expect(strpos($info, 'ds[inlet_celsius].min = -6.0000000000e+01') !== false && strpos($info, 'ds[power_watts].type = "GAUGE"') !== false, 'summary values are gauges and temperatures may be below zero');

	// Firmware change: Fan03 is gone and Fan09 is new. The BMC answers on
	// another port, which the plugin treats as a changed BMC and rediscovers.
	$fans = array_values(array_diff(flow_fans(8), array('Fan03')));
	$fans[] = 'Fan09';
	$fake   = new FakeBmc(flow_fixture('fans8-new', $fans));
	flow_bmc('r8', $fake->port, (int) flow_state('credential'), $host8, $bmc8);
	$run = flow_collect();
	db_execute("UPDATE plugin_redfish_bmc SET admin_state = 'disabled'");
	flow_expect($run['collected'] === 1, 'the collector read the changed BMC');

	// The removed fan was present, so it counts as failed until accepted.
	$before = ss_redfish_summary($host8);
	flow_expect(strpos($before, ' fans_bad:1 ') !== false, 'a fan that was present and is gone counts as failed: ' . $before);

	$query   = $objects->queryId('Redfish Fans');
	$graphs  = redfish_provisioner()->attach($bmc8, $host8, 1);
	$after   = redfish_summary_script()->answer($host8);
	flow_expect(strpos($after, ' fans_bad:0 ') !== false, 'Add Graphs accepts the members, and the removed fan stops counting: ' . $after);
	$indexes = $objects->cachedIndexes($host8, $query);
	flow_expect($graphs === 1 && in_array('1/fan/Fan03', $indexes, true) && in_array('1/fan/Fan09', $indexes, true) && count($indexes) === 9, 'a reindex keeps the removed fan, adds the new one, and graphs only the new one: ' . json_encode(array($graphs, $indexes)));
	flow_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM data_local WHERE id = ?', array($removed)) === 1, 'the removed fan keeps its data source and graph');

	$fresh = redfish_member_query();
	flow_expect($fresh->answer($host8, 'fan', 'get', 'fan_percent', '1/fan/Fan03') === 'U' && $fresh->answer($host8, 'fan', 'get', 'fan_percent', '1/fan/Fan09') === '37', 'the removed fan reads U and the new one is served');
	flow_expect($fresh->answer($host8, 'fan', 'get', 'fan_percent', '../../etc/passwd') === 'U' && $fresh->answer($host8, 'fan', 'get', 'password', '1/fan/Fan01') === 'U' && $fresh->answer($host8, 'psu', 'get', 'fan_percent', '1/fan/Fan01') === 'U', 'hostile indexes and fields are served as U');
	flow_expect($fresh->answer($host8 + 999, 'fan', 'index') === '', 'a device without a BMC lists no members');
} else {
	$host8   = (int) flow_state('host8');
	$removed = (int) flow_state('removed_ds');
	$fan3    = flow_rrd($removed);
	flow_expect(isset($fan3['values']) && $fan3['values']['fan_percent'] === 'U', 'the poller wrote unknown for the removed fan: ' . json_encode($fan3));

	// From the last known value the check phase saw to the last update, at
	// the fan RRD's 10 second step: known values while the fan was there,
	// then a gap once it was gone. A gap is NaN in xport, never 0. rrdtool
	// averages a step that is only partly unknown, so the caller polls three
	// times after the change to leave one whole step unknown.
	$end   = isset($fan3['at']) ? $fan3['at'] - $fan3['at'] % 10 : time();
	$start = (int) flow_state('fan3_known_at') - 30;
	$xport = (string) shell_exec('rrdtool xport --json --start ' . $start . ' --end ' . $end . ' --step 10 DEF:f=' . escapeshellarg($fan3['path']) . ':fan_percent:AVERAGE XPORT:f 2>&1');
	$json  = json_decode(preg_replace('/\b(NaN|nan)\b/', 'null', $xport), true);
	$rows  = is_array($json) && isset($json['data']) ? array_map(function ($r) { return $r[0]; }, $json['data']) : array();
	$known = array_values(array_filter($rows, function ($v) { return $v !== null; }));
	$last  = array_slice($rows, -2);
	$fetch = $known !== array() && in_array(null, $last, true) ? '' : ' ' . (string) shell_exec('rrdtool fetch ' . escapeshellarg($fan3['path']) . ' AVERAGE --start ' . $start . ' --end ' . ($end + 20) . ' 2>&1');
	flow_expect($known !== array() && !in_array(0, $known, true) && !in_array(0.0, $known, true) && in_array(null, $last, true), 'rrdtool xport shows the removed fan as a gap after its known values, never as zero: ' . json_encode($rows) . $fetch);

	// Deleting a device unmaps only its own BMC.
	api_device_remove((int) flow_state('existing'));
	flow_expect($bmcs->find((int) flow_state('bmcx'))->hostId === 0 && $bmcs->find((int) flow_state('bmc8'))->hostId === $host8, 'deleting a device unmaps only its own BMC');

	$bmcs->delete((int) flow_state('bmc8'), 1);
	flow_expect((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($host8)) === 1 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($host8)) === 8 + 1 + 2 + 2 + 4, 'deleting a BMC keeps its device and graphs');

	db_execute("DELETE FROM settings WHERE name LIKE 'redfish\\_e2e\\_%'");
}

exit($failures > 0 ? 1 : 0);
