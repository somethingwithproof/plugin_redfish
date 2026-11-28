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
 * Diagnostic checks for the doctor_register_checks and doctor_register_debug_info hooks.
 *
 * All checks read local state only, never contact a BMC, and never return
 * credential or vault key material.
 */
final class DoctorChecks {
	/**
	 * Evaluate and return canonical Doctor check descriptors.
	 *
	 * @param \Closure(): Schema              $schema
	 * @param \Closure(): CredentialStore     $credentials
	 * @param \Closure(): BmcRepository       $bmcs
	 * @param \Closure(): HealthRepository    $health
	 * @param \Closure(): RackRepository      $racks
	 * @param \Closure(): NodeCacheRepository $caches
	 * @param \Closure(): FreshnessEvaluator  $freshness
	 * @param \Closure(): Settings            $settings
	 * @param \Closure(): Clock               $clock
	 * @return list<array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: array<string, mixed>,
	 *     remediation: string
	 * }>
	 */
	public static function definitions(
		\Closure $schema,
		\Closure $credentials,
		\Closure $bmcs,
		\Closure $health,
		\Closure $racks,
		\Closure $caches,
		\Closure $freshness,
		\Closure $settings,
		\Closure $clock,
	): array {
		$currentSchema = $schema()->storedVersion();
		$schemaOk      = $currentSchema === Schema::VERSION;

		$keyProblem = $credentials()->keyProblem();
		$keyOk      = $keyProblem === null;

		$runs        = $health()->runs();
		$cfg         = $settings();
		$now         = $clock()->now();
		$runIssues   = [];

		foreach ($runs as $run) {
			if ($run['stopped_early'] || $run['run_duration'] > $cfg->collectorBudget) {
				$runIssues[] = sprintf('poller %d exceeded budget (%0.1fs / %ds)', $run['poller_id'], $run['run_duration'], $cfg->collectorBudget);
			}

			if ($run['run_started'] > 0 && ($now - $run['run_started']) > 3 * $cfg->collectInterval) {
				$runIssues[] = sprintf('poller %d last ran %ds ago', $run['poller_id'], $now - $run['run_started']);
			}
		}

		if ($runs === []) {
			$runStatus      = 'warn';
			$runMessage     = 'No collector run recorded yet.';
			$runRemediation = 'Ensure poller_redfish.php runs via Cacti poller_bottom or cron.';
		} elseif ($runIssues !== []) {
			$runStatus      = 'warn';
			$runMessage     = implode('; ', $runIssues);
			$runRemediation = 'Review collector budget, collect interval, and worker count in Redfish settings.';
		} else {
			$runStatus      = 'ok';
			$runMessage     = sprintf('%d poller collector run(s) healthy.', count($runs));
			$runRemediation = '';
		}

		$unverified = 0;
		$ipmi       = 0;

		foreach ($bmcs()->all() as $bmc) {
			if (!$bmc->enabled()) {
				continue;
			}

			if ($bmc->tlsMode === TlsMode::Unverified) {
				$unverified++;
			}

			if ($bmc->ipmiFallback) {
				$ipmi++;
			}
		}

		$secWarn = $unverified > 0 || $ipmi > 0;

		$rackRepo   = $racks();
		$script     = new RackSummaryScript($rackRepo, $caches(), $freshness());
		$allRacks   = $rackRepo->all();
		$rackIssues = [];

		foreach ($allRacks as $rack) {
			$summary = $script->summaryFor($rack->id);

			if ($summary === null || $summary->membersTotal === 0) {
				continue;
			}

			if ($summary->powerWatts === null) {
				$rackIssues[] = sprintf('%s coverage %.1f%% below floor %.1f%%', $rack->name, $summary->coveragePct, $rack->minCoveragePct);
			} elseif ($summary->powerBudgetWatts !== null && $summary->powerWatts > $summary->powerBudgetWatts) {
				$rackIssues[] = sprintf('%s at %.1fW exceeds budget %.1fW', $rack->name, $summary->powerWatts, $summary->powerBudgetWatts);
			}
		}

		$rackWarn = $rackIssues !== [];

		return [
			[
				'id'          => 'redfish.schema',
				'plugin'      => 'redfish',
				'category'    => 'redfish',
				'title'       => 'Redfish schema version',
				'status'      => $schemaOk ? 'ok' : 'fail',
				'message'     => $schemaOk
					? sprintf('Schema is current at version %d.', Schema::VERSION)
					: sprintf('Schema version is %d; expected %d.', $currentSchema, Schema::VERSION),
				'details'     => ['stored_version' => $currentSchema, 'expected_version' => Schema::VERSION],
				'remediation' => $schemaOk ? '' : 'Re-run plugin_redfish upgrade from Cacti Plugin Management.',
			],
			[
				'id'          => 'redfish.vault_key',
				'plugin'      => 'redfish',
				'category'    => 'redfish',
				'title'       => 'Redfish vault key file',
				'status'      => $keyOk ? 'ok' : 'fail',
				'message'     => $keyOk
					? 'Vault key file is readable and passes permission checks.'
					: 'Vault key file problem: ' . $keyProblem,
				'details'     => ['readable' => $keyOk],
				'remediation' => $keyOk ? '' : 'Provision a readable 32-byte vault key file with 0600 permissions.',
			],
			[
				'id'          => 'redfish.collector_runs',
				'plugin'      => 'redfish',
				'category'    => 'redfish',
				'title'       => 'Redfish collector freshness and budget',
				'status'      => $runStatus,
				'message'     => $runMessage,
				'details'     => ['runs' => count($runs), 'issues' => count($runIssues)],
				'remediation' => $runRemediation,
			],
			[
				'id'          => 'redfish.bmc_security',
				'plugin'      => 'redfish',
				'category'    => 'redfish',
				'title'       => 'Redfish BMC TLS and protocol posture',
				'status'      => $secWarn ? 'warn' : 'ok',
				'message'     => $secWarn
					? sprintf('%d enabled BMC(s) with unverified TLS; %d with IPMI fallback enabled.', $unverified, $ipmi)
					: 'All enabled BMCs verify TLS and use Redfish only.',
				'details'     => ['unverified_tls' => $unverified, 'ipmi_fallback' => $ipmi],
				'remediation' => $secWarn ? 'Configure verified TLS (system CA or custom CA bundle) and disable IPMI fallback where possible.' : '',
			],
			[
				'id'          => 'redfish.rack_power',
				'plugin'      => 'redfish',
				'category'    => 'redfish',
				'title'       => 'Redfish rack power budget and coverage',
				'status'      => $rackWarn ? 'warn' : 'ok',
				'message'     => $rackWarn
					? implode('; ', $rackIssues)
					: sprintf('%d rack(s) within power budget and coverage floor.', count($allRacks)),
				'details'     => ['racks' => count($allRacks), 'issues' => count($rackIssues)],
				'remediation' => $rackWarn ? 'Check offline rack members or adjust rack power budgets and minimum coverage thresholds.' : '',
			],
		];
	}

	/**
	 * Build a redacted diagnostic snapshot section for doctor_register_debug_info.
	 * Emits scalar field values only and never includes passwords, vault key
	 * contents, or raw file paths.
	 *
	 * @param array<string, string> $versionInfo plugin INFO metadata
	 * @param Schema                $schema      schema helper
	 * @param CredentialStore       $credentials credential store
	 * @param BmcRepository         $bmcs        BMC repository
	 * @param HealthRepository      $health      health repository
	 * @param RackRepository        $racks       rack repository
	 * @param Settings              $settings    plugin settings
	 * @return array{
	 *     id: string,
	 *     plugin: string,
	 *     label: string,
	 *     private: bool,
	 *     fields: array<string, array{label: string, value: scalar|null}>
	 * }
	 */
	public static function debugInfo(
		array $versionInfo,
		Schema $schema,
		CredentialStore $credentials,
		BmcRepository $bmcs,
		HealthRepository $health,
		RackRepository $racks,
		Settings $settings,
	): array {
		$allBmcs     = $bmcs->all();
		$enabled     = 0;
		$ipmiEnabled = 0;
		$tlsModes    = [];

		foreach ($allBmcs as $bmc) {
			if ($bmc->enabled()) {
				$enabled++;
			}

			if ($bmc->ipmiFallback) {
				$ipmiEnabled++;
			}

			$mode            = $bmc->tlsMode->value;
			$tlsModes[$mode] = ($tlsModes[$mode] ?? 0) + 1;
		}

		ksort($tlsModes);
		$tlsParts = [];

		foreach ($tlsModes as $mode => $count) {
			$tlsParts[] = $mode . '=' . $count;
		}

		$runs = $health->runs();

		return [
			'id'      => 'redfish.overview',
			'plugin'  => 'redfish',
			'label'   => 'Redfish',
			'private' => false,
			'fields'  => [
				'plugin_version'          => ['label' => 'Plugin Version', 'value' => $versionInfo['version'] ?? 'unknown'],
				'schema_version'          => ['label' => 'Schema Version', 'value' => $schema->storedVersion()],
				'schema_version_expected' => ['label' => 'Expected Schema Version', 'value' => Schema::VERSION],
				'vault_key_status'        => ['label' => 'Vault Key Status', 'value' => $credentials->keyProblem() === null ? 'ok' : 'fail'],
				'bmcs_total'              => ['label' => 'BMCs Registered', 'value' => count($allBmcs)],
				'bmcs_enabled'            => ['label' => 'BMCs Enabled', 'value' => $enabled],
				'ipmi_fallback'           => ['label' => 'BMCs With IPMI Fallback', 'value' => $ipmiEnabled],
				'tls_modes'               => ['label' => 'TLS Modes', 'value' => $tlsParts !== [] ? implode(', ', $tlsParts) : 'none'],
				'racks_total'             => ['label' => 'Racks Configured', 'value' => count($racks->all())],
				'collector_runs'          => ['label' => 'Poller Collector Runs', 'value' => count($runs)],
				'collect_interval'        => ['label' => 'Collection Interval (s)', 'value' => $settings->collectInterval],
				'collector_budget'        => ['label' => 'Collector Budget (s)', 'value' => $settings->collectorBudget],
				'workers'                 => ['label' => 'Collector Workers', 'value' => $settings->workers],
			],
		];
	}
}
