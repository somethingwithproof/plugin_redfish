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
 * Discovery and capability probing (REQ-DISC-001, REQ-DISC-002, ADR 0013).
 *
 * Starts at the service root, enumerates Chassis members, and for each
 * reading picks a source in this order: the subsystem model
 * (EnvironmentMetrics, ThermalSubsystem, PowerSubsystem) first, then the
 * legacy Power and Thermal resources, reading by reading, so a BMC that
 * has new power metrics but only legacy fans gets both. The Sensors
 * collection is not walked in this release (docs/PLAN.md).
 *
 * Only links that are paths on this BMC are followed; others are counted
 * in linksRefused and ignored (REQ-RF-005).
 */
final class Discoverer {
	/**
	 * Max chassis constant.
	 */
	public const MAX_CHASSIS = 16;
	/**
	 * Max fans constant.
	 */
	public const MAX_FANS    = 64;
	/**
	 * Max psus constant.
	 */
	public const MAX_PSUS    = 32;

	/**
	 * Links refused property.
	 */
	private int $linksRefused = 0;

	/**
	 * Initialize a new Discoverer instance.
	 *
	 * @param RedfishClient $client
	 * @param Endpoint $endpoint
	 */
	public function __construct(private readonly RedfishClient $client, private readonly Endpoint $endpoint) {
	}

	/**
	 * The session collection the service root names, or '' when it names
	 * none. A link elsewhere is refused.
	 *
	 * @param array<string, mixed> $root
	 */
	public function sessionsPath(array $root): string {
		return $this->follow(Link::of($root, 'Links', 'Sessions')) ?? '';
	}

	/**
	 * @param array<string, mixed> $root the service root, already read
	 * @throws ClientError
	 */
	public function discover(array $root): DiscoveryPlan {
		$this->linksRefused = 0;
		$family = ServiceRoot::family($root);
		$path   = $this->follow(Link::of($root, 'Chassis'));

		if ($path === null) {
			throw new ClientError(FailureClass::NoChassis);
		}

		$chassis = [];
		$targets = [];

		foreach ($this->members($this->client->get($path), self::MAX_CHASSIS) as $chassisPath) {
			$resource = $this->client->get($chassisPath);
			$id       = is_string($resource['Id'] ?? null) ? $resource['Id'] : '';

			if ($id === '' || Member::key($id, 'chassis', $id) === null) {
				continue;
			}

			$links   = is_array($resource['Links'] ?? null) ? $resource['Links'] : [];
			$systems = is_array($links['ComputerSystems'] ?? null) && $links['ComputerSystems'] !== [];
			$found   = $this->chassis($resource, $id);

			$chassis[] = ['id' => $id, 'path' => $chassisPath, 'systems' => $systems, 'power' => $found['power']];
			$targets   = array_merge($targets, $found['targets']);
		}

		if ($chassis === []) {
			throw new ClientError(FailureClass::NoChassis);
		}

		// The node value comes from the chassis that holds a computer system
		// and reports power, lowest Id first; with none it is U.
		$primary    = '';
		$candidates = array_filter($chassis, static fn (array $c): bool => $c['systems'] && $c['power']);
		usort($candidates, static fn (array $a, array $b): int => strnatcmp($a['id'], $b['id']));

		if ($candidates !== []) {
			$primary = $candidates[0]['id'];
		}

		return new DiscoveryPlan(
			fingerprint: ServiceRoot::fingerprint($root),
			redfishVersion: ServiceRoot::text($root, 'RedfishVersion'),
			vendor: ServiceRoot::text($root, 'Vendor'),
			product: ServiceRoot::text($root, 'Product'),
			family: $family,
			features: ServiceRoot::features($root),
			sessionsPath: $this->sessionsPath($root),
			chassis: array_map(static fn (array $c): array => ['id' => $c['id'], 'path' => $c['path'], 'systems' => $c['systems']], $chassis),
			primaryChassis: $primary,
			targets: $targets,
			linksRefused: $this->linksRefused,
		);
	}

	/**
	 * Links refused.
	 *
	 * @return int
	 */
	public function linksRefused(): int {
		return $this->linksRefused;
	}

	/**
	 * @param array<string, mixed> $chassis
	 * @return array{targets: list<Target>, power: bool}
	 * @throws ClientError
	 */
	private function chassis(array $chassis, string $id): array {
		$targets = [];
		$have    = ['power' => false, 'ambient' => false, 'summary' => false, 'temperatures' => false, 'fans' => false, 'psus' => false];

		// EnvironmentMetrics: chassis power, and ambient when defined.
		$env = $this->optional($this->follow(Link::of($chassis, 'EnvironmentMetrics')));

		if ($env !== null) {
			$uses = [];

			if (is_array($env[1]['PowerWatts'] ?? null)) {
				$uses[] = 'power';
			}

			if (is_array($env[1]['AmbientTemperatureCelsius'] ?? null)) {
				$uses[] = 'ambient';
			}

			if ($uses !== []) {
				$targets[] = $this->target(Target::ENVIRONMENT, $env, $id, uses: $uses);
				$have      = array_merge($have, array_fill_keys($uses, true));
			}
		}

		$thermal = $this->optional($this->follow(Link::of($chassis, 'ThermalSubsystem')));

		if ($thermal !== null) {
			$metrics = $this->optional($this->follow(Link::of($thermal[1], 'ThermalMetrics')));

			if ($metrics !== null) {
				$uses    = [];
				$summary = is_array($metrics[1]['TemperatureSummaryCelsius'] ?? null) ? $metrics[1]['TemperatureSummaryCelsius'] : [];

				if (is_array($summary['Intake'] ?? null)) {
					$uses[] = 'summary';
				}

				if (!$have['ambient'] && is_array($summary['Ambient'] ?? null)) {
					$uses[]          = 'ambient';
					$have['ambient'] = true;
				}

				if (is_array($metrics[1]['TemperatureReadingsCelsius'] ?? null) && $metrics[1]['TemperatureReadingsCelsius'] !== []) {
					$uses[]               = 'temperatures';
					$have['temperatures'] = true;
				}

				if ($uses !== []) {
					$targets[] = $this->target(Target::THERMAL_METRICS, $metrics, $id, uses: $uses);
				}
			}

			$fans = $this->optional($this->follow(Link::of($thermal[1], 'Fans')));

			foreach ($fans === null ? [] : $this->members($fans[1], self::MAX_FANS) as $fanPath) {
				$fan = $this->optional($fanPath);

				if ($fan === null || !is_string($fan[1]['Id'] ?? null) || $fan[1]['Id'] === '') {
					continue;
				}

				$targets[]    = $this->target(Target::FAN, $fan, $id, $fan[1]['Id'], $this->fanUnit($fan[1]['SpeedPercent'] ?? null), $this->fanUnit($fan[1]['SecondarySpeedPercent'] ?? null), ['fans']);
				$have['fans'] = true;
			}
		}

		$power = $this->optional($this->follow(Link::of($chassis, 'PowerSubsystem')));

		if ($power !== null) {
			$supplies = $this->optional($this->follow(Link::of($power[1], 'PowerSupplies')));

			foreach ($supplies === null ? [] : $this->members($supplies[1], self::MAX_PSUS) as $psuPath) {
				$psu = $this->optional($psuPath);

				if ($psu === null || !is_string($psu[1]['Id'] ?? null) || $psu[1]['Id'] === '') {
					continue;
				}

				$targets[]    = $this->target(Target::PSU, $psu, $id, $psu[1]['Id'], uses: ['psus']);
				$have['psus'] = true;
				$metrics      = $this->optional($this->follow(Link::of($psu[1], 'Metrics')));

				if ($metrics !== null) {
					$targets[] = $this->target(Target::PSU_METRICS, $metrics, $id, $psu[1]['Id'], uses: ['psus']);
				}
			}
		}

		// Legacy resources supply only what the subsystem model did not.
		$legacyThermal = array_values(array_filter(['temperatures', 'fans'], static fn (string $r): bool => !$have[$r]));
		$legacyPower   = array_values(array_filter(['power', 'psus'], static fn (string $r): bool => !$have[$r]));

		if ($legacyThermal !== []) {
			$resource = $this->optional($this->follow(Link::of($chassis, 'Thermal')));

			if ($resource !== null) {
				$targets[] = $this->target(Target::LEGACY_THERMAL, $resource, $id, uses: $legacyThermal);
			}
		}

		if ($legacyPower !== []) {
			$resource = $this->optional($this->follow(Link::of($chassis, 'Power')));

			if ($resource !== null) {
				$targets[] = $this->target(Target::LEGACY_POWER, $resource, $id, uses: $legacyPower);
				$have['power'] = $have['power'] || in_array('power', $legacyPower, true);
			}
		}

		return ['targets' => $targets, 'power' => $have['power']];
	}

	/**
	 * The unit of the sensor a fan excerpt references, read once here and
	 * cached in the plan. '' when the excerpt names no sensor.
	 *
	 * @throws ClientError
	 */
	private function fanUnit(mixed $excerpt): string {
		if (!is_array($excerpt) || !array_key_exists('DataSourceUri', $excerpt)) {
			return '';
		}

		$sensor = $this->optional($this->follow($excerpt['DataSourceUri']));

		return $sensor === null ? 'unknown' : Normalizer::sensorUnit($sensor[1]);
	}

	/**
	 * @param array{0: string, 1: array<string, mixed>} $resource
	 * @param list<string>                              $uses
	 */
	private function target(string $kind, array $resource, string $chassis, string $member = '', string $unit = '', string $secondaryUnit = '', array $uses = []): Target {
		return new Target($kind, $resource[0], is_string($resource[1]['Id'] ?? null) ? $resource[1]['Id'] : '', ServiceRoot::type($resource[1]), $chassis, $member, $unit, $secondaryUnit, $uses);
	}

	/**
	 * A resource that may be missing: a 404 or an unreadable body reads as
	 * absent. Any other failure, such as a timeout or throttling, ends
	 * discovery, so a slow BMC is not taken for one without resources.
	 *
	 * @return ?array{0: string, 1: array<string, mixed>}
	 * @throws ClientError
	 */
	private function optional(?string $path): ?array {
		if ($path === null) {
			return null;
		}

		try {
			return [$path, $this->client->get($path)];
		} catch (ClientError $e) {
			if (in_array($e->class, [FailureClass::NotFound, FailureClass::Malformed, FailureClass::JsonDepth, FailureClass::UnexpectedStatus, FailureClass::RedirectRefused], true)) {
				return null;
			}

			throw $e;
		}
	}

	/**
	 * Member paths of a collection, at most $limit, refused links skipped.
	 *
	 * @param array<string, mixed> $collection
	 * @return list<string>
	 */
	private function members(array $collection, int $limit): array {
		$paths = [];

		foreach (is_array($collection['Members'] ?? null) ? $collection['Members'] : [] as $member) {
			$path = is_array($member) ? $this->follow($member['@odata.id'] ?? null) : null;

			if ($path !== null && !in_array($path, $paths, true)) {
				$paths[] = $path;
			}

			if (count($paths) >= $limit) {
				break;
			}
		}

		return $paths;
	}

	/**
	 * Follow.
	 *
	 * @param mixed $link
	 * @return string|null
	 */
	private function follow(mixed $link): ?string {
		if ($link === null) {
			return null;
		}

		$path = Link::path($link, $this->endpoint);

		if ($path === null) {
			$this->linksRefused++;
		}

		return $path;
	}
}
