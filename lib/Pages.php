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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships appears in exactly one realm below
 * (REQ-UI-001). Pages that create, edit, delete, or import BMCs or
 * credentials are Redfish Admin pages (REQ-UI-005).
 */
final class Pages {
	/**
	 * Operator constant.
	 */
	public const OPERATOR = ['redfish_operator.php'];
	/**
	 * View constant.
	 */
	public const VIEW  = ['redfish_health.php'];
	/**
	 * Admin constant.
	 */
	public const ADMIN = ['redfish_bmcs.php', 'redfish_credentials.php'];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('Redfish Operator', 'redfish'), 'files' => self::OPERATOR],
			['name' => __('Redfish View', 'redfish'), 'files' => self::VIEW],
			['name' => __('Redfish Admin', 'redfish'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge(self::VIEW, self::ADMIN, self::OPERATOR);
	}

	/**
	 * Console > Management entries.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'redfish_operator.php' => __('Redfish Operations', 'redfish'),
			'redfish_bmcs.php'        => __('Redfish BMCs', 'redfish'),
			'redfish_credentials.php' => __('Redfish Credentials', 'redfish'),
			'redfish_health.php'      => __('Redfish Health', 'redfish'),
		];
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
