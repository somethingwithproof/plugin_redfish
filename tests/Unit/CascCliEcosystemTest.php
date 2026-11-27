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
use Somethingwithproof\Redfish\ApiResources;
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
use Somethingwithproof\Redfish\RealmRepository;
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

it('registers read-only MCP abilities via registrar object with canonical schemas and user-ID permissions', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_credential ORDER BY name' => [['id' => 1, 'name' => 'ro', 'username' => 'monitor', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1]],
		'FROM plugin_redfish_bmc ORDER BY name' => [['id' => 1, 'name' => 'srv01', 'address' => '10.0.1.20', 'port' => 443, 'protocols' => 'redfish', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled']],
		'FROM plugin_redfish_rack ORDER BY site_id, name' => [['id' => 1, 'site_id' => 1, 'name' => 'Rack-1', 'power_budget_watts' => 3000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack_member WHERE rack_id = ?' => [],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [['bmc_id' => 1, 'reachability' => 'reachable', 'last_success' => 1_800_000_000]],
		'FROM plugin_redfish_run ORDER BY poller_id' => [],
		'FROM plugin_realms WHERE plugin = ?' => [
			['id' => 12, 'file' => 'redfish_health.php'],
			['id' => 13, 'file' => 'redfish_bmcs.php,redfish_credentials.php'],
		],
		'FROM user_auth_realm' => [['realm_id' => 112]],
	];

	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow([]);
	$allow    = false;

	$defs = Abilities::definitions(
		static function (int $userId) use (&$allow): bool { return $allow && $userId > 0; },
		static fn (int $userId): bool => false,
		static fn (int $userId): DeviceScope => new DeviceScope(static fn (int $h): bool => $h === 5, static fn (int $h): bool => true),
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

	expect(array_keys($defs))->toBe([Abilities::LIST_BMCS, Abilities::GET_RACK_POWER, Abilities::GET_HEALTH]);

	$assertSchema = static function (array $schema) use (&$assertSchema): void {
		$type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? '');

		if ($type === 'object') {
			expect($schema['additionalProperties'] ?? null)->toBeFalse()
				->and($schema)->toHaveKey('properties');

			foreach ($schema['properties'] as $prop) {
				$assertSchema($prop);
			}
		} elseif ($type === 'array') {
			expect($schema['items'] ?? null)->toBeArray()
				->and($schema['maxItems'] ?? null)->toBeInt()->toBeGreaterThan(0);
			$assertSchema($schema['items']);
		}
	};

	$expectedKeys = ['label', 'description', 'category', 'input_schema', 'output_schema', 'annotations', 'permission', 'execute'];

	foreach ($defs as $def) {
		expect(array_keys($def))->toBe($expectedKeys)
			->and($def['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true]);
		$assertSchema($def['input_schema']);
		$assertSchema($def['output_schema']);
	}

	// Denied when viewer lacks Redfish View realm.
	expect(($defs[Abilities::LIST_BMCS]['permission'])(42))->toBeFalse()
		->and(($defs[Abilities::LIST_BMCS]['execute'])(42, new \stdClass())['ok'])->toBeFalse()
		->and(($defs[Abilities::GET_RACK_POWER]['execute'])(42, new \stdClass())['ok'])->toBeFalse()
		->and(($defs[Abilities::GET_HEALTH]['execute'])(42, new \stdClass())['ok'])->toBeFalse();

	// Allowed with Redfish View realm, zero secrets returned.
	$allow = true;
	$bmcs  = ($defs[Abilities::LIST_BMCS]['execute'])(42, new \stdClass());
	$racks = ($defs[Abilities::GET_RACK_POWER]['execute'])(42, new \stdClass());
	$hlth  = ($defs[Abilities::GET_HEALTH]['execute'])(42, new \stdClass());

	expect($bmcs['ok'])->toBeTrue()
		->and($bmcs['bmcs'][0]['credential_ref'])->toBe('vault:redfish:ro')
		->and((string) json_encode($bmcs))->not->toContain('password')
		->and($racks['ok'])->toBeTrue()
		->and($racks['racks'][0]['name'])->toBe('Rack-1')
		->and($hlth['ok'])->toBeTrue()
		->and($hlth['bmcs'][0]['reachability'])->toBe('reachable');

	// Verify setup.php hook calls $registrar->register($id, $def) and DB-backed permission callback.
	$registrar = new class() {
		/** @var array<string, string> */
		public array $categories = [];
		/** @var array<string, array<string, mixed>> */
		public array $abilities = [];

		public function registerCategory(string $id, string $label): void {
			$this->categories[$id] = $label;
		}

		/** @param array<string, mixed> $def */
		public function register(string $id, array $def): void {
			$this->abilities[$id] = $def;
		}
	};

	redfish_abilities_register($registrar);
	expect($registrar->categories)->toHaveKey('redfish', 'Redfish')
		->and(array_keys($registrar->abilities))->toBe([Abilities::LIST_BMCS, Abilities::GET_RACK_POWER, Abilities::GET_HEALTH])
		->and(($registrar->abilities[Abilities::LIST_BMCS]['permission'])(42))->toBeTrue()
		->and(($registrar->abilities[Abilities::LIST_BMCS]['permission'])(0))->toBeFalse();

	// Verify RealmRepository denies when user has no grant.
	$realms = new RealmRepository(false);
	expect($realms->userHasAnyFile(42, ['redfish_bmcs.php']))->toBeTrue()
		->and($realms->userHasAnyFile(42, ['unknown.php']))->toBeFalse()
		->and($realms->userHasAnyFile(0, ['redfish_health.php']))->toBeFalse();
	$GLOBALS['__redfish_db_rows']['FROM user_auth_realm'] = [];
	expect($realms->userHasAnyFile(42, ['redfish_health.php']))->toBeFalse();
});

it('registers canonical Doctor checks, debug info, CasC provider, and API resources cleanly', function () {
	$GLOBALS['__redfish_db_rows'] = [
		'SELECT value FROM settings WHERE name = ?' => [[(string) Schema::VERSION]],
		'FROM plugin_redfish_run ORDER BY poller_id' => [['poller_id' => 1, 'run_started' => 1_799_999_900, 'run_duration' => 10.0, 'bmcs' => 1, 'collected' => 1, 'skipped' => 0, 'requests' => 5, 'workers' => 4, 'stopped_early' => '']],
		'FROM plugin_redfish_bmc ORDER BY name' => [['id' => 1, 'name' => 'srv01', 'address' => '10.0.1.20', 'port' => 443, 'protocols' => 'redfish,ipmi', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled']],
		'FROM plugin_redfish_rack ORDER BY site_id, name' => [],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [['bmc_id' => 1, 'reachability' => 'reachable', 'last_success' => 1_800_000_000]],
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

	$byId = [];

	foreach ($checks as $check) {
		expect($check)->toHaveKeys(['id', 'plugin', 'category', 'title', 'status', 'message', 'details', 'remediation'])
			->and($check['plugin'])->toBe('redfish')
			->and(str_starts_with($check['id'], 'redfish.'))->toBeTrue()
			->and(in_array($check['status'], ['ok', 'warn', 'fail', 'info'], true))->toBeTrue()
			->and($check['message'])->not->toBe('');
		$byId[$check['id']] = $check;
	}

	expect($byId['redfish.schema']['status'])->toBe('ok')
		->and($byId['redfish.vault_key']['status'])->toBe('fail')
		->and($byId['redfish.collector_runs']['status'])->toBe('ok')
		->and($byId['redfish.bmc_security']['status'])->toBe('warn')
		->and($byId['redfish.rack_power']['status'])->toBe('ok');

	// Verify doctor_register_checks does NOT echo $context and returns evaluated list.
	$hookChecks = redfish_doctor_register_checks(['mode' => 'run', 'plugin' => 'redfish']);
	expect(array_is_list($hookChecks))->toBeTrue()
		->and($hookChecks)->toHaveCount(5)
		->and($hookChecks)->not->toHaveKey('mode')
		->and($hookChecks)->not->toHaveKey('plugin');

	// Verify doctor_register_debug_info does NOT echo $context and returns canonical list with scalar fields.
	$debug = redfish_doctor_register_debug_info(['plugin' => 'redfish']);
	expect(array_is_list($debug))->toBeTrue()
		->and($debug)->toHaveCount(1)
		->and($debug[0]['id'])->toBe('redfish.overview')
		->and($debug[0]['plugin'])->toBe('redfish')
		->and($debug[0]['fields']['schema_version_expected']['value'])->toBe(Schema::VERSION)
		->and($debug[0]['fields']['bmcs_total']['value'])->toBe(1)
		->and((string) json_encode($debug))->not->toContain('/nonexistent/redfish-vault.key')
		->and((string) json_encode($debug))->not->toContain('password');

	foreach ($debug[0]['fields'] as $field) {
		expect($field['value'] === null || is_scalar($field['value']))->toBeTrue();
	}

	// Verify casc_register_providers registers 1-arg descriptor on registrar object and unwraps otherwise.
	$cascRegistrar = new class() {
		/** @var array<string, mixed>|null */
		public ?array $registered = null;

		/** @param array<string, mixed> $descriptor */
		public function register(array $descriptor): void {
			$this->registered = $descriptor;
		}
	};

	redfish_casc_register_providers($cascRegistrar);
	expect($cascRegistrar->registered)->toBeArray()
		->and($cascRegistrar->registered['slug'])->toBe('redfish')
		->and($cascRegistrar->registered['schemaVersion'])->toBe('1.0')
		->and(is_callable($cascRegistrar->registered['export']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['validate']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['plan']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['apply']))->toBeTrue();

	$unwrapped = redfish_casc_register_providers([]);
	expect($unwrapped['slug'])->toBe('redfish')
		->and($unwrapped['schemaVersion'])->toBe('1.0');

	// Verify api_register_resources returns canonical plain list.
	$resources = redfish_api_register_resources([]);
	expect(array_is_list($resources))->toBeTrue()
		->and(array_column($resources, 'collection'))->toBe([
			ApiResources::COLLECTION_BMCS,
			ApiResources::COLLECTION_RACKS,
			ApiResources::COLLECTION_HEALTH,
		]);

	foreach ($resources as $res) {
		expect($res['plugin'])->toBe('redfish')
			->and($res['realm_read'])->toBe('redfish_bmcs.php')
			->and(preg_match('/^[a-z][A-Za-z0-9]{2,63}$/', $res['collection']))->toBe(1)
			->and(array_keys($res['handlers']))->toBe(['list', 'get']);
	}

	$bmcRes  = $resources[0];
	$rackRes = $resources[1];
	$hlthRes = $resources[2];

	$bmcList = $bmcRes['handlers']['list']([]);
	expect($bmcList)->toHaveCount(1)
		->and($bmcList[0]['name'])->toBe('redfishBmcs/1')
		->and((string) json_encode($bmcList))->not->toContain('password');
	expect($bmcRes['handlers']['get']('1', [])['id'] ?? null)->toBe(1);
	expect($bmcRes['handlers']['get']('999', []))->toBeNull();

	expect($rackRes['handlers']['list']([]))->toBe([]);
	expect($rackRes['handlers']['get']('999', []))->toBeNull();

	$hlthList = $hlthRes['handlers']['list']([]);
	expect($hlthList)->toHaveCount(1)
		->and($hlthList[0]['name'])->toBe('redfishHealth/1');
	expect($hlthRes['handlers']['get']('1', [])['bmc_id'] ?? null)->toBe(1);
	expect($hlthRes['handlers']['get']('999', []))->toBeNull();

	// Verify denied path on ApiResources directly.
	$denied = ApiResources::descriptors(
		static fn (int $u): bool => false,
		static fn (int $u): bool => false,
		static fn (int $u): DeviceScope => new DeviceScope(static fn (int $h): bool => false, static fn (int $h): bool => false),
		static fn (): BmcRepository => new BmcRepository(false, $clock),
		static fn (): CredentialRepository => new CredentialRepository(false, $clock),
		static fn (): RackRepository => new RackRepository(false, $clock),
		static fn (): HealthRepository => new HealthRepository(false),
		static fn (): NodeCacheRepository => new NodeCacheRepository(false),
		static fn (): FreshnessEvaluator => FreshnessEvaluator::fromSettings($clock, $settings),
	);
	expect($denied[0]['handlers']['list'](['user_id' => 9]))->toBe([])
		->and($denied[0]['handlers']['get']('1', ['user_id' => 9]))->toBeNull()
		->and($denied[1]['handlers']['list'](['user_id' => 9]))->toBe([])
		->and($denied[1]['handlers']['get']('1', ['user_id' => 9]))->toBeNull()
		->and($denied[2]['handlers']['list'](['user_id' => 9]))->toBe([])
		->and($denied[2]['handlers']['get']('1', ['user_id' => 9]))->toBeNull();
});
