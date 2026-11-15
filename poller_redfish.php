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

/*
 * The Redfish collector for one poller, started by the poller_bottom hook:
 *
 *   php plugins/redfish/poller_redfish.php [--poller=N] [--force] [--debug]
 *
 * The master registers through Cacti's process registry, so a second
 * collector on the same poller exits (REQ-COLL-001), starts the configured
 * number of workers less one as child processes, and works as a worker
 * itself. Children get only the poller and worker numbers on their command
 * line, never a secret (REQ-CRED-001); leases keep them on different BMCs.
 * This file must parse on PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

ini_set('max_execution_time', '0');

$redfish_poller = (int) $config['poller_id'];
$redfish_force  = false;
$redfish_worker = 0;

foreach (array_slice($_SERVER['argv'], 1) as $redfish_arg) {
	if (strpos($redfish_arg, '--poller=') === 0) {
		$redfish_poller = (int) substr($redfish_arg, 9);
	} elseif (strpos($redfish_arg, '--worker=') === 0) {
		$redfish_worker = (int) substr($redfish_arg, 9);
	} elseif ($redfish_arg === '--force') {
		$redfish_force = true;
	} elseif ($redfish_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $redfish_arg . PHP_EOL . 'usage: poller_redfish.php [--poller=N] [--force] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (redfish_refuse_unsupported()) {
	exit(1);
}

redfish_boot();

if (!$redfish_force && !api_plugin_is_enabled('redfish')) {
	exit(0);
}

if ($redfish_poller < 1 || $redfish_worker < 0) {
	exit(0);
}

if (!(new \Somethingwithproof\Redfish\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The Redfish collector did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'REDFISH');
	exit(1);
}

$redfish_settings = redfish_settings();
$redfish_budget   = $redfish_settings->collectorBudget;
$redfish_health   = new \Somethingwithproof\Redfish\HealthRepository(false);

// A worker started by the master: collect and leave.
if ($redfish_worker > 0) {
	$redfish_result = redfish_collector($redfish_poller, $redfish_worker)->run($redfish_poller, $redfish_budget, $redfish_force);
	$redfish_health->addToRun($redfish_poller, $redfish_result);
	exit(0);
}

if (!register_process_start('redfish', 'collector', $redfish_poller, $redfish_budget + 120)) {
	cacti_log('NOTE: A Redfish collector is already running on poller ' . $redfish_poller, false, 'REDFISH', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

$redfish_started  = time();
$redfish_t0       = microtime(true);
$redfish_children = array();

try {
	$redfish_health->saveRun($redfish_poller, array('run_started' => $redfish_started, 'run_finished' => 0, 'run_duration' => 0.0, 'bmcs' => 0, 'collected' => 0, 'skipped' => 0, 'requests' => 0, 'workers' => $redfish_settings->workers, 'stopped_early' => false));

	$redfish_php = PHP_BINARY;

	for ($redfish_i = 1; $redfish_i < $redfish_settings->workers; $redfish_i++) {
		$redfish_args = array($redfish_php, '-q', __FILE__, '--poller=' . $redfish_poller, '--worker=' . $redfish_i);

		if ($redfish_force) {
			$redfish_args[] = '--force';
		}

		// An argument array runs the binary without a shell.
		$redfish_child = proc_open($redfish_args, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $redfish_pipes);

		if (is_resource($redfish_child)) {
			$redfish_children[] = $redfish_child;
		} else {
			cacti_log('WARNING: A Redfish collector worker could not be started; continuing with fewer workers', false, 'REDFISH');
		}
	}

	$redfish_result = redfish_collector($redfish_poller, 0)->run($redfish_poller, $redfish_budget, $redfish_force);
	$redfish_health->addToRun($redfish_poller, $redfish_result);

	foreach ($redfish_children as $redfish_child) {
		proc_close($redfish_child);
	}

	$redfish_health->saveRunFinish($redfish_poller, $redfish_result['bmcs'], time(), microtime(true) - $redfish_t0);
} finally {
	unregister_process('redfish', 'collector', $redfish_poller);
}

cacti_log(sprintf('STATS: Poller:%d BMCs:%d Workers:%d Duration:%.1f', $redfish_poller, $redfish_result['bmcs'], $redfish_settings->workers, microtime(true) - $redfish_t0), false, 'REDFISH', POLLER_VERBOSITY_MEDIUM);

exit(0);
