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
	/**
	 * Source environment constant.
	 */
	public const SOURCE_ENVIRONMENT = 'redfish.environment';
	/**
	 * Source sensor constant.
	 */
	public const SOURCE_SENSOR      = 'redfish.sensor';
	/**
	 * Source legacy constant.
	 */
	public const SOURCE_LEGACY      = 'redfish.legacy';
	/**
	 * Source node constant.
	 */
	public const SOURCE_NODE        = 'redfish.node';
	/**
	 * Source ipmi dcmi constant.
	 */
	public const SOURCE_IPMI_DCMI   = 'ipmi.dcmi';
	/**
	 * Source ipmi sdr constant.
	 */
	public const SOURCE_IPMI_SDR    = 'ipmi.sdr';
	/**
	 * Source ipmi sel constant.
	 */
	public const SOURCE_IPMI_SEL    = 'ipmi.sel';

	/**
	 * Metrics constant.
	 */
	public const METRICS = [
		'power_watts', 'inlet_celsius', 'ambient_celsius', 'temperature_celsius', 'fan_speed',
		'psu_input_watts', 'psu_output_watts', 'health_code',
		'members_absent', 'members_unknown', 'members_degraded',
		'fans_degraded', 'fans_failed', 'fan_min_rpm', 'fan_max_rpm', 'fan_min_percent', 'fan_max_percent',
		'psu_redundancy_code', 'psu_redundancy_loss_count',
		'ecc_corrected_errors', 'ecc_uncorrected_errors',
		'ecc_period_corrected_errors', 'ecc_period_uncorrected_errors',
		'drive_predicted_wear_pct', 'drive_media_life_left_pct', 'drive_failure_predicted',
		'sel_critical_count', 'sel_warning_count',
		'sel_critical_rate_per_hour', 'sel_warning_rate_per_hour',
		'sel_percent_used', 'sel_overflow',
	];

	/**
	 * Initialize a new Reading instance.
	 *
	 * @param string $member
	 * @param string $metric
	 * @param float|null $value
	 * @param string $unit
	 * @param string $source
	 * @param string $rule
	 * @param string $state
	 */
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
