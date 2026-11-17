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
use Somethingwithproof\Redfish\IndexKey;
use Somethingwithproof\Redfish\MemberQuery;
use Somethingwithproof\Redfish\NodeCacheRepository;
use Somethingwithproof\Redfish\SummaryScript;

const REDFISH_NOW = 1_800_000_000;

/**
 * Queue the cache of BMC 1 on device 10 for the DB stubs. Each member is
 * [key, kind, presence, readings as metric => [value, unit, age], expected].
 *
 * @param list<array{0: string, 1: string, 2: string, 3: array<string, array{0: ?float, 1: string, 2: int}>, 4?: bool}> $members
 */
function redfish_cache(array $members, int $lastSuccessAge = 60, int $registeredAge = 86400): void {
	$m = [];
	$r = [];

	foreach ($members as $member) {
		[$key, $kind, $presence, $readings] = $member;
		// Expected unless given: a member present now has been seen present.
		$expected = $member[4] ?? ($presence === 'present');
		$m[]      = ['member_key' => $key, 'kind' => $kind, 'name' => 'Name of ' . $key, 'chassis_id' => '1', 'presence' => $presence, 'expected' => $expected ? 'on' : ''];

		foreach ($readings as $metric => [$value, $unit, $age]) {
			$r[] = ['member_key' => $key, 'metric' => $metric, 'value' => $value === null ? null : (string) $value, 'unit' => $unit, 'observed_at' => (string) (REDFISH_NOW - $age)];
		}
	}

	$GLOBALS['__redfish_db_rows'] = [
		'FROM plugin_redfish_bmc AS b' => [['id' => '1', 'created_at' => (string) (REDFISH_NOW - $registeredAge), 'last_success' => $lastSuccessAge < 0 ? '0' : (string) (REDFISH_NOW - $lastSuccessAge)]],
		'FROM plugin_redfish_member'   => $m,
		'FROM plugin_redfish_reading'  => $r,
	];
}

function redfish_freshness(FixedClock $clock): FreshnessEvaluator {
	// 300 s interval: current up to 600 s, delayed up to 1,800 s.
	return new FreshnessEvaluator($clock, 300, 2, 6);
}

function redfish_query(): MemberQuery {
	$clock = new FixedClock(REDFISH_NOW);

	return new MemberQuery(new NodeCacheRepository(false), redfish_freshness($clock), $clock);
}

function redfish_summary(): string {
	$clock = new FixedClock(REDFISH_NOW);

	return (new SummaryScript(new NodeCacheRepository(false), redfish_freshness($clock), $clock))->answer(10);
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: array<string, array{0: ?float, 1: string, 2: int}>}>
 */
function redfish_fans(int $count, string $unit = '%'): array {
	$fans = [];

	for ($i = $count; $i >= 1; $i--) {
		$fans[] = ["1/fan/Fan$i", 'fan', 'present', ['fan_speed' => [40.0 + $i, $unit, 30], 'health_code' => [0.0, '', 30]]];
	}

	return $fans;
}

beforeEach(function () {
	$GLOBALS['__redfish_db_rows'] = [];
});

it('keeps safe member keys as indexes and makes any other key safe and distinct', function () {
	expect(IndexKey::of('1/fan/Fan1'))->toBe('1/fan/Fan1')
		->and(IndexKey::of('1/fan/Fan1:secondary'))->toBe('1/fan/Fan1:secondary')
		->and(IndexKey::of('1/temperature/CPU 1'))->not->toBe(IndexKey::of('1/temperature/CPU_1'))
		->and(IndexKey::of('1/temperature/CPU 1'))->toStartWith('1/temperature/CPU_1~')
		->and(IndexKey::of('1/psu/a!b|c'))->not->toContain('!')
		->and(IndexKey::of('1/psu/a!b|c'))->not->toContain('|')
		->and(strlen(IndexKey::of(str_repeat('x', 400))))->toBeLessThanOrEqual(IndexKey::MAX)
		->and(IndexKey::of('1/temperature/CPU 1'))->toBe(IndexKey::of('1/temperature/CPU 1'))
		->and(IndexKey::plausible(IndexKey::of('1/psu/a!b|c')))->toBeTrue()
		->and(IndexKey::plausible('1/fan/Fan1; DROP'))->toBeFalse()
		->and(IndexKey::plausible(''))->toBeFalse();
});

it('indexes every fan by its stable key, whatever the order discovery found them in', function (int $count) {
	redfish_cache(redfish_fans($count));

	$index = array_filter(explode("\n", redfish_query()->answer(10, 'fan', 'index')));
	$want  = array_map(fn (int $i) => "1/fan/Fan$i", range(1, $count));
	sort($want, SORT_STRING);

	expect(array_values($index))->toBe($want)
		->and(redfish_query()->answer(10, 'fan', 'num_indexes'))->toBe((string) $count)
		->and(redfish_query()->answer(10, 'fan', 'get', 'fan_percent', '1/fan/Fan3'))->toBe('43');
})->with([4, 8, 16]);

it('answers query lines as index, delimiter, value', function () {
	redfish_cache(redfish_fans(2));

	expect(redfish_query()->answer(10, 'fan', 'query', 'fan_percent'))->toBe("1/fan/Fan1!41\n1/fan/Fan2!42\n")
		->and(redfish_query()->answer(10, 'fan', 'query', 'name'))->toBe("1/fan/Fan1!Name of 1/fan/Fan1\n1/fan/Fan2!Name of 1/fan/Fan2\n")
		->and(redfish_query()->answer(10, 'fan', 'query', 'unit'))->toBe("1/fan/Fan1!%\n1/fan/Fan2!%\n");
});

it('keeps an absent or gone member indexed and reads it as unknown', function (string $presence) {
	$fans       = redfish_fans(3);
	$fans[1][2] = $presence;
	redfish_cache($fans);

	expect(redfish_query()->answer(10, 'fan', 'index'))->toContain('1/fan/Fan2')
		->and(redfish_query()->answer(10, 'fan', 'get', 'fan_percent', '1/fan/Fan2'))->toBe('U')
		->and(redfish_query()->answer(10, 'fan', 'get', 'fan_percent', '1/fan/Fan1'))->toBe('41');
})->with(['absent', 'gone']);

it('never serves a stale, missing, or unknown reading, and never as zero', function () {
	redfish_cache([
		['1/temperature/A', 'temperature', 'present', ['temperature_celsius' => [30.0, 'Cel', 700]]],
		['1/temperature/B', 'temperature', 'present', ['temperature_celsius' => [31.0, 'Cel', 1900]]],
		['1/temperature/C', 'temperature', 'present', ['temperature_celsius' => [null, 'Cel', 10]]],
		['1/temperature/D', 'temperature', 'present', []],
		['1/temperature/E', 'temperature', 'present', ['temperature_celsius' => [-5.5, 'Cel', 10]]],
	]);

	$q = redfish_query();

	expect($q->answer(10, 'temperature', 'get', 'temperature', '1/temperature/A'))->toBe('30')
		->and($q->answer(10, 'temperature', 'get', 'temperature', '1/temperature/B'))->toBe('U')
		->and($q->answer(10, 'temperature', 'get', 'temperature', '1/temperature/C'))->toBe('U')
		->and($q->answer(10, 'temperature', 'get', 'temperature', '1/temperature/D'))->toBe('U')
		->and($q->answer(10, 'temperature', 'get', 'temperature', '1/temperature/E'))->toBe('-5.5');
});

it('serves a fan only on the graph of its own unit', function () {
	redfish_cache(array_merge(redfish_fans(1, '%'), [['1/fan/R1', 'fan', 'present', ['fan_speed' => [5400.0, 'RPM', 30]]]]));

	$q = redfish_query();

	expect($q->answer(10, 'fan', 'get', 'fan_percent', '1/fan/Fan1'))->toBe('41')
		->and($q->answer(10, 'fan', 'get', 'fan_rpm', '1/fan/Fan1'))->toBe('U')
		->and($q->answer(10, 'fan', 'get', 'fan_rpm', '1/fan/R1'))->toBe('5400')
		->and($q->answer(10, 'fan', 'get', 'fan_percent', '1/fan/R1'))->toBe('U');
});

it('refuses unknown kinds, fields, indexes, commands, and devices without echoing them', function () {
	redfish_cache(redfish_fans(2));

	$q = redfish_query();

	expect($q->answer(10, 'chassis', 'get', 'x', '1/fan/Fan1'))->toBe('U')
		->and($q->answer(10, 'fan', 'get', 'psu_input_watts', '1/fan/Fan1'))->toBe('U')
		->and($q->answer(10, 'fan', 'get', 'fan_percent', '1/psu/Fan1'))->toBe('U')
		->and($q->answer(10, 'fan', 'get', 'fan_percent', "<script>"))->toBe('U')
		->and($q->answer(10, 'fan', 'query', '<b>'))->toBe('')
		->and($q->answer(10, 'fan', 'drop'))->toBe('')
		->and($q->answer(0, 'fan', 'index'))->toBe('');

	$GLOBALS['__redfish_db_rows'] = [];

	expect(redfish_query()->answer(11, 'fan', 'get', 'fan_percent', '1/fan/Fan1'))->toBe('U')
		->and(redfish_query()->answer(11, 'fan', 'num_indexes'))->toBe('0');
});

it('strips delimiters and markup from BMC-supplied names', function () {
	redfish_cache([['1/psu/0', 'psu', 'present', []]]);
	$GLOBALS['__redfish_db_rows']['FROM plugin_redfish_member'][0]['name'] = "PSU!1|<b>\n\"x\"";

	expect(redfish_query()->answer(10, 'psu', 'query', 'name'))->toBe("1/psu/0!PSU 1 b x\n");
});

it('summarizes a current node', function () {
	redfish_cache(array_merge([
		['node', 'node', 'present', ['power_watts' => [412.0, 'W', 30], 'inlet_celsius' => [21.5, 'Cel', 30], 'health_code' => [1.0, '', 30]]],
		['1/temperature/A', 'temperature', 'present', ['temperature_celsius' => [55.0, 'Cel', 30]]],
		['1/temperature/B', 'temperature', 'present', ['temperature_celsius' => [71.0, 'Cel', 30]]],
		['1/temperature/C', 'temperature', 'gone', ['temperature_celsius' => [99.0, 'Cel', 30]]],
		['1/fan/Fan9', 'fan', 'present', ['health_code' => [2.0, '', 30]]],
		['1/fan/Fan9:secondary', 'fan', 'present', ['health_code' => [2.0, '', 30]]],
		['1/psu/0', 'psu', 'present', ['health_code' => [0.0, '', 30]]],
		['1/psu/1', 'psu', 'present', ['health_code' => [1.0, '', 30]]],
		['1/psu/2', 'psu', 'absent', ['health_code' => [2.0, '', 30]]],
	], redfish_fans(3)));

	expect(redfish_summary())->toBe('power_watts:412 inlet_celsius:21.5 max_temp_celsius:71 fans_bad:1 psus_bad:1 health_code:1 age:60');
});

it('counts a fan or supply that was present and is now absent or gone as failed', function () {
	$node = ['node', 'node', 'present', ['power_watts' => [412.0, 'W', 30], 'health_code' => [0.0, '', 30]]];

	redfish_cache([
		$node,
		['1/fan/Fan1', 'fan', 'present', ['health_code' => [0.0, '', 30]]],
		['1/fan/Fan2', 'fan', 'absent', [], true],
		['1/fan/Fan3', 'fan', 'gone', ['health_code' => [0.0, '', 30]], true],
		['1/fan/Fan3:secondary', 'fan', 'gone', [], true],
		['1/psu/0', 'psu', 'present', ['health_code' => [0.0, '', 30]]],
		['1/psu/1', 'psu', 'absent', [], true],
		['1/temperature/A', 'temperature', 'gone', [], true],
	]);

	expect(redfish_summary())->toContain(' fans_bad:2 psus_bad:1 ');
});

it('does not count a slot that was never present, or one an administrator accepted', function () {
	redfish_cache([
		['node', 'node', 'present', ['health_code' => [0.0, '', 30]]],
		['1/fan/Fan1', 'fan', 'present', ['health_code' => [0.0, '', 30]]],
		['1/fan/Fan2', 'fan', 'absent', [], false],
		['1/psu/0', 'psu', 'present', ['health_code' => [0.0, '', 30]]],
		['1/psu/1', 'psu', 'gone', [], false],
	]);

	expect(redfish_summary())->toContain(' fans_bad:0 psus_bad:0 ');
});

it('skips only a fan\'s second rotor, never a supply whose id ends the same way', function () {
	redfish_cache([
		['node', 'node', 'present', ['health_code' => [0.0, '', 30]]],
		['1/fan/Fan1', 'fan', 'present', ['health_code' => [2.0, '', 30]]],
		['1/fan/Fan1:secondary', 'fan', 'present', ['health_code' => [2.0, '', 30]]],
		['1/psu/PSU:secondary', 'psu', 'present', ['health_code' => [2.0, '', 30]]],
		['1/psu/Backup:secondary', 'psu', 'absent', [], true],
	]);

	expect(redfish_summary())->toContain(' fans_bad:1 psus_bad:2 ');
});

it('reads a node cache inside one transaction', function () {
	$GLOBALS['__redfish_db_exec'] = [];
	redfish_cache([['node', 'node', 'present', ['health_code' => [0.0, '', 30]]]]);
	redfish_summary();

	expect(array_column($GLOBALS['__redfish_db_exec'], 'sql'))->toBe(['BEGIN', 'COMMIT']);
});

it('lists the missing members for the plan that accepts them', function () {
	redfish_cache([
		['1/fan/Fan1', 'fan', 'present', []],
		['1/fan/Fan2', 'fan', 'absent', [], true],
		['1/fan/Fan3', 'fan', 'absent', [], false],
		['1/psu/1', 'psu', 'gone', [], true],
		['1/temperature/A', 'temperature', 'gone', [], true],
	]);

	expect((new NodeCacheRepository(false))->memberCounts(1))->toBe(['fan' => 3, 'missing' => 2, 'psu' => 1, 'temperature' => 1]);
});

it('reads a stale node as unknown but keeps age growing', function () {
	redfish_cache([
		['node', 'node', 'present', ['power_watts' => [412.0, 'W', 4000], 'inlet_celsius' => [21.5, 'Cel', 4000], 'health_code' => [0.0, '', 4000]]],
		['1/fan/Fan1', 'fan', 'present', ['health_code' => [2.0, '', 4000]]],
	], 4000);

	expect(redfish_summary())->toBe('power_watts:U inlet_celsius:U max_temp_celsius:U fans_bad:U psus_bad:U health_code:U age:4000');
});

it('gives a never-read BMC a numeric age from its registration', function () {
	redfish_cache([], -1, 900);

	expect(redfish_summary())->toBe('power_watts:U inlet_celsius:U max_temp_celsius:U fans_bad:U psus_bad:U health_code:U age:900');
});

it('reads a device with no BMC as all unknown', function () {
	expect(redfish_summary())->toBe('power_watts:U inlet_celsius:U max_temp_celsius:U fans_bad:U psus_bad:U health_code:U age:U');
});

it('names every query field it answers in the query files', function () {
	foreach (['fan' => 'redfish_fans.xml', 'temperature' => 'redfish_temperatures.xml', 'psu' => 'redfish_psus.xml'] as $kind => $file) {
		$xml    = simplexml_load_file(dirname(__DIR__, 2) . '/resource/script_server/' . $file);
		$fields = [];

		foreach ($xml->fields->children() as $name => $field) {
			$fields[(string) $field->direction][] = (string) $field->query_name;
		}

		expect($fields['output'])->toBe(array_keys(MemberQuery::OUTPUTS[$kind]))
			->and($fields['input'])->toBe(MemberQuery::INPUTS)
			->and((string) $xml->arg_prepend)->toBe('|host_id| ' . $kind)
			->and((string) $xml->index_order)->toBe('redfishIndex');
	}
});
