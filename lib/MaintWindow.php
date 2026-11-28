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
 * Cacti Maint plugin window awareness for BMC health views.
 *
 * Evaluates whether a BMC's mapped Cacti device is inside an active
 * maintenance window. Uses MaintRepository schedules and optionally delegates
 * to plugin_maint_check_cacti_host() when available.
 */
final class MaintWindow {
	/**
	 * @param ?\Closure(int): bool $pluginCheck optional hook into plugin_maint_check_cacti_host()
	 */
	public function __construct(
		private readonly MaintRepository $repository,
		private readonly Clock $clock,
		private readonly ?\Closure $pluginCheck = null,
	) {
	}

	/**
	 * Return active maintenance window details for a host, or null if not in maintenance.
	 *
	 * @return ?array{active: bool, schedule_id: int, name: string}
	 */
	public function activeForHost(int $hostId): ?array {
		if ($hostId <= 0) {
			return null;
		}

		$now = $this->clock->now();

		foreach ($this->repository->schedulesForHost($hostId) as $schedule) {
			if (self::isScheduleActive($schedule, $now)) {
				return [
					'active'      => true,
					'schedule_id' => $schedule['id'],
					'name'        => $schedule['name'],
				];
			}
		}

		if ($this->pluginCheck !== null && ($this->pluginCheck)($hostId)) {
			return [
				'active'      => true,
				'schedule_id' => 0,
				'name'        => 'maintenance',
			];
		}

		return null;
	}

	/**
	 * Pure schedule check supporting Maint one-time (mtype=1) and recurring
	 * (mtype=2) schedules.
	 *
	 * @param array{id: int, name: string, mtype: int, stime: int, etime: int, minterval: int} $schedule
	 */
	public static function isScheduleActive(array $schedule, int $now): bool {
		$start = $schedule['stime'];
		$end   = $schedule['etime'];

		if ($start <= 0 || $end <= $start) {
			return false;
		}

		if ($schedule['mtype'] === 1) {
			return $now >= $start && $now <= $end;
		}

		if ($schedule['mtype'] === 2) {
			$interval = $schedule['minterval'];

			if ($interval <= 0 || $now < $start) {
				return false;
			}

			$duration = $end - $start;
			$elapsed  = ($now - $start) % $interval;

			return $elapsed <= $duration;
		}

		return false;
	}
}
