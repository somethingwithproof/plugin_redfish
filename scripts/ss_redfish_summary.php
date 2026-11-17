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
 * Script server input "Redfish Node Summary": the summary values of the BMC
 * mapped to a Cacti device as name:value pairs, from the plugin cache only
 * (REQ-ALERT-001 to REQ-ALERT-004). Thold reads these values through
 * Cacti's poller output.
 *
 * This file must parse on PHP 8.0 for the same reason as
 * ss_redfish_members.php.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../../include/cli_check.php');

	$redfish_args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values(array_filter(array_slice($_SERVER['argv'], 1), 'is_string')) : array();

	print ss_redfish_summary(...$redfish_args);
}

/**
 * @param int|string $host_id the Cacti device the BMC is mapped to
 */
function ss_redfish_summary($host_id = 0): string {
	static $script = null;

	include_once(dirname(__DIR__) . '/setup.php');

	if (!redfish_boot()) {
		return 'power_watts:U inlet_celsius:U max_temp_celsius:U fans_bad:U psus_bad:U health_code:U age:U';
	}

	if ($script === null) {
		$script = redfish_summary_script();
	}

	return $script->answer((int) $host_id);
}
