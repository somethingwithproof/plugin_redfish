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

/*
 * Script server entry point for Thold-ready Redfish Rack Power Summary.
 * Parses on PHP 8.0; refuses below 8.2 by returning U for every output field.
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	include_once(dirname(__FILE__, 4) . '/include/cli_check.php');
	array_shift($_SERVER['argv']);
	print call_user_func_array('ss_redfish_rack', $_SERVER['argv']) . PHP_EOL;
}

/**
 * @param mixed $rack_id
 */
function ss_redfish_rack($rack_id = 0): string {
	include_once(__DIR__ . '/../setup.php');

	if (!redfish_boot()) {
		return 'power_watts:U power_budget_watts:U utilization_pct:U headroom_watts:U members_total:U members_reporting:U coverage_pct:U';
	}

	$id = is_numeric($rack_id) ? (int) $rack_id : 0;

	return redfish_rack_summary_script()->answer($id);
}
