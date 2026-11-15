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

use Somethingwithproof\Redfish\ClientError;
use Somethingwithproof\Redfish\Collector;
use Somethingwithproof\Redfish\Discoverer;
use Somethingwithproof\Redfish\DiscoveryPlan;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\GuardedTransport;
use Somethingwithproof\Redfish\HttpResponse;
use Somethingwithproof\Redfish\Normalizer;
use Somethingwithproof\Redfish\Observation;
use Somethingwithproof\Redfish\OperationGuard;
use Somethingwithproof\Redfish\Redactor;
use Somethingwithproof\Redfish\RedfishClient;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\Target;
use Somethingwithproof\Redfish\TlsMode;

/**
 * Discover a fixture and read every target once, as the collector does.
 *
 * @return array{0: DiscoveryPlan, 1: Observation, 2: FixtureTransport}
 */
function redfish_observe(string $fixture, ?callable $tweak = null): array {
	$fake = new FixtureTransport($fixture);

	if ($tweak !== null) {
		$tweak($fake);
	}

	$endpoint = new Endpoint('bmc.test', 443, TlsMode::System);
	$client   = new RedfishClient(new GuardedTransport($fake, new OperationGuard(), 256), $endpoint, new Redactor(), new FixedClock(1_800_000_000));
	$disc     = new Discoverer($client, $endpoint);
	$root     = $client->serviceRoot();
	$client->guard()->allowSessions($disc->sessionsPath($root));
	$client->login('monitor', new Secret('x'));
	$plan = $disc->discover($root);
	$obs  = new Observation();

	foreach ($plan->targets as $target) {
		$obs->merge(Normalizer::normalize($target, $client->get($target->path), $plan->family));
	}

	$obs->merge(Normalizer::node($obs, $plan->primaryChassis));

	return [$plan, $obs, $fake];
}

function redfish_value(Observation $obs, string $member, string $metric): array {
	$r = $obs->find($member, $metric);

	return $r === null ? ['missing'] : [$r->value, $r->unit, $r->source, $r->rule];
}

it('discovers the subsystem model and picks a source per reading on the Dell shape', function () {
	[$plan, $obs, $fake] = redfish_observe('dell-subsystem');
	$c = 'System.Embedded.1';

	expect($plan->family)->toBe('dell')
		->and($plan->vendor)->toBe('Dell')
		->and($plan->redfishVersion)->toBe('1.20.1')
		->and($plan->features)->toBe(['ExpandQuery', 'SelectQuery', 'FilterQuery', 'OnlyMemberQuery'])
		->and($plan->sessionsPath)->toBe('/redfish/v1/SessionService/Sessions')
		->and($plan->primaryChassis)->toBe($c)
		->and($plan->generation())->toBe('subsystem')
		// Every reading is covered by the subsystem model: no legacy target.
		->and(array_values(array_unique(array_map(fn (Target $t) => $t->kind, $plan->targets))))->toBe(['environment', 'thermal_metrics', 'fan', 'psu', 'psu_metrics'])
		->and(redfish_value($obs, "$c/chassis/$c", 'power_watts'))->toBe([312.0, 'W', 'redfish.environment', 'environment.power'])
		->and(redfish_value($obs, "$c/chassis/$c", 'inlet_celsius'))->toBe([22.0, 'Cel', 'redfish.sensor', 'summary.intake'])
		->and(redfish_value($obs, "$c/temperature/CPU1Temp", 'temperature_celsius'))->toBe([45.0, 'Cel', 'redfish.sensor', 'thermal_metrics.reading'])
		->and(redfish_value($obs, "$c/fan/Fan.Embedded.1A", 'fan_speed'))->toBe([8040.0, 'RPM', 'redfish.sensor', 'fan.sensor_rpm'])
		->and(redfish_value($obs, "$c/fan/Fan.Embedded.1A:secondary", 'fan_speed'))->toBe([7920.0, 'RPM', 'redfish.sensor', 'fan.sensor_rpm'])
		->and($obs->members["$c/fan/Fan.Embedded.2A"]->absent)->toBeTrue()
		->and($obs->find("$c/fan/Fan.Embedded.2A", 'fan_speed'))->toBeNull()
		->and(redfish_value($obs, "$c/fan/Fan.Embedded.2A", 'health_code')[0])->toBeNull()
		->and(redfish_value($obs, "$c/psu/PSU.Slot.1", 'psu_input_watts'))->toBe([160.0, 'W', 'redfish.sensor', 'psu_metrics.input'])
		->and(redfish_value($obs, "$c/psu/PSU.Slot.2", 'health_code')[0])->toBe(2.0)
		->and(redfish_value($obs, 'node', 'power_watts')[0])->toBe(312.0)
		->and(redfish_value($obs, 'node', 'inlet_celsius')[0])->toBe(22.0)
		// Built from members (worst is the Critical supply), not the
		// ThermalSubsystem rollup of Warning.
		->and(redfish_value($obs, 'node', 'health_code')[0])->toBe(2.0)
		->and(redfish_value($obs, 'node', 'members_absent')[0])->toBe(1.0)
		// The legacy Power resource, which says 999 W, is never read.
		->and(in_array('GET /redfish/v1/Chassis/System.Embedded.1/Power', $fake->log(), true))->toBeFalse()
		->and(implode(' ', $fake->log()))->not->toContain('$');
});

it('falls back to legacy Power and Thermal and ignores cross-host links', function () {
	[$plan, $obs] = redfish_observe('legacy');

	expect($plan->generation())->toBe('legacy')
		->and($plan->linksRefused)->toBe(2)
		->and(count($plan->chassis))->toBe(1)
		->and(redfish_value($obs, '1/chassis/1', 'power_watts'))->toBe([250.0, 'W', 'redfish.legacy', 'legacy.power_control'])
		// Keys come from names, not list positions.
		->and(redfish_value($obs, '1/temperature/01-Inlet Ambient', 'temperature_celsius')[0])->toBe(21.0)
		->and(array_key_exists('1/temperature/2', $obs->members))->toBeFalse()
		->and(redfish_value($obs, '1/fan/Fan 1', 'fan_speed'))->toBe([35.0, '%', 'redfish.legacy', 'legacy.fan'])
		->and($obs->members['1/fan/Fan 2']->absent)->toBeTrue()
		->and(redfish_value($obs, '1/fan/Fan 3', 'fan_speed'))->toBe([null, '', 'redfish.legacy', 'legacy.fan_unit_unknown'])
		->and(redfish_value($obs, '1/psu/Power Supply 1', 'psu_output_watts')[0])->toBe(120.0)
		->and(redfish_value($obs, '1/psu/Power Supply 2', 'health_code')[0])->toBeNull()
		// No intake summary on this BMC, so inlet is U, never another temperature.
		->and(redfish_value($obs, 'node', 'inlet_celsius')[0])->toBeNull()
		->and(redfish_value($obs, 'node', 'power_watts')[0])->toBe(250.0);
});

it('keeps an unconfirmed intake out of inlet and reads fan units by the Lenovo rules', function () {
	[$plan, $obs] = redfish_observe('lenovo');

	expect($plan->family)->toBe('lenovo')
		->and(redfish_value($obs, '1/chassis/1', 'inlet_celsius'))->toBe([null, 'Cel', 'redfish.sensor', 'summary.intake_unconfirmed'])
		->and(redfish_value($obs, '1/summary/Ambient_Temp', 'temperature_celsius')[0])->toBe(23.0)
		// EnvironmentMetrics.TemperatureCelsius is never used for inlet or ambient.
		->and($obs->find('1/chassis/1', 'ambient_celsius'))->toBeNull()
		->and(redfish_value($obs, 'node', 'inlet_celsius')[0])->toBeNull()
		->and(redfish_value($obs, '1/fan/Fan_1A_Tach', 'fan_speed'))->toBe([8611.0, 'RPM', 'redfish.sensor', 'fan.rpm_in_reading'])
		->and(redfish_value($obs, '1/fan/Fan_2A_Tach', 'fan_speed'))->toBe([null, '', 'redfish.sensor', 'fan.family_deviation']);
});

it('ends discovery on a throttled BMC instead of mistaking it for one without resources', function () {
	try {
		redfish_observe('dell-subsystem', function (FixtureTransport $fake) {
			$fake->override['/redfish/v1/Chassis/System.Embedded.1/ThermalSubsystem'] = new HttpResponse(429, ['retry-after' => '60'], '');
		});
		$class = null;
	} catch (ClientError $e) {
		$class = $e->class;
	}

	expect($class)->toBe(FailureClass::Throttled);
});

it('round-trips a plan through its stored row', function () {
	[$plan] = redfish_observe('dell-subsystem');
	$row    = ['fingerprint' => $plan->fingerprint, 'redfish_version' => $plan->redfishVersion, 'vendor' => $plan->vendor, 'product' => $plan->product, 'family' => $plan->family, 'features' => implode(',', $plan->features), 'sessions_path' => $plan->sessionsPath, 'plan' => $plan->planJson()];
	$back   = DiscoveryPlan::fromRow($row);

	expect($back?->targets)->toEqual($plan->targets)
		->and($back?->primaryChassis)->toBe($plan->primaryChassis)
		->and(DiscoveryPlan::fromRow(['plan' => '{"targets":[{"kind":"fan","path":"https://x/"}]}'] + $row))->toBeNull()
		->and(DiscoveryPlan::fromRow(['plan' => 'not json'] + $row))->toBeNull();
});

it('maps Status to health codes and presence', function () {
	expect(Normalizer::status(['State' => 'Enabled', 'Health' => 'OK']))->toBe(['code' => 0, 'state' => 'Enabled', 'absent' => false])
		->and(Normalizer::status(['Health' => 'Warning'])['code'])->toBe(1)
		->and(Normalizer::status(['State' => 'Enabled', 'Health' => 'Critical'])['code'])->toBe(2)
		->and(Normalizer::status(['State' => 'Enabled', 'Health' => null])['code'])->toBeNull()
		->and(Normalizer::status(['State' => 'Enabled'])['code'])->toBeNull()
		->and(Normalizer::status(['State' => 'Absent', 'Health' => 'OK']))->toBe(['code' => null, 'state' => 'Absent', 'absent' => true])
		->and(Normalizer::status(['State' => 'Degraded', 'Health' => 'OK'])['code'])->toBe(1)
		->and(Normalizer::status(['State' => 'Degraded', 'Health' => 'Critical'])['code'])->toBe(2)
		->and(Normalizer::status(['State' => 'StandbyOffline', 'Health' => 'OK'])['code'])->toBeNull()
		->and(Normalizer::status('OK')['code'])->toBeNull()
		->and(Normalizer::number('12'))->toBeNull()
		->and(Normalizer::number(true))->toBeNull()
		->and(Normalizer::number(0))->toBe(0.0);
});

it('takes the fan unit from the sensor, then the excerpt shape', function (mixed $excerpt, string $sensor, string $family, array $expected) {
	expect(Normalizer::fanSpeed($excerpt, $sensor, $family))->toBe($expected);
})->with([
	'sensor percent'   => [['Reading' => 40], 'percent', 'dell', [40.0, '%', 'fan.sensor_percent']],
	'sensor rpm'       => [['SpeedRPM' => 8040], 'rpm', 'dell', [8040.0, 'RPM', 'fan.sensor_rpm']],
	'sensor unknown'   => [['Reading' => 40], 'unknown', 'dell', [null, '', 'fan.sensor_unknown']],
	'percent by spec'  => [['Reading' => 40], '', 'other', [40.0, '%', 'fan.percent']],
	'lenovo shape'     => [['Reading' => 8611, 'SpeedRPM' => 8611], '', 'lenovo', [8611.0, 'RPM', 'fan.rpm_in_reading']],
	'deviation'        => [['Reading' => 40], '', 'lenovo', [null, '', 'fan.family_deviation']],
	'rpm only'         => [['SpeedRPM' => 5000], '', 'dell', [5000.0, 'RPM', 'fan.rpm']],
	'nothing'          => [[], '', 'dell', [null, '', 'fan.none']],
	'not an excerpt'   => ['x', '', 'dell', [null, '', 'fan.none']],
]);

it('backs off from the interval, doubling to the cap, at least Retry-After', function () {
	expect(Collector::backoff(300, 1, 0))->toBe(300)
		->and(Collector::backoff(300, 2, 0))->toBe(600)
		->and(Collector::backoff(300, 3, 0))->toBe(1200)
		->and(Collector::backoff(300, 5, 0))->toBe(3600)
		->and(Collector::backoff(300, 60, 0))->toBe(3600)
		->and(Collector::backoff(300, 1, 900))->toBe(900)
		->and(Collector::backoff(300, 1, 99999))->toBe(3600)
		->and(Collector::dueAfter(300))->toBe(270)
		->and(Collector::dueAfter(60))->toBe(54);
});
