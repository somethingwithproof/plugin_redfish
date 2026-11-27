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

use Somethingwithproof\Redfish\Abilities;
use Somethingwithproof\Redfish\AdminState;
use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\CascProvider;
use Somethingwithproof\Redfish\Cli;
use Somethingwithproof\Redfish\Credential;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\DeviceScope;
use Somethingwithproof\Redfish\DoctorChecks;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\FreshnessEvaluator;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\MaintRepository;
use Somethingwithproof\Redfish\MaintWindow;
use Somethingwithproof\Redfish\NodeCacheRepository;
use Somethingwithproof\Redfish\Rack;
use Somethingwithproof\Redfish\RackRepository;
use Somethingwithproof\Redfish\Schema;
use Somethingwithproof\Redfish\Settings;
use Somethingwithproof\Redfish\TlsMode;

beforeEach(function () {
	$GLOBALS['__redfish_db_rows'] = [];
	$GLOBALS['__redfish_db_exec'] = [];
});

it('exports and round-trips YAML and XML CasC documents with vault references and zero secrets', function () {
	$creds = [new Credential(1, 'dc-ro', 'monitor', 1, 100, 100)];
	$bmcs  = [new Bmc(1, 'r660-01', '10.0.1.10', 443, 2, 11, 1, TlsMode::System, '', '', false, AdminState::Enabled, true)];
	$racks = [new Rack(1, 1, 'RowA-01', 4500.0, 80.0, 'Core rack')];

	$doc  = CascProvider::buildDocument($bmcs, $creds, $racks, [1 => [['host_id' => 11, 'rack_id' => 1, 'bmc_id' => 1, 'unit_position' => 4]]]);
	$yaml = CascProvider::export($doc, 'yaml');
	$xml  = CascProvider::export($doc, 'xml');

	expect($yaml)->toContain('vault:redfish:dc-ro')
		->and($yaml)->not->toContain('password')
		->and($xml)->toContain('vault:redfish:dc-ro')
		->and($xml)->not->toContain('password');

	$parsedYaml = CascProvider::parse($yaml, 'yaml');
	$parsedXml  = CascProvider::parse($xml, 'xml');

	expect(CascProvider::validate($parsedYaml))->toBe([])
		->and(CascProvider::validate($parsedXml))->toBe([])
		->and($parsedYaml['bmcs'][0]['poller_id'])->toBe(2)
		->and($parsedYaml['bmcs'][0]['ipmi_fallback'])->toBeTrue()
		->and($parsedXml['racks'][0]['power_budget_watts'])->toBe(4500.0);
});

it('rejects secrets in CasC documents, rejects XML DOCTYPE, and enforces SHA-256 plan confirmation', function () {
	$badDoc = [
		'provider'       => 'redfish',
		'schema_version' => 1,
		'credentials'    => [['name' => 'ro', 'username' => 'mon', 'password' => 'leaked']],
		'bmcs'           => [],
		'racks'          => [],
	];

	expect(implode("\n", CascProvider::validate($badDoc)))->toContain('secret or credential fields are forbidden');
	expect(fn () => CascProvider::parse('<!DOCTYPE foo [<!ENTITY x "y">]><redfish_casc/>', 'xml'))->toThrow(\InvalidArgumentException::class);

	$empty   = CascProvider::buildDocument([], [], []);
	$desired = CascProvider::buildDocument(
		[new Bmc(1, 'srv01', '10.0.1.20', 443, 1, 0, 1, TlsMode::System, '', '', false, AdminState::Enabled)],
		[new Credential(1, 'ro', 'monitor', 1, 100, 100)],
		[new Rack(1, 1, 'Rack-1', 3000.0, 80.0)]
	);

	$plan1 = CascProvider::plan($desired, $empty);
	$plan2 = CascProvider::plan($desired, $empty);

	expect($plan1['hash'])->toBe($plan2['hash'])
		->and(strlen($plan1['hash']))->toBe(64)
		->and($plan1['summary'])->toBe(['create' => 3, 'update' => 0, 'unchanged' => 0])
		->and(CascProvider::verifyPlanHash($plan1['hash'], $plan1['hash']))->toBeTrue()
		->and(CascProvider::verifyPlanHash($plan1['hash'], '0000000000000000')) ->toBeFalse();
});

it('runs CLI export, validate, plan, apply confirmation guard, and health text/json', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_credential ORDER BY name' => [['id' => 1, 'name' => 'ro', 'username' => 'monitor', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1]],
		'FROM plugin_redfish_bmc ORDER BY name' => [['id' => 1, 'name' => 'srv01', 'address' => '10.0.1.20', 'port' => 443, 'protocols' => 'redfish', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled']],
		'FROM plugin_redfish_rack ORDER BY site_id, name' => [['id' => 1, 'site_id' => 1, 'name' => 'Rack-1', 'power_budget_watts' => 3000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack WHERE id = ?' => [['id' => 1, 'site_id' => 1, 'name' => 'Rack-1', 'power_budget_watts' => 3000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack_member WHERE rack_id = ?' => [],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [['bmc_id' => 1, 'reachability' => 'reachable', 'last_success' => 1_800_000_000, 'generation' => 'subsystem', 'auth_mode' => 'session']],
		'FROM plugin_redfish_run ORDER BY poller_id' => [],
	];

	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow([]);
	$cli      = new Cli(
		new BmcRepository(false, $clock),
		new CredentialRepository(false, $clock),
		new RackRepository(false, $clock),
		new HealthRepository(false),
		new NodeCacheRepository(false),
		FreshnessEvaluator::fromSettings($clock, $settings),
		new MaintWindow(new MaintRepository(false), $clock),
		$settings,
		$clock
	);

	$exported = $cli->run(['export', '--format=yaml']);
	expect($exported['exit'])->toBe(0)->and($exported['stdout'])->toContain('vault:redfish:ro');

	$validated = $cli->run(['validate', '--format=yaml'], $exported['stdout']);
	expect($validated['exit'])->toBe(0)->and($validated['stdout'])->toContain('OK');

	$planned = $cli->run(['plan', '--format=yaml'], $exported['stdout']);
	expect($planned['exit'])->toBe(0)->and($planned['stdout'])->toContain('PLAN_HASH=');

	$refusedNoConfirm = $cli->run(['apply', '--format=yaml'], $exported['stdout']);
	expect($refusedNoConfirm['exit'])->toBe(1)->and($refusedNoConfirm['stderr'])->toContain('--confirm');

	$refusedBadHash = $cli->run(['apply', '--format=yaml', '--confirm=badhash'], $exported['stdout']);
	expect($refusedBadHash['exit'])->toBe(1)->and($refusedBadHash['stderr'])->toContain('does not match');

	$healthJson = $cli->run(['health', '--format=json']);
	$decoded    = json_decode($healthJson['stdout'], true);
	expect($healthJson['exit'])->toBe(0)
		->and($decoded['plugin'])->toBe('redfish')
		->and($decoded['bmcs'][0]['name'])->toBe('srv01');

	$healthText = $cli->run(['health', '--format=text']);
	expect($healthText['exit'])->toBe(0)->and($healthText['stdout'])->toContain('srv01');
});

it('registers read-only MCP abilities gated on View realm with zero credentials', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_credential ORDER BY name' => [['id' => 1, 'name' => 'ro', 'username' => 'monitor', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1]],
		'FROM plugin_redfish_bmc ORDER BY name' => [['id' => 1, 'name' => 'srv01', 'address' => '10.0.1.20', 'port' => 443, 'protocols' => 'redfish', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled']],
		'FROM plugin_redfish_rack ORDER BY site_id, name' => [['id' => 1, 'site_id' => 1, 'name' => 'Rack-1', 'power_budget_watts' => 3000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack_member WHERE rack_id = ?' => [],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [['bmc_id' => 1, 'reachability' => 'reachable', 'last_success' => 1_800_000_000]],
		'FROM plugin_redfish_run ORDER BY poller_id' => [],
	];

	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow([]);
	$allow    = false;

	$defs = Abilities::definitions(
		static function () use (&$allow): bool { return $allow; },
		static fn (): bool => false,
		static fn (): DeviceScope => new DeviceScope(static fn (int $h): bool => $h === 5, static fn (int $h): bool => true),
		static fn (): BmcRepository => new BmcRepository(false, $clock),
		static fn (): CredentialRepository => new CredentialRepository(false, $clock),
		static fn (): RackRepository => new RackRepository(false, $clock),
		static fn (): HealthRepository => new HealthRepository(false),
		static fn (): NodeCacheRepository => new NodeCacheRepository(false),
		static fn (): FreshnessEvaluator => FreshnessEvaluator::fromSettings($clock, $settings),
		static fn (): MaintWindow => new MaintWindow(new MaintRepository(false), $clock),
		static fn (): Settings => $settings,
		static fn (): FixedClock => $clock,
	);

	$byName = [];

	foreach ($defs as $def) {
		expect($def['read_only'])->toBeTrue();
		$byName[$def['name']] = $def['callback'];
	}

	expect(array_keys($byName))->toBe([Abilities::LIST_BMCS, Abilities::GET_RACK_POWER, Abilities::GET_HEALTH]);

	// Denied when viewer lacks Redfish View realm.
	expect(($byName[Abilities::LIST_BMCS])([])['ok'])->toBeFalse()
		->and(($byName[Abilities::GET_RACK_POWER])([])['ok'])->toBeFalse()
		->and(($byName[Abilities::GET_HEALTH])([])['ok'])->toBeFalse();

	// Allowed with Redfish View realm, zero secrets returned.
	$allow = true;
	$bmcs  = ($byName[Abilities::LIST_BMCS])([]);
	$racks = ($byName[Abilities::GET_RACK_POWER])([]);
	$hlth  = ($byName[Abilities::GET_HEALTH])([]);

	expect($bmcs['ok'])->toBeTrue()
		->and($bmcs['bmcs'][0]['credential_ref'])->toBe('vault:redfish:ro')
		->and(json_encode($bmcs))->not->toContain('password')
		->and($racks['ok'])->toBeTrue()
		->and($racks['racks'][0]['name'])->toBe('Rack-1')
		->and($hlth['ok'])->toBeTrue()
		->and($hlth['bmcs'][0]['reachability'])->toBe('reachable');
});

it('registers Doctor checks and ecosystem hooks cleanly', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'SELECT value FROM settings WHERE name = ?' => [[(string) Schema::VERSION]],
		'FROM plugin_redfish_run ORDER BY poller_id' => [['poller_id' => 1, 'run_started' => 1_799_999_900, 'run_duration' => 10.0, 'bmcs' => 1, 'collected' => 1, 'skipped' => 0, 'requests' => 5, 'workers' => 4, 'stopped_early' => '']],
		'FROM plugin_redfish_bmc ORDER BY name' => [['id' => 1, 'name' => 'srv01', 'address' => '10.0.1.20', 'port' => 443, 'protocols' => 'redfish,ipmi', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled']],
		'FROM plugin_redfish_rack ORDER BY site_id, name' => [],
	];

	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow(['redfish_vault_key_file' => '/nonexistent/redfish-vault.key']);

	$checks = DoctorChecks::definitions(
		static fn (): Schema => new Schema(false),
		static fn (): CredentialStore => new CredentialStore(new CredentialRepository(false, $clock), $settings->vaultKeyFile),
		static fn (): BmcRepository => new BmcRepository(false, $clock),
		static fn (): HealthRepository => new HealthRepository(false),
		static fn (): RackRepository => new RackRepository(false, $clock),
		static fn (): NodeCacheRepository => new NodeCacheRepository(false),
		static fn (): FreshnessEvaluator => FreshnessEvaluator::fromSettings($clock, $settings),
		static fn (): Settings => $settings,
		static fn (): FixedClock => $clock,
	);

	$results = [];

	foreach ($checks as $check) {
		$results[$check['id']] = ($check['callback'])();
	}

	expect($results['redfish.schema']['status'])->toBe('ok')
		->and($results['redfish.vault_key']['status'])->toBe('error')
		->and($results['redfish.collector_runs']['status'])->toBe('ok')
		->and($results['redfish.bmc_security']['status'])->toBe('warning')
		->and($results['redfish.rack_power']['status'])->toBe('ok');

	// Verify setup.php hook wrappers register entries.
	expect(redfish_abilities_register([]))->toHaveCount(3)
		->and(redfish_doctor_register_checks([]))->toHaveCount(5)
		->and(redfish_casc_register_providers([]))->toHaveCount(1);
});
