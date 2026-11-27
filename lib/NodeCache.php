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
final class NodeCache {
	/**
	 * Lazy cache of members grouped by kind in original order.
	 *
	 * @var array<string, list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>>|null
	 */
	private ?array $byKind = null;

	/**
	 * Lazy cache of members grouped by kind and sorted by IndexKey::of(key).
	 *
	 * @var array<string, list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>>
	 */
	private array $sortedByKind = [];

	/**
	 * Lazy O(1) lookup table keyed by kind then computed IndexKey.
	 *
	 * @var array<string, array<string, array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>>
	 */
	private array $indexByKind = [];

	/**
	 * @param list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}> $members
	 * @param array<string, array<string, array{value: ?float, unit: string, observed_at: int}>>       $readings member key => metric => reading
	 */
	public function __construct(
		public readonly int $bmcId,
		public readonly int $registeredAt,
		public readonly int $lastSuccess,
		public readonly array $members,
		public readonly array $readings,
	) {
	}

	/**
	 * Members of one kind, ordered by index so every listing agrees.
	 *
	 * @return list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>
	 */
	public function membersOf(string $kind): array {
		if (isset($this->sortedByKind[$kind])) {
			return $this->sortedByKind[$kind];
		}

		$raw = $this->membersOfUnsorted($kind);

		if (count($raw) <= 1) {
			return $this->sortedByKind[$kind] = $raw;
		}

		// Schwartzian transform: compute IndexKey::of() once per member rather
		// than inside every O(M log M) comparator invocation.
		$keyed = [];

		foreach ($raw as $member) {
			$idx = IndexKey::of($member['key']);
			$keyed[$idx] ??= [];
			$keyed[$idx][] = $member;
		}

		ksort($keyed, SORT_STRING);
		$sorted = [];

		foreach ($keyed as $bucket) {
			foreach ($bucket as $member) {
				$sorted[] = $member;
			}
		}

		return $this->sortedByKind[$kind] = $sorted;
	}

	/**
	 * Members of one kind without sorting overhead, for aggregations that are
	 * order-independent (such as max temperature or fault counts).
	 *
	 * @param string $kind member kind (fan, temperature, psu, etc.)
	 * @return list<array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}>
	 */
	public function membersOfUnsorted(string $kind): array {
		if ($this->byKind === null) {
			$grouped = [];

			foreach ($this->members as $member) {
				$grouped[$member['kind']][] = $member;
			}

			$this->byKind = $grouped;
		}

		return $this->byKind[$kind] ?? [];
	}

	/**
	 * @return array{key: string, kind: string, name: string, chassis: string, presence: string, expected: bool}|null
	 */
	public function memberAt(string $kind, string $index): ?array {
		if (!isset($this->indexByKind[$kind])) {
			$map = [];

			foreach ($this->membersOfUnsorted($kind) as $member) {
				$idx = IndexKey::of($member['key']);

				if (!isset($map[$idx])) {
					$map[$idx] = $member;
				}
			}

			$this->indexByKind[$kind] = $map;
		}

		return $this->indexByKind[$kind][$index] ?? null;
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
