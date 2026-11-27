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
 * One resource the collector reads each run, chosen by discovery. The
 * expected Id and resource type are checked on every read; a mismatch
 * means the BMC changed under us and triggers rediscovery (REQ-DISC-004).
 *
 * $uses lists the readings this resource supplies, because the source is
 * chosen per reading: a legacy Thermal resource may supply only the fans
 * when ThermalMetrics already supplies the temperatures.
 */
final readonly class Target {
	/**
	 * Environment constant.
	 */
	public const ENVIRONMENT     = 'environment';
	/**
	 * Thermal metrics constant.
	 */
	public const THERMAL_METRICS = 'thermal_metrics';
	/**
	 * Fan constant.
	 */
	public const FAN             = 'fan';
	/**
	 * Psu constant.
	 */
	public const PSU             = 'psu';
	/**
	 * Psu metrics constant.
	 */
	public const PSU_METRICS     = 'psu_metrics';
	/**
	 * Legacy thermal constant.
	 */
	public const LEGACY_THERMAL  = 'legacy_thermal';
	/**
	 * Legacy power constant.
	 */
	public const LEGACY_POWER    = 'legacy_power';

	/**
	 * Kinds constant.
	 */
	public const KINDS = [self::ENVIRONMENT, self::THERMAL_METRICS, self::FAN, self::PSU, self::PSU_METRICS, self::LEGACY_THERMAL, self::LEGACY_POWER];

	/**
	 * @param string       $type   resource type from @odata.type, such as "Fan"
	 * @param string       $member member Id for fan, psu, and psu_metrics targets
	 * @param string       $unit   fan unit from the referenced sensor: percent, rpm, unknown, or '' with no sensor
	 * @param list<string> $uses   readings supplied: power, ambient, summary, temperatures, fans, psus
	 */
	public function __construct(
		public string $kind,
		public string $path,
		public string $id,
		public string $type,
		public string $chassis,
		public string $member = '',
		public string $unit = '',
		public string $secondaryUnit = '',
		public array $uses = [],
	) {
	}

	/**
	 * @return array<string, string|list<string>>
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind, 'path' => $this->path, 'id' => $this->id, 'type' => $this->type, 'chassis' => $this->chassis,
			'member' => $this->member, 'unit' => $this->unit, 'secondary_unit' => $this->secondaryUnit, 'uses' => $this->uses,
		];
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromArray(array $row): ?self {
		$kind = Row::string($row, 'kind');
		$path = Row::string($row, 'path');
		$uses = is_array($row['uses'] ?? null) ? array_values(array_filter($row['uses'], 'is_string')) : [];

		if (!in_array($kind, self::KINDS, true) || !OperationGuard::readablePath($path)) {
			return null;
		}

		return new self($kind, $path, Row::string($row, 'id'), Row::string($row, 'type'), Row::string($row, 'chassis'), Row::string($row, 'member'), Row::string($row, 'unit'), Row::string($row, 'secondary_unit'), $uses);
	}

	/**
	 * Supplies.
	 *
	 * @param string $reading
	 * @return bool
	 */
	public function supplies(string $reading): bool {
		return in_array($reading, $this->uses, true);
	}
}
