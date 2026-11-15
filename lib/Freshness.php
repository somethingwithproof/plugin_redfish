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
 * Freshness of a cached reading (REQ-MODEL-008). Only a current or delayed
 * value is served; a stale or never observed one reads as unknown, never as
 * zero (REQ-MODEL-002).
 */
enum Freshness: string {
	case Current = 'current';
	case Delayed = 'delayed';
	case Stale   = 'stale';
	case Never   = 'never';

	public function usable(): bool {
		return $this === self::Current || $this === self::Delayed;
	}
}
