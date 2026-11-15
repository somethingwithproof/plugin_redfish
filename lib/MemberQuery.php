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

namespace Somethingwithproof\Redfish;

/**
 * Answers the three script server data queries, one per member kind:
 * fans, temperature sensors, and power supplies (REQ-DQ-001 to REQ-DQ-005).
 *
 * Cacti runs the index and query steps as a PHP CLI process and the get
 * step through the script server, one call per value per index. Every
 * argument arrives from Cacti's command line or the script server, so each
 * is checked here: the kind and field against fixed lists, the index
 * against the index pattern. Anything else is answered with U or an empty
 * list and never echoed back.
 *
 * Indexes are every member of the kind that discovery ever found on the
 * device's BMC, including members now absent or gone, so a member that
 * disappears keeps its data source and graph and reads U (REQ-DQ-003). A
 * value is served only while its member is present and its reading is
 * current or delayed.
 */
final class MemberQuery {
	/** Kind => output field => [metric, unit the field requires, or '' for any]. */
	public const OUTPUTS = [
		'fan'         => ['fan_percent' => ['fan_speed', '%'], 'fan_rpm' => ['fan_speed', 'RPM'], 'fan_health' => ['health_code', '']],
		'temperature' => ['temperature' => ['temperature_celsius', 'Cel'], 'temp_health' => ['health_code', '']],
		'psu'         => ['psu_input_watts' => ['psu_input_watts', 'W'], 'psu_output_watts' => ['psu_output_watts', 'W'], 'psu_health' => ['health_code', '']],
	];

	/** The reading whose unit the unit field reports, which decides a fan's graph. */
	public const UNIT_METRIC = ['fan' => 'fan_speed', 'temperature' => 'temperature_celsius', 'psu' => 'psu_input_watts'];

	/** Input fields every query has. */
	public const INPUTS = ['index', 'name', 'chassis', 'unit'];

	private const CACHE_SECONDS = 30;

	/** @var array<int, array{at: int, cache: ?NodeCache}> */
	private array $loaded = [];

	public function __construct(
		private readonly NodeCacheRepository $caches,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
	) {
	}

	public function answer(int $hostId, string $kind, string $command, string $field = '', string $index = ''): string {
		if (!isset(self::OUTPUTS[$kind]) || $hostId <= 0) {
			return $command === 'get' ? 'U' : '';
		}

		$cache = $this->load($hostId);

		if ($cache === null) {
			return match ($command) {
				'get'         => 'U',
				'num_indexes' => '0',
				default       => '',
			};
		}

		$members = $cache->membersOf($kind);

		return match ($command) {
			'index'       => self::lines(array_map(static fn (array $m): string => IndexKey::of($m['key']), $members)),
			'num_indexes' => (string) count($members),
			'query'       => $this->query($cache, $kind, $field, $members),
			'get'         => $this->get($cache, $kind, $field, $index),
			default       => '',
		};
	}

	/**
	 * @param list<array{key: string, kind: string, name: string, chassis: string, presence: string}> $members
	 */
	private function query(NodeCache $cache, string $kind, string $field, array $members): string {
		if (!in_array($field, self::INPUTS, true) && !isset(self::OUTPUTS[$kind][$field])) {
			return '';
		}

		$lines = [];

		foreach ($members as $member) {
			$lines[] = IndexKey::of($member['key']) . '!' . $this->field($cache, $kind, $field, $member);
		}

		return self::lines($lines);
	}

	private function get(NodeCache $cache, string $kind, string $field, string $index): string {
		if (!isset(self::OUTPUTS[$kind][$field]) || !IndexKey::plausible($index)) {
			return 'U';
		}

		$member = $cache->memberAt($kind, $index);

		return $member === null ? 'U' : $this->field($cache, $kind, $field, $member);
	}

	/**
	 * @param array{key: string, kind: string, name: string, chassis: string, presence: string} $member
	 */
	private function field(NodeCache $cache, string $kind, string $field, array $member): string {
		return match ($field) {
			'index'   => IndexKey::of($member['key']),
			'name'    => self::label($member['name'] !== '' ? $member['name'] : $member['key']),
			'chassis' => self::label($member['chassis']),
			'unit'    => self::label($cache->reading($member['key'], self::UNIT_METRIC[$kind] ?? '')['unit'] ?? ''),
			default   => Number::format($this->value($cache, self::OUTPUTS[$kind][$field] ?? null, $member)),
		};
	}

	/**
	 * @param array{0: string, 1: string}|null                                                $spec   metric and required unit of the field
	 * @param array{key: string, kind: string, name: string, chassis: string, presence: string} $member
	 */
	private function value(NodeCache $cache, ?array $spec, array $member): ?float {
		if ($spec === null || $member['presence'] !== 'present') {
			return null;
		}

		[$metric, $unit] = $spec;

		// A fan in percent is never presented as RPM, nor the reverse.
		if ($unit !== '' && ($cache->reading($member['key'], $metric)['unit'] ?? '') !== $unit) {
			return null;
		}

		return $cache->served($member['key'], $metric, $this->freshness);
	}

	/**
	 * A BMC-supplied name, as Cacti stores it in its index cache: no
	 * control characters, no output delimiter, and bounded.
	 */
	private static function label(string $text): string {
		$clean = trim((string) preg_replace('/[\x00-\x1f\x7f!|<>"\'\\\\]+/', ' ', $text));

		return mb_substr($clean === '' ? '-' : $clean, 0, 100);
	}

	private function load(int $hostId): ?NodeCache {
		$now = $this->clock->now();

		if (!isset($this->loaded[$hostId]) || $now - $this->loaded[$hostId]['at'] >= self::CACHE_SECONDS || $now < $this->loaded[$hostId]['at']) {
			$this->loaded[$hostId] = ['at' => $now, 'cache' => $this->caches->forHost($hostId)];
		}

		return $this->loaded[$hostId]['cache'];
	}

	/**
	 * @param list<string> $lines
	 */
	private static function lines(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}
}
