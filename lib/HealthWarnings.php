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
 * The warnings the health page shows (REQ-HEALTH-002). Pure: computed from
 * stored state only, so a fixture state renders the same warning every time.
 * Warnings marked admin concern credentials and are shown only to viewers
 * with Redfish Admin.
 */
final class HealthWarnings {
	/**
	 * @param list<Bmc>             $bmcs
	 * @param array<int, BmcHealth> $health   by BMC id
	 * @param array<int, bool>      $hasCredential by BMC id: the referenced credential exists with a password
	 * @param list<array{poller_id: int, run_started: int, run_duration: float, bmcs: int, collected: int, skipped: int, requests: int, workers: int, stopped_early: bool}> $runs
	 * @return list<array{message: string, admin: bool}>
	 */
	public static function for(array $bmcs, array $health, array $hasCredential, ?string $keyProblem, array $runs, Settings $settings, int $now): array {
		$warnings = [];

		if ($keyProblem !== null) {
			$warnings[] = ['message' => __('The vault key file cannot be used: %s. Stored passwords cannot be read or written until it is fixed.', $keyProblem, 'redfish'), 'admin' => true];
		}

		foreach ($runs as $run) {
			if ($run['run_duration'] > $settings->collectorBudget) {
				$warnings[] = ['message' => __('The collector on poller %d took %.0f seconds, more than its budget of %d seconds.', $run['poller_id'], $run['run_duration'], $settings->collectorBudget, 'redfish'), 'admin' => false];
			}

			if ($run['stopped_early']) {
				$warnings[] = ['message' => __('The collector on poller %d ran out of time before every BMC was read; the rest resume on the next run. Add workers or raise the budget.', $run['poller_id'], 'redfish'), 'admin' => false];
			}

			if ($run['run_started'] > 0 && $now - $run['run_started'] > 3 * $settings->collectInterval) {
				$warnings[] = ['message' => __('The collector on poller %d last ran %s.', $run['poller_id'], Html::age($now - $run['run_started']), 'redfish'), 'admin' => false];
			}
		}

		foreach ($bmcs as $bmc) {
			if (!$bmc->enabled()) {
				continue;
			}

			$h = $health[$bmc->id] ?? new BmcHealth($bmc->id);

			if ($bmc->tlsMode === TlsMode::Unverified) {
				$warnings[] = ['message' => __('%s: TLS verification is off. Anyone on the path can read its traffic, including the BMC password.', $bmc->name, 'redfish'), 'admin' => false];
			}

			if ($h->authMode === 'basic') {
				$warnings[] = ['message' => __('%s: uses Basic authentication because the BMC offers no session service; every request carries the password.', $bmc->name, 'redfish'), 'admin' => false];
			}

			if ($h->sessionPending) {
				$warnings[] = ['message' => __('%s: a session may have been left open on the BMC; the next run deletes it after logging in.', $bmc->name, 'redfish'), 'admin' => false];
			}

			if ($h->failure === FailureClass::SessionBudget || $h->sessionsLost >= Collector::SESSION_BUDGET) {
				$warnings[] = ['message' => __('%s: %d session logins in a row lost their response, so logins stopped. Check the BMC session list, then use Retry Now.', $bmc->name, $h->sessionsLost, 'redfish'), 'admin' => false];
			}

			if ($h->reachability === Reachability::AuthFailed) {
				$warnings[] = ['message' => __('%s: the BMC rejected the credential. It is not tried again until the credential changes.', $bmc->name, 'redfish'), 'admin' => true];
			}

			if (!($hasCredential[$bmc->id] ?? false)) {
				$warnings[] = ['message' => __('%s: its credential is missing or has no stored password.', $bmc->name, 'redfish'), 'admin' => true];
			}

			if ($h->linksRefused > 0) {
				$warnings[] = ['message' => __('%s: %d links in its responses pointed elsewhere and were ignored.', $bmc->name, $h->linksRefused, 'redfish'), 'admin' => false];
			}

			if ($bmc->ipmiFallback) {
				$warnings[] = ['message' => sprintf('%s: IPMI fallback is enabled; IPMI 2.0 over UDP 623 exposes password hashes to reachable clients. Restrict UDP 623 to a management VLAN.', $bmc->name), 'admin' => false];
			}
		}

		return $warnings;
	}
}
