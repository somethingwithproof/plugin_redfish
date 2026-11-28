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
 * Uninstall on a runtime the plugin refuses (PHP below 8.2, or a missing
 * extension), where nothing under lib/ may load. Cacti goes on to drop every
 * tracked plugin table after plugin_redfish_uninstall() returns, so the keep
 * tables setting must be honoured here too, in PHP 8.0 syntax. It mirrors
 * Settings::fromRow() and Schema::untrackTables() / forgetVersion(), and
 * stops through redfish_uninstall_stop() as the booted path does; the
 * lifecycle integration tests run both paths.
 */

/**
 * Uninstall unbooted.
 *
 * @return void
 */
function redfish_uninstall_unbooted(): void {
	$row  = db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', array('redfish_uninstall_keep_tables'));
	$keep = !is_array($row) || $row === array() || (isset($row['value']) && $row['value'] === 'on');

	if ($keep) {
		$ok = db_execute_prepared("DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", array('redfish'));

		if ($ok === false || (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_db_changes WHERE plugin = ? AND method = 'create'", array('redfish')) !== 0) {
			redfish_uninstall_stop();
		}

		cacti_log('NOTE: Uninstall kept the plugin tables as configured', false, 'REDFISH');
	} else {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', array('redfish_schema_version'));
	}
}
