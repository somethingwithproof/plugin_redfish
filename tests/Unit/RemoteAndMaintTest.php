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

use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\DiscoveryRepository;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\MaintRepository;
use Somethingwithproof\Redfish\MaintWindow;
use Somethingwithproof\Redfish\Replication;
use Somethingwithproof\Redfish\ReplicationRepository;

beforeEach(function () {
	$GLOBALS['__redfish_db_rows'] = [];
	$GLOBALS['__redfish_db_exec'] = [];
});

it('replicates only non-secret config tables and keeps vault keys local', function () {
	expect(ReplicationRepository::CONFIG_TABLES)->toContain('plugin_redfish_bmc')
		->and(ReplicationRepository::CONFIG_TABLES)->toContain('plugin_redfish_rack')
		->and(ReplicationRepository::CONFIG_TABLES)->not->toContain('plugin_redfish_secret');

	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc WHERE poller_id = ?' => [[
			'id'             => 10,
			'name'           => 'edge-bmc',
			'address'        => '10.20.0.5',
			'port'           => 443,
			'protocols'      => 'redfish',
			'poller_id'      => 2,
			'host_id'        => 42,
			'credential_id'  => 7,
			'tls_mode'       => 'system',
			'ca_file'        => '',
			'pin_sha256'     => '',
			'basic_fallback' => '',
			'admin_state'    => 'enabled',
			'created_at'     => 100,
			'updated_at'     => 100,
		]],
		'FROM plugin_redfish_credential WHERE id IN' => [[
			'id'         => 7,
			'name'       => 'edge-ro',
			'username'   => 'monitor',
			'revision'   => 1,
			'created_at' => 100,
			'updated_at' => 100,
		]],
		'FROM plugin_redfish_rack ORDER BY id' => [],
		'FROM plugin_redfish_rack_member ORDER BY host_id' => [],
	];

	$clock = new FixedClock(1_800_000_000);
	$rep   = new Replication(
		new ReplicationRepository(false),
		new BmcRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false)
	);

	$payload = $rep->exportForPoller(2);

	expect($payload['poller_id'])->toBe(2)
		->and($payload['vault_keys_local'])->toBeTrue()
		->and($payload['bmcs'])->toHaveCount(1)
		->and($payload['credentials'])->toHaveCount(1)
		->and(json_encode($payload))->not->toContain('ciphertext')
		->and(json_encode($payload))->not->toContain('password');
});

it('serves read-only remote_agent health and refuses non-identifier parameters', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc ORDER BY name' => [[
			'id'             => 10,
			'name'           => 'edge-bmc',
			'address'        => '10.20.0.5',
			'port'           => 443,
			'protocols'      => 'redfish',
			'poller_id'      => 2,
			'host_id'        => 42,
			'credential_id'  => 7,
			'tls_mode'       => 'system',
			'ca_file'        => '',
			'pin_sha256'     => '',
			'basic_fallback' => '',
			'admin_state'    => 'enabled',
		]],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [[
			'bmc_id'       => 10,
			'reachability' => 'reachable',
			'last_success' => 1_800_000_000,
			'generation'   => 'subsystem',
			'auth_mode'    => 'session',
		]],
		'FROM plugin_redfish_run ORDER BY poller_id' => [],
	];

	$clock = new FixedClock(1_800_000_000);
	$rep   = new Replication(
		new ReplicationRepository(false),
		new BmcRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false)
	);

	$ok = $rep->handleRemoteAgent(['action' => Replication::AGENT_ACTION_HEALTH, 'poller_id' => 2]);

	expect($ok['ok'])->toBeTrue()
		->and($ok['bmcs'])->toHaveCount(1)
		->and($ok['bmcs'][0]['reachability'])->toBe('reachable')
		->and(json_encode($ok))->not->toContain('10.20.0.5');

	expect($rep->handleRemoteAgent(['action' => Replication::AGENT_ACTION_HEALTH, 'address' => '10.0.0.1'])['error'])->toBe('parameter_refused')
		->and($rep->handleRemoteAgent(['action' => Replication::AGENT_ACTION_HEALTH, 'password' => 'x'])['error'])->toBe('parameter_refused')
		->and($rep->handleRemoteAgent(['action' => 'reboot_bmc'])['error'])->toBe('unsupported_action');
});

it('evaluates one-time and recurring Cacti Maint schedules accurately', function () {
	$oneTime = ['id' => 1, 'name' => 'Patch Window', 'mtype' => 1, 'stime' => 1000, 'etime' => 2000, 'minterval' => 0];
	expect(MaintWindow::isScheduleActive($oneTime, 999))->toBeFalse()
		->and(MaintWindow::isScheduleActive($oneTime, 1500))->toBeTrue()
		->and(MaintWindow::isScheduleActive($oneTime, 2001))->toBeFalse();

	// Weekly 1-hour window starting at 1000, repeating every 7 days (604800s).
	$recurring = ['id' => 2, 'name' => 'Weekly Maint', 'mtype' => 2, 'stime' => 1000, 'etime' => 4600, 'minterval' => 604800];
	expect(MaintWindow::isScheduleActive($recurring, 1000 + 604800 + 1800))->toBeTrue()
		->and(MaintWindow::isScheduleActive($recurring, 1000 + 604800 + 5000))->toBeFalse()
		->and(MaintWindow::isScheduleActive($recurring, 500))->toBeFalse();

	$maint = new MaintWindow(new MaintRepository(false), new FixedClock(1500), static fn (int $hostId): bool => $hostId === 77);
	expect($maint->activeForHost(77))->toBe(['active' => true, 'schedule_id' => 0, 'name' => 'maintenance'])
		->and($maint->activeForHost(88))->toBeNull();
});
