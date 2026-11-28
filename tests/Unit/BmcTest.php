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

use Somethingwithproof\Redfish\AdminState;
use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\BmcImport;
use Somethingwithproof\Redfish\BmcInput;
use Somethingwithproof\Redfish\TlsMode;

const REDFISH_PIN = 'mJdW0zzm4Ojq4KZSwDjKGAN1WsIw7J1wR8rq7GR3x0U=';

function redfish_import(string $csv, array $taken = []): BmcImport {
	return BmcImport::parse($csv, ['ro-monitor' => 3], fn (string $a, int $p) => $taken["$a $p"] ?? null, fn (string $n) => $n === 'existing', fn (int $h) => $h === 7);
}

it('pins Bmc::fromRow on empty, NULL, and malformed values', function () {
	$row = ['id' => '4', 'name' => 'r660', 'address' => '10.0.0.5', 'port' => '443', 'poller_id' => '1', 'host_id' => '7', 'credential_id' => '3', 'tls_mode' => 'pin', 'ca_file' => '', 'pin_sha256' => 'sha256//' . REDFISH_PIN, 'basic_fallback' => 'on', 'admin_state' => 'enabled'];
	$bmc = Bmc::fromRow($row);

	expect($bmc)->not->toBeNull()
		->and($bmc->pin)->toBe(REDFISH_PIN)
		->and($bmc->basicFallback)->toBeTrue()
		->and($bmc->origin())->toBe('https://10.0.0.5')
		->and(Bmc::fromRow(['tls_mode' => 'nonsense'] + $row)->tlsMode)->toBe(TlsMode::System)
		->and(Bmc::fromRow(['tls_mode' => null] + $row)->tlsMode)->toBe(TlsMode::System)
		->and(Bmc::fromRow(['admin_state' => ''] + $row)->adminState)->toBe(AdminState::Disabled)
		->and(Bmc::fromRow(['port' => '70000'] + $row)->port)->toBe(443)
		->and(Bmc::fromRow(['port' => null] + $row)->port)->toBe(443)
		->and(Bmc::fromRow(['host_id' => 'x'] + $row)->hostId)->toBe(0)
		->and(Bmc::fromRow(['basic_fallback' => null] + $row)->basicFallback)->toBeFalse()
		->and(Bmc::fromRow(['pin_sha256' => 'short'] + $row)->pin)->toBe('')
		->and(Bmc::fromRow(['id' => ''] + $row))->toBeNull()
		->and(Bmc::fromRow(['address' => '127.0.0.1'] + $row))->toBeNull()
		->and(Bmc::fromRow(['address' => 'BMC.example'] + $row))->toBeNull()
		->and(Bmc::fromRow(['address' => null] + $row))->toBeNull()
		->and(Bmc::fromRow(['address' => '2001:db8::1', 'port' => '8443'] + $row)->origin())->toBe('https://[2001:db8::1]:8443');
});

it('validates the edit form and names each failing field', function () {
	$ok = ['name' => 'r660', 'address' => 'bmc01.example.net', 'port' => '', 'poller_id' => '1', 'host_id' => '0', 'credential_id' => '3', 'tls_mode' => 'system', 'enabled' => 'on'];

	expect(BmcInput::parse($ok, 0)->bmc?->port)->toBe(443)
		->and(BmcInput::parse(['address' => 'https://bmc01'] + $ok, 0)->errors)->toHaveKey('address')
		->and(BmcInput::parse(['poller_id' => '0'] + $ok, 0)->errors)->toHaveKey('poller_id')
		->and(BmcInput::parse(['poller_id' => '2'] + $ok, 0)->bmc?->pollerId)->toBe(2)
		->and(BmcInput::parse(['credential_id' => ''] + $ok, 0)->errors)->toHaveKey('credential_id')
		->and(BmcInput::parse(['tls_mode' => 'off'] + $ok, 0)->errors)->toHaveKey('tls_mode')
		->and(BmcInput::parse(['tls_mode' => 'pin', 'pin_sha256' => 'nope'] + $ok, 0)->errors)->toHaveKey('pin_sha256')
		->and(BmcInput::parse(['tls_mode' => 'pin', 'pin_sha256' => REDFISH_PIN] + $ok, 0)->bmc?->pin)->toBe(REDFISH_PIN)
		->and(BmcInput::parse(['tls_mode' => 'ca_bundle', 'ca_file' => 'relative.pem'] + $ok, 0)->errors)->toHaveKey('ca_file')
		->and(BmcInput::parse(['port' => '0'] + $ok, 0)->errors)->toHaveKey('port')
		->and(BmcInput::parse(['name' => "bad\nname"] + $ok, 0)->errors)->toHaveKey('name')
		// A pin entered in another mode is dropped, not kept dormant.
		->and(BmcInput::parse(['pin_sha256' => REDFISH_PIN] + $ok, 0)->bmc?->pin)->toBe('');
});

it('imports valid rows with credential names', function () {
	$import = redfish_import("name,address,credential,tls_mode,enabled\nr660-a,10.0.0.5,ro-monitor,,\nr660-b,bmc02.example.net,ro-monitor,unverified,off\n");

	expect($import->errors)->toBe([])
		->and(count($import->bmcs))->toBe(2)
		->and($import->bmcs[0]->credentialId)->toBe(3)
		->and($import->bmcs[0]->tlsMode)->toBe(TlsMode::System)
		->and($import->bmcs[1]->tlsMode)->toBe(TlsMode::Unverified)
		->and($import->bmcs[1]->enabled())->toBeFalse();
});

it('rejects a file with a secret column before reading any row', function (string $column) {
	$import = redfish_import("name,address,credential,$column\nr660,10.0.0.5,ro-monitor,SENTINEL-in-file\n");

	expect($import->bmcs)->toBe([])
		->and($import->errors)->toHaveCount(1)
		->and($import->errors[0])->toContain('must not carry secrets')
		->and(implode(' ', $import->errors))->not->toContain('SENTINEL-in-file');
})->with(['password', 'Password', 'bmc_pass', 'secret', 'token', 'api_key', 'x-auth-token']);

it('reports every malformed row and applies none of them', function () {
	$import = redfish_import(implode("\n", [
		'name,address,credential,port,host_id',
		'good,10.0.0.5,ro-monitor,443,',
		'badaddr,https://10.0.0.6,ro-monitor,443,',
		'nocred,10.0.0.7,nobody,443,',
		'dup,10.0.0.5,ro-monitor,443,',
		'taken,10.0.0.8,ro-monitor,443,',
		'existing,10.0.0.9,ro-monitor,443,',
		'nohost,10.0.0.10,ro-monitor,443,99',
		'short,10.0.0.11',
		'loop,127.0.0.1,ro-monitor,443,',
	]) . "\n", ['10.0.0.8 443' => 'other-bmc']);

	expect($import->bmcs)->toBe([])
		->and(implode("\n", $import->errors))->toContain('Line 3: Address')
		->and(implode("\n", $import->errors))->toContain('Line 4: Credential: no stored credential is named "nobody"')
		->and(implode("\n", $import->errors))->toContain('Line 5: the address 10.0.0.5 port 443 is already registered as line 2')
		->and(implode("\n", $import->errors))->toContain('Line 6: the address 10.0.0.8 port 443 is already registered as other-bmc')
		->and(implode("\n", $import->errors))->toContain('Line 7: the name existing')
		->and(implode("\n", $import->errors))->toContain('Line 8: no Cacti device has ID 99')
		->and(implode("\n", $import->errors))->toContain('Line 9: expected 5 fields')
		->and(implode("\n", $import->errors))->toContain('Line 10: Address');
});

it('rejects unknown columns, a missing header, and empty files', function () {
	expect(redfish_import("name,address,credential,colour\n")->errors[0])->toContain('only these columns')
		->and(redfish_import("name,address\n")->errors[0])->toContain('only these columns')
		->and(redfish_import('')->errors[0])->toContain('empty')
		->and(redfish_import("name,address,credential\n")->errors[0])->toContain('no BMC rows');
});

it('imports at most one BMC per device, against the registry and within the file', function () {
	$csv = "name,address,credential,host_id\na,10.0.0.1,ro-monitor,7\nb,10.0.0.2,ro-monitor,7\nc,10.0.0.3,ro-monitor,\nd,10.0.0.4,ro-monitor,\n";

	// An import is all or nothing, so a conflict imports no row.
	$inFile = redfish_import($csv);
	expect($inFile->bmcs)->toBe([])
		->and($inFile->errors)->toBe(['Line 3: device 7 already has the BMC of line 2.'])
		->and(redfish_import(str_replace(',7', ',', $csv))->bmcs)->toHaveCount(4);

	$registry = BmcImport::parse($csv, ['ro-monitor' => 3], fn (string $a, int $p) => null, fn (string $n) => false, fn (int $h) => $h === 7, fn (int $h) => $h === 7 ? 'r660' : null);
	expect($registry->bmcs)->toBe([])
		->and($registry->errors)->toBe(['Line 2: device 7 already has the BMC r660.', 'Line 3: device 7 already has the BMC r660.']);
});
