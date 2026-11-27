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
 * Pure rack power rollup calculator (REQ-RACK-010 to REQ-RACK-013).
 *
 * Aggregates fresh node power_watts across rack members and computes coverage,
 * power budget utilization, and headroom. When member coverage falls below the
 * rack's configured coverage floor, power_watts, utilization_pct, and
 * headroom_watts read as null (U) so collector failures never appear as a
 * power drop.
 */
final readonly class RackPowerSummary {
	/**
	 * Thold values constant.
	 */
	public const THOLD_VALUES = [
		'power_watts',
		'power_budget_watts',
		'utilization_pct',
		'headroom_watts',
		'members_total',
		'members_reporting',
		'coverage_pct',
	];

	/**
	 * Initialize a new RackPowerSummary instance.
	 *
	 * @param Rack $rack
	 * @param float|null $powerWatts
	 * @param float|null $powerBudgetWatts
	 * @param float|null $utilizationPct
	 * @param float|null $headroomWatts
	 * @param int $membersTotal
	 * @param int $membersReporting
	 * @param float $coveragePct
	 */
	public function __construct(
		public Rack $rack,
		public ?float $powerWatts,
		public ?float $powerBudgetWatts,
		public ?float $utilizationPct,
		public ?float $headroomWatts,
		public int $membersTotal,
		public int $membersReporting,
		public float $coveragePct,
	) {
	}

	/**
	 * Compute the rack power summary from member NodeCache snapshots.
	 *
	 * @param list<?NodeCache> $memberCaches one entry per rack member
	 */
	public static function compute(Rack $rack, array $memberCaches, FreshnessEvaluator $freshness): self {
		$total     = count($memberCaches);
		$reporting = 0;
		$sumWatts  = 0.0;

		foreach ($memberCaches as $cache) {
			if ($cache === null) {
				continue;
			}

			$watts = $cache->served(Member::NODE, 'power_watts', $freshness);

			if ($watts !== null) {
				$reporting++;
				$sumWatts += $watts;
			}
		}

		$coveragePct = $total > 0 ? round(($reporting / $total) * 100.0, 2) : 0.0;
		$meetsFloor  = $total > 0 && $reporting > 0 && $coveragePct >= $rack->minCoveragePct;
		$powerWatts  = $meetsFloor ? round($sumWatts, 2) : null;
		$budget      = $rack->powerBudgetWatts;
		$utilization = ($powerWatts !== null && $budget !== null && $budget > 0.0) ? round(($powerWatts / $budget) * 100.0, 2) : null;
		$headroom    = ($powerWatts !== null && $budget !== null && $budget > 0.0) ? round($budget - $powerWatts, 2) : null;

		return new self(
			rack: $rack,
			powerWatts: $powerWatts,
			powerBudgetWatts: $budget,
			utilizationPct: $utilization,
			headroomWatts: $headroom,
			membersTotal: $total,
			membersReporting: $reporting,
			coveragePct: $coveragePct,
		);
	}

	/**
	 * Machine-readable representation for CLI JSON, MCP abilities, and Doctor.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'rack_id'            => $this->rack->id,
			'site_id'            => $this->rack->siteId,
			'name'               => $this->rack->name,
			'power_watts'        => $this->powerWatts,
			'power_budget_watts' => $this->powerBudgetWatts,
			'utilization_pct'    => $this->utilizationPct,
			'headroom_watts'     => $this->headroomWatts,
			'members_total'      => $this->membersTotal,
			'members_reporting'  => $this->membersReporting,
			'coverage_pct'       => $this->coveragePct,
			'min_coverage_pct'   => $this->rack->minCoveragePct,
			'over_budget'        => $this->powerWatts !== null && $this->powerBudgetWatts !== null && $this->powerWatts > $this->powerBudgetWatts,
		];
	}

	/**
	 * Thold-ready Cacti script server name:value line.
	 */
	public function formatThold(): string {
		$values = [
			'power_watts'        => $this->powerWatts,
			'power_budget_watts' => $this->powerBudgetWatts,
			'utilization_pct'    => $this->utilizationPct,
			'headroom_watts'     => $this->headroomWatts,
			'members_total'      => (float) $this->membersTotal,
			'members_reporting'  => (float) $this->membersReporting,
			'coverage_pct'       => $this->coveragePct,
		];

		$pairs = [];

		foreach (self::THOLD_VALUES as $name) {
			$pairs[] = $name . ':' . Number::format($values[$name]);
		}

		return implode(' ', $pairs);
	}
}
