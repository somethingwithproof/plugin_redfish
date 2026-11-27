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
 * Diagnostic checks for the doctor_register_checks hook.
 *
 * All checks read local state only, never contact a BMC, and never return
 * credential or vault key material.
 */
final class DoctorChecks {
	/**
	 * @param \Closure(): Schema             $schema
	 * @param \Closure(): CredentialStore    $credentials
	 * @param \Closure(): BmcRepository      $bmcs
	 * @param \Closure(): HealthRepository   $health
	 * @param \Closure(): RackRepository     $racks
	 * @param \Closure(): NodeCacheRepository $caches
	 * @param \Closure(): FreshnessEvaluator $freshness
	 * @param \Closure(): Settings           $settings
	 * @param \Closure(): Clock              $clock
	 * @return list<array<string, mixed>>
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
		return [
			[
				'id'       => 'redfish.schema',
				'plugin'   => 'redfish',
				'title'    => 'Redfish schema version',
				'callback' => static function () use ($schema): array {
					$s       = $schema();
					$current = $s->storedVersion();

					if ($current === Schema::VERSION) {
						return ['status' => 'ok', 'message' => sprintf('Schema is current at version %d.', Schema::VERSION)];
					}

					return ['status' => 'error', 'message' => sprintf('Schema version is %d; expected %d.', $current, Schema::VERSION)];
				},
			],
			[
				'id'       => 'redfish.vault_key',
				'plugin'   => 'redfish',
				'title'    => 'Redfish vault key file',
				'callback' => static function () use ($credentials): array {
					$problem = $credentials()->keyProblem();

					if ($problem === null) {
						return ['status' => 'ok', 'message' => 'Vault key file is readable and passes permission checks.'];
					}

					return ['status' => 'error', 'message' => 'Vault key file problem: ' . $problem];
				},
			],
			[
				'id'       => 'redfish.collector_runs',
				'plugin'   => 'redfish',
				'title'    => 'Redfish collector freshness and budget',
				'callback' => static function () use ($health, $settings, $clock): array {
					$runs = $health()->runs();
					$cfg  = $settings();
					$now  = $clock()->now();

					if ($runs === []) {
						return ['status' => 'warning', 'message' => 'No collector run recorded yet.'];
					}

					$issues = [];

					foreach ($runs as $run) {
						if ($run['stopped_early'] || $run['run_duration'] > $cfg->collectorBudget) {
							$issues[] = sprintf('poller %d exceeded budget (%0.1fs / %ds)', $run['poller_id'], $run['run_duration'], $cfg->collectorBudget);
						}

						if ($run['run_started'] > 0 && ($now - $run['run_started']) > 3 * $cfg->collectInterval) {
							$issues[] = sprintf('poller %d last ran %ds ago', $run['poller_id'], $now - $run['run_started']);
						}
					}

					if ($issues !== []) {
						return ['status' => 'warning', 'message' => implode('; ', $issues)];
					}

					return ['status' => 'ok', 'message' => sprintf('%d poller collector run(s) healthy.', count($runs))];
				},
			],
			[
				'id'       => 'redfish.bmc_security',
				'plugin'   => 'redfish',
				'title'    => 'Redfish BMC TLS and protocol posture',
				'callback' => static function () use ($bmcs): array {
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

					if ($unverified > 0 || $ipmi > 0) {
						return [
							'status'  => 'warning',
							'message' => sprintf('%d enabled BMC(s) with unverified TLS; %d with IPMI fallback enabled.', $unverified, $ipmi),
						];
					}

					return ['status' => 'ok', 'message' => 'All enabled BMCs verify TLS and use Redfish only.'];
				},
			],
			[
				'id'       => 'redfish.rack_power',
				'plugin'   => 'redfish',
				'title'    => 'Redfish rack power budget and coverage',
				'callback' => static function () use ($racks, $caches, $freshness): array {
					$script   = new RackSummaryScript($racks(), $caches(), $freshness());
					$allRacks = $racks()->all();
					$issues   = [];

					foreach ($allRacks as $rack) {
						$summary = $script->summaryFor($rack->id);

						if ($summary === null || $summary->membersTotal === 0) {
							continue;
						}

						if ($summary->powerWatts === null) {
							$issues[] = sprintf('%s coverage %.1f%% below floor %.1f%%', $rack->name, $summary->coveragePct, $rack->minCoveragePct);
						} elseif ($summary->powerBudgetWatts !== null && $summary->powerWatts > $summary->powerBudgetWatts) {
							$issues[] = sprintf('%s at %.1fW exceeds budget %.1fW', $rack->name, $summary->powerWatts, $summary->powerBudgetWatts);
						}
					}

					if ($issues !== []) {
						return ['status' => 'warning', 'message' => implode('; ', $issues)];
					}

					return ['status' => 'ok', 'message' => sprintf('%d rack(s) within power budget and coverage floor.', count($allRacks))];
				},
			],
		];
	}

	/**
	 * Build a redacted diagnostic snapshot for the doctor_register_debug_info
	 * hook. Emits plain associative arrays only and never includes passwords,
	 * vault key contents, or raw file paths.
	 *
	 * @param array<string, string> $versionInfo plugin INFO metadata
	 * @param Schema                $schema      schema helper
	 * @param CredentialStore       $credentials credential store
	 * @param BmcRepository         $bmcs        BMC repository
	 * @param HealthRepository      $health      health repository
	 * @param RackRepository        $racks       rack repository
	 * @param Settings              $settings    plugin settings
	 * @return array<string, mixed>
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

		$runs = $health->runs();

		return [
			'label'   => 'Redfish',
			'private' => false,
			'fields'  => [
				'plugin_version'   => ['label' => 'Plugin Version', 'value' => $versionInfo['version'] ?? 'unknown'],
				'schema_version'   => ['label' => 'Schema Version', 'value' => $schema->storedVersion(), 'expected' => Schema::VERSION],
				'vault_key_status' => ['label' => 'Vault Key Status', 'value' => $credentials->keyProblem() === null ? 'ok' : 'error'],
				'bmcs_total'       => ['label' => 'BMCs Registered', 'value' => count($allBmcs)],
				'bmcs_enabled'     => ['label' => 'BMCs Enabled', 'value' => $enabled],
				'ipmi_fallback'    => ['label' => 'BMCs With IPMI Fallback', 'value' => $ipmiEnabled],
				'tls_modes'        => ['label' => 'TLS Modes', 'value' => $tlsModes],
				'racks_total'      => ['label' => 'Racks Configured', 'value' => count($racks->all())],
				'collector_runs'   => ['label' => 'Poller Collector Runs', 'value' => count($runs)],
				'collect_interval' => ['label' => 'Collection Interval (s)', 'value' => $settings->collectInterval],
				'collector_budget' => ['label' => 'Collector Budget (s)', 'value' => $settings->collectorBudget],
				'workers'          => ['label' => 'Collector Workers', 'value' => $settings->workers],
			],
		];
	}
}
