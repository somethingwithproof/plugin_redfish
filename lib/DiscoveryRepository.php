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
 * Stored discovery results. Written only by ReadingRepository::commit(),
 * inside the BMC's result transaction.
 */
final class DiscoveryRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return array{plan: ?DiscoveryPlan, stale: bool, discovered_at: int, status: string}
	 */
	public function find(int $bmcId): array {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_redfish_discovery WHERE bmc_id = ?', [$bmcId], true, $this->connection);

		if (!is_array($row) || $row === []) {
			return ['plan' => null, 'stale' => false, 'discovered_at' => 0, 'status' => 'never'];
		}

		return [
			'plan'          => DiscoveryPlan::fromRow($row),
			'stale'         => Row::bool($row, 'stale'),
			'discovered_at' => Row::int($row, 'discovered_at', 0, 0),
			'status'        => Row::string($row, 'status'),
		];
	}
}
