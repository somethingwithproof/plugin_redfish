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
 * What the cache holds for the BMC mapped to one Cacti device: the members
 * discovery found and the latest reading of each, as the collector stored
 * them. The script server answers from this only; it never contacts a BMC
 * (REQ-DQ-005, REQ-ALERT-004).
 */
final readonly class NodeCache {
	/**
	 * @param list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}> $members
	 * @param array<string, array<string, array{value: ?float, unit: string, observed_at: int}>>       $readings member key => metric => reading
	 */
	public function __construct(
		public int $bmcId,
		public int $registeredAt,
		public int $lastSuccess,
		public array $members,
		public array $readings,
	) {
	}

	/**
	 * Members of one kind, ordered by index so every listing agrees.
	 *
	 * @return list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>
	 */
	public function membersOf(string $kind): array {
		$out = array_values(array_filter($this->members, static fn (array $m): bool => $m['kind'] === $kind));
		usort($out, static fn (array $a, array $b): int => strcmp(IndexKey::of($a['key']), IndexKey::of($b['key'])));

		return $out;
	}

	/**
	 * @return array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}|null
	 */
	public function memberAt(string $kind, string $index): ?array {
		foreach ($this->members as $member) {
			if ($member['kind'] === $kind && IndexKey::of($member['key']) === $index) {
				return $member;
			}
		}

		return null;
	}

	/**
	 * A value the script server may hand to Cacti: the reading exists, is
	 * current or delayed, and is known. Anything else is unknown, never
	 * zero (REQ-MODEL-002).
	 */
	public function served(string $member, string $metric, FreshnessEvaluator $freshness): ?float {
		$reading = $this->readings[$member][$metric] ?? null;

		return $reading !== null && $freshness->evaluate($reading['observed_at'])->usable() ? $reading['value'] : null;
	}

	/**
	 * @return array{value: ?float, unit: string, observed_at: int}|null
	 */
	public function reading(string $member, string $metric): ?array {
		return $this->readings[$member][$metric] ?? null;
	}

	/**
	 * A fan or supply that was present since its members were last accepted
	 * and is not present now: pulled, failed so far that the BMC reports it
	 * absent, or gone from the tree. A slot never seen present is not
	 * missing. A second rotor follows its fan and is never counted.
	 *
	 * @param array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool} $member
	 */
	public static function missing(array $member): bool {
		return in_array($member['kind'], ['fan', 'psu'], true) && $member['expected'] && $member['presence'] !== 'present' && !self::secondRotor($member);
	}

	/**
	 * The second rotor of a fan, which the normalizer files under the fan's
	 * key plus ":secondary". Only fans have one: a supply whose own id ends
	 * that way is a supply like any other.
	 *
	 * @param array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool} $member
	 */
	public static function secondRotor(array $member): bool {
		return $member['kind'] === 'fan' && str_ends_with($member['key'], ':secondary');
	}
}
