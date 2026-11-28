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
use Somethingwithproof\Redfish\ThermalDeltaAnalyzer;

it('computes inlet temperature distributions and normal delta-T scores on a healthy rack', function () {
	$samples = [
		['id' => 'srv-1', 'inlet_c' => 21.0, 'exhaust_c' => 34.0],
		['id' => 'srv-2', 'inlet_c' => 22.0, 'exhaust_c' => 35.5],
		['id' => 'srv-3', 'inlet_c' => 22.5, 'exhaust_c' => 36.0],
		['id' => 'srv-4', 'inlet_c' => 23.0, 'exhaust_c' => 36.5],
	];

	$report = ThermalDeltaAnalyzer::analyze($samples);

	expect($report['sample_count'])->toBe(4)
		->and($report['delta_sample_count'])->toBe(4)
		->and($report['inlet_distribution']['min_c'])->toBe(21.0)
		->and($report['inlet_distribution']['max_c'])->toBe(23.0)
		->and($report['inlet_distribution']['mean_c'])->toBe(22.13)
		->and($report['inlet_distribution']['median_c'])->toBe(22.25)
		->and($report['inlet_distribution']['ashrae_recommended_pct'])->toBe(100.0)
		->and($report['inlet_distribution']['above_allowable_count'])->toBe(0)
		->and($report['delta_distribution']['median_c'])->toBe(13.5)
		->and($report['max_anomaly_score'])->toBe(0.0)
		->and($report['fleet_severity'])->toBe('normal');
});

it('detects airflow restriction (high delta-T) and hot-aisle recirculation (low/negative delta-T) before OEM limits', function () {
	$samples = [
		['id' => 'healthy-1', 'inlet_c' => 22.0, 'exhaust_c' => 35.0],
		['id' => 'healthy-2', 'inlet_c' => 22.5, 'exhaust_c' => 35.5],
		['id' => 'healthy-3', 'inlet_c' => 22.0, 'exhaust_c' => 35.5],
		['id' => 'healthy-4', 'inlet_c' => 22.5, 'exhaust_c' => 36.0],
		// Restricted airflow: inlet is still well below static 35C OEM warning, but delta-T is 31C.
		['id' => 'clogged-fan', 'inlet_c' => 23.0, 'exhaust_c' => 54.0],
		// Reversed/recirculated airflow: exhaust <= inlet.
		['id' => 'recirc', 'inlet_c' => 30.0, 'exhaust_c' => 29.5],
		// Mild low delta-T recirculation + high allowable exceedance.
		['id' => 'hot-inlet', 'inlet_c' => 34.0, 'exhaust_c' => 36.0],
	];

	$report = ThermalDeltaAnalyzer::analyze($samples);
	$byId   = [];

	foreach ($report['nodes'] as $node) {
		$byId[$node['id']] = $node;
	}

	expect($byId['clogged-fan']['delta_c'])->toBe(31.0)
		->and($byId['clogged-fan']['reasons'])->toContain('high_delta_t_airflow_restriction', 'peer_outlier_high_delta_t')
		->and($byId['clogged-fan']['severity'])->toBe('critical')
		->and($byId['recirc']['reasons'])->toContain('inlet_above_recommended', 'non_positive_delta_t', 'peer_outlier_low_delta_t')
		->and($byId['recirc']['severity'])->toBe('critical')
		->and($byId['hot-inlet']['reasons'])->toContain('inlet_above_allowable', 'low_delta_t_recirculation')
		->and($report['inlet_distribution']['above_allowable_count'])->toBe(1)
		->and($report['fleet_severity'])->toBe('critical');
});

it('handles empty samples, inlet-only samples, and extracts sensors from NodeCache snapshots', function () {
	$empty = ThermalDeltaAnalyzer::analyze([]);

	expect($empty['sample_count'])->toBe(0)
		->and($empty['inlet_distribution']['mean_c'])->toBeNull()
		->and($empty['delta_distribution']['median_c'])->toBeNull()
		->and($empty['fleet_severity'])->toBe('normal');

	// Inlet-only sample with slightly elevated inlet.
	$single = ThermalDeltaAnalyzer::analyze([
		['id' => 'edge-1', 'inlet_c' => 31.0],
	]);

	expect($single['delta_sample_count'])->toBe(0)
		->and($single['nodes'][0]['severity'])->toBe('elevated')
		->and($single['nodes'][0]['reasons'])->toBe(['inlet_above_recommended']);

	$clock     = new FixedClock(1_800_000_100);
	$freshness = new FreshnessEvaluator($clock, 300, 2, 4);

	$cache1 = new NodeCache(1, 1_800_000_000, 1_800_000_050, [], [
		Member::NODE => [
			'inlet_temp_c'   => ['value' => 23.0, 'unit' => 'Cel', 'observed_at' => 1_800_000_050],
			'exhaust_temp_c' => ['value' => 37.0, 'unit' => 'Cel', 'observed_at' => 1_800_000_050],
		],
	]);
	$cache2 = new NodeCache(2, 1_800_000_000, 1_800_000_050, [
		['key' => '1/temperature/Inlet', 'kind' => 'temperature', 'name' => 'Ambient Inlet', 'chassis' => '1', 'presence' => 'present', 'expected' => true],
		['key' => '1/temperature/Exhaust', 'kind' => 'temperature', 'name' => 'System Exhaust', 'chassis' => '1', 'presence' => 'present', 'expected' => true],
	], [
		'1/temperature/Inlet'   => ['temp_c' => ['value' => 24.0, 'unit' => 'Cel', 'observed_at' => 1_800_000_050]],
		'1/temperature/Exhaust' => ['temp_c' => ['value' => 40.0, 'unit' => 'Cel', 'observed_at' => 1_800_000_050]],
	]);

	$extracted = ThermalDeltaAnalyzer::extractSamples([$cache1, $cache2, null], $freshness);

	expect($extracted)->toBe([
		['id' => 'bmc:1', 'inlet_c' => 23.0, 'exhaust_c' => 37.0],
		['id' => 'bmc:2', 'inlet_c' => 24.0, 'exhaust_c' => 40.0],
	]);
});

it('rejects invalid thermal bounds and out-of-range sensor readings', function () {
	expect(fn () => ThermalDeltaAnalyzer::analyze([], 20.0, 10.0))->toThrow(\InvalidArgumentException::class);
	expect(fn () => ThermalDeltaAnalyzer::analyze([], 4.0, 25.0, 15.0))->toThrow(\InvalidArgumentException::class);
	expect(fn () => ThermalDeltaAnalyzer::analyze([['id' => 'bad', 'inlet_c' => 200.0]]))->toThrow(\InvalidArgumentException::class);
	expect(fn () => ThermalDeltaAnalyzer::analyze([['id' => 'bad', 'inlet_c' => 22.0, 'exhaust_c' => -99.0]]))->toThrow(\InvalidArgumentException::class);
});
