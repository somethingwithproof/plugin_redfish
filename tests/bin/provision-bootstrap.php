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
 * Device provisioning from the smallest bootstrap a caller can have: Cacti's
 * CLI bootstrap and the plugin's setup.php, nothing else. The provisioner
 * must load every Cacti library it uses itself.
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/provision-bootstrap.php
 *
 * Run from the Cacti root on a disposable database with the plugin enabled
 * and its templates installed. The collector reads a fake BMC once; the
 * BMC gets a device and its graphs, attaching again adds none, and the
 * device and BMC are removed afterwards. Must parse on PHP 8.0.
 */

chdir(dirname(__DIR__, 4));

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable: this check writes devices and BMCs\n");
	exit(2);
}

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

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

function bootstrap_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

// The point of the check: none of the graph libraries is loaded yet.
bootstrap_expect(!function_exists('create_complete_graph_from_template') && !function_exists('api_reapply_suggested_graph_title') && !function_exists('api_device_save'), 'the bootstrap has not loaded the device and graph libraries');

$dir = realpath(sys_get_temp_dir()) . '/redfish-test-keys';

if (!is_dir($dir)) {
	mkdir($dir, 0700);
}

$keyFile = $dir . '/redfish-provision-bootstrap.key';
file_put_contents($keyFile, bin2hex(random_bytes(32)));
chmod($keyFile, 0600);
db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($keyFile));

$clock       = new SystemClock();
$credentials = new CredentialRepository(false, $clock);
$credential  = $credentials->save(0, 'bootstrap-' . bin2hex(random_bytes(3)), FakeBmc::USER, 1);
(new CredentialStore($credentials, $keyFile))->storePassword($credential, new Secret(FakeBmc::PASSWORD), 1);

$fake  = new FakeBmc('dell-subsystem');
$input = array('name' => 'bootstrap-' . bin2hex(random_bytes(3)), 'address' => 'bmc.test', 'port' => (string) $fake->port, 'poller_id' => '1', 'host_id' => '0', 'credential_id' => (string) $credential, 'tls_mode' => 'ca_bundle', 'ca_file' => FakeBmc::certs() . '/ca.pem', 'enabled' => 'on');
$bmcId = redfish_bmcs()->save(BmcInput::parse($input, 0)->bmc, 1);
bootstrap_expect($bmcId > 0, 'the BMC was registered');

// Collect this BMC only: others registered by earlier checks have no
// fake server any more, and are enabled again afterwards.
$others = array_map('intval', array_column(db_fetch_assoc_prepared("SELECT id FROM plugin_redfish_bmc WHERE admin_state = 'enabled' AND id != ?", array($bmcId)), 'id'));

foreach ($others as $other) {
	db_execute_prepared("UPDATE plugin_redfish_bmc SET admin_state = 'disabled' WHERE id = ?", array($other));
}

$run = (new Collector(
	new BmcRepository(false, $clock),
	new CredentialStore($credentials, $keyFile),
	new LeaseRepository(false, $clock),
	new HealthRepository(false),
	new DiscoveryRepository(false),
	new ReadingRepository(false),
	new ClientFactory(FakeBmc::resolver(), 2.0, 5.0, 1048576, true),
	redfish_settings(),
	$clock,
	'provision-bootstrap'
))->run(1, 240, true);

foreach ($others as $other) {
	db_execute_prepared("UPDATE plugin_redfish_bmc SET admin_state = 'enabled' WHERE id = ?", array($other));
}

db_execute_prepared("UPDATE plugin_redfish_bmc SET admin_state = 'disabled' WHERE id = ?", array($bmcId));
bootstrap_expect($run['collected'] === 1, 'the collector read the fake BMC');

$provisioner = redfish_provisioner();
$refusal     = $provisioner->refusal();
bootstrap_expect($refusal === null, 'the templates are complete: ' . ($refusal ?? 'yes'));

$error  = null;
$hostId = $provisioner->create(redfish_bmcs()->find($bmcId), 1, $error);
bootstrap_expect($hostId > 0, 'the device was created' . ($error === null ? '' : ': ' . $error));

$graphs = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ?', array($hostId));
bootstrap_expect($graphs > 4, "the device has its summary and member graphs ($graphs)");
bootstrap_expect($provisioner->attach($bmcId, $hostId, 1) === 0, 'attaching again adds no graph');

if ($hostId > 0) {
	api_device_remove($hostId);
}

redfish_bmcs()->delete($bmcId, 1);
$credentials->delete($credential, 1);
unlink($keyFile);

exit($failures === 0 ? 0 : 1);
