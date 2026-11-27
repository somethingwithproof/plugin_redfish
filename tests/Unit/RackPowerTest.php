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

use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\FreshnessEvaluator;
use Somethingwithproof\Redfish\Member;
use Somethingwithproof\Redfish\NodeCache;
use Somethingwithproof\Redfish\Rack;
use Somethingwithproof\Redfish\RackPowerSummary;

function redfish_rack_node_cache(int $bmcId, ?float $watts, int $observedAt): NodeCache {
	$readings = [];

	if ($watts !== null) {
		$readings[Member::NODE]['power_watts'] = ['value' => $watts, 'unit' => 'W', 'observed_at' => $observedAt];
	}

	return new NodeCache($bmcId, $observedAt - 600, $observedAt, [], $readings);
}

it('validates Rack::fromRow bounds and optional power budget', function () {
	$rack = Rack::fromRow(['id' => '5', 'site_id' => '2', 'name' => 'RowA-R01', 'power_budget_watts' => '5000', 'min_coverage_pct' => '75', 'notes' => 'Primary row']);

	expect($rack)->not->toBeNull()
		->and($rack->id)->toBe(5)
		->and($rack->siteId)->toBe(2)
		->and($rack->powerBudgetWatts)->toBe(5000.0)
		->and($rack->minCoveragePct)->toBe(75.0)
		->and(Rack::fromRow(['id' => '0', 'name' => 'bad']))->toBeNull()
		->and(Rack::fromRow(['id' => '1', 'name' => '']))->toBeNull()
		->and(Rack::fromRow(['id' => '1', 'name' => 'no-budget', 'power_budget_watts' => null])?->powerBudgetWatts)->toBeNull();
});

it('aggregates fresh member watts, budget utilization, and headroom for Thold', function () {
	$now       = 1_800_000_000;
	$freshness = new FreshnessEvaluator(new FixedClock($now), 300, 2, 4);
	$rack      = new Rack(1, 1, 'Rack-A1', 2000.0, 75.0);

	$summary = RackPowerSummary::compute($rack, [
		redfish_rack_node_cache(1, 450.0, $now - 60),
		redfish_rack_node_cache(2, 550.0, $now - 120),
		redfish_rack_node_cache(3, 600.0, $now - 90),
		redfish_rack_node_cache(4, null, $now - 60),
	], $freshness);

	expect($summary->membersTotal)->toBe(4)
		->and($summary->membersReporting)->toBe(3)
		->and($summary->coveragePct)->toBe(75.0)
		->and($summary->powerWatts)->toBe(1600.0)
		->and($summary->utilizationPct)->toBe(80.0)
		->and($summary->headroomWatts)->toBe(400.0)
		->and($summary->formatThold())->toBe('power_watts:1600 power_budget_watts:2000 utilization_pct:80 headroom_watts:400 members_total:4 members_reporting:3 coverage_pct:75');
});

it('returns U for power_watts and headroom when coverage drops below the configured floor or readings are stale', function () {
	$now       = 1_800_000_000;
	$freshness = new FreshnessEvaluator(new FixedClock($now), 300, 2, 4);
	$rack      = new Rack(2, 1, 'Rack-B2', 3000.0, 80.0);

	$summary = RackPowerSummary::compute($rack, [
		redfish_rack_node_cache(1, 500.0, $now - 60),
		// Stale reading (> 4 * 300 = 1200s old) must not count as reporting.
		redfish_rack_node_cache(2, 500.0, $now - 2000),
		null,
	], $freshness);

	expect($summary->membersTotal)->toBe(3)
		->and($summary->membersReporting)->toBe(1)
		->and($summary->coveragePct)->toBe(33.33)
		->and($summary->powerWatts)->toBeNull()
		->and($summary->utilizationPct)->toBeNull()
		->and($summary->headroomWatts)->toBeNull()
		->and($summary->formatThold())->toContain('power_watts:U')
		->and($summary->formatThold())->toContain('headroom_watts:U');
});

it('omits headroom and utilization when no rack power budget is configured', function () {
	$now       = 1_800_000_000;
	$freshness = new FreshnessEvaluator(new FixedClock($now), 300, 2, 4);
	$rack      = new Rack(3, 1, 'Rack-C3', null, 50.0);

	$summary = RackPowerSummary::compute($rack, [
		redfish_rack_node_cache(1, 320.5, $now - 30),
	], $freshness);

	expect($summary->powerWatts)->toBe(320.5)
		->and($summary->powerBudgetWatts)->toBeNull()
		->and($summary->utilizationPct)->toBeNull()
		->and($summary->headroomWatts)->toBeNull()
		->and($summary->toArray()['over_budget'])->toBeFalse();
});
