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

use Somethingwithproof\Redfish\Log;

beforeEach(function () {
	$GLOBALS['__redfish_log'] = [];
});

it('reads its metadata from INFO', function () {
	$info = plugin_redfish_version();

	expect($info['name'])->toBe('redfish')
		->and($info['compat'])->toBe('1.2.31')
		->and($info['homepage'])->toBe('https://github.com/somethingwithproof/plugin_redfish')
		->and($info['capabilities'])->toBe('online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(redfish_php_supported())->toBeTrue()
		->and(redfish_missing_extensions())->toBe([])
		->and(redfish_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('does not refuse or log on a supported runtime', function () {
	expect(redfish_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__redfish_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Redfish\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\RedfishLog'))->toBeFalse();
});

it('logs under the REDFISH tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__redfish_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'REDFISH', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'REDFISH', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'REDFISH', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'REDFISH', 'level' => ''],
	]);
});
