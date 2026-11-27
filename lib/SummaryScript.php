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
 * Answers the "Redfish Node Summary" script server input: every summary
 * value as name:value pairs in one call (REQ-ALERT-001 to REQ-ALERT-004),
 * from the cache only.
 *
 * Every value but age is U when its readings are missing, stale, or never
 * observed, never 0 (REQ-ALERT-002). The fan and supply counts are U unless
 * the node's own health reading is usable, so a BMC that stopped answering
 * never reports "no bad fans". age is the seconds since the last successful
 * read, or since the BMC was registered when no read has succeeded, so it
 * is always a number while the BMC is registered (REQ-ALERT-003) and Thold
 * can alert on a BMC that stopped answering.
 */
final class SummaryScript {
	/**
	 * Values constant.
	 */
	public const VALUES = ['power_watts', 'inlet_celsius', 'max_temp_celsius', 'fans_bad', 'psus_bad', 'health_code', 'age'];

	/**
	 * Initialize a new SummaryScript instance.
	 *
	 * @param NodeCacheRepository $caches
	 * @param FreshnessEvaluator $freshness
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly NodeCacheRepository $caches,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Answer.
	 *
	 * @param int $hostId
	 * @return string
	 */
	public function answer(int $hostId): string {
		return self::format($this->values($this->caches->forHost($hostId)));
	}

	/**
	 * @return array<string, ?float> value name => value, null for U
	 */
	public function values(?NodeCache $cache): array {
		$out = array_fill_keys(self::VALUES, null);

		if ($cache === null) {
			return $out;
		}

		$node = fn (string $metric): ?float => $cache->served(Member::NODE, $metric, $this->freshness);

		$out['power_watts']   = $node('power_watts');
		$out['inlet_celsius'] = $node('inlet_celsius');
		$out['health_code']   = $node('health_code');

		foreach ($cache->membersOfUnsorted('temperature') as $member) {
			$value = $member['presence'] === 'present' ? $cache->served($member['key'], 'temperature_celsius', $this->freshness) : null;

			if ($value !== null) {
				$out['max_temp_celsius'] = max($out['max_temp_celsius'] ?? $value, $value);
			}
		}

		if ($out['health_code'] !== null) {
			$out['fans_bad'] = (float) $this->bad($cache, 'fan');
			$out['psus_bad'] = (float) $this->bad($cache, 'psu');
		}

		$since      = $cache->lastSuccess > 0 ? $cache->lastSuccess : $cache->registeredAt;
		$out['age'] = (float) max(0, $this->clock->now() - $since);

		return $out;
	}

	/**
	 * Members of a kind that failed: present with usable health of Warning or
	 * worse, or missing (NodeCache::missing) since they were last accepted.
	 * A second fan rotor shares its fan's health and is not counted twice;
	 * a slot never seen present is not counted.
	 */
	private function bad(NodeCache $cache, string $kind): int {
		$bad = 0;

		foreach ($cache->membersOfUnsorted($kind) as $member) {
			if (NodeCache::missing($member)) {
				$bad++;

				continue;
			}

			if ($member['presence'] !== 'present' || NodeCache::secondRotor($member)) {
				continue;
			}

			if (($cache->served($member['key'], 'health_code', $this->freshness) ?? 0.0) >= 1.0) {
				$bad++;
			}
		}

		return $bad;
	}

	/**
	 * @param array<string, ?float> $values
	 */
	public static function format(array $values): string {
		$pairs = [];

		foreach (self::VALUES as $name) {
			$pairs[] = $name . ':' . Number::format($values[$name] ?? null);
		}

		return implode(' ', $pairs);
	}
}
