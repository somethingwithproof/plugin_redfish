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
 * A clock that stays where it is put, for tests and for replaying a run.
 */
final class FixedClock implements Clock {
	/**
	 * Initialize a new FixedClock instance.
	 *
	 * @param int $time
	 */
	public function __construct(private int $time) {
	}

	/**
	 * Now.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->time;
	}

	/**
	 * Advance.
	 *
	 * @param int $seconds
	 * @return void
	 */
	public function advance(int $seconds): void {
		$this->time += $seconds;
	}
}
