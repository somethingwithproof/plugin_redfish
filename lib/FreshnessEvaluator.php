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
 * Freshness from a reading's observation time against the collection
 * interval (REQ-MODEL-008, REQ-COLL-007). A reading is current up to
 * delayedAfter intervals old, delayed up to staleAfter intervals, and stale
 * beyond. A time in the future reads as current only within one interval;
 * further out the poller's clock moved back, and the value cannot be
 * vouched for.
 */
final class FreshnessEvaluator {
	public function __construct(
		private readonly Clock $clock,
		private readonly int $interval,
		private readonly int $delayedAfter,
		private readonly int $staleAfter,
	) {
	}

	public static function fromSettings(Clock $clock, Settings $settings): self {
		return new self($clock, $settings->collectInterval, $settings->delayedAfter, $settings->staleAfter);
	}

	public function evaluate(int $observedAt): Freshness {
		if ($observedAt <= 0) {
			return Freshness::Never;
		}

		$age      = $this->clock->now() - $observedAt;
		$interval = max(1, $this->interval);

		return match (true) {
			$age < -$interval                         => Freshness::Stale,
			$age <= $this->delayedAfter * $interval   => Freshness::Current,
			$age <= $this->staleAfter * $interval     => Freshness::Delayed,
			default                                   => Freshness::Stale,
		};
	}
}
