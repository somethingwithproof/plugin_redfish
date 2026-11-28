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
use Somethingwithproof\Redfish\BmcHealth;
use Somethingwithproof\Redfish\ClientError;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\HealthWarnings;
use Somethingwithproof\Redfish\IpmiClient;
use Somethingwithproof\Redfish\IpmiCommand;
use Somethingwithproof\Redfish\IpmiNormalizer;
use Somethingwithproof\Redfish\Member;
use Somethingwithproof\Redfish\Reading;
use Somethingwithproof\Redfish\Redactor;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\Settings;

it('builds strict read-only ipmitool argv arrays with zero secrets on argv', function () {
	$argv = IpmiCommand::argv('ipmitool', '10.0.0.12', 'ro-user', IpmiCommand::OP_DCMI_POWER, IpmiCommand::AUTH_ENV, '', 12);

	expect($argv)->toBe(['ipmitool', '-I', 'lanplus', '-N', '12', '-R', '1', '-H', '10.0.0.12', '-U', 'ro-user', '-E', 'dcmi', 'power', 'reading'])
		->and($argv)->not->toContain('-P');

	$fileArgv = IpmiCommand::argv('/usr/bin/ipmitool', '10.0.0.12', 'ro-user', IpmiCommand::OP_SDR_PSU, IpmiCommand::AUTH_FILE, '/tmp/pw-file', 8);

	expect($fileArgv)->toBe(['/usr/bin/ipmitool', '-I', 'lanplus', '-N', '8', '-R', '1', '-H', '10.0.0.12', '-U', 'ro-user', '-f', '/tmp/pw-file', 'sdr', 'type', 'Power Supply'])
		->and($fileArgv)->not->toContain('-P');
});

it('refuses mutating or unknown IPMI operations, loopback addresses, and unsafe flags', function () {
	expect(fn () => IpmiCommand::argv('ipmitool', '10.0.0.12', 'ro-user', 'dcmi_set_limit'))->toThrow(ClientError::class)
		->and(fn () => IpmiCommand::argv('ipmitool', '10.0.0.12', 'ro-user', 'power_off'))->toThrow(ClientError::class)
		->and(fn () => IpmiCommand::argv('ipmitool', '127.0.0.1', 'ro-user', IpmiCommand::OP_DCMI_POWER))->toThrow(ClientError::class)
		->and(fn () => IpmiCommand::argv('ipmitool; id', '10.0.0.12', 'ro-user', IpmiCommand::OP_DCMI_POWER))->toThrow(ClientError::class)
		->and(fn () => IpmiCommand::argv('ipmitool', '10.0.0.12', "bad\nuser", IpmiCommand::OP_DCMI_POWER))->toThrow(ClientError::class);
});

it('passes IPMI password via IPMITOOL_PASSWORD env var or mode 0600 temp file and never on argv', function () {
	$secret = new Secret('SUP3R-S3CR3T-IPMI!');
	$bmc    = Bmc::fromRow(['id' => '1', 'name' => 'legacy-bmc', 'address' => '10.0.0.20', 'port' => '443', 'protocols' => 'redfish,ipmi', 'credential_id' => '2', 'tls_mode' => 'system', 'admin_state' => 'enabled']);

	$seenArgv = [];
	$seenEnv  = [];
	$client   = new IpmiClient('ipmitool', 5, IpmiCommand::AUTH_ENV, static function (array $argv, array $env, int $timeout) use (&$seenArgv, &$seenEnv): array {
		$seenArgv[] = $argv;
		$seenEnv[]  = $env;

		return ['exit' => 0, 'stdout' => "Instantaneous power reading: 245 Watts\n", 'stderr' => ''];
	});

	$out = $client->runOperation($bmc, 'monitor', $secret, IpmiCommand::OP_DCMI_POWER, new Redactor());

	expect($out)->toContain('245 Watts')
		->and(implode(' ', $seenArgv[0]))->not->toContain('SUP3R-S3CR3T-IPMI!')
		->and($seenEnv[0]['IPMITOOL_PASSWORD'] ?? null)->toBe('SUP3R-S3CR3T-IPMI!');

	// File mode: creates 0600 temp file and unlinks it in finally.
	$capturedFile = '';
	$capturedMode = 0;
	$capturedBody = '';
	$fileClient   = new IpmiClient('ipmitool', 5, IpmiCommand::AUTH_FILE, static function (array $argv, array $env, int $timeout) use (&$capturedFile, &$capturedMode, &$capturedBody): array {
		$idx          = array_search('-f', $argv, true);
		$capturedFile = $idx !== false ? (string) ($argv[$idx + 1] ?? '') : '';
		$capturedMode = $capturedFile !== '' && is_file($capturedFile) ? (fileperms($capturedFile) & 0777) : 0;
		$capturedBody = $capturedFile !== '' && is_file($capturedFile) ? (string) file_get_contents($capturedFile) : '';

		return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
	});

	$fileClient->runOperation($bmc, 'monitor', $secret, IpmiCommand::OP_CHASSIS_STATUS, new Redactor());

	expect($capturedFile)->not->toBe('')
		->and($capturedMode)->toBe(0600)
		->and($capturedBody)->toBe('SUP3R-S3CR3T-IPMI!')
		->and(file_exists($capturedFile))->toBeFalse();
});

it('normalizes DCMI power and SDR temperature, fan, PSU, and chassis readings into the Redfish model', function () {
	$dcmi = <<<'TXT'
    Instantaneous power reading:                   318 Watts
    Minimum during sampling period:                280 Watts
    Maximum during sampling period:                350 Watts
TXT;
	$temps = <<<'TXT'
Inlet Temp       | 04h | ok  |  7.1 | 23 degrees C
Exhaust Temp     | 01h | nc  |  7.1 | 48 degrees C
CPU2 Temp        | 0Fh | ns  |  3.2 | Disabled
TXT;
	$fans = <<<'TXT'
Fan1A            | 30h | ok  |  7.1 | 7200 RPM
Fan2A            | 31h | cr  |  7.1 | 25 percent
Fan3A            | 32h | ns  |  7.1 | Not Present
TXT;
	$psus = <<<'TXT'
PS1 Status       | 64h | ok  | 10.1 | Presence detected, 165 Watts
PS2 Status       | 65h | cr  | 10.2 | Power Supply AC lost
TXT;
	$chassis = <<<'TXT'
System Power         : on
Power Fault          : false
Cooling/fan fault    : false
TXT;

	$obs = IpmiNormalizer::normalizeAll($dcmi, $temps, $fans, $psus, $chassis);
	$obs->merge(\Somethingwithproof\Redfish\Normalizer::node($obs, IpmiNormalizer::CHASSIS_ID));

	expect($obs->find(Member::NODE, 'power_watts')?->value)->toBe(318.0)
		->and($obs->find(Member::NODE, 'power_watts')?->source)->toBe(Reading::SOURCE_IPMI_DCMI)
		->and($obs->find(Member::NODE, 'inlet_celsius')?->value)->toBe(23.0)
		->and($obs->find(Member::NODE, 'inlet_celsius')?->source)->toBe(Reading::SOURCE_IPMI_SDR)
		->and($obs->find('ipmi/temperature/Exhaust Temp', 'temperature_celsius')?->value)->toBe(48.0)
		->and($obs->find('ipmi/temperature/Exhaust Temp', 'health_code')?->value)->toBe(1.0)
		->and($obs->find('ipmi/temperature/CPU2 Temp', 'temperature_celsius')?->value)->toBeNull()
		->and($obs->find('ipmi/fan/Fan1A', 'fan_speed')?->value)->toBe(7200.0)
		->and($obs->find('ipmi/fan/Fan1A', 'fan_speed')?->unit)->toBe('RPM')
		->and($obs->find('ipmi/fan/Fan2A', 'fan_speed')?->unit)->toBe('%')
		->and($obs->find('ipmi/fan/Fan2A', 'health_code')?->value)->toBe(2.0)
		->and($obs->members['ipmi/fan/Fan3A']->absent)->toBeTrue()
		->and($obs->find('ipmi/psu/PS1 Status', 'psu_input_watts')?->value)->toBe(165.0)
		->and($obs->find('ipmi/psu/PS2 Status', 'health_code')?->value)->toBe(2.0)
		->and($obs->find(Member::NODE, 'health_code')?->value)->toBe(2.0);
});

it('maps unparsable IPMI output to null (unknown) and warns when IPMI fallback is enabled', function () {
	expect(IpmiNormalizer::parseDcmiPower('garbage output'))->toBeNull()
		->and(IpmiNormalizer::parseChassisFault('unrelated text'))->toBeNull();

	$bmc = Bmc::fromRow(['id' => '1', 'name' => 'r740', 'address' => '10.0.0.9', 'port' => '443', 'protocols' => 'redfish,ipmi', 'credential_id' => '1', 'tls_mode' => 'system', 'admin_state' => 'enabled']);
	expect($bmc->ipmiFallback)->toBeTrue();

	$warnings = HealthWarnings::for([$bmc], [1 => new BmcHealth(1)], [1 => true], null, [], Settings::fromRow([]), 1_800_000_000);
	$messages = implode("\n", array_column($warnings, 'message'));

	expect($messages)->toContain('IPMI fallback is enabled')
		->and($messages)->toContain('UDP 623');
});

it('classifies IPMI auth rejections cleanly', function () {
	$bmc    = Bmc::fromRow(['id' => '1', 'name' => 'legacy-bmc', 'address' => '10.0.0.21', 'port' => '443', 'protocols' => 'redfish,ipmi', 'credential_id' => '2', 'tls_mode' => 'system', 'admin_state' => 'enabled']);
	$client = new IpmiClient('ipmitool', 5, IpmiCommand::AUTH_ENV, static fn (): array => [
		'exit'   => 1,
		'stdout' => '',
		'stderr' => 'Error: Unable to establish IPMI v2 / RMCP+ session: Invalid password',
	]);

	try {
		$client->runOperation($bmc, 'monitor', new Secret('pw'), IpmiCommand::OP_SDR_TEMPERATURE, new Redactor());
		expect(false)->toBeTrue('Expected ClientError');
	} catch (ClientError $e) {
		expect($e->class)->toBe(FailureClass::AuthRejected);
	}
});
