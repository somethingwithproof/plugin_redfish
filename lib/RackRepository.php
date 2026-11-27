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
 * Rack registry and member assignments (REQ-RACK-001 to REQ-RACK-005).
 *
 * A device belongs to at most one rack (enforced by host_id primary key on
 * plugin_redfish_rack_member). Rack names are unique within a site.
 */
final class RackRepository {
	/**
	 * Initialize a new RackRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly \PDO|false $connection,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Find.
	 *
	 * @param int $id
	 * @return Rack|null
	 */
	public function find(int $id): ?Rack {
		if ($id <= 0) {
			return null;
		}

		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_rack WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) && $row !== [] ? Rack::fromRow($row) : null;
	}

	/**
	 * Find by name.
	 *
	 * @param int $siteId
	 * @param string $name
	 * @return Rack|null
	 */
	public function findByName(int $siteId, string $name): ?Rack {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_rack WHERE site_id = ? AND name = ?', [$siteId, trim($name)], true, $this->connection);

		return is_array($row) && $row !== [] ? Rack::fromRow($row) : null;
	}

	/**
	 * @return list<Rack>
	 */
	public function all(): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_redfish_rack ORDER BY site_id, name', [], true, $this->connection)) as $row) {
			$rack = Rack::fromRow($row);

			if ($rack !== null) {
				$out[] = $rack;
			}
		}

		return $out;
	}

	/**
	 * Save.
	 *
	 * @param Rack $rack
	 * @param int $userId
	 * @return int
	 */
	public function save(Rack $rack, int $userId): int {
		$now = $this->clock->now();

		if ($rack->id <= 0) {
			$ok = db_execute_prepared(
				'INSERT INTO plugin_redfish_rack (site_id, name, power_budget_watts, min_coverage_pct, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
				[$rack->siteId, $rack->name, $rack->powerBudgetWatts, $rack->minCoveragePct, $rack->notes, $now, $now],
				true,
				$this->connection
			);

			$id = $ok ? (int) db_fetch_insert_id($this->connection) : 0;

			if ($id > 0) {
				$this->audit($id, $userId, 'create', ['site_id', 'name', 'power_budget_watts', 'min_coverage_pct']);
			}

			return $id;
		}

		$ok = db_execute_prepared(
			'UPDATE plugin_redfish_rack SET site_id = ?, name = ?, power_budget_watts = ?, min_coverage_pct = ?, notes = ?, updated_at = ? WHERE id = ?',
			[$rack->siteId, $rack->name, $rack->powerBudgetWatts, $rack->minCoveragePct, $rack->notes, $now, $rack->id],
			true,
			$this->connection
		);

		if ($ok) {
			$this->audit($rack->id, $userId, 'update', ['site_id', 'name', 'power_budget_watts', 'min_coverage_pct', 'notes']);
		}

		return $ok ? $rack->id : 0;
	}

	/**
	 * Delete.
	 *
	 * @param int $id
	 * @param int $userId
	 * @return bool
	 */
	public function delete(int $id, int $userId): bool {
		if ($this->find($id) === null || !db_begin_transaction($this->connection)) {
			return false;
		}

		$ok = db_execute_prepared('DELETE FROM plugin_redfish_rack_member WHERE rack_id = ?', [$id], true, $this->connection)
			&& db_execute_prepared('DELETE FROM plugin_redfish_rack WHERE id = ?', [$id], true, $this->connection);

		if (!$ok || !db_commit_transaction($this->connection)) {
			db_rollback_transaction($this->connection);

			return false;
		}

		$this->audit($id, $userId, 'delete', []);

		return true;
	}

	/**
	 * Members assigned to one rack.
	 *
	 * @return list<array{host_id: int, rack_id: int, bmc_id: int, unit_position: int}>
	 */
	public function members(int $rackId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT host_id, rack_id, bmc_id, unit_position FROM plugin_redfish_rack_member WHERE rack_id = ? ORDER BY unit_position, host_id', [$rackId], true, $this->connection)) as $row) {
			$out[] = [
				'host_id'       => Row::int($row, 'host_id', 0, 0),
				'rack_id'       => Row::int($row, 'rack_id', 0, 0),
				'bmc_id'        => Row::int($row, 'bmc_id', 0, 0),
				'unit_position' => Row::int($row, 'unit_position', 0, 0),
			];
		}

		return $out;
	}

	/**
	 * Assign a device to a rack. Because host_id is primary key, a device
	 * belongs to at most one rack (REQ-RACK-002).
	 */
	public function assignHost(int $rackId, int $hostId, int $bmcId, int $unitPosition, int $userId): bool {
		if ($rackId <= 0 || $hostId <= 0) {
			return false;
		}

		$ok = (bool) db_execute_prepared(
			'REPLACE INTO plugin_redfish_rack_member (host_id, rack_id, bmc_id, unit_position, added_at) VALUES (?, ?, ?, ?, ?)',
			[$hostId, $rackId, max(0, $bmcId), max(0, $unitPosition), $this->clock->now()],
			true,
			$this->connection
		);

		if ($ok) {
			$this->audit($rackId, $userId, 'assign', ['host_id']);
		}

		return $ok;
	}

	/**
	 * Remove host.
	 *
	 * @param int $hostId
	 * @param int $userId
	 * @return bool
	 */
	public function removeHost(int $hostId, int $userId): bool {
		if ($hostId <= 0) {
			return false;
		}

		$rackId = (int) db_fetch_cell_prepared('SELECT rack_id FROM plugin_redfish_rack_member WHERE host_id = ?', [$hostId], '', true, $this->connection);
		$ok     = (bool) db_execute_prepared('DELETE FROM plugin_redfish_rack_member WHERE host_id = ?', [$hostId], true, $this->connection);

		if ($ok && $rackId > 0) {
			$this->audit($rackId, $userId, 'unassign', ['host_id']);
		}

		return $ok;
	}

	/**
	 * Remove any rack assignments for deleted Cacti devices.
	 *
	 * @param list<int> $hostIds
	 */
	public function removeHosts(array $hostIds): int {
		$hostIds = array_values(array_filter($hostIds, static fn (int $id): bool => $id > 0));

		if ($hostIds === []) {
			return 0;
		}

		$in = implode(', ', array_fill(0, count($hostIds), '?'));
		$ok = db_execute_prepared("DELETE FROM plugin_redfish_rack_member WHERE host_id IN ($in)", $hostIds, true, $this->connection);

		return $ok ? max(0, (int) db_affected_rows($this->connection)) : 0;
	}

	/**
	 * Preview rack seeding candidates from existing Cacti host.location values
	 * without mutating any assignment (REQ-RACK-003).
	 *
	 * @return list<array{site_id: int, location: string, host_ids: list<int>}>
	 */
	public function seedCandidates(): array {
		$grouped = [];

		foreach (Row::rows(db_fetch_assoc_prepared("SELECT id, site_id, location FROM host WHERE location != '' ORDER BY site_id, location, id", [], true, $this->connection)) as $row) {
			$siteId   = Row::int($row, 'site_id', 0, 0);
			$location = trim(Row::string($row, 'location'));
			$hostId   = Row::int($row, 'id', 0, 1);

			if ($location === '' || $hostId === 0) {
				continue;
			}

			$key = $siteId . "\0" . $location;

			if (!isset($grouped[$key])) {
				$grouped[$key] = ['site_id' => $siteId, 'location' => $location, 'host_ids' => []];
			}

			$grouped[$key]['host_ids'][] = $hostId;
		}

		return array_values($grouped);
	}

	/**
	 * @param list<string> $fields
	 */
	private function audit(int $id, int $userId, string $action, array $fields): void {
		db_execute_prepared(
			'INSERT INTO plugin_redfish_audit (object, object_id, user_id, changed_at, action, fields) VALUES (?, ?, ?, ?, ?, ?)',
			['rack', $id, $userId, $this->clock->now(), $action, implode(',', $fields)],
			true,
			$this->connection
		);
	}
}
