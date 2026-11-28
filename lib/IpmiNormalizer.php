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
 * Pure parser normalizing read-only ipmitool text output into the same
 * Observation/Member/Reading model as Redfish (REQ-IPMI-010 to REQ-IPMI-013).
 *
 * Unparsable, disabled, or missing sensor readings always produce null (U),
 * never 0.
 */
final class IpmiNormalizer {
	/**
	 * Chassis id constant.
	 */
	public const CHASSIS_ID = 'ipmi';

	/**
	 * Parse all IPMI fallback command outputs into one Observation.
	 */
	public static function normalizeAll(
		string $dcmiOutput,
		string $temperatureOutput,
		string $fanOutput,
		string $psuOutput,
		string $chassisOutput = '',
	): Observation {
		$obs = new Observation();

		$chassisKey = Member::key(self::CHASSIS_ID, 'chassis', self::CHASSIS_ID);

		if ($chassisKey !== null) {
			$obs->member(new Member($chassisKey, 'chassis', self::CHASSIS_ID, self::CHASSIS_ID, Reading::SOURCE_IPMI_DCMI));
			$obs->add(new Reading($chassisKey, 'power_watts', self::parseDcmiPower($dcmiOutput), 'W', Reading::SOURCE_IPMI_DCMI, 'ipmi.dcmi.power'));
		}

		$obs->merge(self::parseSdrTemperatures($temperatureOutput));
		$obs->merge(self::parseSdrFans($fanOutput));
		$obs->merge(self::parseSdrPowerSupplies($psuOutput));

		$chassisFault = self::parseChassisFault($chassisOutput);

		if ($chassisFault !== null && $chassisKey !== null) {
			$obs->add(new Reading($chassisKey, 'health_code', $chassisFault, '', Reading::SOURCE_IPMI_SDR, 'ipmi.chassis.status'));
		}

		return $obs;
	}

	/**
	 * Parse "ipmitool dcmi power reading" instantaneous power in Watts.
	 */
	public static function parseDcmiPower(string $output): ?float {
		if (preg_match('/^\s*Instantaneous power reading:\s*([0-9]+(?:\.[0-9]+)?)\s*Watts?\b/mi', $output, $match) === 1) {
			$value = (float) $match[1];

			return is_finite($value) && $value >= 0.0 ? $value : null;
		}

		return null;
	}

	/**
	 * Parse "ipmitool sdr type Temperature" lines.
	 */
	public static function parseSdrTemperatures(string $output): Observation {
		$obs        = new Observation();
		$chassisKey = Member::key(self::CHASSIS_ID, 'chassis', self::CHASSIS_ID);
		$inlet      = null;
		$ambient    = null;

		foreach (self::sdrRows($output) as $row) {
			$id  = $row['name'];
			$key = Member::key(self::CHASSIS_ID, 'temperature', $id);

			if ($key === null || isset($obs->members[$key])) {
				continue;
			}

			$absent = self::isAbsent($row['status'], $row['reading']);
			$value  = $absent ? null : self::parseTemperature($row['reading']);
			$health = $absent ? null : self::sdrHealthCode($row['status'], $row['reading']);

			$obs->member(new Member($key, 'temperature', self::CHASSIS_ID, $id, Reading::SOURCE_IPMI_SDR, $absent));
			$obs->add(new Reading($key, 'health_code', $health, '', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.status', $row['status']));

			if (!$absent) {
				$obs->add(new Reading($key, 'temperature_celsius', $value, 'Cel', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.temperature', $row['status']));

				$lower = strtolower($id);

				if ($inlet === null && $value !== null && str_contains($lower, 'inlet')) {
					$inlet = $value;
				} elseif ($ambient === null && $value !== null && str_contains($lower, 'ambient')) {
					$ambient = $value;
				}
			}
		}

		if ($chassisKey !== null) {
			if ($inlet !== null) {
				$obs->member(new Member($chassisKey, 'chassis', self::CHASSIS_ID, self::CHASSIS_ID, Reading::SOURCE_IPMI_SDR));
				$obs->add(new Reading($chassisKey, 'inlet_celsius', $inlet, 'Cel', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.inlet'));
			}

			if ($ambient !== null) {
				$obs->member(new Member($chassisKey, 'chassis', self::CHASSIS_ID, self::CHASSIS_ID, Reading::SOURCE_IPMI_SDR));
				$obs->add(new Reading($chassisKey, 'ambient_celsius', $ambient, 'Cel', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.ambient'));
			}
		}

		return $obs;
	}

	/**
	 * Parse "ipmitool sdr type Fan" lines.
	 */
	public static function parseSdrFans(string $output): Observation {
		$obs = new Observation();

		foreach (self::sdrRows($output) as $row) {
			$id  = $row['name'];
			$key = Member::key(self::CHASSIS_ID, 'fan', $id);

			if ($key === null || isset($obs->members[$key])) {
				continue;
			}

			$absent = self::isAbsent($row['status'], $row['reading']);
			$health = $absent ? null : self::sdrHealthCode($row['status'], $row['reading']);

			$obs->member(new Member($key, 'fan', self::CHASSIS_ID, $id, Reading::SOURCE_IPMI_SDR, $absent));
			$obs->add(new Reading($key, 'health_code', $health, '', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.status', $row['status']));

			if (!$absent) {
				[$value, $unit] = self::parseFanReading($row['reading']);
				$obs->add(new Reading($key, 'fan_speed', $value, $unit, Reading::SOURCE_IPMI_SDR, $unit === '' ? 'ipmi.sdr.fan_unknown' : 'ipmi.sdr.fan', $row['status']));
			}
		}

		return $obs;
	}

	/**
	 * Parse "ipmitool sdr type 'Power Supply'" lines.
	 */
	public static function parseSdrPowerSupplies(string $output): Observation {
		$obs            = new Observation();
		$chassisKey     = Member::key(self::CHASSIS_ID, 'chassis', self::CHASSIS_ID);
		$redundancyCode = null;
		$lossCount      = 0;

		foreach (self::sdrRows($output) as $row) {
			$id    = $row['name'];
			$lower = strtolower($id . ' ' . $row['reading']);

			if (str_contains($lower, 'redundancy') && !str_contains($lower, 'watts')) {
				if (str_contains($lower, 'redundancy lost') || str_contains($lower, 'non-redundant') || str_contains($lower, 'insufficient')) {
					$redundancyCode = max($redundancyCode ?? 0.0, 2.0);
					$lossCount      = max($lossCount, 1);
				} elseif (str_contains($lower, 'redundancy degraded') || str_contains($lower, 'degraded')) {
					$redundancyCode = max($redundancyCode ?? 0.0, 1.0);
					$lossCount      = max($lossCount, 1);
				} elseif (str_contains($lower, 'fully redundant') || $row['status'] === 'ok') {
					$redundancyCode ??= 0.0;
				}

				continue;
			}

			$key = Member::key(self::CHASSIS_ID, 'psu', $id);

			if ($key === null || isset($obs->members[$key])) {
				continue;
			}

			$absent = self::isAbsent($row['status'], $row['reading']);
			$health = $absent ? null : self::sdrHealthCode($row['status'], $row['reading']);

			$obs->member(new Member($key, 'psu', self::CHASSIS_ID, $id, Reading::SOURCE_IPMI_SDR, $absent));
			$obs->add(new Reading($key, 'health_code', $health, '', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.status', $row['status']));

			if (!$absent) {
				$watts = self::parseWatts($row['reading']);

				if ($watts !== null) {
					$nameLower = strtolower($id);
					$metric    = str_contains($nameLower, 'out') ? 'psu_output_watts' : 'psu_input_watts';
					$obs->add(new Reading($key, $metric, $watts, 'W', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.psu_watts', $row['status']));
				}
			}
		}

		if ($chassisKey !== null && $redundancyCode !== null) {
			$statusLabel = match ((int) $redundancyCode) {
				0       => RedundancyTelemetry::STATUS_REDUNDANT,
				1       => RedundancyTelemetry::STATUS_DEGRADED,
				default => RedundancyTelemetry::STATUS_LOST,
			};
			$obs->member(new Member($chassisKey, 'chassis', self::CHASSIS_ID, self::CHASSIS_ID, Reading::SOURCE_IPMI_SDR));
			$obs->add(new Reading($chassisKey, 'psu_redundancy_code', $redundancyCode, '', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.psu_redundancy', $statusLabel));
			$obs->add(new Reading($chassisKey, 'psu_redundancy_loss_count', (float) $lossCount, '', Reading::SOURCE_IPMI_SDR, 'ipmi.sdr.psu_redundancy', $statusLabel));
		}

		return $obs;
	}

	/**
	 * Parse fan and PSU redundancy summary from IPMI SDR outputs.
	 *
	 * @param string $fanOutput output of "ipmitool sdr type Fan"
	 * @param string $psuOutput output of "ipmitool sdr type 'Power Supply'"
	 * @return RedundancyTelemetry
	 */
	public static function parseSdrRedundancy(string $fanOutput, string $psuOutput): RedundancyTelemetry {
		return RedundancyTelemetry::fromIpmiSdr($fanOutput, $psuOutput);
	}

	/**
	 * Parse IPMI SEL output into a typed SelSummary.
	 *
	 * @param string $selOutput     output of "ipmitool sel elist" or "ipmitool sel info"
	 * @param int    $windowSeconds rolling window width in seconds
	 * @param int    $now           reference Unix timestamp
	 * @return SelSummary
	 */
	public static function parseSel(string $selOutput, int $windowSeconds = 3600, int $now = 0): SelSummary {
		return HardwareHealthParser::parseIpmiSel($selOutput, $windowSeconds, $now);
	}

	/**
	 * Parse "ipmitool chassis status" fault indicators into health_code.
	 */
	public static function parseChassisFault(string $output): ?float {
		if (trim($output) === '') {
			return null;
		}

		$sawField = false;
		$fault    = false;

		foreach (['Power Fault', 'Cooling/fan fault', 'Drive Fault'] as $field) {
			if (preg_match('/^\s*' . preg_quote($field, '/') . '\s*:\s*(true|false|yes|no)\b/mi', $output, $match) === 1) {
				$sawField = true;
				$val      = strtolower($match[1]);

				if ($val === 'true' || $val === 'yes') {
					$fault = true;
				}
			}
		}

		if (!$sawField) {
			return null;
		}

		return $fault ? 2.0 : 0.0;
	}

	/**
	 * Parse pipe-delimited SDR lines: "Name | SensorID | Status | Entity | Reading".
	 *
	 * @return list<array{name: string, status: string, reading: string}>
	 */
	private static function sdrRows(string $output): array {
		$rows  = [];
		$lines = preg_split('/\r?\n/', $output);

		if (!is_array($lines)) {
			return [];
		}

		foreach (array_slice($lines, 0, Normalizer::MAX_MEMBERS) as $line) {
			if (!str_contains($line, '|')) {
				continue;
			}

			$parts = array_map('trim', explode('|', $line));

			if (count($parts) < 3) {
				continue;
			}

			$name = $parts[0];

			if ($name === '') {
				continue;
			}

			// Standard 5-column "sdr type ..." or 3-column "sdr list" format.
			if (count($parts) >= 5) {
				$status  = strtolower($parts[2]);
				$reading = $parts[4];
			} else {
				$reading = $parts[1];
				$status  = strtolower($parts[2]);
			}

			$rows[] = [
				'name'    => substr($name, 0, 255),
				'status'  => substr($status, 0, 32),
				'reading' => $reading,
			];
		}

		return $rows;
	}

	/**
	 * Is absent.
	 *
	 * @param string $status
	 * @param string $reading
	 * @return bool
	 */
	private static function isAbsent(string $status, string $reading): bool {
		$lower = strtolower($reading);

		return $status === 'ns' && (str_contains($lower, 'not present') || str_contains($lower, 'absent'));
	}

	/**
	 * Sdr health code.
	 *
	 * @param string $status
	 * @param string $reading
	 * @return float|null
	 */
	private static function sdrHealthCode(string $status, string $reading): ?float {
		$lower = strtolower($reading);

		if (str_contains($lower, 'failure detected') || str_contains($lower, 'ac lost') || str_contains($lower, 'predictive failure')) {
			return 2.0;
		}

		if (str_contains($lower, 'redundancy lost') || str_contains($lower, 'degraded')) {
			return 1.0;
		}

		return match ($status) {
			'ok'         => 0.0,
			'nc', 'lnc', 'unc' => 1.0,
			'cr', 'lcr', 'ucr', 'nr', 'lnr', 'unr' => 2.0,
			default      => null,
		};
	}

	/**
	 * Parse temperature.
	 *
	 * @param string $reading
	 * @return float|null
	 */
	private static function parseTemperature(string $reading): ?float {
		if (preg_match('/(-?[0-9]+(?:\.[0-9]+)?)\s*degrees\s*C\b/i', $reading, $match) === 1) {
			return (float) $match[1];
		}

		if (preg_match('/(-?[0-9]+(?:\.[0-9]+)?)\s*degrees\s*F\b/i', $reading, $match) === 1) {
			return round((((float) $match[1]) - 32.0) * 5.0 / 9.0, 2);
		}

		return null;
	}

	/**
	 * @return array{0: ?float, 1: string}
	 */
	private static function parseFanReading(string $reading): array {
		if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*RPM\b/i', $reading, $match) === 1) {
			return [(float) $match[1], 'RPM'];
		}

		if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:percent|%)\b/i', $reading, $match) === 1) {
			return [(float) $match[1], '%'];
		}

		return [null, ''];
	}

	/**
	 * Parse watts.
	 *
	 * @param string $reading
	 * @return float|null
	 */
	private static function parseWatts(string $reading): ?float {
		if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*Watts?\b/i', $reading, $match) === 1) {
			return (float) $match[1];
		}

		return null;
	}
}
