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
 * Values as Cacti's poller reads them: `U` for unknown, otherwise a plain
 * decimal without an exponent, which RRDtool and Thold both parse.
 */
final class Number {
	/**
	 * Format.
	 *
	 * @param float|null $value
	 * @return string
	 */
	public static function format(?float $value): string {
		if ($value === null || !is_finite($value)) {
			return 'U';
		}

		if (floor($value) === $value && abs($value) < 1e15) {
			return (string) (int) $value;
		}

		return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
	}
}
