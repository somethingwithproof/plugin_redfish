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
 * Collection status of one BMC, as stored in plugin_redfish_health.
 */
final readonly class BmcHealth {
	public function __construct(
		public int $bmcId,
		public int $runStarted = 0,
		public int $runFinished = 0,
		public float $runDuration = 0.0,
		public Reachability $reachability = Reachability::Never,
		public ?FailureClass $failure = null,
		public int $lastSuccess = 0,
		public string $generation = '',
		public string $authMode = '',
		public int $requests = 0,
		public float $latencyMs = 0.0,
		public int $failures = 0,
		public int $backoffUntil = 0,
		public int $authCredentialId = 0,
		public int $authRevision = 0,
		public bool $sessionPending = false,
		public int $sessionsLost = 0,
		public int $linksRefused = 0,
	) {
	}

	/**
	 * Unknown text reads as Never and no failure class.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): self {
		return new self(
			bmcId: Row::int($row, 'bmc_id', 0, 0),
			runStarted: Row::int($row, 'run_started', 0, 0),
			runFinished: Row::int($row, 'run_finished', 0, 0),
			runDuration: Row::float($row, 'run_duration') ?? 0.0,
			reachability: Reachability::tryFrom(Row::string($row, 'reachability')) ?? Reachability::Never,
			failure: FailureClass::tryFrom(Row::string($row, 'failure_class')),
			lastSuccess: Row::int($row, 'last_success', 0, 0),
			generation: Row::string($row, 'generation'),
			authMode: Row::string($row, 'auth_mode'),
			requests: Row::int($row, 'requests', 0, 0),
			latencyMs: Row::float($row, 'latency_ms') ?? 0.0,
			failures: Row::int($row, 'failures', 0, 0),
			backoffUntil: Row::int($row, 'backoff_until', 0, 0),
			authCredentialId: Row::int($row, 'auth_credential_id', 0, 0),
			authRevision: Row::int($row, 'auth_revision', 0, 0),
			sessionPending: Row::bool($row, 'session_pending'),
			sessionsLost: Row::int($row, 'sessions_lost', 0, 0),
			linksRefused: Row::int($row, 'links_refused', 0, 0),
		);
	}

	/**
	 * @return array<string, string|int|float>
	 */
	public function toRow(): array {
		return [
			'bmc_id'             => $this->bmcId,
			'run_started'        => $this->runStarted,
			'run_finished'       => $this->runFinished,
			'run_duration'       => round($this->runDuration, 3),
			'reachability'       => $this->reachability->value,
			'failure_class'      => $this->failure->value ?? '',
			'last_success'       => $this->lastSuccess,
			'generation'         => $this->generation,
			'auth_mode'          => $this->authMode,
			'requests'           => $this->requests,
			'latency_ms'         => $this->latencyMs,
			'failures'           => $this->failures,
			'backoff_until'      => $this->backoffUntil,
			'auth_credential_id' => $this->authCredentialId,
			'auth_revision'      => $this->authRevision,
			'session_pending'    => $this->sessionPending ? 'on' : '',
			'sessions_lost'      => $this->sessionsLost,
			'links_refused'      => $this->linksRefused,
		];
	}
}
