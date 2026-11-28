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
 * Typed BMC System Event Log (SEL) event count and rate summary normalized
 * from Redfish Managers/{id}/LogServices/SEL/Entries or IPMI SEL output.
 */
final readonly class SelSummary {
	/**
	 * Initialize a new SelSummary value object.
	 *
	 * @param string $managerId           Redfish manager ID or 'ipmi'
	 * @param int    $totalEntries        total SEL entries inspected or reported
	 * @param int    $criticalCount       total critical-severity SEL entries
	 * @param int    $warningCount        total warning-severity SEL entries
	 * @param int    $windowCriticalCount critical entries observed within the rate window
	 * @param int    $windowWarningCount  warning entries observed within the rate window
	 * @param float  $criticalRatePerHour critical SEL events per hour over the window
	 * @param float  $warningRatePerHour  warning SEL events per hour over the window
	 * @param ?float $percentUsed         SEL storage utilization percentage when reported
	 * @param bool   $overflow            whether the SEL buffer reported overflow
	 * @param string $source              reading source identifier
	 */
	public function __construct(
		public string $managerId,
		public int $totalEntries,
		public int $criticalCount,
		public int $warningCount,
		public int $windowCriticalCount,
		public int $windowWarningCount,
		public float $criticalRatePerHour,
		public float $warningRatePerHour,
		public ?float $percentUsed,
		public bool $overflow,
		public string $source,
	) {
	}

	/**
	 * Convert this SEL summary into a normalized Observation attached to a
	 * manager SEL member.
	 *
	 * @return Observation
	 */
	public function toObservation(): Observation {
		$obs = new Observation();
		$key = Member::key($this->managerId, 'sel', 'SEL');

		if ($key === null) {
			return $obs;
		}

		$health = match (true) {
			$this->overflow || $this->windowCriticalCount > 0 || $this->criticalCount > 0 => 2.0,
			$this->windowWarningCount > 0 || $this->warningCount > 0                      => 1.0,
			default                                                                       => 0.0,
		};

		$obs->member(new Member($key, 'sel', $this->managerId, 'BMC SEL', $this->source));
		$obs->add(new Reading($key, 'health_code', $health, '', $this->source, 'sel.health'));
		$obs->add(new Reading($key, 'sel_critical_count', (float) $this->criticalCount, '', $this->source, 'sel.count'));
		$obs->add(new Reading($key, 'sel_warning_count', (float) $this->warningCount, '', $this->source, 'sel.count'));
		$obs->add(new Reading($key, 'sel_critical_rate_per_hour', $this->criticalRatePerHour, '/h', $this->source, 'sel.rate'));
		$obs->add(new Reading($key, 'sel_warning_rate_per_hour', $this->warningRatePerHour, '/h', $this->source, 'sel.rate'));
		$obs->add(new Reading($key, 'sel_overflow', $this->overflow ? 1.0 : 0.0, '', $this->source, 'sel.overflow'));

		if ($this->percentUsed !== null) {
			$obs->add(new Reading($key, 'sel_percent_used', $this->percentUsed, '%', $this->source, 'sel.capacity'));
		}

		return $obs;
	}

	/**
	 * Machine-readable array representation for CLI JSON and MCP tools.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'manager_id'             => $this->managerId,
			'total_entries'          => $this->totalEntries,
			'critical_count'         => $this->criticalCount,
			'warning_count'          => $this->warningCount,
			'window_critical_count'  => $this->windowCriticalCount,
			'window_warning_count'   => $this->windowWarningCount,
			'critical_rate_per_hour' => $this->criticalRatePerHour,
			'warning_rate_per_hour'  => $this->warningRatePerHour,
			'percent_used'           => $this->percentUsed,
			'overflow'               => $this->overflow,
			'source'                 => $this->source,
		];
	}
}
