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
 * One rack definition in the rack registry (REQ-RACK-001 to REQ-RACK-005).
 */
final readonly class Rack {
	public const DEFAULT_MIN_COVERAGE_PCT = 80.0;

	public function __construct(
		public int $id,
		public int $siteId,
		public string $name,
		public ?float $powerBudgetWatts = null,
		public float $minCoveragePct = self::DEFAULT_MIN_COVERAGE_PCT,
		public string $notes = '',
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id   = Row::int($row, 'id', 0, 1);
		$name = trim(Row::string($row, 'name'));

		if ($id === 0 || $name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
			return null;
		}

		$budget   = Row::float($row, 'power_budget_watts');
		$coverage = Row::float($row, 'min_coverage_pct') ?? self::DEFAULT_MIN_COVERAGE_PCT;

		return new self(
			id: $id,
			siteId: Row::int($row, 'site_id', 0, 0),
			name: $name,
			powerBudgetWatts: $budget !== null && $budget > 0.0 ? $budget : null,
			minCoveragePct: max(0.0, min(100.0, $coverage)),
			notes: substr(Row::string($row, 'notes'), 0, 255),
		);
	}

	/**
	 * @return array<string, string|int|float|null>
	 */
	public function toRow(): array {
		return [
			'site_id'            => $this->siteId,
			'name'               => $this->name,
			'power_budget_watts' => $this->powerBudgetWatts,
			'min_coverage_pct'   => $this->minCoveragePct,
			'notes'              => $this->notes,
		];
	}
}
