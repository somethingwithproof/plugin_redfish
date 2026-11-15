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

use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\BmcHealth;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\Freshness;
use Somethingwithproof\Redfish\FreshnessEvaluator;
use Somethingwithproof\Redfish\HealthWarnings;
use Somethingwithproof\Redfish\Reachability;
use Somethingwithproof\Redfish\Settings;

function redfish_bmc(array $over = []): Bmc {
	return Bmc::fromRow($over + ['id' => '1', 'name' => 'r660', 'address' => '10.0.0.5', 'port' => '443', 'credential_id' => '3', 'tls_mode' => 'system', 'admin_state' => 'enabled']);
}

function redfish_warnings(Bmc $bmc, BmcHealth $h, bool $credential = true, ?string $key = null, array $runs = []): string {
	return implode("\n", array_map(fn (array $w) => ($w['admin'] ? '[admin] ' : '') . $w['message'], HealthWarnings::for([$bmc], [$bmc->id => $h], [$bmc->id => $credential], $key, $runs, Settings::fromRow([]), 1_800_000_000)));
}

it('warns for each risky state', function (array $bmc, array $health, string $expected) {
	expect(redfish_warnings(redfish_bmc($bmc), BmcHealth::fromRow($health + ['bmc_id' => '1'])))->toContain($expected);
})->with([
	'unverified TLS' => [['tls_mode' => 'unverified'], [], 'TLS verification is off'],
	'basic in use'   => [[], ['auth_mode' => 'basic'], 'uses Basic authentication'],
	'session left'   => [[], ['session_pending' => 'on'], 'a session may have been left open'],
	'budget'         => [[], ['sessions_lost' => '3', 'failure_class' => 'session_budget'], 'logins stopped'],
	'auth held'      => [[], ['reachability' => 'auth_failed'], '[admin] r660: the BMC rejected the credential'],
	'links refused'  => [[], ['links_refused' => '2'], '2 links in its responses'],
]);

it('warns about a missing credential, an unusable key, and an overrunning collector', function () {
	$text = redfish_warnings(redfish_bmc(), new BmcHealth(1), false, 'the vault key file does not exist', [
		['poller_id' => 1, 'run_started' => 1_800_000_000 - 2000, 'run_duration' => 300.0, 'bmcs' => 4, 'collected' => 2, 'skipped' => 0, 'requests' => 40, 'workers' => 4, 'stopped_early' => true],
	]);

	expect($text)->toContain('[admin] r660: its credential is missing')
		->and($text)->toContain('[admin] The vault key file cannot be used')
		->and($text)->toContain('took 300 seconds, more than its budget of 240')
		->and($text)->toContain('ran out of time')
		->and($text)->toContain('last ran 33 minutes ago');
});

it('stays quiet for a healthy BMC and for a disabled one', function () {
	expect(redfish_warnings(redfish_bmc(), BmcHealth::fromRow(['bmc_id' => '1', 'reachability' => 'reachable', 'auth_mode' => 'session'])))->toBe('')
		->and(redfish_warnings(redfish_bmc(['tls_mode' => 'unverified', 'admin_state' => 'disabled']), new BmcHealth(1)))->toBe('');
});

it('pins BmcHealth::fromRow on loose values', function () {
	$h = BmcHealth::fromRow(['bmc_id' => '2', 'reachability' => 'bogus', 'failure_class' => 'nope', 'latency_ms' => 'x', 'session_pending' => null, 'failures' => '-1']);

	expect($h->reachability)->toBe(Reachability::Never)
		->and($h->failure)->toBeNull()
		->and($h->latencyMs)->toBe(0.0)
		->and($h->sessionPending)->toBeFalse()
		->and($h->failures)->toBe(0)
		->and(BmcHealth::fromRow(['failure_class' => 'tls_pin'])->failure)->toBe(FailureClass::TlsPin)
		->and(FailureClass::TlsPin->reachability())->toBe(Reachability::TlsFailed)
		->and(FailureClass::AuthRejected->needsOperator())->toBeTrue()
		->and(FailureClass::Throttled->needsOperator())->toBeFalse();
});

it('computes freshness from observation time against the interval', function () {
	$f = new FreshnessEvaluator(new FixedClock(10_000), 300, 2, 4);

	expect($f->evaluate(0))->toBe(Freshness::Never)
		->and($f->evaluate(10_000 - 600))->toBe(Freshness::Current)
		->and($f->evaluate(10_000 - 601))->toBe(Freshness::Delayed)
		->and($f->evaluate(10_000 - 1200))->toBe(Freshness::Delayed)
		->and($f->evaluate(10_000 - 1201))->toBe(Freshness::Stale)
		->and($f->evaluate(10_000 + 200))->toBe(Freshness::Current)
		->and($f->evaluate(10_000 + 400))->toBe(Freshness::Stale)
		->and(Freshness::Stale->usable())->toBeFalse();
});

it('reads settings with defaults and ranges', function () {
	$s = Settings::fromRow(['redfish_workers' => '99', 'redfish_delayed_after' => '5', 'redfish_stale_after' => '3', 'redfish_request_timeout' => 'x']);

	expect($s->workers)->toBe(32)
		->and($s->staleAfter)->toBe(6)
		->and($s->requestTimeout)->toBe(20)
		->and($s->keepTablesOnUninstall)->toBeTrue()
		->and($s->vaultKeyFile)->toBe('/etc/cacti/redfish-vault.key')
		->and(Settings::fromRow(['redfish_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse();
});
