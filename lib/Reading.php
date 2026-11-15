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
 * One normalized value (REQ-MODEL-001). A null value is unknown and reads as
 * U, never as zero (REQ-MODEL-002). $source is the model it came from
 * (REQ-MODEL-007) and $rule the exact rule that chose it, so an operator can
 * see why a value is what it is. The observation time is the collector's
 * clock at write time; freshness is derived from it when read.
 */
final readonly class Reading {
	public const SOURCE_ENVIRONMENT = 'redfish.environment';
	public const SOURCE_SENSOR      = 'redfish.sensor';
	public const SOURCE_LEGACY      = 'redfish.legacy';
	public const SOURCE_NODE        = 'redfish.node';

	public const METRICS = [
		'power_watts', 'inlet_celsius', 'ambient_celsius', 'temperature_celsius', 'fan_speed',
		'psu_input_watts', 'psu_output_watts', 'health_code',
		'members_absent', 'members_unknown', 'members_degraded',
	];

	public function __construct(
		public string $member,
		public string $metric,
		public ?float $value,
		public string $unit,
		public string $source,
		public string $rule,
		public string $state = '',
	) {
	}
}
