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
 * realm (or higher) via DB lookup for the explicit user ID and Cacti device
 * permissions, and returns zero credentials, passwords, vault keys, or tokens.
 */
final class Abilities {
	/**
	 * Ability category slug for Redfish abilities.
	 */
	public const CATEGORY = 'redfish';

	/**
	 * Ability category display label.
	 */
	public const CATEGORY_LABEL = 'Redfish';

	/**
	 * List BMCs ability identifier.
	 */
	public const LIST_BMCS = 'redfish-list-bmcs';

	/**
	 * Get rack power ability identifier.
	 */
	public const GET_RACK_POWER = 'redfish-get-rack-power';

	/**
	 * Get health ability identifier.
	 */
	public const GET_HEALTH = 'redfish-get-health';

	/**
	 * Required MCP read-only annotations.
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Build canonical MCP ability definitions keyed by ability ID.
	 *
	 * @param \Closure(int): bool              $canView     checks Redfish View realm authorization for $userId
	 * @param \Closure(int): bool              $isAdmin     checks Redfish Admin realm authorization for $userId
	 * @param \Closure(int): DeviceScope       $scope       resolves Cacti device visibility for $userId
	 * @param \Closure(): BmcRepository        $bmcs
	 * @param \Closure(): CredentialRepository $credentials
	 * @param \Closure(): RackRepository       $racks
	 * @param \Closure(): HealthRepository     $health
	 * @param \Closure(): NodeCacheRepository  $caches
	 * @param \Closure(): FreshnessEvaluator   $freshness
	 * @param \Closure(): MaintWindow          $maint
	 * @param \Closure(): Settings             $settings
	 * @param \Closure(): Clock                $clock
	 * @return array<string, array<string, mixed>>
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
		$permission = static fn (int $userId): bool => $canView($userId);

		return [
			self::LIST_BMCS => [
				'label'        => 'List Redfish BMCs',
				'description'  => 'List visible Redfish BMCs with protocol and TLS configuration (read-only, no credentials).',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'poller_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'ok'   => ['type' => 'boolean'],
						'bmcs' => [
							'type'     => 'array',
							'maxItems' => 500,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'id'             => ['type' => 'integer'],
									'name'           => ['type' => 'string'],
									'address'        => ['type' => 'string'],
									'port'           => ['type' => 'integer'],
									'poller_id'      => ['type' => 'integer'],
									'host_id'        => ['type' => 'integer'],
									'protocols'      => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
									'tls_mode'       => ['type' => 'string'],
									'admin_state'    => ['type' => 'string'],
									'credential_ref' => ['type' => 'string'],
								],
								'required'             => ['id', 'name', 'address', 'port', 'poller_id', 'host_id', 'protocols', 'tls_mode', 'admin_state', 'credential_ref'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['ok', 'bmcs'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => static function (int $userId, \stdClass|array $args = []) use ($canView, $isAdmin, $scope, $bmcs, $credentials): array {
					if (!$canView($userId)) {
						return ['ok' => false, 'bmcs' => []];
					}

					$input    = is_array($args) ? $args : (array) $args;
					$admin    = $isAdmin($userId);
					$pollerId = Row::int($input, 'poller_id', 0, 0);
					$visible  = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $admin);
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

					return ['ok' => true, 'bmcs' => array_slice($items, 0, 500)];
				},
			],
			self::GET_RACK_POWER => [
				'label'        => 'Get Redfish Rack Power',
				'description'  => 'Get aggregate rack power watts, configured budget, headroom, and member coverage (read-only).',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'rack_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'ok'    => ['type' => 'boolean'],
						'racks' => [
							'type'     => 'array',
							'maxItems' => 500,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'rack_id'            => ['type' => 'integer'],
									'site_id'            => ['type' => 'integer'],
									'name'               => ['type' => 'string'],
									'power_watts'        => ['type' => ['number', 'null']],
									'power_budget_watts' => ['type' => ['number', 'null']],
									'utilization_pct'    => ['type' => ['number', 'null']],
									'headroom_watts'     => ['type' => ['number', 'null']],
									'members_total'      => ['type' => 'integer'],
									'members_reporting'  => ['type' => 'integer'],
									'coverage_pct'       => ['type' => 'number'],
									'min_coverage_pct'   => ['type' => 'number'],
									'over_budget'        => ['type' => 'boolean'],
								],
								'required'             => ['rack_id', 'site_id', 'name', 'power_watts', 'power_budget_watts', 'utilization_pct', 'headroom_watts', 'members_total', 'members_reporting', 'coverage_pct', 'min_coverage_pct', 'over_budget'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['ok', 'racks'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => static function (int $userId, \stdClass|array $args = []) use ($canView, $scope, $racks, $caches, $freshness): array {
					if (!$canView($userId)) {
						return ['ok' => false, 'racks' => []];
					}

					$input     = is_array($args) ? $args : (array) $args;
					$rackId    = Row::int($input, 'rack_id', 0, 0);
					$rackRepo  = $racks();
					$cacheRepo = $caches();
					$freshEval = $freshness();
					$devScope  = $scope($userId);
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

					return ['ok' => true, 'racks' => array_slice($summaries, 0, 500)];
				},
			],
			self::GET_HEALTH => [
				'label'        => 'Get Redfish Health',
				'description'  => 'Get Redfish BMC reachability, collector runs, warnings, and maintenance status (read-only, no credentials).',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'bmc_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'ok'       => ['type' => 'boolean'],
						'runs'     => [
							'type'     => 'array',
							'maxItems' => 100,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'poller_id'     => ['type' => 'integer'],
									'run_started'   => ['type' => 'integer'],
									'run_duration'  => ['type' => 'number'],
									'bmcs'          => ['type' => 'integer'],
									'collected'     => ['type' => 'integer'],
									'skipped'       => ['type' => 'integer'],
									'requests'      => ['type' => 'integer'],
									'workers'       => ['type' => 'integer'],
									'stopped_early' => ['type' => 'boolean'],
								],
								'required'             => ['poller_id', 'run_started', 'run_duration', 'bmcs', 'collected', 'skipped', 'requests', 'workers', 'stopped_early'],
								'additionalProperties' => false,
							],
						],
						'bmcs'     => [
							'type'     => 'array',
							'maxItems' => 500,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'bmc_id'        => ['type' => 'integer'],
									'name'          => ['type' => 'string'],
									'poller_id'     => ['type' => 'integer'],
									'host_id'       => ['type' => 'integer'],
									'admin_state'   => ['type' => 'string'],
									'reachability'  => ['type' => 'string'],
									'failure_class' => ['type' => 'string'],
									'last_success'  => ['type' => 'integer'],
									'generation'    => ['type' => 'string'],
									'auth_mode'     => ['type' => 'string'],
									'requests'      => ['type' => 'integer'],
									'latency_ms'    => ['type' => 'number'],
									'in_maint'      => ['type' => 'boolean'],
									'maint_name'    => ['type' => 'string'],
								],
								'required'             => ['bmc_id', 'name', 'poller_id', 'host_id', 'admin_state', 'reachability', 'failure_class', 'last_success', 'generation', 'auth_mode', 'requests', 'latency_ms', 'in_maint', 'maint_name'],
								'additionalProperties' => false,
							],
						],
						'warnings' => [
							'type'     => 'array',
							'maxItems' => 200,
							'items'    => ['type' => 'string'],
						],
					],
					'required'             => ['ok', 'runs', 'bmcs', 'warnings'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => static function (int $userId, \stdClass|array $args = []) use ($canView, $isAdmin, $scope, $bmcs, $health, $maint, $settings, $clock): array {
					if (!$canView($userId)) {
						return ['ok' => false, 'runs' => [], 'bmcs' => [], 'warnings' => []];
					}

					$input      = is_array($args) ? $args : (array) $args;
					$admin      = $isAdmin($userId);
					$bmcId      = Row::int($input, 'bmc_id', 0, 0);
					$visible    = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $admin);
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
						'runs'     => array_slice($runs, 0, 100),
						'bmcs'     => array_slice($items, 0, 500),
						'warnings' => array_slice($warnings, 0, 200),
					];
				},
			],
		];
	}

	/**
	 * Register Redfish ability category and definitions onto an AbilitiesRegistrar object.
	 *
	 * @param object                              $registrar host registrar providing register() and optional registerCategory()
	 * @param array<string, array<string, mixed>> $defs      ability definitions keyed by ID
	 * @return void
	 */
	public static function registerOn(object $registrar, array $defs): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			try {
				$registrar->registerCategory(self::CATEGORY, self::CATEGORY_LABEL);
			} catch (\Throwable) {
				// Category already registered in the host registry.
			}
		}

		foreach ($defs as $id => $def) {
			$registrar->register($id, $def);
		}
	}
}
