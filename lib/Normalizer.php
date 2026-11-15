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
 * Turns one decoded resource into members and readings (ADR 0013 per-reading
 * source choice, docs/research/sensors.md and health-mapping.md).
 *
 * Pure: no network, clock, or database (REQ-CODE-012). Every rule that
 * cannot vouch for a value yields null, which reads as U (REQ-MODEL-002).
 */
final class Normalizer {
	public const MAX_MEMBERS = 128;

	/**
	 * @param array<string, mixed> $resource
	 */
	public static function normalize(Target $target, array $resource, string $family): Observation {
		return match ($target->kind) {
			Target::ENVIRONMENT     => self::environment($target, $resource),
			Target::THERMAL_METRICS => self::thermalMetrics($target, $resource, $family),
			Target::FAN             => self::fan($target, $resource, $family),
			Target::PSU             => self::psu($target, $resource),
			Target::PSU_METRICS     => self::psuMetrics($target, $resource),
			Target::LEGACY_THERMAL  => self::legacyThermal($target, $resource),
			Target::LEGACY_POWER    => self::legacyPower($target, $resource),
			default                 => new Observation(),
		};
	}

	/**
	 * Health from a Status object (REQ-MODEL-005, REQ-MODEL-006): OK,
	 * Warning, and Critical are 0, 1, and 2; null or missing health is
	 * unknown. State Absent is absent, with no health. Degraded is at least
	 * Warning. Any other state but Enabled leaves health unknown, because a
	 * disabled or starting member's health says nothing about the hardware.
	 *
	 * @return array{code: ?int, state: string, absent: bool}
	 */
	public static function status(mixed $status): array {
		$status = is_array($status) ? $status : [];
		$state  = is_string($status['State'] ?? null) ? $status['State'] : '';
		$health = match ($status['Health'] ?? null) {
			'OK'       => 0,
			'Warning'  => 1,
			'Critical' => 2,
			default    => null,
		};

		return match ($state) {
			'Absent'      => ['code' => null, 'state' => $state, 'absent' => true],
			'Enabled', '' => ['code' => $health, 'state' => $state, 'absent' => false],
			'Degraded'    => ['code' => max(1, $health ?? 1), 'state' => $state, 'absent' => false],
			default       => ['code' => null, 'state' => substr($state, 0, 32), 'absent' => false],
		};
	}

	/**
	 * A JSON number, or null. Strings, booleans, and non-finite values are
	 * not measurements.
	 */
	public static function number(mixed $value): ?float {
		return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
	}

	/**
	 * The value of an excerpt's Reading, such as PowerWatts.Reading.
	 *
	 * @param array<array-key, mixed> $object
	 */
	public static function excerpt(array $object, string $key, string $field = 'Reading'): ?float {
		return is_array($object[$key] ?? null) ? self::number($object[$key][$field] ?? null) : null;
	}

	/**
	 * Fan speed and unit (REQ-MODEL-003, docs/research/sensors.md).
	 *
	 * The unit comes from the sensor the excerpt references, cached at
	 * discovery. Without one, SpeedPercent.Reading is percent as Fan.v1_6_0
	 * defines it, unless SpeedRPM equals it (the Lenovo shape: the value is
	 * RPM) or the family is a known deviation. SpeedRPM alone is RPM.
	 * Anything else is unknown.
	 *
	 * @return array{0: ?float, 1: string, 2: string} value, unit, rule
	 */
	public static function fanSpeed(mixed $excerpt, string $sensorUnit, string $family): array {
		if (!is_array($excerpt)) {
			return [null, '', 'fan.none'];
		}

		$reading = self::number($excerpt['Reading'] ?? null);
		$rpm     = self::number($excerpt['SpeedRPM'] ?? null);

		return match (true) {
			$sensorUnit === 'percent'                                  => [$reading, '%', 'fan.sensor_percent'],
			$sensorUnit === 'rpm'                                      => [$rpm ?? $reading, 'RPM', 'fan.sensor_rpm'],
			$sensorUnit !== ''                                         => [null, '', 'fan.sensor_unknown'],
			$reading !== null && $rpm !== null && $rpm === $reading    => [$reading, 'RPM', 'fan.rpm_in_reading'],
			$reading !== null && in_array($family, ServiceRoot::FAN_UNIT_DEVIATIONS, true) => [null, '', 'fan.family_deviation'],
			$reading !== null                                          => [$reading, '%', 'fan.percent'],
			$rpm !== null                                              => [$rpm, 'RPM', 'fan.rpm'],
			default                                                    => [null, '', 'fan.none'],
		};
	}

	/**
	 * The unit a Sensor resource reports, for the fan cache.
	 *
	 * @param array<string, mixed> $sensor
	 */
	public static function sensorUnit(array $sensor): string {
		$type  = $sensor['ReadingType'] ?? null;
		$units = $sensor['ReadingUnits'] ?? null;

		return match (true) {
			$type === 'Percent' || $units === '%'      => 'percent',
			$type === 'Rotational' || $units === 'RPM' => 'rpm',
			default                                    => 'unknown',
		};
	}

	/**
	 * The member key for a legacy array entry: the last segment of its
	 * DataSourceUri, then a MemberId that is not a bare number (a bare
	 * number is often a list position), then its Name.
	 *
	 * @param array<array-key, mixed> $entry
	 */
	public static function legacyId(array $entry): string {
		$uri = $entry['DataSourceUri'] ?? null;

		if (is_string($uri) && $uri !== '') {
			$segment = basename(rtrim(explode('#', $uri)[0], '/'));

			if ($segment !== '' && $segment !== '.') {
				return rawurldecode($segment);
			}
		}

		$memberId = $entry['MemberId'] ?? null;

		if (is_string($memberId) && $memberId !== '' && !ctype_digit($memberId)) {
			return $memberId;
		}

		return is_string($entry['Name'] ?? null) ? trim($entry['Name']) : '';
	}

	/**
	 * Node values (REQ-DISC-006): power, inlet, and ambient come from the
	 * primary chassis chosen at discovery, the chassis linked to a computer
	 * system with a power source, lowest Id first; with none they are U.
	 * Node health is the worst known member health, built from members and
	 * not from a subsystem rollup, with absent and unknown members counted.
	 */
	public static function node(Observation $all, string $primary): Observation {
		$node = new Observation();
		$node->member(new Member(Member::NODE, 'node', '', 'node', Reading::SOURCE_NODE));

		$chassisKey = $primary === '' ? null : Member::key($primary, 'chassis', $primary);

		foreach (['power_watts' => 'W', 'inlet_celsius' => 'Cel', 'ambient_celsius' => 'Cel'] as $metric => $unit) {
			$from = $chassisKey === null ? null : $all->find($chassisKey, $metric);
			$node->add(new Reading(Member::NODE, $metric, $from?->value, $unit, $from === null ? Reading::SOURCE_NODE : $from->source, $from === null ? 'node.no_primary' : 'node.primary'));
		}

		$worst    = null;
		$absent   = 0;
		$unknown  = 0;
		$degraded = 0;

		foreach ($all->members as $member) {
			if ($member->kind === 'chassis' || $member->kind === 'summary') {
				continue;
			}

			if ($member->absent) {
				$absent++;

				continue;
			}

			$code = $all->find($member->key, 'health_code')?->value;

			if ($code === null) {
				$unknown++;

				continue;
			}

			$worst = max($worst ?? 0, (int) $code);

			if ($code >= 1) {
				$degraded++;
			}
		}

		$node->add(new Reading(Member::NODE, 'health_code', $worst === null ? null : (float) $worst, '', Reading::SOURCE_NODE, 'node.worst_member'));

		foreach (['members_absent' => $absent, 'members_unknown' => $unknown, 'members_degraded' => $degraded] as $metric => $count) {
			$node->add(new Reading(Member::NODE, $metric, (float) $count, '', Reading::SOURCE_NODE, 'node.count'));
		}

		return $node;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function environment(Target $target, array $resource): Observation {
		$out = new Observation();
		$key = Member::key($target->chassis, 'chassis', $target->chassis);

		if ($key === null) {
			return $out;
		}

		$out->member(new Member($key, 'chassis', $target->chassis, $target->chassis, Reading::SOURCE_ENVIRONMENT));

		if ($target->supplies('power')) {
			$out->add(new Reading($key, 'power_watts', self::excerpt($resource, 'PowerWatts'), 'W', Reading::SOURCE_ENVIRONMENT, 'environment.power'));
		}

		// AmbientTemperatureCelsius is defined as measured outside the
		// chassis. TemperatureCelsius is "for this resource" and is never
		// used for inlet or ambient.
		if ($target->supplies('ambient')) {
			$out->add(new Reading($key, 'ambient_celsius', self::excerpt($resource, 'AmbientTemperatureCelsius'), 'Cel', Reading::SOURCE_ENVIRONMENT, 'environment.ambient'));
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function thermalMetrics(Target $target, array $resource, string $family): Observation {
		$out = new Observation();
		$key = Member::key($target->chassis, 'chassis', $target->chassis);

		if ($key === null) {
			return $out;
		}

		$out->member(new Member($key, 'chassis', $target->chassis, $target->chassis, Reading::SOURCE_SENSOR));

		if ($target->supplies('summary')) {
			$summary = is_array($resource['TemperatureSummaryCelsius'] ?? null) ? $resource['TemperatureSummaryCelsius'] : [];
			$intake  = self::excerpt($summary, 'Intake');

			if (in_array($family, ServiceRoot::INTAKE_CONFIRMED, true)) {
				$out->add(new Reading($key, 'inlet_celsius', $intake, 'Cel', Reading::SOURCE_SENSOR, 'summary.intake'));
			} else {
				// Not yet confirmed to be air intake for this family
				// (REQ-MODEL-004): kept under its source sensor's name, and
				// inlet stays U.
				$out->add(new Reading($key, 'inlet_celsius', null, 'Cel', Reading::SOURCE_SENSOR, 'summary.intake_unconfirmed'));

				$name      = is_array($summary['Intake'] ?? null) ? self::legacyId($summary['Intake']) : '';
				$sourceKey = $intake === null ? null : Member::key($target->chassis, 'summary', $name === '' ? 'Intake' : $name);

				if ($sourceKey !== null) {
					$out->member(new Member($sourceKey, 'summary', $target->chassis, $name === '' ? 'Intake' : $name, Reading::SOURCE_SENSOR));
					$out->add(new Reading($sourceKey, 'temperature_celsius', $intake, 'Cel', Reading::SOURCE_SENSOR, 'summary.intake_source'));
				}
			}

		}

		if ($target->supplies('ambient')) {
			$summary = is_array($resource['TemperatureSummaryCelsius'] ?? null) ? $resource['TemperatureSummaryCelsius'] : [];
			$out->add(new Reading($key, 'ambient_celsius', self::excerpt($summary, 'Ambient'), 'Cel', Reading::SOURCE_SENSOR, 'summary.ambient'));
		}

		if ($target->supplies('temperatures')) {
			$readings = is_array($resource['TemperatureReadingsCelsius'] ?? null) ? array_slice($resource['TemperatureReadingsCelsius'], 0, self::MAX_MEMBERS) : [];

			foreach ($readings as $entry) {
				if (!is_array($entry)) {
					continue;
				}

				$id     = self::legacyId(['DataSourceUri' => $entry['DataSourceUri'] ?? null, 'Name' => $entry['DeviceName'] ?? null]);
				$member = Member::key($target->chassis, 'temperature', $id);

				if ($member === null || isset($out->members[$member])) {
					continue;
				}

				$out->member(new Member($member, 'temperature', $target->chassis, $id, Reading::SOURCE_SENSOR));
				$out->add(new Reading($member, 'temperature_celsius', self::number($entry['Reading'] ?? null), 'Cel', Reading::SOURCE_SENSOR, 'thermal_metrics.reading'));
				// An excerpt carries no Status: health is unknown, not OK.
				$out->add(new Reading($member, 'health_code', null, '', Reading::SOURCE_SENSOR, 'excerpt.no_status'));
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function fan(Target $target, array $resource, string $family): Observation {
		$out    = new Observation();
		$key    = Member::key($target->chassis, 'fan', $target->member);
		$status = self::status($resource['Status'] ?? null);

		if ($key === null) {
			return $out;
		}

		$name = is_string($resource['Name'] ?? null) ? substr($resource['Name'], 0, 255) : $target->member;
		$out->member(new Member($key, 'fan', $target->chassis, $name, Reading::SOURCE_SENSOR, $status['absent']));
		$out->add(new Reading($key, 'health_code', $status['code'] === null ? null : (float) $status['code'], '', Reading::SOURCE_SENSOR, 'status', $status['state']));

		if ($status['absent']) {
			return $out;
		}

		[$value, $unit, $rule] = self::fanSpeed($resource['SpeedPercent'] ?? null, $target->unit, $family);
		$out->add(new Reading($key, 'fan_speed', $value, $unit, Reading::SOURCE_SENSOR, $rule, $status['state']));

		// A second rotor is a second index under the same fan identity.
		if (is_array($resource['SecondarySpeedPercent'] ?? null)) {
			$second = $key . ':secondary';
			$out->member(new Member($second, 'fan', $target->chassis, $name . ' (secondary)', Reading::SOURCE_SENSOR));
			[$value, $unit, $rule] = self::fanSpeed($resource['SecondarySpeedPercent'], $target->secondaryUnit, $family);
			$out->add(new Reading($second, 'fan_speed', $value, $unit, Reading::SOURCE_SENSOR, $rule, $status['state']));
			$out->add(new Reading($second, 'health_code', $status['code'] === null ? null : (float) $status['code'], '', Reading::SOURCE_SENSOR, 'status', $status['state']));
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function psu(Target $target, array $resource): Observation {
		$out    = new Observation();
		$key    = Member::key($target->chassis, 'psu', $target->member);
		$status = self::status($resource['Status'] ?? null);

		if ($key === null) {
			return $out;
		}

		$name = is_string($resource['Name'] ?? null) ? substr($resource['Name'], 0, 255) : $target->member;
		$out->member(new Member($key, 'psu', $target->chassis, $name, Reading::SOURCE_SENSOR, $status['absent']));
		$out->add(new Reading($key, 'health_code', $status['code'] === null ? null : (float) $status['code'], '', Reading::SOURCE_SENSOR, 'status', $status['state']));

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function psuMetrics(Target $target, array $resource): Observation {
		$out = new Observation();
		$key = Member::key($target->chassis, 'psu', $target->member);

		if ($key === null) {
			return $out;
		}

		$out->add(new Reading($key, 'psu_input_watts', self::excerpt($resource, 'InputPowerWatts'), 'W', Reading::SOURCE_SENSOR, 'psu_metrics.input'));
		$out->add(new Reading($key, 'psu_output_watts', self::excerpt($resource, 'OutputPowerWatts'), 'W', Reading::SOURCE_SENSOR, 'psu_metrics.output'));

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function legacyThermal(Target $target, array $resource): Observation {
		$out = new Observation();

		if ($target->supplies('temperatures')) {
			foreach (self::entries($resource, 'Temperatures') as $entry) {
				self::legacyMember($out, $target, 'temperature', $entry, static function (string $key, array $entry, string $state) use ($out): void {
					$out->add(new Reading($key, 'temperature_celsius', self::number($entry['ReadingCelsius'] ?? null), 'Cel', Reading::SOURCE_LEGACY, 'legacy.temperature', $state));
				});
			}
		}

		if ($target->supplies('fans')) {
			foreach (self::entries($resource, 'Fans') as $entry) {
				self::legacyMember($out, $target, 'fan', $entry, static function (string $key, array $entry, string $state) use ($out): void {
					$unit = match ($entry['ReadingUnits'] ?? null) {
						'RPM'     => 'RPM',
						'Percent' => '%',
						default   => '',
					};

					$out->add(new Reading($key, 'fan_speed', $unit === '' ? null : self::number($entry['Reading'] ?? null), $unit, Reading::SOURCE_LEGACY, $unit === '' ? 'legacy.fan_unit_unknown' : 'legacy.fan', $state));
				});
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 */
	private static function legacyPower(Target $target, array $resource): Observation {
		$out = new Observation();
		$key = Member::key($target->chassis, 'chassis', $target->chassis);

		if ($target->supplies('power') && $key !== null) {
			$watts = null;

			// The chassis total: the first PowerControl entry that reports one.
			foreach (self::entries($resource, 'PowerControl') as $entry) {
				$watts = self::number($entry['PowerConsumedWatts'] ?? null);

				if ($watts !== null) {
					break;
				}
			}

			$out->member(new Member($key, 'chassis', $target->chassis, $target->chassis, Reading::SOURCE_LEGACY));
			$out->add(new Reading($key, 'power_watts', $watts, 'W', Reading::SOURCE_LEGACY, 'legacy.power_control'));
		}

		if ($target->supplies('psus')) {
			foreach (self::entries($resource, 'PowerSupplies') as $entry) {
				self::legacyMember($out, $target, 'psu', $entry, static function (string $key, array $entry, string $state) use ($out): void {
					$output = self::number($entry['PowerOutputWatts'] ?? null) ?? self::number($entry['LastPowerOutputWatts'] ?? null);

					$out->add(new Reading($key, 'psu_input_watts', self::number($entry['PowerInputWatts'] ?? null), 'W', Reading::SOURCE_LEGACY, 'legacy.psu_input', $state));
					$out->add(new Reading($key, 'psu_output_watts', $output, 'W', Reading::SOURCE_LEGACY, 'legacy.psu_output', $state));
				});
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $resource
	 * @return list<array<array-key, mixed>>
	 */
	private static function entries(array $resource, string $key): array {
		$entries = is_array($resource[$key] ?? null) ? $resource[$key] : [];

		return array_values(array_filter(array_slice($entries, 0, self::MAX_MEMBERS), 'is_array'));
	}

	/**
	 * Add a legacy member with its health, then its readings through
	 * $readings unless it is absent. Entries without a stable identifier,
	 * and duplicates, are skipped rather than keyed by position.
	 *
	 * @param array<array-key, mixed>                                  $entry
	 * @param callable(string, array<array-key, mixed>, string): void $readings
	 */
	private static function legacyMember(Observation $out, Target $target, string $kind, array $entry, callable $readings): void {
		$id  = self::legacyId($entry);
		$key = Member::key($target->chassis, $kind, $id);

		if ($key === null || isset($out->members[$key])) {
			return;
		}

		$status = self::status($entry['Status'] ?? null);
		$name   = is_string($entry['Name'] ?? null) ? substr($entry['Name'], 0, 255) : $id;

		$out->member(new Member($key, $kind, $target->chassis, $name, Reading::SOURCE_LEGACY, $status['absent']));
		$out->add(new Reading($key, 'health_code', $status['code'] === null ? null : (float) $status['code'], '', Reading::SOURCE_LEGACY, 'status', $status['state']));

		if (!$status['absent']) {
			$readings($key, $entry, $status['state']);
		}
	}
}
