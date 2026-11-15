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
 * Collector scenarios against the fake BMC (issues #40 and #42), included by
 * tests/bin/lifecycle.php, which sets $action and reads $result:
 *
 *   collector_flow    collect, schedule, back off, sweep a lost session,
 *                     refuse an unresolvable name, scan for the password,
 *                     and delete a BMC without touching its Cacti device
 *   collector_victim  a run killed mid-BMC (started by collector_kill)
 *   collector_kill    the next runs after that kill
 *   collector_second  a second collector start on the poller exits at once
 *
 * The BMC name bmc.test resolves to 127.0.0.1 through a test resolver, and
 * the loopback binding is enabled only here; nothing leaves the container.
 */

use Somethingwithproof\Redfish\BmcInput;
use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\ClientFactory;
use Somethingwithproof\Redfish\Clock;
use Somethingwithproof\Redfish\Collector;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\DiscoveryRepository;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\HttpRequest;
use Somethingwithproof\Redfish\HttpResponse;
use Somethingwithproof\Redfish\HttpTransport;
use Somethingwithproof\Redfish\LeaseRepository;
use Somethingwithproof\Redfish\ReadingRepository;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\SocketTransport;

include_once($config['base_path'] . '/plugins/redfish/setup.php');
redfish_boot();
require_once($config['base_path'] . '/plugins/redfish/tests/Support/FakeBmc.php');

const FLOW_BUDGET = 240;

final class KillingTransport implements HttpTransport {
	private int $sent = 0;

	public function __construct(private HttpTransport $inner, private int $killAt) {
	}

	public function send(HttpRequest $request): HttpResponse {
		// Killed outright, as by the OOM killer or a reboot: no finally
		// block runs, so the lease and the session stay behind.
		if (++$this->sent === $this->killAt) {
			function_exists('posix_kill') ? posix_kill(getmypid(), 9) : exec('kill -9 ' . getmypid());
			sleep(5);
		}

		return $this->inner->send($request);
	}
}

function flow_key_file(): string {
	$file = sys_get_temp_dir() . '/redfish-flow.key';

	if (!is_file($file)) {
		file_put_contents($file, bin2hex(random_bytes(32)));
		chmod($file, 0600);
	}

	db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($file));

	return $file;
}

function flow_collector(Clock $clock, string $holder, int $killAt = 0): Collector {
	$resolver = FakeBmc::resolver();
	$factory  = new ClientFactory($resolver, 2.0, 5.0, 1048576, true, $killAt > 0 ? static fn (Endpoint $e): HttpTransport => new KillingTransport(new SocketTransport($e, $resolver, 2.0, 5.0, 1048576, true), $killAt) : null);

	return new Collector(
		new BmcRepository(false, $clock),
		new CredentialStore(new CredentialRepository(false, $clock), flow_key_file()),
		new LeaseRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false),
		new ReadingRepository(false),
		$factory,
		redfish_settings(),
		$clock,
		$holder
	);
}

/**
 * A credential with the fake BMC's account, stored through the vault.
 */
function flow_credential(Clock $clock, bool $password = true): int {
	$credentials = new CredentialRepository(false, $clock);
	$id          = $credentials->save(0, 'fake-' . bin2hex(random_bytes(3)), FakeBmc::USER, 1);

	if ($password) {
		(new CredentialStore($credentials, flow_key_file()))->storePassword($id, new Secret(FakeBmc::PASSWORD), 1);
	}

	return $id;
}

function flow_bmc(Clock $clock, string $name, string $address, int $port, int $credential, int $host = 0): int {
	$input = array('name' => $name, 'address' => $address, 'port' => (string) $port, 'poller_id' => '1', 'host_id' => (string) $host, 'credential_id' => (string) $credential, 'tls_mode' => 'ca_bundle', 'ca_file' => FakeBmc::certs() . '/ca.pem', 'enabled' => 'on');
	$bmc   = BmcInput::parse($input, 0)->bmc;

	if ($bmc === null) {
		throw new RuntimeException('BMC input refused: ' . json_encode(BmcInput::parse($input, 0)->errors));
	}

	return (new BmcRepository(false, $clock))->save($bmc, 1);
}

function flow_health(int $bmc): array {
	$row = db_fetch_row_prepared('SELECT reachability, failure_class, auth_mode, failures, backoff_until, session_pending, sessions_lost, generation FROM plugin_redfish_health WHERE bmc_id = ?', array($bmc));

	return is_array($row) ? $row : array();
}

function flow_counts(int $bmc): array {
	$out = array();

	foreach (array('reading', 'member', 'discovery', 'health', 'lease') as $table) {
		$out[$table] = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_' . $table . ' WHERE bmc_id = ?', array($bmc));
	}

	return $out;
}

/**
 * Tables, log, and the given page bodies that contain the password.
 */
function flow_leaks(array $texts = array()): array {
	$leaks = array();

	foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_redfish\\_%'") as $table) {
		foreach (db_fetch_assoc('SELECT * FROM `' . $table['t'] . '`') as $row) {
			if (strpos(implode("\0", array_map('strval', $row)), FakeBmc::PASSWORD) !== false) {
				$leaks[] = $table['t'];
			}
		}
	}

	foreach (db_fetch_assoc("SELECT name, value FROM settings WHERE name LIKE 'redfish\\_%'") as $row) {
		if (strpos((string) $row['value'], FakeBmc::PASSWORD) !== false) {
			$leaks[] = 'settings';
		}
	}

	if (strpos((string) @file_get_contents(cacti_log_file()), FakeBmc::PASSWORD) !== false) {
		$leaks[] = 'cacti.log';
	}

	foreach ($texts as $name => $text) {
		if (strpos($text, FakeBmc::PASSWORD) !== false) {
			$leaks[] = $name;
		}
	}

	return $leaks;
}

function flow_reading(int $bmc, string $member, string $metric): ?array {
	$row = db_fetch_row_prepared('SELECT value, unit, source FROM plugin_redfish_reading WHERE bmc_id = ? AND member_key = ? AND metric = ?', array($bmc, $member, $metric));

	return is_array($row) && $row !== array() ? $row : null;
}

switch ($action) {
	case 'collector_flow':
		$fake  = new FakeBmc('dell-subsystem');
		$t     = time();
		$clock = new FixedClock($t);
		db_execute("INSERT INTO host (description, hostname, status) VALUES ('bmc-host', '192.0.2.10', 3)");
		$host = (int) db_fetch_insert_id();
		$cred = flow_credential($clock);
		$bmc  = flow_bmc($clock, 'r660', 'bmc.test', $fake->port, $cred, $host);

		$result = array('run1' => flow_collector($clock, 'flow')->run(1, FLOW_BUDGET));
		$result['health1']   = flow_health($bmc);
		$result['counts1']   = flow_counts($bmc);
		$result['power']     = flow_reading($bmc, 'node', 'power_watts');
		$result['inlet']     = flow_reading($bmc, 'node', 'inlet_celsius');
		$result['fan']       = flow_reading($bmc, 'System.Embedded.1/fan/Fan.Embedded.1A', 'fan_speed');
		$result['sessions1'] = $fake->state()['sessions'];
		$methods             = array_map(static fn (array $r): string => $r['method'], $fake->state()['log']);
		$result['methods']   = array_values(array_unique($methods));

		// Inside the interval the BMC is not due; at it, it is.
		$clock->advance(60);
		$result['early'] = flow_collector($clock, 'flow')->run(1, FLOW_BUDGET);
		$clock->advance(240);
		$result['due'] = flow_collector($clock, 'flow')->run(1, FLOW_BUDGET);

		// Throttling backs off, doubling, at least Retry-After.
		$fake->flags(array('throttle' => true));
		$waits = array();

		for ($i = 0; $i < 3; $i++) {
			$clock->advance(1);
			flow_collector($clock, 'flow')->run(1, FLOW_BUDGET, true);
			$h       = flow_health($bmc);
			$waits[] = array((int) $h['failures'], (int) $h['backoff_until'] - $clock->now(), $h['failure_class']);
		}

		$result['backoff'] = $waits;
		$clock->advance(60);
		$result['in_backoff'] = flow_collector($clock, 'flow')->run(1, FLOW_BUDGET);
		$result['readings_kept'] = flow_reading($bmc, 'node', 'power_watts') !== null;

		// A session POST whose response is lost leaves the marker; the
		// next run deletes the session it may have made, then logs in.
		$fake->flags(array('lose_login' => true));
		$clock->advance(7200);
		flow_collector($clock, 'flow')->run(1, FLOW_BUDGET, true);
		$result['lost']          = flow_health($bmc);
		$result['lost_sessions'] = count($fake->state()['sessions']);
		$fake->flags(array());
		$clock->advance(7200);
		flow_collector($clock, 'flow')->run(1, FLOW_BUDGET, true);
		$result['swept']          = flow_health($bmc);
		$result['swept_sessions'] = count($fake->state()['sessions']);

		// A name that does not resolve is refused before any connection.
		$nowhere = flow_bmc($clock, 'nowhere', 'nowhere.test', 443, $cred);
		$clock->advance(7200);
		flow_collector($clock, 'flow')->run(1, FLOW_BUDGET, true);
		$result['nowhere'] = flow_health($nowhere);

		// The pages render and never show the password.
		ob_start();
		$pageClock = new FixedClock($clock->now());
		(new \Somethingwithproof\Redfish\HealthPage(new BmcRepository(false, $pageClock), new HealthRepository(false), new ReadingRepository(false), new DiscoveryRepository(false), redfish_credential_store(), \Somethingwithproof\Redfish\FreshnessEvaluator::fromSettings($pageClock, redfish_settings()), $pageClock, new \Somethingwithproof\Redfish\DeviceScope(static fn (int $h): bool => true, static fn (int $h): bool => true)))->render(redfish_settings(), true);
		$page = (string) ob_get_clean();
		$result['page_lists'] = strpos($page, 'r660') !== false;
		$result['leaks']      = flow_leaks(array('health page' => $page));

		// Deleting the BMC removes its cache, keeps its Cacti device.
		$result['deleted']      = (new BmcRepository(false, $clock))->delete($bmc, 1);
		$result['counts_after'] = flow_counts($bmc);
		$result['host_kept']    = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host WHERE id = ?', array($host));
		$result['audit']        = array_column(db_fetch_assoc("SELECT object, action FROM plugin_redfish_audit ORDER BY id"), 'action', 'object');

		db_execute_prepared('DELETE FROM host WHERE id = ?', array($host));

		break;
	case 'collector_victim':
		flow_collector(new FixedClock((int) db_fetch_cell("SELECT value FROM settings WHERE name = 'redfish_test_time'")), 'victim', 4)->run(1, FLOW_BUDGET);

		break;
	case 'collector_kill':
		$fake  = new FakeBmc('dell-subsystem');
		$t     = time();
		$clock = new FixedClock($t);
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_test_time', ?)", array($t));
		$bmc = flow_bmc($clock, 'victim', 'bmc.test', $fake->port, flow_credential($clock));

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lifecycle.php') . ' collector_victim 2>&1', $lines, $rc);
		$result = array(
			'victim_rc' => $rc,
			'lease'     => db_fetch_cell_prepared('SELECT holder FROM plugin_redfish_lease WHERE bmc_id = ?', array($bmc)),
			'health'    => flow_health($bmc),
			'readings'  => flow_counts($bmc)['reading'],
			'sessions'  => count($fake->state()['sessions']),
		);

		// Inside the dead run's lease the BMC is skipped, not doubled.
		$clock->advance(60);
		$result['while_leased'] = flow_collector($clock, 'next')->run(1, FLOW_BUDGET);

		// After the lease expires the next run sweeps the session and collects.
		$clock->advance(FLOW_BUDGET + 120);
		$result['after_expiry'] = flow_collector($clock, 'next')->run(1, FLOW_BUDGET);
		$result['final']        = flow_health($bmc);
		$result['final_count']  = flow_counts($bmc)['reading'] > 0;
		$result['final_open']   = count($fake->state()['sessions']);
		db_execute("DELETE FROM settings WHERE name = 'redfish_test_time'");

		break;
	case 'collector_second':
		// An enabled BMC whose credential has no password: a run that
		// starts leases it, and fails before any connection.
		$clock = new FixedClock(time());
		flow_bmc($clock, 'second', 'bmc.test', 9, flow_credential($clock, false));

		// A live process holds the collector slot for poller 1. Cacti 1.2.x
		// treats a slot as held only when the holder runs the same script,
		// so the stand-in names poller_redfish.php on its command line.
		$script  = $config['base_path'] . '/plugins/redfish/poller_redfish.php';
		$holder  = proc_open(array(PHP_BINARY, '-r', 'sleep(60);', $script), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		$status  = proc_get_status($holder);
		register_process('redfish', 'collector', 1, $status['pid'], 300);
		$started = microtime(true);
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --poller=1 --force 2>&1', $lines, $rc);
		proc_terminate($holder, 9);
		proc_close($holder);
		unregister_process('redfish', 'collector', 1);
		$result = array('rc' => $rc, 'seconds' => round(microtime(true) - $started, 1), 'output' => implode("\n", $lines), 'leases' => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_redfish_lease'));

		// Control: with the slot free, the same start does take the lease.
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --poller=1 --force 2>&1', $lines, $rc);
		$result['leases_when_free'] = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_redfish_lease');
		$result['run']              = db_fetch_row('SELECT bmcs, collected, workers FROM plugin_redfish_run WHERE poller_id = 1');
		$result['config_failure']   = db_fetch_cell("SELECT failure_class FROM plugin_redfish_health LIMIT 1");

		break;
}
