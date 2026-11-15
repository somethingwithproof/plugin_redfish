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
 * What discovery found on one BMC (REQ-DISC-002): the service fingerprint,
 * the protocol features it advertises, its session collection, its chassis,
 * and the resources to read, each with the reading it supplies.
 */
final readonly class DiscoveryPlan {
	/**
	 * @param list<string>                                    $features ProtocolFeaturesSupported names advertised as true
	 * @param list<array{id: string, path: string, systems: bool}> $chassis
	 * @param list<Target>                                    $targets
	 */
	public function __construct(
		public string $fingerprint,
		public string $redfishVersion,
		public string $vendor,
		public string $product,
		public string $family,
		public array $features,
		public string $sessionsPath,
		public array $chassis,
		public string $primaryChassis,
		public array $targets,
		public int $linksRefused = 0,
	) {
	}

	/**
	 * subsystem, legacy, mixed, or none: which model supplied the targets.
	 */
	public function generation(): string {
		$legacy = $subsystem = false;

		foreach ($this->targets as $target) {
			if ($target->kind === Target::LEGACY_POWER || $target->kind === Target::LEGACY_THERMAL) {
				$legacy = true;
			} else {
				$subsystem = true;
			}
		}

		return match (true) {
			$legacy && $subsystem => 'mixed',
			$legacy               => 'legacy',
			$subsystem            => 'subsystem',
			default               => 'none',
		};
	}

	public function planJson(): string {
		return (string) json_encode([
			'chassis' => $this->chassis,
			'primary' => $this->primaryChassis,
			'targets' => array_map(static fn (Target $t): array => $t->toArray(), $this->targets),
		], JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param array<array-key, mixed> $row a plugin_redfish_discovery row
	 */
	public static function fromRow(array $row): ?self {
		$plan = json_decode(Row::string($row, 'plan'), true, 16);

		if (!is_array($plan) || Row::string($row, 'fingerprint') === '') {
			return null;
		}

		$targets = [];

		foreach (is_array($plan['targets'] ?? null) ? $plan['targets'] : [] as $entry) {
			$target = is_array($entry) ? Target::fromArray($entry) : null;

			if ($target === null) {
				return null;
			}

			$targets[] = $target;
		}

		$chassis = [];

		foreach (is_array($plan['chassis'] ?? null) ? $plan['chassis'] : [] as $entry) {
			if (is_array($entry)) {
				$chassis[] = ['id' => Row::string($entry, 'id'), 'path' => Row::string($entry, 'path'), 'systems' => Row::bool($entry, 'systems')];
			}
		}

		$features = Row::string($row, 'features');

		return new self(
			Row::string($row, 'fingerprint'),
			Row::string($row, 'redfish_version'),
			Row::string($row, 'vendor'),
			Row::string($row, 'product'),
			Row::string($row, 'family'),
			$features === '' ? [] : explode(',', $features),
			Row::string($row, 'sessions_path'),
			$chassis,
			is_string($plan['primary'] ?? null) ? $plan['primary'] : '',
			$targets,
		);
	}
}
