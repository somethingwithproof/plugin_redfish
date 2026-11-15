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
 * Scale harness (issue #51): N BMCs served by the fake BMC, each mapped to
 * a device id, then the collector and the script server functions timed.
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/scale-flow.php <N> [latency-ms]
 *
 * Prints METRIC lines: the collector's wall time and peak memory for the
 * first run (discovery and readings) and a second run (readings only), its
 * requests per BMC, and the mean and 99th percentile time per call of the
 * summary input and of a member query get, as the script server makes them
 * (one process, many calls). latency-ms delays every fake BMC answer.
 *
 * The collector runs in this process as one worker, reaching the fake BMC
 * on loopback through a test resolver; poller_redfish.php divides the BMCs
 * among its workers. Results are for the machine the run is on, with one
 * fake BMC serving one connection at a time; nothing here is a capacity
 * claim. Must parse on PHP 8.0. Disposable databases only; the BMCs are
 * removed at the end.
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

$n       = isset($argv[1]) ? (int) $argv[1] : 0;
$latency = isset($argv[2]) ? (int) $argv[2] : 0;

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable' || $n < 1 || $n > 5000 || $latency < 0 || $latency > 1000) {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable and pass a BMC count of 1 to 5000 and an optional latency of 0 to 1000 ms\n");
	exit(2);
}

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

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
use Somethingwithproof\Redfish\Resolver;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\SystemClock;

/** Device ids the BMCs are mapped to; no Cacti device is needed to time the script server. */
const SCALE_HOST_BASE = 900000;

function scale_metric($label, $value) {
	print "METRIC $label $value" . PHP_EOL;
}

/**
 * @param list<float> $ms
 */
function scale_spread($label, array $ms) {
	sort($ms);
	scale_metric($label . '_mean_ms', sprintf('%.3f', array_sum($ms) / max(1, count($ms))));
	scale_metric($label . '_p99_ms', sprintf('%.3f', $ms[(int) floor(0.99 * (count($ms) - 1))]));
}

function scale_key_file() {
	$dir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

	if (!is_dir($dir)) {
		mkdir($dir, 0700);
	}

	$file = $dir . '/redfish-scale.key';

	if (!is_file($file)) {
		file_put_contents($file, bin2hex(random_bytes(32)));
		chmod($file, 0600);
	}

	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($file));

	return $file;
}

/** bmc-<n>.scale.test resolves to the fake BMC on loopback; nothing else resolves. */
final class ScaleResolver implements Resolver {
	public function resolve(string $host): array {
		return preg_match('/^bmc-\d{4}\.scale\.test$/', $host) === 1 ? array('127.0.0.1') : array();
	}
}

function scale_collect($budget) {
	$clock = new SystemClock();

	return (new Collector(
		new BmcRepository(false, $clock),
		new CredentialStore(new CredentialRepository(false, $clock), scale_key_file()),
		new LeaseRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false),
		new ReadingRepository(false),
		new ClientFactory(new ScaleResolver(), 5.0, 20.0, 1048576, true),
		redfish_settings(),
		$clock,
		'scale'
	))->run(1, $budget, true);
}

$bmcs = redfish_bmcs();

foreach ($bmcs->all() as $old) {
	$bmcs->delete($old->id, 1);
}

$fake        = new FakeBmc('dell-subsystem', 'good', array('flags' => array('quiet' => true, 'delay_ms' => $latency)), false, 7200);
$credentials = new CredentialRepository(false, new SystemClock());
$credential  = $credentials->save(0, 'scale-' . bin2hex(random_bytes(3)), FakeBmc::USER, 1);
(new CredentialStore($credentials, scale_key_file()))->storePassword($credential, new Secret(FakeBmc::PASSWORD), 1);

$list = array();

for ($i = 1; $i <= $n; $i++) {
	$input  = array('name' => sprintf('scale-%04d', $i), 'address' => sprintf('bmc-%04d.scale.test', $i), 'port' => (string) $fake->port, 'poller_id' => '1', 'host_id' => (string) (SCALE_HOST_BASE + $i), 'credential_id' => (string) $credential, 'tls_mode' => 'ca_bundle', 'ca_file' => FakeBmc::certs() . '/ca.pem', 'enabled' => 'on');
	$list[] = BmcInput::parse($input, 0)->bmc;
}

if (in_array(null, $list, true) || !$bmcs->import($list, 1)) {
	fwrite(STDERR, "The scale BMCs could not be registered\n");
	exit(1);
}

scale_metric("$n latency_ms", $latency);

// The budget is raised for the measurement only: a production run stops
// starting BMCs at the budget and resumes the rest on its next run.
foreach (array('first' => 'discovery and readings', 'second' => 'readings') as $run => $what) {
	$t0  = hrtime(true);
	$res = scale_collect(3600);
	$s   = (hrtime(true) - $t0) / 1e9;
	scale_metric("$n collector_{$run}_s", sprintf('%.1f', $s));
	scale_metric("$n collector_{$run}_collected", $res['collected']);
	scale_metric("$n collector_{$run}_ms_per_bmc", sprintf('%.1f', 1000 * $s / max(1, $res['collected'])));
	scale_metric("$n collector_{$run}_requests_per_bmc", sprintf('%.1f', $res['requests'] / max(1, $res['collected'])));
}

scale_metric("$n collector_peak_memory_mib", sprintf('%.1f', memory_get_peak_usage(true) / 1048576));
scale_metric("$n reading_rows", (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_redfish_reading'));

// The script server keeps one process and the answerers' static state
// across calls; each call reads the cache of its device.
$calls   = min(2000, 2 * $n);
$summary = array();
$get     = array();
$unknown = 0;

for ($c = 0; $c < $calls; $c++) {
	$host = SCALE_HOST_BASE + 1 + ($c * 7919) % $n;

	$t0        = hrtime(true);
	$answer    = ss_redfish_summary($host);
	$summary[] = (hrtime(true) - $t0) / 1e6;
	$unknown  += strpos($answer, 'power_watts:U') === 0 ? 1 : 0;

	$t0    = hrtime(true);
	ss_redfish_members($host, 'fan', 'get', 'fan_rpm', 'System.Embedded.1/fan/Fan.Embedded.1A');
	$get[] = (hrtime(true) - $t0) / 1e6;
}

scale_spread("$n summary", $summary);
scale_spread("$n member_get", $get);
scale_metric("$n summary_unknown_power", $unknown);

foreach ($bmcs->all() as $old) {
	$bmcs->delete($old->id, 1);
}

exit($unknown === 0 ? 0 : 1);
