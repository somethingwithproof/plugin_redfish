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

use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\DeviceScope;

function redfish_scope(): DeviceScope {
	// Device 5 is visible, 6 is hidden, 7 was deleted.
	return new DeviceScope(static fn (int $h): bool => $h === 5, static fn (int $h): bool => in_array($h, [5, 6], true));
}

function redfish_scoped_bmc(int $id, int $host): Bmc {
	return Bmc::fromRow(['id' => (string) $id, 'name' => "b$id", 'address' => "10.0.0.$id", 'port' => '443', 'host_id' => (string) $host, 'credential_id' => '1', 'admin_state' => 'enabled']);
}

it('lets a user act on unmapped BMCs and BMCs on visible or deleted devices only', function () {
	$scope = redfish_scope();

	expect($scope->allows(0))->toBeTrue()
		->and($scope->allows(5))->toBeTrue()
		->and($scope->allows(6))->toBeFalse()
		->and($scope->allows(7))->toBeTrue()
		// A new mapping needs a device that exists and is visible.
		->and($scope->allowsNew(0))->toBeTrue()
		->and($scope->allowsNew(5))->toBeTrue()
		->and($scope->allowsNew(6))->toBeFalse()
		->and($scope->allowsNew(7))->toBeFalse();
});

it('filters lists to the BMCs the user may see', function () {
	$ids = array_map(fn (Bmc $b) => $b->id, redfish_scope()->filter([redfish_scoped_bmc(1, 0), redfish_scoped_bmc(2, 5), redfish_scoped_bmc(3, 6), redfish_scoped_bmc(4, 7)]));

	expect($ids)->toBe([1, 2, 4]);
});

it('lets a user bind a credential only when every BMC using it is visible', function () {
	$scope = redfish_scope();

	expect($scope->allowsAll([]))->toBeTrue()
		->and($scope->allowsAll([0, 5, 7]))->toBeTrue()
		->and($scope->allowsAll([5, 6]))->toBeFalse();
});
