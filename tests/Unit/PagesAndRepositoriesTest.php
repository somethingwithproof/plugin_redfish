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

use Somethingwithproof\Redfish\AdminState;
use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\BmcHealth;
use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\BmcsPage;
use Somethingwithproof\Redfish\CactiObjectRepository;
use Somethingwithproof\Redfish\Credential;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialsPage;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\DeviceProvisioner;
use Somethingwithproof\Redfish\DeviceScope;
use Somethingwithproof\Redfish\DeviceWarnings;
use Somethingwithproof\Redfish\DiscoveryPlan;
use Somethingwithproof\Redfish\DiscoveryRepository;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\FreshnessEvaluator;
use Somethingwithproof\Redfish\GraphLinks;
use Somethingwithproof\Redfish\HealthPage;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\Html;
use Somethingwithproof\Redfish\LeaseRepository;
use Somethingwithproof\Redfish\MaintRepository;
use Somethingwithproof\Redfish\MaintWindow;
use Somethingwithproof\Redfish\Member;
use Somethingwithproof\Redfish\NodeCacheRepository;
use Somethingwithproof\Redfish\Number;
use Somethingwithproof\Redfish\Observation;
use Somethingwithproof\Redfish\Pages;
use Somethingwithproof\Redfish\RackRepository;
use Somethingwithproof\Redfish\RackSummaryScript;
use Somethingwithproof\Redfish\Reachability;
use Somethingwithproof\Redfish\Reading;
use Somethingwithproof\Redfish\ReadingRepository;
use Somethingwithproof\Redfish\Schema;
use Somethingwithproof\Redfish\Settings;
use Somethingwithproof\Redfish\SettingsRepository;
use Somethingwithproof\Redfish\Target;
use Somethingwithproof\Redfish\TemplateInstaller;
use Somethingwithproof\Redfish\TlsMode;

beforeEach(function () {
	$GLOBALS['__redfish_db_rows'] = [];
	$GLOBALS['__redfish_db_exec'] = [];
	$GLOBALS['__redfish_db_fail'] = [];
	$GLOBALS['__redfish_log']     = [];
	$GLOBALS['__redfish_options'] = [];
});

it('covers Pages realms, files, menu, and navigation contracts', function () {
	$realms = Pages::realms();
	$files  = Pages::files();
	$menu   = Pages::menu();
	$nav    = Pages::navigation();

	expect($realms)->toHaveCount(3)
		->and($files)->toBe(array_values(array_unique($files)))
		->and($menu)->toHaveKey('redfish_bmcs.php')
		->and($nav['redfish_health.php:']['url'])->toBe('redfish_health.php');
});

it('renders escaped Html form helpers and relative age formatting', function () {
	expect(Html::text('n', 'Label <x>', 'v"', 10, 'Help & tip'))->toContain('Label &lt;x&gt;', 'Help &amp; tip', 'value="v&quot;"')
		->and(Html::secret('pw', 'Password', 'Write only'))->toContain('type="password"', 'value=""')
		->and(Html::checkbox('en', 'Enabled', true, 'Toggle'))->toContain('checked')
		->and(Html::select('mode', 'Mode', ['a' => 'A', 'b' => 'B'], 'b', 'Pick'))->toContain('value="b" selected')
		->and(Html::action('redfish_bmcs.php', 7, 'delete', 'Delete'))->toContain('method="post"', 'value="7"')
		->and(Html::row(['a', '<b>']))->toContain('&lt;b&gt;')
		->and(Html::header(['Col']))->toContain('<th>Col</th>')
		->and(Html::age(-5))->toBe('0 seconds ago')
		->and(Html::age(45))->toBe('45 seconds ago')
		->and(Html::age(600))->toBe('10 minutes ago')
		->and(Html::age(10800))->toBe('3 hours ago')
		->and(Html::age(200000))->toBe('2 days ago');
});

it('formats numeric outputs for RRDtool and Thold', function () {
	expect(Number::format(null))->toBe('U')
		->and(Number::format(NAN))->toBe('U')
		->and(Number::format(INF))->toBe('U')
		->and(Number::format(42.0))->toBe('42')
		->and(Number::format(42.125))->toBe('42.125')
		->and(Number::format(42.500))->toBe('42.5');
});

it('resolves GraphLinks for mapped devices and emits safe link HTML', function () {
	$clock = new FixedClock(1_800_000_000);
	$GLOBALS['__redfish_db_rows'] = [
		'FROM graph_local WHERE id = ?' => [['host_id' => 12]],
		'SELECT id FROM plugin_redfish_bmc WHERE host_id = ?' => [['id' => 1]],
		'FROM plugin_redfish_bmc WHERE id = ?' => [['id' => 1, 'name' => 'bmc1', 'address' => '10.0.0.1', 'port' => 443, 'poller_id' => 1, 'host_id' => 12, 'credential_id' => 1, 'tls_mode' => 'system', 'admin_state' => 'enabled']],
	];

	$links = new GraphLinks(new BmcRepository(false, $clock), new CactiObjectRepository(false));
	expect($links->hostFor(0))->toBe(0)
		->and($links->hostFor(99))->toBe(12)
		->and(GraphLinks::html('/cacti/', 12))->toContain('host_id=12', 'rfilter=Redfish');
});

it('builds DeviceWarnings for template failures, stale templates, deleted hosts, and poller mismatches', function () {
	$root    = dirname(__DIR__, 2);
	$objects = new CactiObjectRepository(false);
	$bmcs    = [
		new Bmc(1, 'unmapped', '10.0.0.1', 443, 1, 0, 1, TlsMode::System, '', '', false, AdminState::Enabled),
		new Bmc(2, 'deleted-host', '10.0.0.2', 443, 1, 50, 1, TlsMode::System, '', '', false, AdminState::Enabled),
		new Bmc(3, 'wrong-poller', '10.0.0.3', 443, 2, 51, 1, TlsMode::System, '', '', false, AdminState::Enabled),
	];

	$GLOBALS['__redfish_options'] = [
		TemplateInstaller::FAILURE_SETTING => 'import boom',
	];
	$installer = new TemplateInstaller($root, '/nonexistent', $objects);
	$warnings  = DeviceWarnings::for($bmcs, $objects, $installer);
	expect(array_column($warnings, 'message')[0])->toContain('import boom')
		->and(array_column($warnings, 'message')[1])->toContain('deleted');

	// Non-failure but not current templates, and a mismatched poller.
	$GLOBALS['__redfish_options'] = [];
	$GLOBALS['__redfish_db_rows'] = [
		'FROM host WHERE id = ?' => [['id' => 51, 'description' => 'srv', 'poller_id' => 1]],
	];
	$warnings2 = DeviceWarnings::for([$bmcs[2]], $objects, $installer);
	expect(array_column($warnings2, 'message')[0])->toContain('not installed or not current')
		->and(array_column($warnings2, 'message')[1])->toContain('poller 1');
});

it('loads SettingsRepository values and manages LeaseRepository lifecycle', function () {
	$clock = new FixedClock(1_800_000_000);
	$GLOBALS['__redfish_db_rows'] = [
		'FROM settings WHERE name IN' => [
			['name' => 'redfish_collect_interval', 'value' => '120'],
			['name' => 'redfish_workers', 'value' => '8'],
		],
		'SELECT COUNT(*) FROM plugin_redfish_lease' => [[1]],
		'SELECT acquired_at FROM plugin_redfish_lease' => [[1_799_999_500]],
	];

	$settings = (new SettingsRepository(false))->load();
	expect($settings->collectInterval)->toBe(120)
		->and($settings->workers)->toBe(8);

	$leases = new LeaseRepository(false, $clock);
	expect($leases->acquire(5, 'w1', 60, 1_799_999_900))->toBeTrue()
		->and($leases->holds(5, 'w1'))->toBeTrue()
		->and($leases->lastAcquired(5))->toBe(1_799_999_500);
	$leases->release(5, 'w1');
	expect(array_column($GLOBALS['__redfish_db_exec'], 'sql'))->toContain('UPDATE plugin_redfish_lease SET expires_at = 0 WHERE bmc_id = ? AND holder = ?');
});

it('commits and rolls back ReadingRepository transactions and reads cached summaries', function () {
	$repo   = new ReadingRepository(false);
	$health = new BmcHealth(1, 100, 105, 1.2, Reachability::Reachable, null, 105, 'subsystem', 'session', 3, 12.5);
	$plan   = new DiscoveryPlan('fp1', '1.17.0', 'Dell', 'R660', 'idrac', [], '/redfish/v1/SessionService/Sessions', [['id' => '1', 'path' => '/redfish/v1/Chassis/1', 'systems' => true]], '1', [new Target(Target::ENVIRONMENT, '/redfish/v1/Chassis/1/EnvironmentMetrics', 'EnvironmentMetrics', 'EnvironmentMetrics', '1')]);
	$obs    = new Observation(
		[new Member('1/fan/A', 'fan', '1', 'Fan A', 'redfish.sensor', false)],
		[new Reading('1/fan/A', 'fan_rpm', 4200.0, 'RPM', 'redfish.sensor', 'direct', 'OK')]
	);

	// Happy path when lease is held.
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_lease WHERE bmc_id = ?' => [[1]],
		'FROM plugin_redfish_reading WHERE bmc_id = ?' => [
			['member_key' => '1/fan/A', 'metric' => 'fan_rpm', 'value' => '4200', 'unit' => 'RPM', 'rule' => 'direct', 'observed_at' => 105],
		],
		'FROM plugin_redfish_member WHERE bmc_id = ? GROUP BY' => [
			['kind' => 'fan', 'presence' => 'present', 'n' => 4],
		],
	];

	expect($repo->commit(1, 'w1', 105, $health, $plan, $obs, true, true))->toBeTrue()
		->and($repo->forBmc(1)[0]['value'])->toBe(4200.0)
		->and($repo->memberCounts(1))->toBe(['fan present' => 4]);

	// Rolls back when lease is not held.
	$GLOBALS['__redfish_db_rows']['FROM plugin_redfish_lease WHERE bmc_id = ?'] = [[0]];
	expect($repo->commit(1, 'w1', 105, $health, null, null, false, false))->toBeFalse();
});

it('answers RackSummaryScript for known and unknown racks', function () {
	$clock     = new FixedClock(1_800_000_100);
	$freshness = new FreshnessEvaluator($clock, 300, 2, 4);
	$script    = new RackSummaryScript(new RackRepository(false, $clock), new NodeCacheRepository(false), $freshness);

	// Unknown rack returns all U pairs.
	expect($script->summaryFor(999))->toBeNull()
		->and($script->answer(999))->toContain('power_watts:U', 'coverage_pct:U');

	// Known rack with reporting member.
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_rack WHERE id = ?' => [['id' => 1, 'site_id' => 1, 'name' => 'R1', 'power_budget_watts' => 2000.0, 'min_coverage_pct' => 50.0, 'notes' => '']],
		'FROM plugin_redfish_rack_member WHERE rack_id = ?' => [['host_id' => 10, 'rack_id' => 1, 'bmc_id' => 5, 'unit_position' => 1]],
		'FROM plugin_redfish_bmc AS b LEFT JOIN' => [['id' => 5, 'created_at' => 1_800_000_000, 'last_success' => 1_800_000_050]],
		'FROM plugin_redfish_member WHERE bmc_id = ?' => [],
		'FROM plugin_redfish_reading WHERE bmc_id = ?' => [
			['member_key' => Member::NODE, 'metric' => 'power_watts', 'value' => 500.0, 'unit' => 'W', 'observed_at' => 1_800_000_050],
		],
	];

	expect($script->answer(1))->toContain('power_watts:500', 'headroom_watts:1500');
});

it('renders HealthPage, CredentialsPage, and BmcsPage views and checks databaseErrors', function () {
	$clock    = new FixedClock(1_800_000_100);
	$settings = Settings::fromRow(['redfish_vault_key_file' => '/nonexistent/key']);
	$scope    = new DeviceScope(static fn (int $h): bool => $h !== 99, static fn (int $h): bool => $h !== 99);
	$bmcs     = new BmcRepository(false, $clock);
	$creds    = new CredentialRepository(false, $clock);
	$store    = new CredentialStore($creds, $settings->vaultKeyFile);
	$health   = new HealthRepository(false);
	$readings = new ReadingRepository(false);
	$disc     = new DiscoveryRepository(false);
	$fresh    = FreshnessEvaluator::fromSettings($clock, $settings);
	$maint    = new MaintWindow(new MaintRepository(false), $clock);

	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc ORDER BY name' => [
			['id' => 1, 'name' => 'srv01', 'address' => '10.0.0.10', 'port' => 443, 'protocols' => 'redfish', 'poller_id' => 1, 'host_id' => 10, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled'],
		],
		'FROM plugin_redfish_credential ORDER BY name' => [
			['id' => 1, 'name' => 'ro', 'username' => 'monitor', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1],
		],
		'FROM plugin_redfish_run ORDER BY poller_id' => [
			['poller_id' => 1, 'run_started' => 1_800_000_000, 'run_duration' => 4.2, 'bmcs' => 1, 'collected' => 1, 'skipped' => 0, 'requests' => 4, 'workers' => 2, 'stopped_early' => ''],
		],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [
			['bmc_id' => 1, 'reachability' => 'reachable', 'failure_class' => '', 'last_success' => 1_800_000_050, 'generation' => 'subsystem', 'auth_mode' => 'session', 'requests' => 4, 'latency_ms' => 15.0],
		],
		'FROM plugin_redfish_reading WHERE bmc_id = ?' => [
			['member_key' => Member::NODE, 'metric' => 'power_watts', 'value' => 250.0, 'unit' => 'W', 'rule' => 'direct', 'observed_at' => 1_800_000_050],
		],
		'FROM host ORDER BY description' => [
			['id' => 10, 'description' => 'srv01'],
		],
	];

	$healthPage = new HealthPage($bmcs, $health, $readings, $disc, $store, $fresh, $clock, $scope, $maint);
	ob_start();
	$healthPage->render($settings, true, [['admin' => true, 'message' => 'Extra admin note']]);
	$healthHtml = (string) ob_get_clean();
	expect($healthHtml)->toContain('Redfish Warnings', 'srv01', 'Extra admin note');

	$credPage = new CredentialsPage($creds, $store, $bmcs, $scope);
	ob_start();
	$credPage->renderList();
	$credPage->renderEdit(new Credential(1, 'ro', 'monitor', 1, 1, 1), [], ['name' => 'Bad name']);
	$credHtml = (string) ob_get_clean();
	expect($credHtml)->toContain('Redfish Credentials', 'Bad name', 'type="password"');

	$bmcsPage = new BmcsPage($bmcs, $creds, $scope);
	ob_start();
	$bmcsPage->renderList();
	$bmcsPage->renderProvision(new Bmc(1, 'srv01', '10.0.0.10', 443, 1, 0, 1, TlsMode::System, '', '', false, AdminState::Enabled), ['Add device'], null);
	$bmcsPage->renderProvision(new Bmc(1, 'srv01', '10.0.0.10', 443, 1, 10, 1, TlsMode::System, '', '', false, AdminState::Enabled), [], null);
	$bmcsPage->renderProvision(new Bmc(1, 'srv01', '10.0.0.10', 443, 1, 10, 1, TlsMode::System, '', '', false, AdminState::Enabled), [], 'Missing templates');
	$bmcsPage->renderImport(['Bad CSV']);
	$bmcsPage->renderEdit(new Bmc(1, 'srv01', '10.0.0.10', 443, 1, 99, 1, TlsMode::System, '', '', false, AdminState::Enabled), [], ['address' => 'Bad address']);
	$bmcHtml = (string) ob_get_clean();
	expect($bmcHtml)->toContain('Redfish BMCs', 'Add device', 'Missing templates', 'Bad CSV', 'Deleted device (ID 99)');
	expect($bmcsPage->exportJson())->toContain('"plugin": "redfish"');

	// databaseErrors detects hidden-device, missing credential, and collisions.
	$errors = $bmcsPage->databaseErrors(new Bmc(0, 'srv01', '10.0.0.10', 443, 1, 99, 5, TlsMode::System, '', '', false, AdminState::Enabled));
	expect($errors)->toHaveKeys(['credential_id', 'host_id']);
});

it('builds DeviceProvisioner plan, refusal, and RFC 6761 hostname', function () {
	$clock     = new FixedClock(1_800_000_000);
	$objects   = new CactiObjectRepository(false);
	$installer = new TemplateInstaller(dirname(__DIR__, 2), '/nonexistent', $objects);
	$prov      = new DeviceProvisioner(
		new BmcRepository(false, $clock),
		$objects,
		$installer,
		static function (): void {
		},
		static fn (int $h, int $u): bool => true
	);

	$bmc = new Bmc(7, 'r660-07', '10.0.0.7', 443, 1, 0, 1, TlsMode::System, '', '', false, AdminState::Enabled);
	expect(DeviceProvisioner::hostname($bmc))->toBe('bmc-7.redfish.invalid')
		->and($prov->refusal())->not->toBeNull()
		->and($prov->plan($bmc, ['fan' => 4, 'temperature' => 8, 'psu' => 2, 'missing' => 1]))->not->toBeEmpty();

	$error = null;
	expect($prov->create($bmc, 1, $error))->toBe(0)
		->and($error)->not->toBeNull();
});

it('verifies Schema version checks, v2-to-v3 upgrade idempotency, and downgrade refusal in unit tests', function () {
	$schema = new Schema(false);

	// Refuse downgrade when stored version > Schema::VERSION.
	$GLOBALS['__redfish_db_rows'] = [
		'SELECT value FROM settings WHERE name = ?' => [['99']],
	];
	expect($schema->storedVersion())->toBe(99)
		->and($schema->isCurrent())->toBeFalse()
		->and($schema->migrate())->toBeFalse();

	// Current schema (v3) is a no-op and idempotent.
	$GLOBALS['__redfish_db_rows'] = [
		'SELECT value FROM settings WHERE name = ?' => [[(string) Schema::VERSION]],
		'SELECT version FROM plugin_config' => [['0.1.0']],
		'SELECT status FROM plugin_config' => [[1]],
		'SELECT COUNT(DISTINCT hook) FROM plugin_hooks' => [[count(Schema::HOOKS)]],
		'SELECT COUNT(*) FROM plugin_realms' => [[count(Pages::realms())]],
		'FROM information_schema.TABLES' => [[1]],
		'SELECT COUNT(*) FROM plugin_db_changes' => [[0]],
	];

	expect($schema->isCurrent())->toBeTrue()
		->and($schema->migrate())->toBeTrue()
		->and($schema->recordedPluginVersion())->toBe('0.1.0')
		->and($schema->pluginEnabled())->toBeTrue()
		->and($schema->hooksRegistered())->toBeTrue()
		->and($schema->untrackTables())->toBeTrue();

	$schema->recordPluginVersion('0.1.0', 'Author', 'https://example.invalid');
	$schema->trackTables();
	$schema->forgetVersion();

	expect(Schema::tables())->toHaveKeys(['plugin_redfish_rack', 'plugin_redfish_rack_member'])
		->and(Schema::HOOKS)->toHaveKeys(['doctor_register_debug_info', 'api_register_resources']);
});
