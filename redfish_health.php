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
 * Redfish Health (Redfish View realm). Parses on PHP 8.0;
 * redfish_page_start() refuses an unsupported runtime before any plugin
 * class loads. No request here contacts a BMC.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/redfish/setup.php');

if (!redfish_page_start()) {
	exit;
}

$redfish_clock    = new \Somethingwithproof\Redfish\SystemClock();
$redfish_settings = redfish_settings();
$redfish_page     = new \Somethingwithproof\Redfish\HealthPage(
	redfish_bmcs(),
	new \Somethingwithproof\Redfish\HealthRepository(false),
	new \Somethingwithproof\Redfish\ReadingRepository(false),
	new \Somethingwithproof\Redfish\DiscoveryRepository(false),
	redfish_credential_store(),
	\Somethingwithproof\Redfish\FreshnessEvaluator::fromSettings($redfish_clock, $redfish_settings),
	$redfish_clock,
	redfish_device_scope(),
	redfish_maint()
);

top_header();
$redfish_admin = api_user_realm_auth('redfish_bmcs.php');
$redfish_page->render($redfish_settings, $redfish_admin, \Somethingwithproof\Redfish\DeviceWarnings::for(\Somethingwithproof\Redfish\HealthPage::visible(redfish_device_scope()->filter(redfish_bmcs()->all()), $redfish_admin), redfish_cacti_objects(), redfish_template_installer()));
bottom_footer();
