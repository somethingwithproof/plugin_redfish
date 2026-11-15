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
 * Facts read from a service root. Pure functions of the decoded response.
 */
final class ServiceRoot {
	/**
	 * Families whose ThermalMetrics.TemperatureSummaryCelsius.Intake is
	 * confirmed to measure air intake (docs/research/sensors.md). Only these
	 * fill inlet_celsius from the summary; every other family keeps the value
	 * under its source name until research #24 confirms it.
	 */
	public const INTAKE_CONFIRMED = ['dell'];

	/**
	 * Families known to put a value other than percent in
	 * SpeedPercent.Reading without saying so.
	 */
	public const FAN_UNIT_DEVIATIONS = ['lenovo'];

	/**
	 * A hash of what identifies the firmware build: a change triggers
	 * rediscovery (REQ-DISC-004).
	 *
	 * @param array<string, mixed> $root
	 */
	public static function fingerprint(array $root): string {
		$parts = [];

		foreach (['@odata.type', 'RedfishVersion', 'Vendor', 'Product', 'UUID'] as $key) {
			$parts[] = is_scalar($root[$key] ?? null) ? (string) $root[$key] : '';
		}

		return hash('sha256', implode("\n", $parts));
	}

	/**
	 * @param array<string, mixed> $root
	 */
	public static function family(array $root): string {
		$vendor = strtolower(self::text($root, 'Vendor'));
		$oem    = is_array($root['Oem'] ?? null) ? array_map('strtolower', array_map('strval', array_keys($root['Oem']))) : [];

		foreach (['dell' => ['dell'], 'hpe' => ['hpe', 'hp'], 'lenovo' => ['lenovo'], 'supermicro' => ['supermicro'], 'openbmc' => ['openbmc']] as $family => $names) {
			foreach ($names as $name) {
				if (str_contains($vendor, $name) || in_array($name, $oem, true)) {
					return $family;
				}
			}
		}

		return 'other';
	}

	/**
	 * The ProtocolFeaturesSupported members advertised as true, as names
	 * such as "ExpandQuery" and "SelectQuery". Recorded, not used in this
	 * release: no request sends $select or $expand (REQ-DISC-003).
	 *
	 * @param array<string, mixed> $root
	 * @return list<string>
	 */
	public static function features(array $root): array {
		$features = is_array($root['ProtocolFeaturesSupported'] ?? null) ? $root['ProtocolFeaturesSupported'] : [];
		$out      = [];

		foreach (['ExpandQuery', 'SelectQuery', 'FilterQuery', 'OnlyMemberQuery'] as $name) {
			$value = $features[$name] ?? null;

			if ($value === true || (is_array($value) && in_array(true, $value, true))) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $root
	 */
	public static function text(array $root, string $key): string {
		return is_string($root[$key] ?? null) ? substr($root[$key], 0, 128) : '';
	}

	/**
	 * The resource name in an @odata.type such as "#Fan.v1_5_2.Fan".
	 *
	 * @param array<array-key, mixed> $resource
	 */
	public static function type(array $resource): string {
		$type = $resource['@odata.type'] ?? null;

		return is_string($type) && preg_match('/^#([A-Za-z]+)\./', $type, $m) === 1 ? $m[1] : '';
	}
}
