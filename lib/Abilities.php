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
 * Read-only MCP / Abilities API definitions for the abilities_register hook.
 *
 * Every registered ability is strictly read-only, gated on the Redfish View
 * realm (redfish_health.php) and Cacti device permissions, and returns zero
 * credentials, passwords, vault keys, or session tokens.
 */
final class Abilities {
	/**
	 * List bmcs constant.
	 */
	public const LIST_BMCS      = 'redfish-list-bmcs';
	/**
	 * Get rack power constant.
	 */
	public const GET_RACK_POWER = 'redfish-get-rack-power';
	/**
	 * Get health constant.
	 */
	public const GET_HEALTH     = 'redfish-get-health';

	/**
	 * @param \Closure(): bool               $canView   checks Redfish View realm authorization
	 * @param \Closure(): bool               $isAdmin   checks Redfish Admin realm authorization
	 * @param \Closure(): DeviceScope        $scope
	 * @param \Closure(): BmcRepository      $bmcs
	 * @param \Closure(): CredentialRepository $credentials
	 * @param \Closure(): RackRepository     $racks
	 * @param \Closure(): HealthRepository   $health
	 * @param \Closure(): NodeCacheRepository $caches
	 * @param \Closure(): FreshnessEvaluator $freshness
	 * @param \Closure(): MaintWindow        $maint
	 * @param \Closure(): Settings           $settings
	 * @param \Closure(): Clock              $clock
	 * @return list<array<string, mixed>>
	 */
	public static function definitions(
		\Closure $canView,
		\Closure $isAdmin,
		\Closure $scope,
		\Closure $bmcs,
		\Closure $credentials,
		\Closure $racks,
		\Closure $health,
		\Closure $caches,
		\Closure $freshness,
		\Closure $maint,
		\Closure $settings,
		\Closure $clock,
	): array {
		return [
			[
				'name'        => self::LIST_BMCS,
				'plugin'      => 'redfish',
				'description' => 'List visible Redfish BMCs with protocol and TLS configuration (read-only, no credentials).',
				'read_only'   => true,
				'realm'       => HealthPage::FILE,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'poller_id' => ['type' => 'integer', 'minimum' => 1],
					],
				],
				'callback' => static function (array $input = []) use ($canView, $isAdmin, $scope, $bmcs, $credentials): array {
					if (!$canView()) {
						return ['ok' => false, 'error' => 'forbidden'];
					}

					$admin    = $isAdmin();
					$pollerId = Row::int($input, 'poller_id', 0, 0);
					$visible  = HealthPage::visible($scope()->filter($bmcs()->all()), $admin);
					$credRefs = [];

					foreach ($credentials()->all() as $c) {
						$credRefs[$c->id] = CascProvider::VAULT_PREFIX . $c->name;
					}

					$items = [];

					foreach ($visible as $bmc) {
						if ($pollerId > 0 && $bmc->pollerId !== $pollerId) {
							continue;
						}

						$items[] = [
							'id'             => $bmc->id,
							'name'           => $bmc->name,
							'address'        => $bmc->address,
							'port'           => $bmc->port,
							'poller_id'      => $bmc->pollerId,
							'host_id'        => $bmc->hostId,
							'protocols'      => $bmc->ipmiFallback ? ['redfish', 'ipmi'] : ['redfish'],
							'tls_mode'       => $bmc->tlsMode->value,
							'admin_state'    => $bmc->adminState->value,
							'credential_ref' => $credRefs[$bmc->credentialId] ?? '',
						];
					}

					return ['ok' => true, 'bmcs' => $items];
				},
			],
			[
				'name'        => self::GET_RACK_POWER,
				'plugin'      => 'redfish',
				'description' => 'Get aggregate rack power watts, configured budget, headroom, and member coverage (read-only).',
				'read_only'   => true,
				'realm'       => HealthPage::FILE,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'rack_id' => ['type' => 'integer', 'minimum' => 1],
					],
				],
				'callback' => static function (array $input = []) use ($canView, $scope, $racks, $caches, $freshness): array {
					if (!$canView()) {
						return ['ok' => false, 'error' => 'forbidden'];
					}

					$rackId    = Row::int($input, 'rack_id', 0, 0);
					$rackRepo  = $racks();
					$cacheRepo = $caches();
					$freshEval = $freshness();
					$devScope  = $scope();
					$targets   = $rackId > 0 ? array_values(array_filter([$rackRepo->find($rackId)])) : $rackRepo->all();
					$summaries = [];

					foreach ($targets as $rack) {
						$memberCaches = [];

						foreach ($rackRepo->members($rack->id) as $member) {
							if (!$devScope->allows($member['host_id'])) {
								continue;
							}

							$memberCaches[] = $member['bmc_id'] > 0
								? $cacheRepo->forBmc($member['bmc_id'])
								: $cacheRepo->forHost($member['host_id']);
						}

						$summaries[] = RackPowerSummary::compute($rack, $memberCaches, $freshEval)->toArray();
					}

					return ['ok' => true, 'racks' => $summaries];
				},
			],
			[
				'name'        => self::GET_HEALTH,
				'plugin'      => 'redfish',
				'description' => 'Get Redfish BMC reachability, collector runs, warnings, and maintenance status (read-only, no credentials).',
				'read_only'   => true,
				'realm'       => HealthPage::FILE,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'bmc_id' => ['type' => 'integer', 'minimum' => 1],
					],
				],
				'callback' => static function (array $input = []) use ($canView, $isAdmin, $scope, $bmcs, $health, $maint, $settings, $clock): array {
					if (!$canView()) {
						return ['ok' => false, 'error' => 'forbidden'];
					}

					$admin      = $isAdmin();
					$bmcId      = Row::int($input, 'bmc_id', 0, 0);
					$visible    = HealthPage::visible($scope()->filter($bmcs()->all()), $admin);
					$healthRepo = $health();
					$maintWin   = $maint();
					$healthMap  = [];
					$hasCred    = [];
					$items      = [];

					foreach ($visible as $bmc) {
						if ($bmcId > 0 && $bmc->id !== $bmcId) {
							continue;
						}

						$h                  = $healthRepo->find($bmc->id) ?? new BmcHealth($bmc->id);
						$healthMap[$bmc->id] = $h;
						$hasCred[$bmc->id]   = $bmc->credentialId > 0;
						$activeMaint         = $maintWin->activeForHost($bmc->hostId);

						$items[] = [
							'bmc_id'        => $bmc->id,
							'name'          => $bmc->name,
							'poller_id'     => $bmc->pollerId,
							'host_id'       => $bmc->hostId,
							'admin_state'   => $bmc->adminState->value,
							'reachability'  => $h->reachability->value,
							'failure_class' => $h->failure->value ?? '',
							'last_success'  => $h->lastSuccess,
							'generation'    => $h->generation,
							'auth_mode'     => $h->authMode,
							'requests'      => $h->requests,
							'latency_ms'    => $h->latencyMs,
							'in_maint'      => $activeMaint !== null,
							'maint_name'    => $activeMaint['name'] ?? '',
						];
					}

					$runs     = $healthRepo->runs();
					$warnings = [];

					foreach (HealthWarnings::for($visible, $healthMap, $hasCred, null, $runs, $settings(), $clock()->now()) as $w) {
						if (!$w['admin'] || $admin) {
							$warnings[] = $w['message'];
						}
					}

					return [
						'ok'       => true,
						'runs'     => $runs,
						'bmcs'     => $items,
						'warnings' => $warnings,
					];
				},
			],
		];
	}
}
