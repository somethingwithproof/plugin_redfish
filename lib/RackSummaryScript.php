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
 * Answers the Thold-ready rack power rollup script server input from the local
 * reading cache only, never contacting a BMC.
 */
final class RackSummaryScript {
	public function __construct(
		private readonly RackRepository $racks,
		private readonly NodeCacheRepository $caches,
		private readonly FreshnessEvaluator $freshness,
	) {
	}

	public function summaryFor(int $rackId): ?RackPowerSummary {
		$rack = $this->racks->find($rackId);

		if ($rack === null) {
			return null;
		}

		$memberCaches = [];

		foreach ($this->racks->members($rack->id) as $member) {
			$cache = $member['bmc_id'] > 0
				? $this->caches->forBmc($member['bmc_id'])
				: $this->caches->forHost($member['host_id']);

			$memberCaches[] = $cache;
		}

		return RackPowerSummary::compute($rack, $memberCaches, $this->freshness);
	}

	public function answer(int $rackId): string {
		$summary = $this->summaryFor($rackId);

		if ($summary === null) {
			$pairs = [];

			foreach (RackPowerSummary::THOLD_VALUES as $name) {
				$pairs[] = $name . ':U';
			}

			return implode(' ', $pairs);
		}

		return $summary->formatThold();
	}
}
