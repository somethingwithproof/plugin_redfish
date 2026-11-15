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
 * Attaches a BMC's readings to a Cacti device (ADR 0015, REQ-DEV-001 to
 * REQ-DEV-006): the server's existing device when the operator mapped one,
 * otherwise a new device from the "Redfish BMC" device template, created
 * only after the administrator confirmed the plan.
 *
 * Everything goes through Cacti's own functions: api_device_save() for the
 * device, api_device_dq_add() for the data queries (which runs the first
 * index), create_complete_graph_from_template() for graphs, and
 * api_device_remove() to roll back. The plugin writes only its own mapping.
 * An existing device keeps every setting; only the data queries and graphs
 * are added, and a repeated run adds only what is missing.
 */
final class DeviceProvisioner {
	/** Fan graph by the unit Cacti cached for the index. */
	private const FAN_GRAPHS = ['%' => 'Fan Speed Percent', 'RPM' => 'Fan Speed RPM'];

	/**
	 * @param \Closure(): void         $loadCacti loads the Cacti libraries that
	 *                                            device and graph creation use, so
	 *                                            no caller has to load them first
	 * @param \Closure(int, int): bool $visible   whether a user may see a device
	 */
	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly CactiObjectRepository $objects,
		private readonly TemplateInstaller $templates,
		private readonly \Closure $loadCacti,
		private readonly \Closure $visible,
	) {
	}

	/**
	 * Why the plugin cannot provision now, or null when it can: every
	 * shipped template, data query, and query graph, and the query files.
	 */
	public function refusal(): ?string {
		if (!$this->templates->present() || $this->templates->queryFileProblems() !== []) {
			return __('The Redfish templates are not installed completely. Reinstall them from the Redfish BMCs page.', 'redfish');
		}

		return null;
	}

	/**
	 * What provisioning would add, for the confirmation page (REQ-DEV-002).
	 *
	 * @param array<string, int> $members member kind => count in the cache
	 *
	 * @return list<string>
	 */
	public function plan(Bmc $bmc, array $members): array {
		$lines = [];

		if ($bmc->hostId === 0) {
			$lines[] = __('A device "%s" from the %s device template, polled by poller %d, with availability checks off. Its host name is a reserved name that is never contacted.', $bmc->name, TemplateInstaller::DEVICE_TEMPLATE, $bmc->pollerId, 'redfish');
		}

		foreach (TemplateInstaller::QUERIES as $name => [$kind]) {
			if ($bmc->hostId === 0 || !$this->objects->hasQuery($bmc->hostId, $this->objects->queryId($name))) {
				$lines[] = __('The data query "%s", with a graph for each of the %d members known now.', $name, $members[$kind] ?? 0, 'redfish');
			}
		}

		if (($members['missing'] ?? 0) > 0) {
			$lines[] = __('%d fans or power supplies that were present are no longer reported. They count as failed until you confirm; confirming accepts the members the BMC reports now.', $members['missing'], 'redfish');
		}

		foreach (TemplateInstaller::SUMMARY_GRAPHS as $name) {
			if ($bmc->hostId === 0 || $this->objects->graphCount($bmc->hostId, $this->objects->graphTemplateId($name)) === 0) {
				$lines[] = __('The graph "%s", with its "%s" data source.', $name, TemplateInstaller::SUMMARY, 'redfish');
			}
		}

		return $lines;
	}

	/**
	 * Create a device for an unmapped BMC and attach it. Returns the new
	 * device id, or 0 with the reason in $error. A device the creating user
	 * cannot see is removed again, so nobody is left holding a device that
	 * the permissions they were given would hide.
	 */
	public function create(Bmc $bmc, int $userId, ?string &$error = null): int {
		$templateId = $this->objects->deviceTemplateId();
		$refusal    = $this->refusal();

		if ($refusal !== null || $templateId === 0 || $bmc->hostId > 0) {
			$error = $refusal ?? __('The BMC is already mapped to a device.', 'redfish');

			return 0;
		}

		($this->loadCacti)();

		$hostId = (int) api_device_save(0, $templateId, $bmc->name, self::hostname($bmc), '', 0, '', '', 161, 500, '', AVAIL_NONE, 0, 23, 400, 1,
			__('Created by the Redfish plugin for BMC %s. Its values come from the plugin cache; the host name is a reserved name that is never contacted.', $bmc->name, 'redfish'),
			'MD5', '', 'DES', '', '', 5, 1, $bmc->pollerId, 0);

		if ($hostId <= 0) {
			Log::error(sprintf('Cacti refused the device for BMC %d', $bmc->id));
			$error = __('Cacti refused to create the device. See the Cacti log.', 'redfish');

			return 0;
		}

		if (!($this->visible)($hostId, $userId)) {
			api_device_remove($hostId);
			Log::warning(sprintf('Device %d for BMC %d was removed again: user %d may not see it', $hostId, $bmc->id, $userId));
			$error = __('The new device would be hidden from you by your device permissions, so it was removed again. Ask an administrator to grant access, or map the BMC to a device you can see.', 'redfish');

			return 0;
		}

		if (!$this->bmcs->mapHost($bmc->id, $hostId, $userId)) {
			api_device_remove($hostId);
			Log::warning(sprintf('Device %d was created for BMC %d, which was mapped elsewhere meanwhile or could not be mapped, so the device was removed again', $hostId, $bmc->id));
			$error = __('The BMC was mapped to a device by someone else meanwhile, or could not be mapped, so the new device was removed again. Reload the page and check its mapping.', 'redfish');

			return 0;
		}

		$this->attach($bmc->id, $hostId, $userId);

		return $hostId;
	}

	/**
	 * Add the data queries, the summary graphs, and a graph for each cached
	 * index that has none. Safe to repeat: a query already on the device is
	 * indexed again, and existing graphs are kept. This is also the reindex
	 * action: indexes that disappeared keep their graphs and read U
	 * (REQ-DQ-003), and new ones get graphs.
	 *
	 * @return int graphs created
	 */
	public function attach(int $bmcId, int $hostId, int $userId): int {
		($this->loadCacti)();

		$created = 0;

		// Adding graphs accepts the members the BMC reports now.
		if (!$this->bmcs->acceptMembers($bmcId, $userId)) {
			Log::error(sprintf('The members of BMC %d could not be accepted', $bmcId));
		}

		foreach (TemplateInstaller::QUERIES as $name => [$kind]) {
			$queryId = $this->objects->queryId($name);

			if ($queryId === 0) {
				continue;
			}

			if ($this->objects->hasQuery($hostId, $queryId)) {
				run_data_query($hostId, $queryId);
			} else {
				api_device_dq_add($hostId, $queryId, DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE);
			}

			$created += $this->queryGraphs($bmcId, $hostId, $name, $kind, $queryId);
		}

		foreach (TemplateInstaller::SUMMARY_GRAPHS as $name) {
			$templateId = $this->objects->graphTemplateId($name);

			if ($templateId === 0 || $this->objects->graphCount($hostId, $templateId) > 0) {
				continue;
			}

			$none      = [];
			$suggested = [];
			$graph     = create_complete_graph_from_template($templateId, $hostId, $none, $suggested);

			if (is_array($graph) && is_numeric($graph['local_graph_id'] ?? null)) {
				$created++;
			} else {
				Log::error(sprintf('Cacti did not create the %s graph for BMC %d', $name, $bmcId));
			}
		}

		// Fills the summary data source's host_id input and the poller cache.
		push_out_host($hostId, 0);

		return $created;
	}

	private function queryGraphs(int $bmcId, int $hostId, string $queryName, string $kind, int $queryId): int {
		$created = 0;
		$units   = $kind === 'fan' ? $this->objects->cachedField($hostId, $queryId, 'redfishUnit') : [];

		foreach ($this->objects->cachedIndexes($hostId, $queryId) as $index) {
			$graphName = $kind === 'fan' ? (self::FAN_GRAPHS[$units[$index] ?? ''] ?? null) : array_key_first(TemplateInstaller::QUERY_GRAPHS[$queryName]);

			// A fan whose unit is not known yet gets its graph on a later
			// reindex, once the unit is, so it never lands on the wrong axis.
			if ($graphName === null) {
				continue;
			}

			$templateId = $this->objects->graphTemplateId(TemplateInstaller::QUERY_GRAPHS[$queryName][$graphName] ?? '');
			$sqgId      = $this->objects->queryGraphId($queryName, $graphName);

			if ($templateId === 0 || $sqgId === 0 || in_array($index, $this->objects->graphedIndexes($hostId, $queryId, $templateId), true)) {
				continue;
			}

			$suggested = [];
			$graph     = create_complete_graph_from_template($templateId, $hostId, ['snmp_query_id' => $queryId, 'snmp_index_on' => 'redfishIndex', 'snmp_query_graph_id' => $sqgId, 'snmp_index' => $index], $suggested);

			if (is_array($graph) && is_numeric($graph['local_graph_id'] ?? null)) {
				$created++;
			} else {
				Log::error(sprintf('Cacti did not create the %s graph of index %s for BMC %d', $graphName, $index, $bmcId));
			}
		}

		return $created;
	}

	/**
	 * A name in the reserved .invalid domain (RFC 6761), so no lookup or
	 * availability check of the device reaches the BMC or anything else, and
	 * the device page never shows the BMC's address (REQ-PERM-002).
	 */
	public static function hostname(Bmc $bmc): string {
		return 'bmc-' . $bmc->id . '.redfish.invalid';
	}
}
