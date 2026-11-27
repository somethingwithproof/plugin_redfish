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
 * Reads the plugin settings straight from Cacti's settings table.
 *
 * read_config_option() substitutes a form default for a setting that was
 * never saved, and only when the config_settings hook has run in this
 * request, which the poller does not do. Reading the rows keeps "never
 * saved" distinguishable from "saved empty", so Settings::fromRow() applies
 * the same defaults everywhere.
 */
final class SettingsRepository {
	/**
	 * Initialize a new SettingsRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Load.
	 *
	 * @return Settings
	 */
	public function load(): Settings {
		$placeholders = implode(', ', array_fill(0, count(Settings::NAMES), '?'));
		$row          = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT name, value FROM settings WHERE name IN ($placeholders)", Settings::NAMES, true, $this->connection)) as $setting) {
			$row[Row::string($setting, 'name')] = $setting['value'] ?? null;
		}

		return Settings::fromRow($row);
	}
}
