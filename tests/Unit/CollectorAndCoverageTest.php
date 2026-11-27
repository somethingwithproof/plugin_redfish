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
use Somethingwithproof\Redfish\CactiObjectRepository;
use Somethingwithproof\Redfish\CascProvider;
use Somethingwithproof\Redfish\Cli;
use Somethingwithproof\Redfish\ClientFactory;
use Somethingwithproof\Redfish\Collector;
use Somethingwithproof\Redfish\CredentialRepository;
use Somethingwithproof\Redfish\CredentialStore;
use Somethingwithproof\Redfish\DeviceScope;
use Somethingwithproof\Redfish\DiscoveryRepository;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\FreshnessEvaluator;
use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\HttpResponse;
use Somethingwithproof\Redfish\IpmiClient;
use Somethingwithproof\Redfish\LeaseRepository;
use Somethingwithproof\Redfish\MaintRepository;
use Somethingwithproof\Redfish\MaintWindow;
use Somethingwithproof\Redfish\NodeCacheRepository;
use Somethingwithproof\Redfish\Rack;
use Somethingwithproof\Redfish\RackRepository;
use Somethingwithproof\Redfish\Reachability;
use Somethingwithproof\Redfish\ReadingRepository;
use Somethingwithproof\Redfish\Resolver;
use Somethingwithproof\Redfish\Schema;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\Settings;
use Somethingwithproof\Redfish\TemplateInstaller;
use Somethingwithproof\Redfish\TlsMode;
use Somethingwithproof\Redfish\TransportError;
use Somethingwithproof\Redfish\Vault;
use Somethingwithproof\Redfish\VaultKey;

beforeEach(function () {
	$GLOBALS['__redfish_db_rows'] = [];
	$GLOBALS['__redfish_db_exec'] = [];
	$GLOBALS['__redfish_db_fail'] = [];
	$GLOBALS['__redfish_log']     = [];
	$GLOBALS['__redfish_options'] = [];
});

/**
 * Create a temporary valid vault key file in a 0700 directory.
 */
function redfish_test_key_file(): string {
	$base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
	$dir  = $base . '/redfish-unit-key-' . getmypid();

	if (!is_dir($dir)) {
		mkdir($dir, 0700, true);
	}

	chmod($dir, 0700);
	$path = $dir . '/vault.key';
	file_put_contents($path, bin2hex(random_bytes(32)));
	chmod($path, 0600);

	return $path;
}

it('collects end-to-end with Collector using FixtureTransport, handling session login, discovery, stale session sweep, backoff, and IPMI fallback', function () {
	$keyFile  = redfish_test_key_file();
	$key      = VaultKey::fromFile($keyFile);
	$entry    = (new Vault($key))->encrypt(new Secret('ro-pass-123'), 1, CredentialRepository::PASSWORD);
	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow(['redfish_vault_key_file' => $keyFile]);

	$transport = new FixtureTransport('dell-subsystem');
	$transport->sessions['/redfish/v1/SessionService/Sessions/old'] = 'old-tok';

	$resolver = new class implements Resolver {
		public function resolve(string $host): array {
			return ['192.0.2.10'];
		}
	};

	$factory = new ClientFactory($resolver, 5.0, 20.0, 2097152, false, static fn (Endpoint $e): FixtureTransport => $transport);

	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc WHERE poller_id = ?' => [
			['id' => 1, 'name' => 'idrac-01', 'address' => '192.0.2.10', 'port' => 443, 'protocols' => 'redfish,ipmi', 'poller_id' => 1, 'host_id' => 10, 'credential_id' => 1, 'tls_mode' => 'system', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => 'on', 'admin_state' => 'enabled'],
		],
		'FROM plugin_redfish_lease WHERE bmc_id = ?' => [[1]],
		'SELECT acquired_at FROM plugin_redfish_lease' => [[0]],
		'FROM plugin_redfish_health WHERE bmc_id = ?' => [
			['bmc_id' => 1, 'reachability' => 'never', 'session_pending' => 'on', 'session_uri' => '/redfish/v1/SessionService/Sessions/old', 'sessions_lost' => 0, 'failures' => 0, 'backoff_until' => 0],
		],
		'FROM plugin_redfish_credential WHERE id = ?' => [
			['id' => 1, 'name' => 'ro', 'username' => 'monitor', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1],
		],
		'FROM plugin_redfish_secret WHERE credential_id = ?' => [
			['nonce' => $entry->nonce, 'ciphertext' => $entry->ciphertext, 'tag' => $entry->tag, 'key_id' => $entry->keyId],
		],
		'FROM plugin_redfish_discovery WHERE bmc_id = ?' => [],
	];

	$ipmi = new IpmiClient(runner: static fn (array $args, array $env, int $timeout): array => [
		'exit'   => 0,
		'stdout' => "Inlet Temp | 23 | degrees C | ok\nFan1 | 5200 | RPM | ok\nPwr Consumption | 280 | Watts | ok\n",
		'stderr' => '',
	]);

	$collector = new Collector(
		new BmcRepository(false, $clock),
		new CredentialStore(new CredentialRepository(false, $clock), $keyFile),
		new LeaseRepository(false, $clock),
		new HealthRepository(false),
		new DiscoveryRepository(false),
		new ReadingRepository(false),
		$factory,
		$settings,
		$clock,
		'p1w1',
		$ipmi
	);

	expect(Collector::dueAfter(300))->toBe(270)
		->and(Collector::backoff(300, 1, 0))->toBe(300)
		->and(Collector::backoff(300, 2, 0))->toBe(600)
		->and(Collector::backoff(300, 10, 9000))->toBe(Collector::BACKOFF_CAP);

	$run = $collector->run(1, 240, true);
	expect($run['bmcs'])->toBe(1)
		->and($run['collected'])->toBe(1)
		->and($run['stopped_early'])->toBeFalse()
		->and($transport->sessions)->toBe([]);

	// Session budget exceeded stops login immediately.
	$bmc = new Bmc(1, 'idrac-01', '192.0.2.10', 443, 1, 10, 1, TlsMode::System, '', '', true, AdminState::Enabled, true);
	expect($collector->collect($bmc, new BmcHealth(1, sessionsLost: Collector::SESSION_BUDGET)))->toBe(0);

	// Auth-failed hold skips without contacting BMC.
	expect($collector->collect($bmc, new BmcHealth(1, reachability: Reachability::AuthFailed, authCredentialId: 1, authRevision: 1)))->toBeNull();

	// Redfish unavailable triggers IPMI fallback cleanly.
	$transport->override['GET /redfish/v1/'] = new TransportError(FailureClass::ConnectRefused, 'refused');
	$ipmiReqs                                = $collector->collect($bmc, new BmcHealth(1));
	expect($ipmiReqs)->toBe(1);

	// Basic fallback when session POST returns 404.
	unset($transport->override['GET /redfish/v1/']);
	$transport->override['POST /redfish/v1/SessionService/Sessions'] = new HttpResponse(404, [], '{}');
	$basicReqs                                                        = $collector->collect($bmc, new BmcHealth(1));
	expect($basicReqs)->toBeGreaterThan(0);

	// Lost session POST response increments sessionsLost.
	$transport->override['POST /redfish/v1/SessionService/Sessions'] = new TransportError(FailureClass::Timeout, 'timed out', true);
	$collector->collect($bmc, new BmcHealth(1));

	// Credential unavailable path.
	$GLOBALS['__redfish_db_rows']['FROM plugin_redfish_credential WHERE id = ?'] = [];
	expect($collector->collect($bmc, new BmcHealth(1)))->toBe(0);

	@unlink($keyFile);
});

it('exercises TemplateInstaller query file install, symlink refusal, modified-file refusal, and uninstall', function () {
	$pluginRoot = dirname(__DIR__, 2);
	$cactiTmp   = sys_get_temp_dir() . '/redfish-cacti-tmp-' . getmypid();
	@mkdir($cactiTmp . '/resource/script_server', 0755, true);
	@mkdir($cactiTmp . '/lib', 0755, true);

	file_put_contents($cactiTmp . '/lib/utility.php', "<?php\n");
	file_put_contents(
		$cactiTmp . '/lib/import.php',
		"<?php\nif (!function_exists('import_xml_data')) {\n\tfunction import_xml_data(\$xml, \$preview = false, \$profile = 1) {\n\t\treturn \$GLOBALS['__redfish_import_result'] ?? ['host_template' => [['result' => 'success']]];\n\t}\n}\n"
	);

	$objects   = new CactiObjectRepository(false);
	$installer = new TemplateInstaller($pluginRoot, $cactiTmp, $objects);

	// All object hashes return > 0 so present() is true.
	$GLOBALS['__redfish_db_rows'] = [
		'WHERE hash = ?' => [[1]],
		'FROM snmp_query_graph' => [[1]],
		'FROM data_source_profiles' => [[1]],
	];

	expect($installer->queryFileProblems())->toHaveCount(3)
		->and($installer->install())->toBeTrue()
		->and($installer->queryFileProblems())->toBe([])
		->and($installer->isCurrent())->toBeTrue();

	// Modifying a query file prevents overwrite on reinstall.
	file_put_contents($cactiTmp . '/resource/script_server/redfish_fans.xml', '<custom/>');
	expect($installer->install())->toBeFalse()
		->and($installer->failure())->toContain('was not written by this plugin');

	// Symlink target is refused.
	unlink($cactiTmp . '/resource/script_server/redfish_fans.xml');
	symlink($pluginRoot . '/INFO', $cactiTmp . '/resource/script_server/redfish_fans.xml');
	expect($installer->install())->toBeFalse()
		->and($installer->failure())->toContain('symbolic link');
	unlink($cactiTmp . '/resource/script_server/redfish_fans.xml');

	// Uninstall removes unchanged plugin-written files.
	$installer->uninstall();
	expect(file_exists($cactiTmp . '/resource/script_server/redfish_temperatures.xml'))->toBeFalse();

	// Import failure paths.
	$GLOBALS['__redfish_import_result'] = [];
	expect($installer->install())->toBeFalse()
		->and($installer->failure())->toContain('returned nothing');

	$GLOBALS['__redfish_import_result'] = ['graph_templates' => [['result' => 'fail']]];
	expect($installer->install())->toBeFalse()
		->and($installer->failure())->toContain('failed to import');

	// Cleanup temp files.
	foreach (glob($cactiTmp . '/resource/script_server/*') ?: [] as $f) {
		@unlink($f);
	}
	@unlink($cactiTmp . '/lib/utility.php');
	@unlink($cactiTmp . '/lib/import.php');
	@rmdir($cactiTmp . '/resource/script_server');
	@rmdir($cactiTmp . '/resource');
	@rmdir($cactiTmp . '/lib');
	@rmdir($cactiTmp);
});

it('exercises RackRepository, CredentialRepository, and BmcRepository CRUD and audit methods', function () {
	$clock = new FixedClock(1_800_000_000);
	$racks = new RackRepository(false, $clock);

	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_rack WHERE id = ?' => [['id' => 1, 'site_id' => 2, 'name' => 'R1', 'power_budget_watts' => 4000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack WHERE site_id = ? AND name = ?' => [['id' => 1, 'site_id' => 2, 'name' => 'R1', 'power_budget_watts' => 4000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack ORDER BY' => [['id' => 1, 'site_id' => 2, 'name' => 'R1', 'power_budget_watts' => 4000.0, 'min_coverage_pct' => 80.0, 'notes' => '']],
		'FROM plugin_redfish_rack_member WHERE rack_id = ?' => [['host_id' => 10, 'rack_id' => 1, 'bmc_id' => 5, 'unit_position' => 2]],
		'SELECT rack_id FROM plugin_redfish_rack_member' => [[1]],
		'FROM host WHERE location !=' => [['id' => 10, 'site_id' => 2, 'location' => 'RowA']],
	];

	expect($racks->find(0))->toBeNull()
		->and($racks->find(1)?->name)->toBe('R1')
		->and($racks->findByName(2, 'R1')?->id)->toBe(1)
		->and($racks->all())->toHaveCount(1)
		->and($racks->save(new Rack(0, 2, 'R2', 3000.0, 80.0), 1))->toBe(1)
		->and($racks->save(new Rack(1, 2, 'R1', 4500.0, 80.0), 1))->toBe(1)
		->and($racks->assignHost(0, 10, 5, 2, 1))->toBeFalse()
		->and($racks->assignHost(1, 10, 5, 2, 1))->toBeTrue()
		->and($racks->removeHost(0, 1))->toBeFalse()
		->and($racks->removeHost(10, 1))->toBeTrue()
		->and($racks->removeHosts([]))->toBe(0)
		->and($racks->removeHosts([10, 11]))->toBe(1)
		->and($racks->seedCandidates())->toBe([['site_id' => 2, 'location' => 'RowA', 'host_ids' => [10]]])
		->and($racks->delete(1, 1))->toBeTrue();

	// CredentialRepository CRUD
	$creds = new CredentialRepository(false, $clock);
	$entry = (new Vault(VaultKey::fromBytes(random_bytes(32))))->encrypt(new Secret('pw'), 1, CredentialRepository::PASSWORD);
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_credential WHERE id = ?' => [['id' => 1, 'name' => 'ro', 'username' => 'mon', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1]],
		'FROM plugin_redfish_credential WHERE name = ?' => [[0]],
		'FROM plugin_redfish_secret WHERE credential_id = ?' => [['nonce' => $entry->nonce, 'ciphertext' => $entry->ciphertext, 'tag' => $entry->tag, 'key_id' => $entry->keyId]],
		'SELECT COUNT(*) FROM plugin_redfish_bmc WHERE credential_id = ?' => [[0]],
	];

	expect($creds->nameTaken('ro', 1))->toBeFalse()
		->and($creds->save(0, 'new', 'mon', 1))->toBe(1)
		->and($creds->save(1, 'ro', 'mon', 1))->toBe(1)
		->and($creds->save(1, 'ro2', 'mon2', 1))->toBe(1)
		->and($creds->storeSecret(1, CredentialRepository::PASSWORD, $entry, 1))->toBeTrue()
		->and($creds->loadSecret(1, CredentialRepository::PASSWORD))->not->toBeNull()
		->and($creds->delete(1, 1))->toBeTrue();

	// BmcRepository CRUD + export + addressTakenBy
	$bmcs = new BmcRepository(false, $clock);
	$bmcRow = ['id' => 1, 'name' => 'srv01', 'address' => '10.0.0.1', 'port' => 443, 'protocols' => 'redfish', 'poller_id' => 1, 'host_id' => 5, 'credential_id' => 1, 'tls_mode' => 'unverified', 'ca_file' => '', 'pin_sha256' => '', 'basic_fallback' => '', 'admin_state' => 'enabled'];
	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc WHERE id = ?' => [$bmcRow],
		'SELECT id FROM plugin_redfish_bmc WHERE address = ?' => [[1]],
		'SELECT id FROM plugin_redfish_bmc WHERE host_id = ?' => [],
		'SELECT id FROM plugin_redfish_bmc WHERE host_id IN' => [['id' => 1]],
		'SELECT host_id FROM plugin_redfish_bmc WHERE credential_id = ?' => [['host_id' => 5]],
		'SELECT COUNT(*) FROM host WHERE id = ?' => [[1]],
		'FROM plugin_redfish_bmc ORDER BY name' => [$bmcRow],
		'FROM plugin_redfish_credential ORDER BY name' => [['id' => 1, 'name' => 'ro', 'username' => 'mon', 'revision' => 1, 'created_at' => 1, 'updated_at' => 1]],
		'FROM plugin_redfish_audit' => [['action' => 'create', 'fields' => 'name', 'user_id' => 1, 'changed_at' => 1]],
	];

	$bmcNew = new Bmc(0, 'srv02', '10.0.0.2', 443, 1, 0, 1, TlsMode::Unverified, '', '', false, AdminState::Enabled);
	$bmcUpd = new Bmc(1, 'srv01-renamed', '10.0.0.1', 443, 1, 5, 1, TlsMode::System, '', '', true, AdminState::Disabled);
	$scopeAll  = new DeviceScope(static fn (int $h): bool => true, static fn (int $h): bool => true);
	$scopeNone = new DeviceScope(static fn (int $h): bool => false, static fn (int $h): bool => true);

	expect($bmcs->save($bmcNew, 1))->toBe(1)
		->and($bmcs->save($bmcUpd, 1))->toBe(1)
		->and($bmcs->import([$bmcNew], 1))->toBeTrue()
		->and($bmcs->mapHost(1, 5, 1))->toBeTrue()
		->and($bmcs->acceptMembers(1, 1))->toBeTrue()
		->and($bmcs->unmapHosts([5]))->toBe(1)
		->and($bmcs->credentialHosts(1))->toBe([5])
		->and($bmcs->hostExists(5))->toBeTrue()
		->and($bmcs->addressTakenBy('10.0.0.1', 443, 0, $scopeAll))->toBe('srv01')
		->and($bmcs->addressTakenBy('10.0.0.1', 443, 0, $scopeNone))->toBe('another BMC')
		->and($bmcs->export($scopeAll))->toHaveCount(1);
});

it('exercises Cli error branches, apply confirmation success, and CascProvider apply/registration', function () {
	$clock    = new FixedClock(1_800_000_000);
	$settings = Settings::fromRow([]);
	$bmcs     = new BmcRepository(false, $clock);
	$creds    = new CredentialRepository(false, $clock);
	$racks    = new RackRepository(false, $clock);

	$cli = new Cli(
		$bmcs,
		$creds,
		$racks,
		new HealthRepository(false),
		new NodeCacheRepository(false),
		FreshnessEvaluator::fromSettings($clock, $settings),
		new MaintWindow(new MaintRepository(false), $clock),
		$settings,
		$clock
	);

	expect($cli->run(['--help'])['exit'])->toBe(0)
		->and($cli->run(['bogus'])['exit'])->toBe(1)
		->and($cli->run(['export', '--format=toml'])['exit'])->toBe(1)
		->and($cli->run(['health', '--format=xml'])['exit'])->toBe(1)
		->and($cli->run(['validate'], '')['exit'])->toBe(1)
		->and($cli->run(['validate', '--file=/nonexistent/casc.yaml'])['exit'])->toBe(1)
		->and($cli->run(['validate', '--format=xml'], '<broken')['exit'])->toBe(1)
		->and($cli->run(['plan'], '')['exit'])->toBe(1);

	// Apply with valid plan hash succeeds cleanly.
	$yaml = "provider: redfish\nschema_version: 1\ncredentials:\n  - name: ro\n    username: monitor\nbmcs:\n  - name: srv1\n    address: 10.0.0.5\n    port: 443\n    poller_id: 1\n    host_id: 0\n    credential_ref: vault:redfish:ro\n    tls_mode: system\n    admin_state: enabled\nracks:\n  - site_id: 1\n    name: RackA\n    power_budget_watts: 3000\n    min_coverage_pct: 80\n";
	$plan = $cli->run(['plan', '--format=yaml'], $yaml);
	expect($plan['exit'])->toBe(0);
	preg_match('/PLAN_HASH=([0-9a-f]{64})/', $plan['stdout'], $m);
	$hash = $m[1] ?? '';

	$applied = $cli->run(['apply', '--format=yaml', '--confirm=' . $hash], $yaml);
	expect($applied['exit'])->toBe(0)
		->and($applied['stdout'])->toContain('Applied ');

	// CascProvider::registration callbacks.
	$reg = CascProvider::registration(
		static fn (): BmcRepository => $bmcs,
		static fn (): CredentialRepository => $creds,
		static fn (): RackRepository => $racks
	);
	expect($reg['id'])->toBe('redfish')
		->and($reg['export']('yaml'))->toContain('provider: redfish')
		->and($reg['validate']($yaml, 'yaml'))->toBe([])
		->and($reg['plan']($yaml, 'yaml')['hash'])->toBe($hash);
});

it('exercises Schema full migration v0 through v3 including v2 host_owner deduplication and v3 rack tables', function () {
	if (!function_exists('api_plugin_db_table_create')) {
		function api_plugin_db_table_create($plugin, $table, $data) {
			$GLOBALS['__redfish_created_tables'][] = $table;
		}
	}

	$GLOBALS['__redfish_created_tables'] = [];
	$GLOBALS['__redfish_db_rows']        = [
		'SELECT value FROM settings WHERE name = ?' => [['0']],
		'FROM information_schema.TABLES' => [[1]],
		'FROM information_schema.STATISTICS' => [[1]],
		'FROM information_schema.COLUMNS' => [[1]],
		'SELECT DISTINCT b.id, b.host_id FROM plugin_redfish_bmc' => [
			['id' => 2, 'host_id' => 50],
		],
	];

	$schema = new Schema(false);
	expect($schema->migrate(3))->toBeTrue()
		->and($GLOBALS['__redfish_created_tables'])->toContain('plugin_redfish_bmc', 'plugin_redfish_rack', 'plugin_redfish_rack_member');
});
