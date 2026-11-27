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
 * Remote data collector replication and read-only remote_agent handler
 * (REQ-REM-001 to REQ-REM-014).
 */
final class Replication {
	public const AGENT_ACTION_HEALTH    = 'redfish_health';
	public const AGENT_ACTION_DISCOVERY = 'redfish_discovery';

	public function __construct(
		private readonly ReplicationRepository $repository,
		private readonly BmcRepository $bmcs,
		private readonly HealthRepository $health,
		private readonly DiscoveryRepository $discovery,
	) {
	}

	/**
	 * Build a non-secret replication payload for a remote poller (replicate_out).
	 * Vault keys stay strictly local on each poller and are never included.
	 *
	 * @return array{
	 *     poller_id: int,
	 *     tables: list<string>,
	 *     vault_keys_local: bool,
	 *     bmcs: list<array<string, mixed>>,
	 *     credentials: list<array<string, mixed>>,
	 *     racks: list<array<string, mixed>>,
	 *     rack_members: list<array<string, mixed>>
	 * }
	 */
	public function exportForPoller(int $pollerId): array {
		$bmcs          = $this->repository->bmcRows($pollerId);
		$credentialIds = [];

		foreach ($bmcs as $row) {
			$cid = Row::int($row, 'credential_id', 0, 0);

			if ($cid > 0) {
				$credentialIds[] = $cid;
			}
		}

		return [
			'poller_id'        => $pollerId,
			'tables'           => ReplicationRepository::CONFIG_TABLES,
			'vault_keys_local' => true,
			'bmcs'             => $bmcs,
			'credentials'      => $this->repository->credentialMetadataRows($credentialIds),
			'racks'            => $this->repository->rackRows(),
			'rack_members'     => $this->repository->rackMemberRows(),
		];
	}

	/**
	 * Handle a read-only remote_agent action (REQ-REM-010 to REQ-REM-014).
	 *
	 * Accepts only numeric identifiers (poller_id, bmc_id). Refuses any input
	 * carrying addresses, paths, or credentials. Returns zero secrets.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	public function handleRemoteAgent(array $request): array {
		$action = Row::string($request, 'action');

		if ($action !== self::AGENT_ACTION_HEALTH && $action !== self::AGENT_ACTION_DISCOVERY) {
			return ['ok' => false, 'error' => 'unsupported_action'];
		}

		// REQ-REM-011: reject any unexpected parameter carrying addresses, paths, or credentials.
		foreach (array_keys($request) as $key) {
			if (!in_array($key, ['action', 'poller_id', 'bmc_id'], true)) {
				return ['ok' => false, 'error' => 'parameter_refused'];
			}
		}

		$pollerId = Row::int($request, 'poller_id', 0, 0);
		$bmcId    = Row::int($request, 'bmc_id', 0, 0);

		if ($action === self::AGENT_ACTION_HEALTH) {
			return $this->agentHealth($pollerId, $bmcId);
		}

		return $this->agentDiscovery($pollerId, $bmcId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function agentHealth(int $pollerId, int $bmcId): array {
		$bmcs = $this->filterBmcs($pollerId, $bmcId);
		$out  = [];

		foreach ($bmcs as $bmc) {
			$h = $this->health->find($bmc->id) ?? new BmcHealth($bmc->id);

			$out[] = [
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
				'failures'      => $h->failures,
			];
		}

		return [
			'ok'     => true,
			'action' => self::AGENT_ACTION_HEALTH,
			'runs'   => $this->health->runs(),
			'bmcs'   => $out,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function agentDiscovery(int $pollerId, int $bmcId): array {
		$bmcs = $this->filterBmcs($pollerId, $bmcId);
		$out  = [];

		foreach ($bmcs as $bmc) {
			$found = $this->discovery->find($bmc->id);
			$plan  = $found['plan'];

			$out[] = [
				'bmc_id'          => $bmc->id,
				'name'            => $bmc->name,
				'poller_id'       => $bmc->pollerId,
				'discovered_at'   => $found['discovered_at'],
				'stale'           => $found['stale'],
				'vendor'          => $plan->vendor ?? '',
				'product'         => $plan->product ?? '',
				'redfish_version' => $plan->redfishVersion ?? '',
				'generation'      => $plan !== null ? $plan->generation() : '',
			];
		}

		return [
			'ok'     => true,
			'action' => self::AGENT_ACTION_DISCOVERY,
			'bmcs'   => $out,
		];
	}

	/**
	 * @return list<Bmc>
	 */
	private function filterBmcs(int $pollerId, int $bmcId): array {
		if ($bmcId > 0) {
			$bmc = $this->bmcs->find($bmcId);

			if ($bmc === null || ($pollerId > 0 && $bmc->pollerId !== $pollerId)) {
				return [];
			}

			return [$bmc];
		}

		$all = $this->bmcs->all();

		if ($pollerId <= 0) {
			return $all;
		}

		return array_values(array_filter($all, static fn (Bmc $b): bool => $b->pollerId === $pollerId));
	}
}
