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
 * Health page warnings about the Cacti side: templates that failed to
 * install, and mapped devices that are deleted or polled by another poller
 * than their BMC (REQ-DEV-006). Template problems are for administrators;
 * a device warning is shown to whoever may see that BMC.
 */
final class DeviceWarnings {
	/**
	 * @param list<Bmc> $bmcs the BMCs the viewer may see
	 *
	 * @return list<array{admin: bool, message: string}>
	 */
	public static function for(array $bmcs, CactiObjectRepository $objects, TemplateInstaller $templates): array {
		$out = [];

		if ($templates->failure() !== '') {
			$out[] = ['admin' => true, 'message' => __('The Redfish templates could not be installed: %s', $templates->failure(), 'redfish')];
		} elseif (!$templates->isCurrent()) {
			$out[] = ['admin' => true, 'message' => __('The Redfish templates are not installed or not current. Reinstall them from the Redfish BMCs page.', 'redfish')];
		}

		foreach ($bmcs as $bmc) {
			if ($bmc->hostId === 0) {
				continue;
			}

			$device = $objects->device($bmc->hostId);

			if ($device === null) {
				$out[] = ['admin' => false, 'message' => __('%s: its Cacti device was deleted. Map it to another device or create one from the BMCs page.', $bmc->name, 'redfish')];
			} elseif ($device['poller_id'] !== $bmc->pollerId) {
				$out[] = ['admin' => false, 'message' => __('%s: its device is polled by poller %d but the BMC is read by poller %d. Move one of them so both use the same poller, or its graphs can read as unknown.', $bmc->name, $device['poller_id'], $bmc->pollerId, 'redfish')];
			}
		}

		return $out;
	}
}
