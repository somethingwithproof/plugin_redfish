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
 * Pure parsers for enterprise BMC hardware health telemetry:
 * - Redfish Memory ECC metrics (Systems/{id}/Memory/{id}/MemoryMetrics)
 * - Redfish Storage/NVMe drive wear and SMART health (Systems/{id}/Storage/{id}/Drives/{id})
 * - BMC SEL critical and warning event counts/rates (Redfish LogServices/SEL/Entries and IPMI SEL)
 */
final class HardwareHealthParser {
	/**
	 * Maximum SEL entries scanned per payload to bound CPU and memory usage.
	 */
	public const MAX_SEL_ENTRIES = 2048;

	/**
	 * Predicted wear percentage at or above which drive health elevates to Warning.
	 */
	public const DRIVE_WEAR_WARNING_PCT = 80.0;

	/**
	 * Predicted wear percentage at or above which drive health elevates to Critical.
	 */
	public const DRIVE_WEAR_CRITICAL_PCT = 95.0;

	/**
	 * Parse Redfish MemoryMetrics (Systems/{id}/Memory/{id}/MemoryMetrics) into
	 * normalized DIMM ECC error readings.
	 *
	 * @param string                    $systemId       owning Redfish ComputerSystem ID
	 * @param string                    $dimmId         stable Memory resource ID
	 * @param array<string, mixed>      $metrics        decoded MemoryMetrics JSON payload
	 * @param array<string, mixed>|null $memoryResource optional decoded parent Memory resource for Name/Status
	 * @return Observation
	 */
	public static function parseMemoryMetrics(
		string $systemId,
		string $dimmId,
		array $metrics,
		?array $memoryResource = null,
	): Observation {
		$obs = new Observation();
		$key = Member::key($systemId, 'memory', $dimmId);

		if ($key === null) {
			return $obs;
		}

		$status = Normalizer::status($memoryResource['Status'] ?? ($metrics['Status'] ?? null));
		$name   = is_string($memoryResource['Name'] ?? null)
			? substr($memoryResource['Name'], 0, 255)
			: (is_string($metrics['Name'] ?? null) ? substr($metrics['Name'], 0, 255) : $dimmId);

		$obs->member(new Member($key, 'memory', $systemId, $name, Reading::SOURCE_SENSOR, $status['absent']));

		if ($status['absent']) {
			$obs->add(new Reading($key, 'health_code', null, '', Reading::SOURCE_SENSOR, 'memory.absent', $status['state']));

			return $obs;
		}

		$lifeTime      = is_array($metrics['LifeTime'] ?? null) ? $metrics['LifeTime'] : [];
		$currentPeriod = is_array($metrics['CurrentPeriod'] ?? null) ? $metrics['CurrentPeriod'] : [];

		$corrected        = Normalizer::number($lifeTime['CorrectableECCErrorCount'] ?? null);
		$uncorrected      = Normalizer::number($lifeTime['UncorrectableECCErrorCount'] ?? null);
		$periodCorrected  = Normalizer::number($currentPeriod['CorrectableECCErrorCount'] ?? null);
		$periodUncorrect  = Normalizer::number($currentPeriod['UncorrectableECCErrorCount'] ?? null);

		$healthCode = $status['code'];

		if (($uncorrected !== null && $uncorrected > 0.0) || ($periodUncorrect !== null && $periodUncorrect > 0.0)) {
			$healthCode = 2;
		} elseif ($healthCode === null && (($corrected !== null && $corrected > 0.0) || ($periodCorrected !== null && $periodCorrected > 0.0))) {
			$healthCode = 1;
		}

		$obs->add(new Reading($key, 'health_code', $healthCode === null ? null : (float) $healthCode, '', Reading::SOURCE_SENSOR, 'memory.ecc_health', $status['state']));
		$obs->add(new Reading($key, 'ecc_corrected_errors', $corrected, '', Reading::SOURCE_SENSOR, 'memory.ecc_lifetime', $status['state']));
		$obs->add(new Reading($key, 'ecc_uncorrected_errors', $uncorrected, '', Reading::SOURCE_SENSOR, 'memory.ecc_lifetime', $status['state']));

		if ($periodCorrected !== null) {
			$obs->add(new Reading($key, 'ecc_period_corrected_errors', $periodCorrected, '', Reading::SOURCE_SENSOR, 'memory.ecc_period', $status['state']));
		}

		if ($periodUncorrect !== null) {
			$obs->add(new Reading($key, 'ecc_period_uncorrected_errors', $periodUncorrect, '', Reading::SOURCE_SENSOR, 'memory.ecc_period', $status['state']));
		}

		return $obs;
	}

	/**
	 * Parse Redfish Drive health (Systems/{id}/Storage/{id}/Drives/{id}) for
	 * NVMe/SSD/HDD predicted media wear and SMART failure state.
	 *
	 * @param string               $systemId owning Redfish ComputerSystem ID
	 * @param string               $driveId  stable Drive resource ID
	 * @param array<string, mixed> $drive    decoded Drive JSON payload
	 * @return Observation
	 */
	public static function parseDriveHealth(string $systemId, string $driveId, array $drive): Observation {
		$obs = new Observation();
		$key = Member::key($systemId, 'drive', $driveId);

		if ($key === null) {
			return $obs;
		}

		$status = Normalizer::status($drive['Status'] ?? null);
		$name   = is_string($drive['Name'] ?? null) ? substr($drive['Name'], 0, 255) : $driveId;

		$obs->member(new Member($key, 'drive', $systemId, $name, Reading::SOURCE_SENSOR, $status['absent']));

		if ($status['absent']) {
			$obs->add(new Reading($key, 'health_code', null, '', Reading::SOURCE_SENSOR, 'drive.absent', $status['state']));

			return $obs;
		}

		$lifeLeft = Normalizer::number($drive['PredictedMediaLifeLeftPercent'] ?? null);
		$wearPct  = null;

		if ($lifeLeft !== null) {
			$lifeLeft = max(0.0, min(100.0, $lifeLeft));
			$wearPct  = round(100.0 - $lifeLeft, 2);
		} else {
			$nvmeWear = self::extractOemWearPercent($drive);

			if ($nvmeWear !== null) {
				$wearPct  = max(0.0, min(100.0, round($nvmeWear, 2)));
				$lifeLeft = round(100.0 - $wearPct, 2);
			}
		}

		$failurePredicted = is_bool($drive['FailurePredicted'] ?? null) ? $drive['FailurePredicted'] : null;
		$healthCode       = $status['code'];

		if ($failurePredicted === true || ($wearPct !== null && $wearPct >= self::DRIVE_WEAR_CRITICAL_PCT)) {
			$healthCode = 2;
		} elseif ($wearPct !== null && $wearPct >= self::DRIVE_WEAR_WARNING_PCT) {
			$healthCode = max(1, $healthCode ?? 1);
		}

		$obs->add(new Reading($key, 'health_code', $healthCode === null ? null : (float) $healthCode, '', Reading::SOURCE_SENSOR, 'drive.smart_health', $status['state']));
		$obs->add(new Reading($key, 'drive_predicted_wear_pct', $wearPct, '%', Reading::SOURCE_SENSOR, 'drive.media_wear', $status['state']));
		$obs->add(new Reading($key, 'drive_media_life_left_pct', $lifeLeft, '%', Reading::SOURCE_SENSOR, 'drive.media_life', $status['state']));
		$obs->add(new Reading(
			$key,
			'drive_failure_predicted',
			$failurePredicted === null ? null : ($failurePredicted ? 1.0 : 0.0),
			'',
			Reading::SOURCE_SENSOR,
			'drive.failure_predicted',
			$status['state']
		));

		return $obs;
	}

	/**
	 * Parse Redfish Managers/{id}/LogServices/SEL/Entries collection payload
	 * into critical/warning counts and hourly rates over $windowSeconds.
	 *
	 * @param string               $managerId     Redfish Manager ID
	 * @param array<string, mixed> $entries       decoded LogEntryCollection JSON payload
	 * @param int                  $windowSeconds rolling window width in seconds (> 0)
	 * @param int                  $now           reference Unix timestamp
	 * @return SelSummary
	 */
	public static function parseRedfishSel(
		string $managerId,
		array $entries,
		int $windowSeconds = 3600,
		int $now = 0,
	): SelSummary {
		$window         = max(60, $windowSeconds);
		$cutoff         = $now > 0 ? $now - $window : 0;
		$members        = is_array($entries['Members'] ?? null) ? array_slice($entries['Members'], 0, self::MAX_SEL_ENTRIES) : [];
		$total          = 0;
		$critical       = 0;
		$warning        = 0;
		$windowCritical = 0;
		$windowWarning  = 0;
		$overflow       = false;

		if (is_bool($entries['Overflow'] ?? null)) {
			$overflow = $entries['Overflow'];
		}

		/** @var array<string, int|false> $tsCache */
		$tsCache = [];

		foreach ($members as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$total++;
			$severity = is_string($entry['Severity'] ?? null) ? strtolower(trim($entry['Severity'])) : '';
			$created  = is_string($entry['Created'] ?? null) ? trim($entry['Created']) : '';
			$inWindow = true;

			if ($now > 0 && $created !== '') {
				if (!array_key_exists($created, $tsCache)) {
					$tsCache[$created] = strtotime($created);
				}

				$ts       = $tsCache[$created];
				$inWindow = $ts !== false && $ts >= $cutoff && $ts <= $now + 300;
			}

			if ($severity === 'critical') {
				$critical++;

				if ($inWindow) {
					$windowCritical++;
				}
			} elseif ($severity === 'warning') {
				$warning++;

				if ($inWindow) {
					$windowWarning++;
				}
			}
		}

		$hours = $window / 3600.0;

		return new SelSummary(
			managerId: $managerId,
			totalEntries: $total,
			criticalCount: $critical,
			warningCount: $warning,
			windowCriticalCount: $windowCritical,
			windowWarningCount: $windowWarning,
			criticalRatePerHour: round($windowCritical / $hours, 2),
			warningRatePerHour: round($windowWarning / $hours, 2),
			percentUsed: Normalizer::number($entries['PercentUsed'] ?? null),
			overflow: $overflow,
			source: Reading::SOURCE_SENSOR,
		);
	}

	/**
	 * Parse IPMI SEL output ("ipmitool sel elist" and/or "ipmitool sel info")
	 * into critical/warning counts, hourly rates, and buffer utilization.
	 *
	 * @param string $selOutput     raw stdout from ipmitool sel elist or sel info
	 * @param int    $windowSeconds rolling window width in seconds (> 0)
	 * @param int    $now           reference Unix timestamp (0 counts all dated entries in window)
	 * @return SelSummary
	 */
	public static function parseIpmiSel(string $selOutput, int $windowSeconds = 3600, int $now = 0): SelSummary {
		$window         = max(60, $windowSeconds);
		$cutoff         = $now > 0 ? $now - $window : 0;
		$total          = 0;
		$infoEntries    = null;
		$critical       = 0;
		$warning        = 0;
		$windowCritical = 0;
		$windowWarning  = 0;
		$percentUsed    = null;
		$overflow       = false;

		$lines = preg_split('/\r?\n/', $selOutput);

		if (!is_array($lines)) {
			$lines = [];
		}

		$scanned = 0;

		foreach ($lines as $line) {
			$trimmed = trim($line);

			if ($trimmed === '') {
				continue;
			}

			// Parse "ipmitool sel info" metadata lines when present.
			if (!str_contains($trimmed, '|')) {
				if (preg_match('/^Entries\s*:\s*([0-9]+)\b/i', $trimmed, $m) === 1) {
					$infoEntries = (int) $m[1];
				} elseif (preg_match('/^Percent Used\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*%/i', $trimmed, $m) === 1) {
					$percentUsed = (float) $m[1];
				} elseif (preg_match('/^Overflow\s*:\s*(true|yes)\b/i', $trimmed) === 1) {
					$overflow = true;
				}

				continue;
			}

			if ($scanned >= self::MAX_SEL_ENTRIES) {
				break;
			}

			$parts = array_map('trim', explode('|', $trimmed));

			if (count($parts) < 5) {
				continue;
			}

			$scanned++;
			$total++;

			$dateStr = $parts[1];
			$timeStr = $parts[2];
			$desc    = strtolower(implode(' ', array_slice($parts, 3)));

			$inWindow = true;

			if ($now > 0 && $dateStr !== '' && $timeStr !== '' && !str_contains($dateStr, 'Pre-Init')) {
				$ts       = strtotime($dateStr . ' ' . $timeStr . ' UTC');
				$inWindow = $ts !== false && $ts >= $cutoff && $ts <= $now + 300;
			}

			if (self::isIpmiSelCritical($desc)) {
				$critical++;

				if ($inWindow) {
					$windowCritical++;
				}
			} elseif (self::isIpmiSelWarning($desc)) {
				$warning++;

				if ($inWindow) {
					$windowWarning++;
				}
			}
		}

		$hours = $window / 3600.0;

		return new SelSummary(
			managerId: IpmiNormalizer::CHASSIS_ID,
			totalEntries: $total > 0 ? $total : ($infoEntries ?? 0),
			criticalCount: $critical,
			warningCount: $warning,
			windowCriticalCount: $windowCritical,
			windowWarningCount: $windowWarning,
			criticalRatePerHour: round($windowCritical / $hours, 2),
			warningRatePerHour: round($windowWarning / $hours, 2),
			percentUsed: $percentUsed,
			overflow: $overflow,
			source: Reading::SOURCE_IPMI_SEL,
		);
	}

	/**
	 * Extract vendor OEM NVMe/SSD wear percentage when PredictedMediaLifeLeftPercent is absent.
	 *
	 * @param array<string, mixed> $drive decoded Drive JSON payload
	 * @return ?float
	 */
	private static function extractOemWearPercent(array $drive): ?float {
		if (!is_array($drive['Oem'] ?? null)) {
			return null;
		}

		foreach ($drive['Oem'] as $vendorBlock) {
			if (!is_array($vendorBlock)) {
				continue;
			}

			foreach (['NVMeSMARTPercentageUsed', 'MediaWearoutIndicator', 'PercentageUsed'] as $field) {
				$val = Normalizer::number($vendorBlock[$field] ?? null);

				if ($val !== null) {
					return $val;
				}
			}
		}

		return null;
	}

	/**
	 * Classify whether an IPMI SEL description indicates a critical hardware event.
	 *
	 * @param string $desc lowercased SEL sensor + event description
	 * @return bool
	 */
	private static function isIpmiSelCritical(string $desc): bool {
		if (str_contains($desc, 'deasserted')) {
			return false;
		}

		return str_contains($desc, 'non-recoverable')
			|| str_contains($desc, 'uncorrectable')
			|| str_contains($desc, 'upper critical')
			|| str_contains($desc, 'lower critical')
			|| str_contains($desc, 'critical interrupt')
			|| str_contains($desc, 'ac lost')
			|| str_contains($desc, 'redundancy lost')
			|| str_contains($desc, 'power supply failure')
			|| str_contains($desc, 'drive fault')
			|| str_contains($desc, 'failure detected');
	}

	/**
	 * Classify whether an IPMI SEL description indicates a warning hardware event.
	 *
	 * @param string $desc lowercased SEL sensor + event description
	 * @return bool
	 */
	private static function isIpmiSelWarning(string $desc): bool {
		if (str_contains($desc, 'deasserted')) {
			return false;
		}

		return str_contains($desc, 'non-critical')
			|| str_contains($desc, 'correctable')
			|| str_contains($desc, 'predictive failure')
			|| str_contains($desc, 'degraded')
			|| str_contains($desc, 'warning')
			|| str_contains($desc, 'throttl');
	}
}
