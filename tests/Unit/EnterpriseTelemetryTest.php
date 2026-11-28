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

use Somethingwithproof\Redfish\HardwareHealthParser;
use Somethingwithproof\Redfish\IpmiCommand;
use Somethingwithproof\Redfish\IpmiNormalizer;
use Somethingwithproof\Redfish\Member;
use Somethingwithproof\Redfish\NodeCache;
use Somethingwithproof\Redfish\Normalizer;
use Somethingwithproof\Redfish\Observation;
use Somethingwithproof\Redfish\Reading;
use Somethingwithproof\Redfish\RedundancyTelemetry;
use Somethingwithproof\Redfish\SelSummary;
use Somethingwithproof\Redfish\Target;

it('parses fan RPM/%, fault counts, and PSU redundancy from Redfish legacy Power/Thermal and PowerSubsystem', function () {
	$legacyPower = [
		'Id' => 'Power',
		'PowerSupplies' => [
			['MemberId' => 'PSU1', 'Name' => 'PSU 1', 'Status' => ['State' => 'Enabled', 'Health' => 'OK'], 'PowerInputWatts' => 140],
			['MemberId' => 'PSU2', 'Name' => 'PSU 2', 'Status' => ['State' => 'Enabled', 'Health' => 'Critical'], 'PowerInputWatts' => 0],
		],
		'Redundancy' => [
			[
				'Mode'          => 'Failover',
				'MinNumNeeded'  => 1,
				'RedundancySet' => [['@odata.id' => '/p1'], ['@odata.id' => '/p2']],
				'Status'        => ['State' => 'Disabled', 'Health' => 'Critical'],
			],
		],
	];

	$parsedPower = RedundancyTelemetry::fromRedfishPower($legacyPower);
	expect($parsedPower['status'])->toBe(RedundancyTelemetry::STATUS_LOST)
		->and($parsedPower['loss_count'])->toBe(1)
		->and($parsedPower['total'])->toBe(2)
		->and($parsedPower['healthy'])->toBe(1)
		->and($parsedPower['failed'])->toBe(1);

	// Modern PowerSubsystem with Degraded redundancy.
	$subsystemPower = [
		'Id'                    => 'PowerSubsystem',
		'PowerSupplyRedundancy' => [
			[
				'RedundancyType'   => 'NPlusM',
				'MinNeededInGroup' => 2,
				'RedundancyGroup'  => [['@odata.id' => '/p1'], ['@odata.id' => '/p2'], ['@odata.id' => '/p3']],
				'Status'           => ['State' => 'Degraded', 'Health' => 'Warning'],
			],
		],
	];
	$psuResources = [
		['Status' => ['State' => 'Enabled', 'Health' => 'OK']],
		['Status' => ['State' => 'Enabled', 'Health' => 'OK']],
		['Status' => ['State' => 'Enabled', 'Health' => 'Warning']],
	];

	$subParsed = RedundancyTelemetry::fromRedfishPower($subsystemPower, $psuResources);
	expect($subParsed['status'])->toBe(RedundancyTelemetry::STATUS_DEGRADED)
		->and($subParsed['loss_count'])->toBe(1);

	// Healthy redundant PowerSubsystem.
	$healthySub = RedundancyTelemetry::fromRedfishPower(
		[
			'PowerSupplyRedundancy' => [
				[
					'MinNeededInGroup' => 1,
					'RedundancyGroup'  => [['@odata.id' => '/p1'], ['@odata.id' => '/p2']],
					'Status'           => ['State' => 'Enabled', 'Health' => 'OK'],
				],
			],
		],
		[
			['Status' => ['State' => 'Enabled', 'Health' => 'OK']],
			['Status' => ['State' => 'Enabled', 'Health' => 'OK']],
		]
	);
	expect($healthySub['status'])->toBe(RedundancyTelemetry::STATUS_REDUNDANT)
		->and($healthySub['loss_count'])->toBe(0);

	// Legacy Thermal fan RPM + percent summary and fault counts.
	$legacyThermal = [
		'Fans' => [
			['Name' => 'Fan 1', 'Reading' => 6200, 'ReadingUnits' => 'RPM', 'Status' => ['State' => 'Enabled', 'Health' => 'OK']],
			['Name' => 'Fan 2', 'Reading' => 8400, 'ReadingUnits' => 'RPM', 'Status' => ['State' => 'Degraded', 'Health' => 'Warning']],
			['Name' => 'Fan 3', 'Reading' => 55, 'ReadingUnits' => 'Percent', 'Status' => ['State' => 'Enabled', 'Health' => 'Critical']],
			['Name' => 'Fan 4', 'Reading' => 0, 'ReadingUnits' => 'RPM', 'Status' => ['State' => 'Absent']],
		],
	];
	$thermalParsed = RedundancyTelemetry::fromRedfishThermal($legacyThermal);
	expect($thermalParsed['total'])->toBe(3)
		->and($thermalParsed['degraded'])->toBe(1)
		->and($thermalParsed['failed'])->toBe(1)
		->and($thermalParsed['min_rpm'])->toBe(6200.0)
		->and($thermalParsed['max_rpm'])->toBe(8400.0)
		->and($thermalParsed['min_percent'])->toBe(55.0)
		->and($thermalParsed['max_percent'])->toBe(55.0);

	// Normalizer emits legacyPower and powerSubsystem PSU redundancy readings.
	$legacyTarget = new Target(Target::LEGACY_POWER, '/redfish/v1/Chassis/1/Power', 'Power', 'Power', '1', uses: ['psus']);
	$obs          = Normalizer::normalize($legacyTarget, $legacyPower, 'dell');
	expect($obs->find('1/chassis/1', 'psu_redundancy_code')?->value)->toBe(2.0)
		->and($obs->find('1/chassis/1', 'psu_redundancy_loss_count')?->value)->toBe(1.0);

	$subTarget = new Target(Target::POWER_SUBSYSTEM, '/redfish/v1/Chassis/1/PowerSubsystem', 'PowerSubsystem', 'PowerSubsystem', '1', uses: ['psus']);
	$subObs    = Normalizer::normalize($subTarget, $subsystemPower, 'dell');
	expect($subObs->find('1/chassis/1', 'psu_redundancy_code')?->value)->toBe(1.0)
		->and($subObs->find('1/chassis/1', 'psu_redundancy_loss_count')?->value)->toBe(1.0);

	// RedundancyTelemetry::fromObservation + toReadings + toArray.
	$telemetry = RedundancyTelemetry::fromObservation($obs, $legacyPower);
	$readings  = $telemetry->toReadings();
	$arr       = $telemetry->toArray();
	expect($telemetry->psuRedundancyStatus)->toBe(RedundancyTelemetry::STATUS_LOST)
		->and($telemetry->psuRedundancyCode())->toBe(2.0)
		->and($arr['psu_redundancy_status'])->toBe('Lost')
		->and(count($readings))->toBeGreaterThanOrEqual(4);
});

it('parses fan and PSU redundancy telemetry from IPMI SDR records', function () {
	$fanSdr = <<<'TXT'
Fan1A            | 30h | ok  |  7.1 | 7200 RPM
Fan2A            | 31h | nc  |  7.1 | 4800 RPM
Fan3A            | 32h | cr  |  7.1 | 35 percent
Fan4A            | 33h | ns  |  7.1 | Not Present
TXT;
	$psuSdr = <<<'TXT'
PS1 Status       | 64h | ok  | 10.1 | Presence detected, 160 Watts
PS2 Status       | 65h | cr  | 10.2 | Power Supply AC lost
PS Redundancy    | 74h | cr  |  7.1 | Redundancy Lost
TXT;

	$telemetry = IpmiNormalizer::parseSdrRedundancy($fanSdr, $psuSdr);
	expect($telemetry->fansTotal)->toBe(3)
		->and($telemetry->fansDegraded)->toBe(1)
		->and($telemetry->fansFailed)->toBe(1)
		->and($telemetry->fanMinRpm)->toBe(4800.0)
		->and($telemetry->fanMaxRpm)->toBe(7200.0)
		->and($telemetry->fanMinPercent)->toBe(35.0)
		->and($telemetry->psusTotal)->toBe(2)
		->and($telemetry->psusHealthy)->toBe(1)
		->and($telemetry->psusFailed)->toBe(1)
		->and($telemetry->psuRedundancyStatus)->toBe(RedundancyTelemetry::STATUS_LOST)
		->and($telemetry->psuRedundancyLossCount)->toBe(1);

	$healthyPsu = <<<'TXT'
PS1 Status       | 64h | ok  | 10.1 | Presence detected, 160 Watts
PS2 Status       | 65h | ok  | 10.2 | Presence detected, 155 Watts
PS Redundancy    | 74h | ok  |  7.1 | Fully Redundant
TXT;
	$healthy = IpmiNormalizer::parseSdrRedundancy($fanSdr, $healthyPsu);
	expect($healthy->psuRedundancyStatus)->toBe(RedundancyTelemetry::STATUS_REDUNDANT)
		->and($healthy->psuRedundancyLossCount)->toBe(0);
});

it('parses Redfish Memory ECC metrics and Storage/NVMe drive health', function () {
	$memMetrics = [
		'Id'       => 'MemoryMetrics',
		'Name'     => 'DIMM.Socket.A1 Metrics',
		'LifeTime' => [
			'CorrectableECCErrorCount'   => 14,
			'UncorrectableECCErrorCount' => 2,
		],
		'CurrentPeriod' => [
			'CorrectableECCErrorCount'   => 3,
			'UncorrectableECCErrorCount' => 1,
		],
	];
	$memParent = [
		'Id'     => 'DIMM.Socket.A1',
		'Name'   => 'DIMM A1',
		'Status' => ['State' => 'Enabled', 'Health' => 'OK'],
	];

	$memObs = HardwareHealthParser::parseMemoryMetrics('System.1', 'DIMM.Socket.A1', $memMetrics, $memParent);
	$mKey   = 'System.1/memory/DIMM.Socket.A1';
	expect($memObs->find($mKey, 'ecc_corrected_errors')?->value)->toBe(14.0)
		->and($memObs->find($mKey, 'ecc_uncorrected_errors')?->value)->toBe(2.0)
		->and($memObs->find($mKey, 'ecc_period_corrected_errors')?->value)->toBe(3.0)
		->and($memObs->find($mKey, 'ecc_period_uncorrected_errors')?->value)->toBe(1.0)
		// Uncorrectable ECC forces Critical health (2.0) even when parent reported OK.
		->and($memObs->find($mKey, 'health_code')?->value)->toBe(2.0);

	// Via Normalizer target dispatch.
	$memTarget = new Target(Target::MEMORY_METRICS, '/redfish/v1/Systems/System.1/Memory/DIMM.A2/MemoryMetrics', 'MemoryMetrics', 'MemoryMetrics', 'System.1', 'DIMM.A2');
	$memObs2   = Normalizer::normalize($memTarget, ['LifeTime' => ['CorrectableECCErrorCount' => 5, 'UncorrectableECCErrorCount' => 0]], 'dell');
	expect($memObs2->find('System.1/memory/DIMM.A2', 'ecc_corrected_errors')?->value)->toBe(5.0)
		->and($memObs2->find('System.1/memory/DIMM.A2', 'health_code')?->value)->toBe(1.0);

	// Absent DIMM reports null health and no fabricated ECC zero.
	$absentMem = HardwareHealthParser::parseMemoryMetrics('System.1', 'DIMM.A3', [], ['Status' => ['State' => 'Absent']]);
	expect($absentMem->members['System.1/memory/DIMM.A3']->absent)->toBeTrue()
		->and($absentMem->find('System.1/memory/DIMM.A3', 'ecc_corrected_errors'))->toBeNull();

	// NVMe Drive with PredictedMediaLifeLeftPercent = 12 -> 88% wear -> Warning.
	$nvmeDrive = [
		'Id'                            => 'NVMe.Bay.0',
		'Name'                          => 'Solid State Disk 0:1:0',
		'MediaType'                     => 'SSD',
		'Protocol'                      => 'NVMe',
		'PredictedMediaLifeLeftPercent' => 12,
		'FailurePredicted'              => false,
		'Status'                        => ['State' => 'Enabled', 'Health' => 'OK'],
	];
	$driveObs = HardwareHealthParser::parseDriveHealth('System.1', 'NVMe.Bay.0', $nvmeDrive);
	$dKey     = 'System.1/drive/NVMe.Bay.0';
	expect($driveObs->find($dKey, 'drive_media_life_left_pct')?->value)->toBe(12.0)
		->and($driveObs->find($dKey, 'drive_predicted_wear_pct')?->value)->toBe(88.0)
		->and($driveObs->find($dKey, 'drive_failure_predicted')?->value)->toBe(0.0)
		->and($driveObs->find($dKey, 'health_code')?->value)->toBe(1.0);

	// Drive with SMART FailurePredicted = true and OEM wear indicator -> Critical (2.0).
	$failingTarget = new Target(Target::DRIVE, '/redfish/v1/Systems/System.1/Storage/1/Drives/NVMe.Bay.1', 'NVMe.Bay.1', 'Drive', 'System.1', 'NVMe.Bay.1');
	$failingObs    = Normalizer::normalize($failingTarget, [
		'Name'             => 'NVMe 1',
		'FailurePredicted' => true,
		'Oem'              => ['Dell' => ['NVMeSMARTPercentageUsed' => 97]],
		'Status'           => ['State' => 'Enabled', 'Health' => 'Warning'],
	], 'dell');
	expect($failingObs->find('System.1/drive/NVMe.Bay.1', 'drive_predicted_wear_pct')?->value)->toBe(97.0)
		->and($failingObs->find('System.1/drive/NVMe.Bay.1', 'drive_failure_predicted')?->value)->toBe(1.0)
		->and($failingObs->find('System.1/drive/NVMe.Bay.1', 'health_code')?->value)->toBe(2.0);
});

it('parses BMC SEL critical and warning event counts and hourly rates from Redfish and IPMI', function () {
	$now     = 1_800_000_000;
	$redfish = [
		'PercentUsed' => 42,
		'Overflow'    => false,
		'Members'     => [
			['Id' => '1', 'Severity' => 'Critical', 'Created' => gmdate('Y-m-d\TH:i:s\Z', $now - 300)],
			['Id' => '2', 'Severity' => 'Critical', 'Created' => gmdate('Y-m-d\TH:i:s\Z', $now - 900)],
			// Outside 30-minute window (still counted in total criticalCount, not in window rate).
			['Id' => '3', 'Severity' => 'Critical', 'Created' => gmdate('Y-m-d\TH:i:s\Z', $now - 7200)],
			['Id' => '4', 'Severity' => 'Warning', 'Created' => gmdate('Y-m-d\TH:i:s\Z', $now - 600)],
			['Id' => '5', 'Severity' => 'OK', 'Created' => gmdate('Y-m-d\TH:i:s\Z', $now - 60)],
		],
	];

	$summary = HardwareHealthParser::parseRedfishSel('iDRAC.Embedded.1', $redfish, 1800, $now);
	expect($summary)->toBeInstanceOf(SelSummary::class)
		->and($summary->totalEntries)->toBe(5)
		->and($summary->criticalCount)->toBe(3)
		->and($summary->warningCount)->toBe(1)
		->and($summary->windowCriticalCount)->toBe(2)
		->and($summary->windowWarningCount)->toBe(1)
		// 2 critical events in 0.5h => 4.0 / hour; 1 warning in 0.5h => 2.0 / hour.
		->and($summary->criticalRatePerHour)->toBe(4.0)
		->and($summary->warningRatePerHour)->toBe(2.0)
		->and($summary->percentUsed)->toBe(42.0)
		->and($summary->overflow)->toBeFalse();

	$selObs = $summary->toObservation();
	$selKey = 'iDRAC.Embedded.1/sel/SEL';
	expect($selObs->find($selKey, 'sel_critical_count')?->value)->toBe(3.0)
		->and($selObs->find($selKey, 'sel_critical_rate_per_hour')?->value)->toBe(4.0)
		->and($selObs->find($selKey, 'sel_warning_rate_per_hour')?->value)->toBe(2.0)
		->and($selObs->find($selKey, 'sel_percent_used')?->value)->toBe(42.0)
		->and($selObs->find($selKey, 'health_code')?->value)->toBe(2.0);

	// Via Normalizer target dispatch.
	$selTarget = new Target(Target::SEL_ENTRIES, '/redfish/v1/Managers/1/LogServices/SEL/Entries', 'Entries', 'LogEntryCollection', '1');
	$selNorm   = Normalizer::normalize($selTarget, $redfish, 'dell');
	expect($selNorm->find('1/sel/SEL', 'sel_critical_count')?->value)->toBe(3.0);

	// IPMI SEL elist + sel info + allowlisted read-only IPMI commands.
	$ipmiArgv = IpmiCommand::argv('ipmitool', '10.0.0.12', 'ro-user', IpmiCommand::OP_SEL_ELIST);
	expect($ipmiArgv)->toContain('sel', 'elist');
	expect(IpmiCommand::argv('ipmitool', '10.0.0.12', 'ro-user', IpmiCommand::OP_SEL_INFO))->toContain('sel', 'info');

	$t1      = gmdate('m/d/Y | H:i:s', $now - 400);
	$t2      = gmdate('m/d/Y | H:i:s', $now - 800);
	$tOld    = gmdate('m/d/Y | H:i:s', $now - 10800);
	$ipmiSel = <<<TXT
Entries          : 512
Percent Used     : 68%
Overflow         : true
   1 | $t1 | Power Supply PS2 | Power Supply AC lost | Asserted
   2 | $t2 | Memory DIMM_A1   | Correctable ECC | Asserted
   3 | $t2 | Fan Fan1A        | Lower Critical going low | Deasserted
   4 | $tOld | Drive Bay0     | Drive Fault | Asserted
TXT;

	$ipmiSummary = IpmiNormalizer::parseSel($ipmiSel, 3600, $now);
	expect($ipmiSummary->source)->toBe(Reading::SOURCE_IPMI_SEL)
		->and($ipmiSummary->criticalCount)->toBe(2)
		->and($ipmiSummary->warningCount)->toBe(1)
		->and($ipmiSummary->windowCriticalCount)->toBe(1)
		->and($ipmiSummary->windowWarningCount)->toBe(1)
		->and($ipmiSummary->criticalRatePerHour)->toBe(1.0)
		->and($ipmiSummary->warningRatePerHour)->toBe(1.0)
		->and($ipmiSummary->percentUsed)->toBe(68.0)
		->and($ipmiSummary->overflow)->toBeTrue()
		->and($ipmiSummary->toArray()['overflow'])->toBeTrue();
});

it('maintains deterministic ordering and O(1) index lookup in NodeCache', function () {
	$cache = new NodeCache(1, 100, 200, [
		['key' => '1/fan/Fan B', 'kind' => 'fan', 'name' => 'Fan B', 'chassis' => '1', 'presence' => 'present', 'expected' => true],
		['key' => '1/fan/Fan A', 'kind' => 'fan', 'name' => 'Fan A', 'chassis' => '1', 'presence' => 'present', 'expected' => true],
		['key' => '1/psu/PSU 1', 'kind' => 'psu', 'name' => 'PSU 1', 'chassis' => '1', 'presence' => 'present', 'expected' => true],
	], []);

	$sorted = $cache->membersOf('fan');
	expect(array_column($sorted, 'name'))->toBe(['Fan A', 'Fan B'])
		->and($cache->membersOfUnsorted('psu'))->toHaveCount(1)
		->and($cache->memberAt('fan', \Somethingwithproof\Redfish\IndexKey::of('1/fan/Fan B'))['name'] ?? null)->toBe('Fan B')
		->and($cache->memberAt('fan', 'nonexistent'))->toBeNull();
});
