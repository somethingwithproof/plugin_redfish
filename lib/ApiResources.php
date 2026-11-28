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
	 * AIP collection identifier for Redfish BMCs.
	 */
	public const COLLECTION_BMCS = 'redfishBmcs';

	/**
	 * AIP collection identifier for Redfish racks and power summaries.
	 */
	public const COLLECTION_RACKS = 'redfishRacks';

	/**
	 * AIP collection identifier for Redfish BMC health records.
	 */
	public const COLLECTION_HEALTH = 'redfishHealth';

	/**
	 * Build plain-array Google AIP resource descriptors for api_register_resources.
	 *
	 * @param \Closure(int): bool              $canView     checks Redfish View realm authorization for $userId
	 * @param \Closure(int): bool              $isAdmin     checks Redfish Admin realm authorization for $userId
	 * @param \Closure(int): DeviceScope       $scope       resolves Cacti device visibility for $userId
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
				'plugin'        => 'redfish',
				'collection'    => self::COLLECTION_BMCS,
				'singleton'     => 'redfishBmc',
				'description'   => 'Read-only Redfish BMC inventory and protocol configuration (no credentials).',
				'realm_read'    => BmcsPage::FILE,
				'filter_fields' => ['id', 'name', 'display_name', 'address', 'poller_id', 'host_id', 'tls_mode', 'admin_state'],
				'order_fields'  => ['id', 'name', 'display_name', 'poller_id'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($canView, $isAdmin, $scope, $bmcs, $credentials): array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId)) {
							return [];
						}

						$visible  = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $isAdmin($userId));
						$credRefs = [];

						foreach ($credentials()->all() as $cred) {
							$credRefs[$cred->id] = CascProvider::VAULT_PREFIX . $cred->name;
						}

						$items = [];

						foreach ($visible as $bmc) {
							$items[] = [
								'name'           => self::COLLECTION_BMCS . '/' . $bmc->id,
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

						return $items;
					},
					'get'  => static function (int|string $id, array $context = []) use ($canView, $isAdmin, $scope, $bmcs, $credentials): ?array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId) || !is_numeric($id) || (int) $id <= 0) {
							return null;
						}

						$targetId = (int) $id;
						$visible  = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $isAdmin($userId));

						foreach ($visible as $bmc) {
							if ($bmc->id !== $targetId) {
								continue;
							}

							$cred    = $bmc->credentialId > 0 ? $credentials()->find($bmc->credentialId) : null;
							$credRef = $cred !== null ? CascProvider::VAULT_PREFIX . $cred->name : '';

							return [
								'name'           => self::COLLECTION_BMCS . '/' . $bmc->id,
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
							];
						}

						return null;
					},
				],
			],
			[
				'plugin'        => 'redfish',
				'collection'    => self::COLLECTION_RACKS,
				'singleton'     => 'redfishRack',
				'description'   => 'Read-only Redfish rack power summaries, budgets, and member coverage.',
				'realm_read'    => BmcsPage::FILE,
				'filter_fields' => ['id', 'name', 'display_name', 'site_id', 'over_budget'],
				'order_fields'  => ['id', 'name', 'display_name', 'site_id'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($canView, $scope, $racks, $caches, $freshness): array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId)) {
							return [];
						}

						$rackRepo  = $racks();
						$cacheRepo = $caches();
						$freshEval = $freshness();
						$devScope  = $scope($userId);
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

							$summary = RackPowerSummary::compute($rack, $memberCaches, $freshEval)->toArray();
							$items[] = [
								'name'         => self::COLLECTION_RACKS . '/' . $rack->id,
								'id'           => $rack->id,
								'display_name' => $rack->name,
							] + $summary;
						}

						return $items;
					},
					'get'  => static function (int|string $id, array $context = []) use ($canView, $scope, $racks, $caches, $freshness): ?array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId) || !is_numeric($id) || (int) $id <= 0) {
							return null;
						}

						$rackRepo = $racks();
						$rack     = $rackRepo->find((int) $id);

						if ($rack === null) {
							return null;
						}

						$cacheRepo    = $caches();
						$devScope     = $scope($userId);
						$memberCaches = [];

						foreach ($rackRepo->members($rack->id) as $member) {
							if (!$devScope->allows($member['host_id'])) {
								continue;
							}

							$memberCaches[] = $member['bmc_id'] > 0
								? $cacheRepo->forBmc($member['bmc_id'])
								: $cacheRepo->forHost($member['host_id']);
						}

						$summary = RackPowerSummary::compute($rack, $memberCaches, $freshness())->toArray();

						return [
							'name'         => self::COLLECTION_RACKS . '/' . $rack->id,
							'id'           => $rack->id,
							'display_name' => $rack->name,
						] + $summary;
					},
				],
			],
			[
				'plugin'        => 'redfish',
				'collection'    => self::COLLECTION_HEALTH,
				'singleton'     => 'redfishHealth',
				'description'   => 'Read-only Redfish BMC reachability and collection health snapshots.',
				'realm_read'    => BmcsPage::FILE,
				'filter_fields' => ['id', 'name', 'bmc_id', 'reachability', 'failure_class'],
				'order_fields'  => ['id', 'name', 'bmc_id', 'last_success'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($canView, $isAdmin, $scope, $bmcs, $health): array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId)) {
							return [];
						}

						$visible    = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $isAdmin($userId));
						$healthRepo = $health();
						$items      = [];

						foreach ($visible as $bmc) {
							$h       = $healthRepo->find($bmc->id) ?? new BmcHealth($bmc->id);
							$items[] = [
								'name'          => self::COLLECTION_HEALTH . '/' . $bmc->id,
								'id'            => $bmc->id,
								'bmc_id'        => $bmc->id,
								'reachability'  => $h->reachability->value,
								'failure_class' => $h->failure->value ?? '',
								'last_success'  => $h->lastSuccess,
								'generation'    => $h->generation,
								'latency_ms'    => $h->latencyMs,
							];
						}

						return $items;
					},
					'get'  => static function (int|string $id, array $context = []) use ($canView, $isAdmin, $scope, $bmcs, $health): ?array {
						$userId = Row::int($context, 'user_id', 0, 0);

						if (!$canView($userId) || !is_numeric($id) || (int) $id <= 0) {
							return null;
						}

						$targetId = (int) $id;
						$visible  = HealthPage::visible($scope($userId)->filter($bmcs()->all()), $isAdmin($userId));

						foreach ($visible as $bmc) {
							if ($bmc->id !== $targetId) {
								continue;
							}

							$h = $health()->find($bmc->id) ?? new BmcHealth($bmc->id);

							return [
								'name'          => self::COLLECTION_HEALTH . '/' . $bmc->id,
								'id'            => $bmc->id,
								'bmc_id'        => $bmc->id,
								'reachability'  => $h->reachability->value,
								'failure_class' => $h->failure->value ?? '',
								'last_success'  => $h->lastSuccess,
								'generation'    => $h->generation,
								'latency_ms'    => $h->latencyMs,
							];
						}

						return null;
					},
				],
			],
		];
	}
}
