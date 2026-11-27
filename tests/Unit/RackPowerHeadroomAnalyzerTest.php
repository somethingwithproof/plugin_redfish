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
use Somethingwithproof\Redfish\RackPowerHeadroomAnalyzer;

it('computes oversubscription ratio, empirical headroom, and safe oversubscription capacity per Fan et al.', function () {
	// Four servers rated at 1000W nameplate each (4000W total) on a 3500W PDU,
	// with empirical peaks around 700W each (2800W aggregate, 0.70 activity factor).
	$nodes = [
		['id' => 'srv-1', 'observed_watts' => 650.0, 'peak_watts' => 700.0, 'nameplate_watts' => 1000.0],
		['id' => 'srv-2', 'observed_watts' => 680.0, 'peak_watts' => 700.0, 'nameplate_watts' => 1000.0],
		['id' => 'srv-3', 'observed_watts' => 620.0, 'peak_watts' => 700.0, 'nameplate_watts' => 1000.0],
		['id' => 'srv-4', 'observed_watts' => 640.0, 'peak_watts' => 700.0, 'nameplate_watts' => 1000.0],
	];

	$report = RackPowerHeadroomAnalyzer::analyzeNodes(3500.0, $nodes, 0.10);

	// Usable PDU = 3500 * 0.90 = 3150W; safe nameplate = 3150 / 0.70 = 4500W.
	expect($report['node_count'])->toBe(4)
		->and($report['total_nameplate_watts'])->toBe(4000.0)
		->and($report['aggregate_observed_watts'])->toBe(2590.0)
		->and($report['aggregate_peak_watts'])->toBe(2800.0)
		->and($report['oversubscription_ratio'])->toBe(1.1429)
		->and($report['empirical_peak_to_nameplate_ratio'])->toBe(0.7)
		->and($report['peak_to_nameplate_headroom_watts'])->toBe(1200.0)
		->and($report['peak_to_nameplate_headroom_pct'])->toBe(30.0)
		->and($report['pdu_headroom_watts'])->toBe(700.0)
		->and($report['pdu_utilization_pct'])->toBe(80.0)
		->and($report['safe_oversubscription_capacity_watts'])->toBe(4500.0)
		->and($report['safe_oversubscription_ratio'])->toBe(1.2857)
		->and($report['additional_safe_nameplate_watts'])->toBe(500.0)
		->and($report['risk_level'])->toBe('caution');
});

it('flags critical risk when empirical peak breaches usable PDU budget and floors activity factor on idle racks', function () {
	$idle = RackPowerHeadroomAnalyzer::analyzeNodes(5000.0, [
		['observed_watts' => 50.0, 'nameplate_watts' => 1000.0],
	], 0.10, 0.25);

	expect($idle['effective_activity_factor'])->toBe(0.25)
		->and($idle['safe_oversubscription_capacity_watts'])->toBe(18000.0)
		->and($idle['risk_level'])->toBe('ok');

	$critical = RackPowerHeadroomAnalyzer::analyzeNodes(2000.0, [
		['observed_watts' => 950.0, 'nameplate_watts' => 1000.0],
		['observed_watts' => 900.0, 'nameplate_watts' => 1000.0],
	], 0.10);

	expect($critical['aggregate_peak_watts'])->toBe(1850.0)
		->and($critical['pdu_utilization_pct'])->toBe(92.5)
		->and($critical['risk_level'])->toBe('critical');
});

it('handles empty node sets cleanly', function () {
	$empty = RackPowerHeadroomAnalyzer::analyzeNodes(4000.0, []);

	expect($empty['node_count'])->toBe(0)
		->and($empty['oversubscription_ratio'])->toBe(0.0)
		->and($empty['safe_oversubscription_capacity_watts'])->toBe(3600.0)
		->and($empty['risk_level'])->toBe('ok');
});

it('computes statistical multiplexing diversity from time-aligned server power series', function () {
	// Individual peaks: 600 + 600 + 600 = 1800W, but peaks occur at different steps -> coincident peak 1200W.
	$series = [
		[600.0, 300.0, 300.0],
		[300.0, 600.0, 300.0],
		[300.0, 300.0, 600.0],
	];

	$mux = RackPowerHeadroomAnalyzer::coincidentPeak($series);

	expect($mux['server_count'])->toBe(3)
		->and($mux['sample_count'])->toBe(3)
		->and($mux['non_coincident_peak_sum_watts'])->toBe(1800.0)
		->and($mux['coincident_peak_watts'])->toBe(1200.0)
		->and($mux['diversity_factor'])->toBe(0.6667)
		->and($mux['multiplexing_headroom_watts'])->toBe(600.0)
		->and($mux['multiplexing_savings_pct'])->toBe(33.33);

	expect(RackPowerHeadroomAnalyzer::coincidentPeak([])['server_count'])->toBe(0);
});

it('analyzes a Rack directly from cached BMC NodeCache snapshots', function () {
	$clock     = new FixedClock(1_800_000_100);
	$freshness = new FreshnessEvaluator($clock, 300, 2, 4);
	$rack      = new Rack(1, 1, 'RowB-04', 2500.0, 80.0);

	$c1 = new NodeCache(10, 1_800_000_000, 1_800_000_050, [], [
		Member::NODE => [
			'power_watts'          => ['value' => 420.0, 'unit' => 'W', 'observed_at' => 1_800_000_050],
			'power_capacity_watts' => ['value' => 750.0, 'unit' => 'W', 'observed_at' => 1_800_000_050],
		],
	]);
	$c2 = new NodeCache(11, 1_800_000_000, 1_800_000_050, [], [
		Member::NODE => [
			'power_watts' => ['value' => 380.0, 'unit' => 'W', 'observed_at' => 1_800_000_050],
		],
	]);
	// Stale cache is ignored.
	$c3 = new NodeCache(12, 1_800_000_000, 1_799_000_000, [], [
		Member::NODE => [
			'power_watts' => ['value' => 500.0, 'unit' => 'W', 'observed_at' => 1_799_000_000],
		],
	]);

	$result = RackPowerHeadroomAnalyzer::analyzeRack($rack, [$c1, $c2, $c3, null], $freshness, 700.0);

	expect($result)->not->toBeNull()
		->and($result['rack_name'])->toBe('RowB-04')
		->and($result['members_total'])->toBe(4)
		->and($result['members_analyzed'])->toBe(2)
		->and($result['aggregate_observed_watts'])->toBe(800.0)
		->and($result['total_nameplate_watts'])->toBe(1450.0)
		->and($result['risk_level'])->toBe('ok');

	// Rack without power budget returns null.
	expect(RackPowerHeadroomAnalyzer::analyzeRack(new Rack(2, 1, 'NoBudget', null, 80.0), [$c1], $freshness, 700.0))->toBeNull();
	// No reporting nodes with nameplate returns null.
	expect(RackPowerHeadroomAnalyzer::analyzeRack($rack, [$c2], $freshness, 0.0))->toBeNull();
});

it('rejects invalid capacities, margins, and malformed series inputs', function () {
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(0.0, []))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(1000.0, [], 1.0))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(1000.0, [], 0.1, 0.0))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(1000.0, [['observed_watts' => -1.0, 'nameplate_watts' => 500.0]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(1000.0, [['observed_watts' => 100.0, 'nameplate_watts' => 0.0]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::analyzeNodes(1000.0, [['observed_watts' => 200.0, 'peak_watts' => 150.0, 'nameplate_watts' => 500.0]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::coincidentPeak([[]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::coincidentPeak([[100.0, 200.0], [100.0]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => RackPowerHeadroomAnalyzer::coincidentPeak([[-5.0]]))->toThrow(\InvalidArgumentException::class);
});
