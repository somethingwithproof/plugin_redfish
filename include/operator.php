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

use Somethingwithproof\Redfish\HealthRepository;
use Somethingwithproof\Redfish\BmcHealth;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function redfish_operator_rows(): array {
	$rows   = [];
	$health = new HealthRepository(false);
	$scope  = redfish_device_scope();

	foreach (redfish_bmcs()->all() as $bmc) {
		if ($bmc->hostId <= 0 || !$scope->allowsNew($bmc->hostId)) {
			continue;
		}
		$h      = $health->find($bmc->id) ?? new BmcHealth($bmc->id);
		$rows[] = [
			'id' => (string) $bmc->id,
			'name' => $bmc->name,
			'state' => $bmc->enabled() ? $h->reachability->value : 'disabled',
			'observed' => $h->runFinished,
			'interval' => redfish_settings()->collectInterval,
			'detail'       => ($h->failure->value ?? '') . ' ' . __('Check collection freshness and the device graphs. Retry releases a collection hold for the next scheduled run; it does not contact the BMC now.', 'redfish')
		];
	}

	return $rows;
}

/** The caller has reloaded this BMC within the current device scope. */
function redfish_operator_retry(int $id): void {
	if (!(new HealthRepository(false))->clearHolds($id)) {
		throw new RuntimeException('Collection hold could not be released');
	}
}
