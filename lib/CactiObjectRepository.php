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
 * Read-only lookups in Cacti's own tables: templates by hash, devices, and
 * graphs. Every change to a Cacti object goes through Cacti's API functions
 * in DeviceProvisioner; nothing here writes.
 */
final class CactiObjectRepository {
	/** Tables whose rows the plugin looks up by hash. */
	private const HASHED = ['host_template', 'data_input', 'data_template', 'graph_templates', 'snmp_query'];

	/**
	 * Initialize a new CactiObjectRepository instance.
	 *
	 * @param \PDO|false $connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Id by hash.
	 *
	 * @param string $table
	 * @param string $hash
	 * @return int
	 */
	public function idByHash(string $table, string $hash): int {
		if (!in_array($table, self::HASHED, true) || preg_match('/^[0-9a-f]{32}$/', $hash) !== 1) {
			return 0;
		}

		return (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ? ORDER BY id LIMIT 1", [$hash], '', true, $this->connection);
	}

	/**
	 * Graph template id.
	 *
	 * @param string $name
	 * @return int
	 */
	public function graphTemplateId(string $name): int {
		return $this->idByHash('graph_templates', TemplateInstaller::hash('graph_template', $name));
	}

	/**
	 * Query id.
	 *
	 * @param string $name
	 * @return int
	 */
	public function queryId(string $name): int {
		return $this->idByHash('snmp_query', TemplateInstaller::hash('data_query', $name));
	}

	/**
	 * Device template id.
	 *
	 * @return int
	 */
	public function deviceTemplateId(): int {
		return $this->idByHash('host_template', TemplateInstaller::hash('host_template', TemplateInstaller::DEVICE_TEMPLATE));
	}

	/**
	 * Default profile id.
	 *
	 * @return int
	 */
	public function defaultProfileId(): int {
		$id = (int) db_fetch_cell_prepared("SELECT id FROM data_source_profiles WHERE `default` = 'on' ORDER BY id LIMIT 1", [], '', true, $this->connection);

		return $id > 0 ? $id : 1;
	}

	/**
	 * @return array{id: int, description: string, poller_id: int, disabled: bool}|null
	 */
	public function device(int $hostId): ?array {
		$row = $hostId > 0 ? db_fetch_row_prepared("SELECT id, description, poller_id, disabled FROM host WHERE id = ? AND deleted = ''", [$hostId], true, $this->connection) : false;

		if (!is_array($row) || Row::int($row, 'id', 0, 1) === 0) {
			return null;
		}

		return ['id' => Row::int($row, 'id', 0, 1), 'description' => Row::string($row, 'description'), 'poller_id' => Row::int($row, 'poller_id', 1, 1), 'disabled' => Row::string($row, 'disabled') === 'on'];
	}

	/**
	 * Graph count.
	 *
	 * @param int $hostId
	 * @param int $graphTemplateId
	 * @return int
	 */
	public function graphCount(int $hostId, int $graphTemplateId): int {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_local WHERE host_id = ? AND graph_template_id = ?', [$hostId, $graphTemplateId], '', true, $this->connection);
	}

	/**
	 * Has query.
	 *
	 * @param int $hostId
	 * @param int $queryId
	 * @return bool
	 */
	public function hasQuery(int $hostId, int $queryId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', [$hostId, $queryId], '', true, $this->connection) > 0;
	}

	/**
	 * Indexes of a data query on a device that already have a graph from
	 * one graph template, so a repeated provision adds only missing graphs.
	 *
	 * @return list<string>
	 */
	public function graphedIndexes(int $hostId, int $queryId, int $graphTemplateId): array {
		return array_map(static fn (array $row): string => Row::string($row, 'snmp_index'), Row::rows(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM graph_local WHERE host_id = ? AND snmp_query_id = ? AND graph_template_id = ?', [$hostId, $queryId, $graphTemplateId], true, $this->connection)));
	}

	/**
	 * The data query graph id of a graph name under a query, found by hash.
	 */
	/**
	 * The query graph by hash, only while it still belongs to the shipped
	 * data query and points at its shipped graph template.
	 */
	public function queryGraphId(string $queryName, string $graphName): int {
		$template = TemplateInstaller::QUERY_GRAPHS[$queryName][$graphName] ?? '';

		return (int) db_fetch_cell_prepared('SELECT sqg.id FROM snmp_query_graph AS sqg
			INNER JOIN snmp_query AS sq ON sq.id = sqg.snmp_query_id
			INNER JOIN graph_templates AS gt ON gt.id = sqg.graph_template_id
			WHERE sqg.hash = ? AND sq.hash = ? AND gt.hash = ?', [
			TemplateInstaller::hash('data_query_graph', $queryName . ':' . $graphName),
			TemplateInstaller::hash('data_query', $queryName),
			TemplateInstaller::hash('graph_template', $template),
		], '', true, $this->connection);
	}

	/**
	 * Indexes Cacti cached for a data query on a device.
	 *
	 * @return list<string>
	 */
	public function cachedIndexes(int $hostId, int $queryId): array {
		return array_map(static fn (array $row): string => Row::string($row, 'snmp_index'), Row::rows(db_fetch_assoc_prepared('SELECT DISTINCT snmp_index FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? ORDER BY snmp_index', [$hostId, $queryId], true, $this->connection)));
	}

	/**
	 * One field Cacti cached for each index of a data query on a device.
	 *
	 * @return array<string, string> index => value
	 */
	public function cachedField(int $hostId, int $queryId, string $field): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT snmp_index, field_value FROM host_snmp_cache WHERE host_id = ? AND snmp_query_id = ? AND field_name = ?', [$hostId, $queryId, $field], true, $this->connection)) as $row) {
			$out[Row::string($row, 'snmp_index')] = Row::string($row, 'field_value');
		}

		return $out;
	}

	/**
	 * @return list<int> local graph ids of a device, oldest first
	 */
	public function graphsOf(int $hostId): array {
		return array_map(static fn (array $row): int => Row::int($row, 'id', 0, 0), Row::rows(db_fetch_assoc_prepared('SELECT id FROM graph_local WHERE host_id = ? ORDER BY id', [$hostId], true, $this->connection)));
	}

	/**
	 * Host of graph.
	 *
	 * @param int $localGraphId
	 * @return int
	 */
	public function hostOfGraph(int $localGraphId): int {
		return (int) db_fetch_cell_prepared('SELECT host_id FROM graph_local WHERE id = ?', [$localGraphId], '', true, $this->connection);
	}
}
