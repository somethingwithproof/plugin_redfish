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

use Somethingwithproof\Redfish\BmcRepository;
use Somethingwithproof\Redfish\FixedClock;

function redfish_delete_repo(): BmcRepository {
	$GLOBALS['__redfish_db_exec']   = [];
	$GLOBALS['__redfish_end_tx']    = [];
	$GLOBALS['__redfish_savepoint'] = false;
	$GLOBALS['__redfish_db_rows']   = ['FROM plugin_redfish_bmc WHERE id' => [['id' => '7', 'name' => 'r660', 'address' => '10.0.0.5', 'port' => '443', 'credential_id' => '3', 'tls_mode' => 'system', 'admin_state' => 'enabled']]];

	return new BmcRepository(false, new FixedClock(1_800_000_000));
}

function redfish_delete_log(): array {
	return array_count_values(array_column($GLOBALS['__redfish_db_exec'], 'sql'));
}

// REQ-BMC-006: the transaction check must not use @@in_transaction, which
// MySQL lacks; a savepoint lives only as long as its transaction.
it('deletes the BMC and its cache in one transaction without @@in_transaction', function () {
	expect(redfish_delete_repo()->delete(7, 1))->toBeTrue();

	$log = redfish_delete_log();

	expect($log['BEGIN'])->toBe(1)
		->and($log['COMMIT'])->toBe(1)
		->and($log)->not->toHaveKey('ROLLBACK')
		->and($log['DELETE FROM plugin_redfish_bmc WHERE id = ?'])->toBe(1);

	foreach (array_keys($log) as $sql) {
		expect($sql)->not->toContain('@@in_transaction');
	}
});

it('reruns the delete when the server ends the transaction part way', function () {
	$repo = redfish_delete_repo();
	// A deadlock rolls the transaction back after the member table delete.
	$GLOBALS['__redfish_end_tx'] = ['DELETE FROM plugin_redfish_member'];

	expect($repo->delete(7, 1))->toBeTrue();

	$log = redfish_delete_log();

	expect($log['BEGIN'])->toBe(2)
		->and($log['COMMIT'])->toBe(1)
		->and($log)->not->toHaveKey('ROLLBACK');
});

it('rolls back and reports failure when the BMC row delete keeps failing', function () {
	$repo = redfish_delete_repo();
	$GLOBALS['__redfish_db_fail'] = ['DELETE FROM plugin_redfish_bmc'];

	try {
		expect($repo->delete(7, 1))->toBeFalse();
	} finally {
		unset($GLOBALS['__redfish_db_fail']);
	}

	$log = redfish_delete_log();

	expect($log)->not->toHaveKey('COMMIT')
		->and($log['ROLLBACK'])->toBe(3);
});
