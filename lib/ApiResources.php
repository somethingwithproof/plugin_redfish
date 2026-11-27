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
 * Decoupled Google AIP-style resource descriptors for api_register_resources.
 *
 * Emits plain associative arrays only, with zero PHP imports or runtime
 * dependencies on external API plugins. Remains 100% standalone when no API
 * host plugin is installed. Never exposes secrets, passwords, or key material.
 */
final class ApiResources {
	/**
	 * AIP resource type identifier for Redfish BMCs.
	 */
	public const TYPE_BMC = 'redfish.cacti.net/Bmc';

	/**
	 * AIP resource type identifier for Redfish racks and power summaries.
	 */
	public const TYPE_RACK = 'redfish.cacti.net/Rack';

	/**
	 * AIP resource type identifier for Redfish BMC health records.
	 */
	public const TYPE_HEALTH = 'redfish.cacti.net/Health';

	/**
	 * Build plain-array Google AIP resource descriptors for api_register_resources.
	 *
	 * @param \Closure(): bool                 $canView     checks Redfish View realm authorization
	 * @param \Closure(): bool                 $isAdmin     checks Redfish Admin realm authorization
	 * @param \Closure(): DeviceScope          $scope       resolves Cacti device visibility
	 * @param \Closure(): BmcRepository        $bmcs        BMC repository provider
	 * @param \Closure(): CredentialRepository $credentials credential repository provider
	 * @param \Closure(): RackRepository       $racks       rack repository provider
	 * @param \Closure(): HealthRepository     $health      health repository provider
	 * @param \Closure(): NodeCacheRepository  $caches      node cache repository provider
	 * @param \Closure(): FreshnessEvaluator   $freshness   freshness evaluator provider
	 * @return list<array<string, mixed>>
	 */
	public static function descriptors(
		\Closure $canView,
		\Closure $isAdmin,
		\Closure $scope,
		\Closure $bmcs,
		\Closure $credentials,
		\Closure $racks,
		\Closure $health,
		\Closure $caches,
		\Closure $freshness,
	): array {
		return [
			[
				'plugin'     => 'redfish',
				'type'       => self::TYPE_BMC,
				'singular'   => 'bmc',
				'plural'     => 'bmcs',
				'pattern'    => 'bmcs/{bmc}',
				'version'    => 'v1',
				'read_only'  => true,
				'methods'    => ['get', 'list'],
				'realm'      => HealthPage::FILE,
				'fields'     => [
					'name'           => 'string',
					'id'             => 'integer',
					'display_name'   => 'string',
					'address'        => 'string',
					'port'           => 'integer',
					'poller_id'      => 'integer',
					'host_id'        => 'integer',
					'protocols'      => 'array',
					'tls_mode'       => 'string',
					'admin_state'    => 'string',
					'credential_ref' => 'string',
				],
				'handlers'   => [
					'list' => static function (array $params = []) use ($canView, $isAdmin, $scope, $bmcs, $credentials): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED', 'bmcs' => []];
						}

						$visible  = HealthPage::visible($scope()->filter($bmcs()->all()), $isAdmin());
						$credRefs = [];

						foreach ($credentials()->all() as $cred) {
							$credRefs[$cred->id] = CascProvider::VAULT_PREFIX . $cred->name;
						}

						$items = [];

						foreach ($visible as $bmc) {
							$items[] = [
								'name'           => 'bmcs/' . $bmc->id,
								'id'             => $bmc->id,
								'display_name'   => $bmc->name,
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

						return ['code' => 200, 'bmcs' => $items];
					},
					'get'  => static function (int $id) use ($canView, $isAdmin, $scope, $bmcs, $credentials): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED'];
						}

						$visible = HealthPage::visible($scope()->filter($bmcs()->all()), $isAdmin());
						$credRef = '';

						foreach ($visible as $bmc) {
							if ($bmc->id !== $id) {
								continue;
							}

							$cred    = $bmc->credentialId > 0 ? $credentials()->find($bmc->credentialId) : null;
							$credRef = $cred !== null ? CascProvider::VAULT_PREFIX . $cred->name : '';

							return [
								'code'     => 200,
								'resource' => [
									'name'           => 'bmcs/' . $bmc->id,
									'id'             => $bmc->id,
									'display_name'   => $bmc->name,
									'address'        => $bmc->address,
									'port'           => $bmc->port,
									'poller_id'      => $bmc->pollerId,
									'host_id'        => $bmc->hostId,
									'protocols'      => $bmc->ipmiFallback ? ['redfish', 'ipmi'] : ['redfish'],
									'tls_mode'       => $bmc->tlsMode->value,
									'admin_state'    => $bmc->adminState->value,
									'credential_ref' => $credRef,
								],
							];
						}

						return ['code' => 404, 'error' => 'NOT_FOUND'];
					},
				],
			],
			[
				'plugin'     => 'redfish',
				'type'       => self::TYPE_RACK,
				'singular'   => 'rack',
				'plural'     => 'racks',
				'pattern'    => 'racks/{rack}',
				'version'    => 'v1',
				'read_only'  => true,
				'methods'    => ['get', 'list'],
				'realm'      => HealthPage::FILE,
				'fields'     => [
					'name'               => 'string',
					'rack_id'            => 'integer',
					'site_id'            => 'integer',
					'power_watts'        => 'number',
					'power_budget_watts' => 'number',
					'utilization_pct'    => 'number',
					'headroom_watts'     => 'number',
					'coverage_pct'       => 'number',
					'over_budget'        => 'boolean',
				],
				'handlers'   => [
					'list' => static function (array $params = []) use ($canView, $scope, $racks, $caches, $freshness): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED', 'racks' => []];
						}

						$rackRepo  = $racks();
						$cacheRepo = $caches();
						$freshEval = $freshness();
						$devScope  = $scope();
						$items     = [];

						foreach ($rackRepo->all() as $rack) {
							$memberCaches = [];

							foreach ($rackRepo->members($rack->id) as $member) {
								if (!$devScope->allows($member['host_id'])) {
									continue;
								}

								$memberCaches[] = $member['bmc_id'] > 0
									? $cacheRepo->forBmc($member['bmc_id'])
									: $cacheRepo->forHost($member['host_id']);
							}

							$items[] = ['resource_name' => 'racks/' . $rack->id] + RackPowerSummary::compute($rack, $memberCaches, $freshEval)->toArray();
						}

						return ['code' => 200, 'racks' => $items];
					},
					'get'  => static function (int $id) use ($canView, $scope, $racks, $caches, $freshness): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED'];
						}

						$rackRepo = $racks();
						$rack     = $rackRepo->find($id);

						if ($rack === null) {
							return ['code' => 404, 'error' => 'NOT_FOUND'];
						}

						$cacheRepo    = $caches();
						$devScope     = $scope();
						$memberCaches = [];

						foreach ($rackRepo->members($rack->id) as $member) {
							if (!$devScope->allows($member['host_id'])) {
								continue;
							}

							$memberCaches[] = $member['bmc_id'] > 0
								? $cacheRepo->forBmc($member['bmc_id'])
								: $cacheRepo->forHost($member['host_id']);
						}

						return [
							'code'     => 200,
							'resource' => ['resource_name' => 'racks/' . $rack->id] + RackPowerSummary::compute($rack, $memberCaches, $freshness())->toArray(),
						];
					},
				],
			],
			[
				'plugin'     => 'redfish',
				'type'       => self::TYPE_HEALTH,
				'singular'   => 'health',
				'plural'     => 'health',
				'pattern'    => 'bmcs/{bmc}/health',
				'version'    => 'v1',
				'read_only'  => true,
				'methods'    => ['get', 'list'],
				'realm'      => HealthPage::FILE,
				'fields'     => [
					'name'          => 'string',
					'bmc_id'        => 'integer',
					'reachability'  => 'string',
					'failure_class' => 'string',
					'last_success'  => 'integer',
					'generation'    => 'string',
					'latency_ms'    => 'number',
				],
				'handlers'   => [
					'list' => static function (array $params = []) use ($canView, $isAdmin, $scope, $bmcs, $health): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED', 'health' => []];
						}

						$visible    = HealthPage::visible($scope()->filter($bmcs()->all()), $isAdmin());
						$healthRepo = $health();
						$items      = [];

						foreach ($visible as $bmc) {
							$h       = $healthRepo->find($bmc->id) ?? new BmcHealth($bmc->id);
							$items[] = [
								'name'          => 'bmcs/' . $bmc->id . '/health',
								'bmc_id'        => $bmc->id,
								'reachability'  => $h->reachability->value,
								'failure_class' => $h->failure->value ?? '',
								'last_success'  => $h->lastSuccess,
								'generation'    => $h->generation,
								'latency_ms'    => $h->latencyMs,
							];
						}

						return ['code' => 200, 'health' => $items];
					},
					'get'  => static function (int $id) use ($canView, $isAdmin, $scope, $bmcs, $health): array {
						if (!$canView()) {
							return ['code' => 403, 'error' => 'PERMISSION_DENIED'];
						}

						$visible = HealthPage::visible($scope()->filter($bmcs()->all()), $isAdmin());

						foreach ($visible as $bmc) {
							if ($bmc->id !== $id) {
								continue;
							}

							$h = $health()->find($bmc->id) ?? new BmcHealth($bmc->id);

							return [
								'code'     => 200,
								'resource' => [
									'name'          => 'bmcs/' . $bmc->id . '/health',
									'bmc_id'        => $bmc->id,
									'reachability'  => $h->reachability->value,
									'failure_class' => $h->failure->value ?? '',
									'last_success'  => $h->lastSuccess,
									'generation'    => $h->generation,
									'latency_ms'    => $h->latencyMs,
								],
							];
						}

						return ['code' => 404, 'error' => 'NOT_FOUND'];
					},
				],
			],
		];
	}
}
