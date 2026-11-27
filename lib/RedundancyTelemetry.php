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
 * Typed fan and power supply redundancy summary normalized from Redfish
 * Thermal, ThermalSubsystem, Power, PowerSubsystem, or IPMI SDR telemetry.
 *
 * Unknown measurements stay null rather than fabricating zero values.
 */
final readonly class RedundancyTelemetry {
	/**
	 * Redundancy state when all required failover capacity is intact.
	 */
	public const STATUS_REDUNDANT = 'Redundant';

	/**
	 * Redundancy state when redundancy is impaired or operating with reduced margin.
	 */
	public const STATUS_DEGRADED = 'Degraded';

	/**
	 * Redundancy state when N+1 failover capacity has been lost.
	 */
	public const STATUS_LOST = 'Lost';

	/**
	 * Redundancy state when the BMC did not report enough data to classify redundancy.
	 */
	public const STATUS_UNKNOWN = 'Unknown';

	/**
	 * Initialize a new RedundancyTelemetry snapshot.
	 *
	 * @param int    $fansTotal              present or observed fan count
	 * @param int    $fansDegraded           fans reporting warning/degraded state
	 * @param int    $fansFailed             fans reporting critical/failed state
	 * @param ?float $fanMinRpm              minimum observed fan speed in RPM
	 * @param ?float $fanMaxRpm              maximum observed fan speed in RPM
	 * @param ?float $fanMinPercent          minimum observed fan speed in percent
	 * @param ?float $fanMaxPercent          maximum observed fan speed in percent
	 * @param int    $psusTotal              present or observed power supply count
	 * @param int    $psusHealthy            healthy power supplies
	 * @param int    $psusFailed             degraded or critical power supplies
	 * @param string $psuRedundancyStatus    one of Redundant, Degraded, Lost, Unknown
	 * @param int    $psuRedundancyLossCount number of lost redundant supplies (N+1 shortfall or failed PSU count)
	 */
	public function __construct(
		public int $fansTotal,
		public int $fansDegraded,
		public int $fansFailed,
		public ?float $fanMinRpm,
		public ?float $fanMaxRpm,
		public ?float $fanMinPercent,
		public ?float $fanMaxPercent,
		public int $psusTotal,
		public int $psusHealthy,
		public int $psusFailed,
		public string $psuRedundancyStatus,
		public int $psuRedundancyLossCount,
	) {
	}

	/**
	 * Numeric code for PSU redundancy status: 0=Redundant, 1=Degraded, 2=Lost, null=Unknown.
	 *
	 * @return ?float
	 */
	public function psuRedundancyCode(): ?float {
		return match ($this->psuRedundancyStatus) {
			self::STATUS_REDUNDANT => 0.0,
			self::STATUS_DEGRADED  => 1.0,
			self::STATUS_LOST      => 2.0,
			default                => null,
		};
	}

	/**
	 * Parse PSU redundancy telemetry from a Redfish legacy Power or modern
	 * PowerSubsystem resource, with optional subsystem PowerSupply resources.
	 *
	 * @param array<string, mixed>       $powerResource decoded Power or PowerSubsystem payload
	 * @param list<array<string, mixed>> $psuResources  optional decoded PowerSupply resources for PowerSubsystem
	 * @return array{status: string, loss_count: int, total: int, healthy: int, failed: int}
	 */
	public static function fromRedfishPower(array $powerResource, array $psuResources = []): array {
		$total   = 0;
		$healthy = 0;
		$failed  = 0;

		$supplies = $psuResources;

		if ($supplies === [] && is_array($powerResource['PowerSupplies'] ?? null)) {
			foreach ($powerResource['PowerSupplies'] as $entry) {
				if (is_array($entry) && !isset($entry['@odata.id'])) {
					/** @var array<string, mixed> $entry */
					$supplies[] = $entry;
				}
			}
		}

		foreach ($supplies as $psu) {
			$status = Normalizer::status($psu['Status'] ?? null);

			if ($status['absent']) {
				continue;
			}

			$total++;

			if ($status['code'] === 0) {
				$healthy++;
			} elseif ($status['code'] !== null && $status['code'] >= 1) {
				$failed++;
			}
		}

		$redundancyGroups = [];

		if (is_array($powerResource['PowerSupplyRedundancy'] ?? null)) {
			$redundancyGroups = $powerResource['PowerSupplyRedundancy'];
		} elseif (is_array($powerResource['Redundancy'] ?? null)) {
			$redundancyGroups = $powerResource['Redundancy'];
		}

		$status    = self::STATUS_UNKNOWN;
		$lossCount = 0;

		foreach ($redundancyGroups as $group) {
			if (!is_array($group)) {
				continue;
			}

			$groupStatus = self::classifyRedfishRedundancyGroup($group, $total, $healthy, $failed);
			$status      = self::worseStatus($status, $groupStatus['status']);
			$lossCount   = max($lossCount, $groupStatus['loss_count']);
		}

		if ($status === self::STATUS_UNKNOWN && $total >= 2) {
			if ($failed === 0 && $healthy >= 2) {
				$status = self::STATUS_REDUNDANT;
			} elseif ($healthy >= 1 && $failed >= 1) {
				$status    = $healthy === 1 ? self::STATUS_LOST : self::STATUS_DEGRADED;
				$lossCount = $failed;
			} elseif ($failed > 0) {
				$status    = self::STATUS_LOST;
				$lossCount = $failed;
			}
		}

		return [
			'status'     => $status,
			'loss_count' => $lossCount,
			'total'      => $total,
			'healthy'    => $healthy,
			'failed'     => $failed,
		];
	}

	/**
	 * Parse fan speed and fault telemetry from a Redfish legacy Thermal or
	 * ThermalSubsystem payload plus optional Fan resources.
	 *
	 * @param array<string, mixed>       $thermalResource decoded Thermal or ThermalSubsystem payload
	 * @param list<array<string, mixed>> $fanResources    optional decoded Fan resources
	 * @param string                     $family          vendor family for fan speed normalization
	 * @return array{total: int, degraded: int, failed: int, min_rpm: ?float, max_rpm: ?float, min_percent: ?float, max_percent: ?float}
	 */
	public static function fromRedfishThermal(array $thermalResource, array $fanResources = [], string $family = 'other'): array {
		$total      = 0;
		$degraded   = 0;
		$failed     = 0;
		$minRpm     = null;
		$maxRpm     = null;
		$minPercent = null;
		$maxPercent = null;

		$fans = $fanResources;

		if ($fans === [] && is_array($thermalResource['Fans'] ?? null)) {
			foreach ($thermalResource['Fans'] as $entry) {
				if (is_array($entry) && !isset($entry['@odata.id'])) {
					/** @var array<string, mixed> $entry */
					$fans[] = $entry;
				}
			}
		}

		foreach ($fans as $fan) {
			$status = Normalizer::status($fan['Status'] ?? null);

			if ($status['absent']) {
				continue;
			}

			$total++;

			if ($status['code'] === 1) {
				$degraded++;
			} elseif ($status['code'] !== null && $status['code'] >= 2) {
				$failed++;
			}

			$value = null;
			$unit  = '';

			if (isset($fan['SpeedPercent']) && is_array($fan['SpeedPercent'])) {
				[$value, $unit] = Normalizer::fanSpeed($fan['SpeedPercent'], '', $family);
			} else {
				$rawUnit = $fan['ReadingUnits'] ?? null;

				if ($rawUnit === 'RPM' || $rawUnit === 'Percent') {
					$unit  = $rawUnit === 'RPM' ? 'RPM' : '%';
					$value = Normalizer::number($fan['Reading'] ?? null);
				}
			}

			if ($value !== null && $unit === 'RPM') {
				$minRpm = $minRpm === null ? $value : min($minRpm, $value);
				$maxRpm = $maxRpm === null ? $value : max($maxRpm, $value);
			} elseif ($value !== null && $unit === '%') {
				$minPercent = $minPercent === null ? $value : min($minPercent, $value);
				$maxPercent = $maxPercent === null ? $value : max($maxPercent, $value);
			}
		}

		return [
			'total'       => $total,
			'degraded'    => $degraded,
			'failed'      => $failed,
			'min_rpm'     => $minRpm,
			'max_rpm'     => $maxRpm,
			'min_percent' => $minPercent,
			'max_percent' => $maxPercent,
		];
	}

	/**
	 * Build RedundancyTelemetry from an already-normalized Observation and an
	 * optional Redfish Power or PowerSubsystem payload.
	 *
	 * @param Observation               $obs           normalized Redfish or IPMI observation
	 * @param array<string, mixed>|null $powerResource optional Redfish Power or PowerSubsystem payload
	 * @return self
	 */
	public static function fromObservation(Observation $obs, ?array $powerResource = null): self {
		$fansTotal   = 0;
		$fansDeg     = 0;
		$fansFailed  = 0;
		$minRpm      = null;
		$maxRpm      = null;
		$minPercent  = null;
		$maxPercent  = null;
		$psusTotal   = 0;
		$psusHealthy = 0;
		$psusFailed  = 0;

		foreach ($obs->members as $member) {
			if ($member->absent) {
				continue;
			}

			if ($member->kind === 'fan') {
				if (str_ends_with($member->key, ':secondary')) {
					continue;
				}

				$fansTotal++;
				$health = $obs->find($member->key, 'health_code')?->value;

				if ($health === 1.0) {
					$fansDeg++;
				} elseif ($health !== null && $health >= 2.0) {
					$fansFailed++;
				}

				$speed = $obs->find($member->key, 'fan_speed');

				if ($speed !== null && $speed->value !== null) {
					if ($speed->unit === 'RPM') {
						$minRpm = $minRpm === null ? $speed->value : min($minRpm, $speed->value);
						$maxRpm = $maxRpm === null ? $speed->value : max($maxRpm, $speed->value);
					} elseif ($speed->unit === '%') {
						$minPercent = $minPercent === null ? $speed->value : min($minPercent, $speed->value);
						$maxPercent = $maxPercent === null ? $speed->value : max($maxPercent, $speed->value);
					}
				}
			} elseif ($member->kind === 'psu') {
				$psusTotal++;
				$health = $obs->find($member->key, 'health_code')?->value;

				if ($health === 0.0) {
					$psusHealthy++;
				} elseif ($health !== null && $health >= 1.0) {
					$psusFailed++;
				}
			}
		}

		$psuStatus = self::STATUS_UNKNOWN;
		$lossCount = 0;

		if ($powerResource !== null) {
			$parsed    = self::fromRedfishPower($powerResource);
			$psuStatus = $parsed['status'];
			$lossCount = $parsed['loss_count'];

			if ($psusTotal === 0) {
				$psusTotal   = $parsed['total'];
				$psusHealthy = $parsed['healthy'];
				$psusFailed  = $parsed['failed'];
			}
		} else {
			// Check whether a chassis-level redundancy reading was already emitted.
			foreach ($obs->readings as $reading) {
				if ($reading->metric === 'psu_redundancy_code' && $reading->value !== null) {
					$codeStatus = match ((int) $reading->value) {
						0       => self::STATUS_REDUNDANT,
						1       => self::STATUS_DEGRADED,
						2       => self::STATUS_LOST,
						default => self::STATUS_UNKNOWN,
					};
					$psuStatus = self::worseStatus($psuStatus, $codeStatus);
				} elseif ($reading->metric === 'psu_redundancy_loss_count' && $reading->value !== null) {
					$lossCount = max($lossCount, (int) $reading->value);
				}
			}

			if ($psuStatus === self::STATUS_UNKNOWN && $psusTotal >= 2) {
				if ($psusFailed === 0 && $psusHealthy >= 2) {
					$psuStatus = self::STATUS_REDUNDANT;
				} elseif ($psusHealthy >= 1 && $psusFailed >= 1) {
					$psuStatus = $psusHealthy === 1 ? self::STATUS_LOST : self::STATUS_DEGRADED;
					$lossCount = $psusFailed;
				} elseif ($psusFailed > 0) {
					$psuStatus = self::STATUS_LOST;
					$lossCount = $psusFailed;
				}
			}
		}

		return new self(
			fansTotal: $fansTotal,
			fansDegraded: $fansDeg,
			fansFailed: $fansFailed,
			fanMinRpm: $minRpm,
			fanMaxRpm: $maxRpm,
			fanMinPercent: $minPercent,
			fanMaxPercent: $maxPercent,
			psusTotal: $psusTotal,
			psusHealthy: $psusHealthy,
			psusFailed: $psusFailed,
			psuRedundancyStatus: $psuStatus,
			psuRedundancyLossCount: $lossCount,
		);
	}

	/**
	 * Parse fan and PSU redundancy telemetry directly from IPMI SDR text output.
	 *
	 * @param string $fanSdr output of "ipmitool sdr type Fan"
	 * @param string $psuSdr output of "ipmitool sdr type 'Power Supply'"
	 * @return self
	 */
	public static function fromIpmiSdr(string $fanSdr, string $psuSdr): self {
		$obs = new Observation();
		$obs->merge(IpmiNormalizer::parseSdrFans($fanSdr));
		$obs->merge(IpmiNormalizer::parseSdrPowerSupplies($psuSdr));

		return self::fromObservation($obs);
	}

	/**
	 * Convert this redundancy summary into normalized Reading instances.
	 *
	 * @param string $memberKey member key to attach readings to (default Member::NODE)
	 * @param string $source    reading source identifier
	 * @return list<Reading>
	 */
	public function toReadings(string $memberKey = Member::NODE, string $source = Reading::SOURCE_NODE): array {
		$readings = [
			new Reading($memberKey, 'fans_degraded', (float) $this->fansDegraded, '', $source, 'redundancy.fans'),
			new Reading($memberKey, 'fans_failed', (float) $this->fansFailed, '', $source, 'redundancy.fans'),
			new Reading($memberKey, 'psu_redundancy_code', $this->psuRedundancyCode(), '', $source, 'redundancy.psu', $this->psuRedundancyStatus),
			new Reading($memberKey, 'psu_redundancy_loss_count', (float) $this->psuRedundancyLossCount, '', $source, 'redundancy.psu_loss', $this->psuRedundancyStatus),
		];

		if ($this->fanMinRpm !== null) {
			$readings[] = new Reading($memberKey, 'fan_min_rpm', $this->fanMinRpm, 'RPM', $source, 'redundancy.fan_rpm');
			$readings[] = new Reading($memberKey, 'fan_max_rpm', $this->fanMaxRpm, 'RPM', $source, 'redundancy.fan_rpm');
		}

		if ($this->fanMinPercent !== null) {
			$readings[] = new Reading($memberKey, 'fan_min_percent', $this->fanMinPercent, '%', $source, 'redundancy.fan_percent');
			$readings[] = new Reading($memberKey, 'fan_max_percent', $this->fanMaxPercent, '%', $source, 'redundancy.fan_percent');
		}

		return $readings;
	}

	/**
	 * Machine-readable array representation for CLI JSON and MCP tools.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'fans_total'                => $this->fansTotal,
			'fans_degraded'             => $this->fansDegraded,
			'fans_failed'               => $this->fansFailed,
			'fan_min_rpm'               => $this->fanMinRpm,
			'fan_max_rpm'               => $this->fanMaxRpm,
			'fan_min_percent'           => $this->fanMinPercent,
			'fan_max_percent'           => $this->fanMaxPercent,
			'psus_total'                => $this->psusTotal,
			'psus_healthy'              => $this->psusHealthy,
			'psus_failed'               => $this->psusFailed,
			'psu_redundancy_status'     => $this->psuRedundancyStatus,
			'psu_redundancy_code'       => $this->psuRedundancyCode(),
			'psu_redundancy_loss_count' => $this->psuRedundancyLossCount,
		];
	}

	/**
	 * Classify a single Redfish Redundancy or PowerSupplyRedundancy group.
	 *
	 * @param array<array-key, mixed> $group   Redfish redundancy group object
	 * @param int                     $total   observed PSU count
	 * @param int                     $healthy healthy PSU count
	 * @param int                     $failed  failed PSU count
	 * @return array{status: string, loss_count: int}
	 */
	private static function classifyRedfishRedundancyGroup(array $group, int $total, int $healthy, int $failed): array {
		$statusObj = is_array($group['Status'] ?? null) ? $group['Status'] : [];
		$health    = is_string($statusObj['Health'] ?? null) ? $statusObj['Health'] : '';
		$state     = is_string($statusObj['State'] ?? null) ? $statusObj['State'] : '';
		$minNeeded = Normalizer::number($group['MinNumNeeded'] ?? ($group['MinNeededInGroup'] ?? null));
		$groupSize = 0;

		if (is_array($group['RedundancyGroup'] ?? null)) {
			$groupSize = count($group['RedundancyGroup']);
		} elseif (is_array($group['RedundancySet'] ?? null)) {
			$groupSize = count($group['RedundancySet']);
		}

		$lossCount = $failed;

		if ($minNeeded !== null && $minNeeded > 0.0) {
			$expectedRedundant = max((int) $minNeeded + 1, $groupSize, $total);

			if ($healthy > 0 || $total > 0) {
				$lossCount = max($lossCount, max(0, $expectedRedundant - $healthy));
			}
		}

		if ($health === 'Critical' || $state === 'UnavailableOffline' || $state === 'Disabled') {
			return ['status' => self::STATUS_LOST, 'loss_count' => max(1, $lossCount)];
		}

		if ($health === 'Warning' || $state === 'Degraded') {
			return ['status' => self::STATUS_DEGRADED, 'loss_count' => max(1, $lossCount)];
		}

		if ($health === 'OK' && ($state === 'Enabled' || $state === '')) {
			if ($minNeeded !== null && $healthy > 0 && $healthy <= (int) $minNeeded) {
				return ['status' => self::STATUS_LOST, 'loss_count' => max(1, $lossCount)];
			}

			return ['status' => self::STATUS_REDUNDANT, 'loss_count' => 0];
		}

		return ['status' => self::STATUS_UNKNOWN, 'loss_count' => $lossCount];
	}

	/**
	 * Return the worse of two PSU redundancy status strings.
	 *
	 * @param string $a first status
	 * @param string $b second status
	 * @return string
	 */
	private static function worseStatus(string $a, string $b): string {
		$rank = static fn (string $s): int => match ($s) {
			self::STATUS_LOST      => 3,
			self::STATUS_DEGRADED  => 2,
			self::STATUS_REDUNDANT => 1,
			default                => 0,
		};

		return $rank($b) > $rank($a) ? $b : $a;
	}
}
