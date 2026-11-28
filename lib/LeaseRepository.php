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
 * Per-BMC collector leases (REQ-COLL-003, REQ-RF-008). A lease is taken in
 * one statement, only when no unexpired lease is held by another worker and
 * the BMC was not started since $notSince, so two workers or two collectors
 * can neither read one BMC at once nor read it twice in one interval.
 */
final class LeaseRepository {
	/**
	 * Initialize a new LeaseRepository instance.
	 *
	 * @param \PDO|false $connection
	 * @param Clock $clock
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Acquire.
	 *
	 * @param int $bmcId
	 * @param string $holder
	 * @param int $seconds
	 * @param int $notSince
	 * @return bool
	 */
	public function acquire(int $bmcId, string $holder, int $seconds, int $notSince): bool {
		$now = $this->clock->now();

		db_execute_prepared('INSERT IGNORE INTO plugin_redfish_lease (bmc_id, holder, acquired_at, expires_at) VALUES (?, ?, 0, 0)', [$bmcId, ''], true, $this->connection);
		db_execute_prepared('UPDATE plugin_redfish_lease SET holder = ?, acquired_at = ?, expires_at = ? WHERE bmc_id = ? AND (holder = ? OR expires_at < ?) AND acquired_at <= ?', [$holder, $now, $now + $seconds, $bmcId, $holder, $now, $notSince], true, $this->connection);

		return $this->holds($bmcId, $holder);
	}

	/**
	 * Holds.
	 *
	 * @param int $bmcId
	 * @param string $holder
	 * @return bool
	 */
	public function holds(int $bmcId, string $holder): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_lease WHERE bmc_id = ? AND holder = ? AND expires_at >= ?', [$bmcId, $holder, $this->clock->now()], '', true, $this->connection) === 1;
	}

	/**
	 * When a run last took this BMC's lease, or 0. Kept after release, so it
	 * records attempts whose results were never written.
	 */
	public function lastAcquired(int $bmcId): int {
		return (int) db_fetch_cell_prepared('SELECT acquired_at FROM plugin_redfish_lease WHERE bmc_id = ?', [$bmcId], '', true, $this->connection);
	}

	/**
	 * Release.
	 *
	 * @param int $bmcId
	 * @param string $holder
	 * @return void
	 */
	public function release(int $bmcId, string $holder): void {
		db_execute_prepared('UPDATE plugin_redfish_lease SET expires_at = 0 WHERE bmc_id = ? AND holder = ?', [$bmcId, $holder], true, $this->connection);
	}
}
