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
 * One collector worker on one poller (REQ-COLL-001 to REQ-COLL-007).
 *
 * It reads only enabled BMCs of its poller, least recently attempted first,
 * stops starting BMCs when its time budget is spent, and holds a lease for
 * each BMC it reads, so workers never share a BMC. Every BMC's results are
 * committed in one transaction. A failing BMC backs off exponentially from
 * its interval to BACKOFF_CAP and is reset by a success.
 *
 * Sessions follow docs/research/sessions.md: one per run, deleted in a
 * finally path; a session POST that lost its response leaves a pending
 * marker, and the next run deletes the user's stale sessions after login;
 * three lost responses in a row stop logins until an administrator acts.
 * Basic is used only when the BMC allows it and sessions are unavailable.
 */
final class Collector {
	public const DUE_SLACK      = 30;
	public const BACKOFF_CAP    = 3600;
	public const SESSION_BUDGET = 3;
	public const REDISCOVER_AFTER_FAILURES = 3;

	/** Failure classes that end the reading of targets for this run. */
	private const STOP = [
		FailureClass::Timeout, FailureClass::Throttled, FailureClass::ServiceUnavailable, FailureClass::ServerError,
		FailureClass::AuthRejected, FailureClass::RequestBudget, FailureClass::ConnectRefused, FailureClass::DnsFailed,
		FailureClass::AddressRefused, FailureClass::OperationRefused,
	];

	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly CredentialStore $credentials,
		private readonly LeaseRepository $leases,
		private readonly HealthRepository $health,
		private readonly DiscoveryRepository $discovery,
		private readonly ReadingRepository $readings,
		private readonly ClientFactory $clients,
		private readonly Settings $settings,
		private readonly Clock $clock,
		private readonly string $holder,
	) {
	}

	/**
	 * Seconds after a run's start when the BMC is due again: its interval
	 * less a slack of a tenth of it, at most DUE_SLACK, because each run
	 * starts a few seconds after its poller cycle.
	 */
	public static function dueAfter(int $interval): int {
		return $interval - min(self::DUE_SLACK, intdiv($interval, 10));
	}

	/**
	 * Seconds to wait after the $failures-th failure in a row: the interval
	 * doubled per failure, at least Retry-After, at most BACKOFF_CAP.
	 */
	public static function backoff(int $interval, int $failures, int $retryAfter): int {
		$wait = $interval * (2 ** min(20, max(0, $failures - 1)));

		return min(self::BACKOFF_CAP, max($wait, $retryAfter));
	}

	/**
	 * @return array{bmcs: int, collected: int, skipped: int, requests: int, stopped_early: bool}
	 */
	public function run(int $pollerId, int $budgetSeconds, bool $force = false): array {
		$started   = $this->clock->now();
		$collected = 0;
		$skipped   = 0;
		$requests  = 0;
		$bmcs      = $this->bmcs->enabledForPoller($pollerId);
		$lastRun   = [];

		// An attempt is recorded by the lease, which a run that overran its
		// budget may still have taken even if its results were never written.
		foreach ($bmcs as $bmc) {
			$lastRun[$bmc->id] = $this->leases->lastAcquired($bmc->id);
		}

		usort($bmcs, static fn (Bmc $a, Bmc $b): int => [$lastRun[$a->id], $a->id] <=> [$lastRun[$b->id], $b->id]);

		foreach ($bmcs as $bmc) {
			$now = $this->clock->now();

			if ($now - $started >= $budgetSeconds) {
				Log::info(sprintf('Collector budget of %d seconds spent; remaining BMCs resume next run', $budgetSeconds));

				return ['bmcs' => count($bmcs), 'collected' => $collected, 'skipped' => $skipped, 'requests' => $requests, 'stopped_early' => true];
			}

			$previous = $this->health->find($bmc->id);

			if ($previous === null || (!$force && $previous->backoffUntil > $now)) {
				$skipped++;

				continue;
			}

			$notSince = $force ? $now : $now - self::dueAfter($this->settings->collectInterval);

			if (!$this->leases->acquire($bmc->id, $this->holder, $budgetSeconds + 60, $notSince)) {
				$skipped++;

				continue;
			}

			try {
				$used = $this->collect($bmc, $previous);

				if ($used === null) {
					$skipped++;
				} else {
					$collected++;
					$requests += $used;
				}
			} finally {
				$this->leases->release($bmc->id, $this->holder);
			}
		}

		return ['bmcs' => count($bmcs), 'collected' => $collected, 'skipped' => $skipped, 'requests' => $requests, 'stopped_early' => false];
	}

	/**
	 * Read one BMC under a lease this worker holds. Returns the requests
	 * sent, or null when the BMC was not attempted.
	 */
	public function collect(Bmc $bmc, BmcHealth $previous): ?int {
		$started  = $this->clock->now();
		$t0       = microtime(true);
		$redactor = new Redactor();

		try {
			$resolved = $this->credentials->resolve($bmc->credentialId);
		} catch (CredentialError $e) {
			// No fallback to another credential or a default (REQ-CRED-005).
			Log::warning(sprintf('BMC %d: credential unavailable: %s', $bmc->id, $e->getMessage()));
			$this->finish($bmc, $previous, $started, $t0, FailureClass::CredentialUnavailable, 0, null, null, false, false, null, []);

			return 0;
		}

		$credential = $resolved['credential'];
		$password   = $resolved['password'];
		$redactor->add($password);

		// A rejected credential is not tried again until it changes, so a
		// shared BMC account is not locked out by retries.
		if ($previous->reachability === Reachability::AuthFailed && $previous->authCredentialId === $bmc->credentialId && $previous->authRevision === $credential->revision) {
			return null;
		}

		if ($previous->sessionsLost >= self::SESSION_BUDGET) {
			$this->finish($bmc, $previous, $started, $t0, FailureClass::SessionBudget, 0, null, null, false, false, null, ['sessions_lost' => $previous->sessionsLost]);

			return 0;
		}

		$endpoint   = Endpoint::forBmc($bmc);
		$client     = $this->clients->client($bmc, $redactor, $this->clock);
		$discoverer = new Discoverer($client, $endpoint);
		$stored     = $this->discovery->find($bmc->id);
		$failure    = null;
		$retryAfter = 0;
		$newPlan    = null;
		$plan       = null;
		$obs        = null;
		$complete   = false;
		$markStale  = false;
		$rejected   = false;
		$oldPending = $previous->sessionPending;
		$ownPending = false;
		$lost       = $previous->sessionsLost;
		$authMode   = '';

		try {
			$root     = $client->serviceRoot();
			$sessions = $discoverer->sessionsPath($root);

			if ($sessions !== '') {
				$client->guard()->allowSessions($sessions);

				if (!$this->health->markSessionPending($bmc->id, $this->holder, $this->clock->now())) {
					// The lease is gone; another worker owns the BMC now.
					return null;
				}

				$ownPending = true;

				try {
					$client->login($credential->username, $password);
					$lost = 0;
				} catch (ClientError $e) {
					$ownPending = !self::definitive($e);

					if ($ownPending) {
						$lost++;

						throw new ClientError(FailureClass::SessionLost);
					}

					if ($e->class !== FailureClass::SessionsUnavailable || !$bmc->basicFallback) {
						$rejected = $e->class === FailureClass::AuthRejected;

						throw $e;
					}

					$client->useBasic($credential->username, $password);
				}
			} elseif ($bmc->basicFallback) {
				$client->useBasic($credential->username, $password);
			} else {
				throw new ClientError(FailureClass::SessionsUnavailable);
			}

			$authMode = $client->authMode();

			// A previous run may have left a session open; close those of
			// this user now that the run can authenticate.
			if ($oldPending && $sessions !== '') {
				$all = true;

				foreach ($client->staleSessions($credential->username) as $path) {
					$all = $client->deleteSession($path) && $all;
				}

				$oldPending = !$all;
			}

			$plan = $stored['plan'];

			if ($plan === null || $stored['stale'] || $plan->fingerprint !== ServiceRoot::fingerprint($root)
				|| $started - $stored['discovered_at'] >= $this->settings->discoverInterval || $previous->failures >= self::REDISCOVER_AFTER_FAILURES) {
				$plan = $newPlan = $discoverer->discover($root);
			}

			[$obs, $rediscover, $complete, $stop] = $this->readTargets($client, $plan);

			// A target that vanished or changed type: rediscover at once,
			// once per run, and read again.
			if ($rediscover && $newPlan === null && $stop === null) {
				$plan = $newPlan = $discoverer->discover($root);
				[$obs, $rediscover, $complete, $stop] = $this->readTargets($client, $plan);
			}

			$markStale = $rediscover;
			$obs->merge(Normalizer::node($obs, $plan->primaryChassis));

			if ($stop !== null) {
				throw $stop;
			}
		} catch (ClientError $e) {
			$failure    = $e->class;
			$retryAfter = $e->retryAfter;
			$rejected   = $rejected || ($e->class === FailureClass::AuthRejected && $client->authMode() === 'basic');
		} catch (TransportError $e) {
			$failure = $e->class;
		} finally {
			// Every session is deleted, on error paths too (REQ-RF-004).
			if ($client->authMode() === 'session') {
				$ownPending = !$client->logout();
			}
		}

		$this->finish($bmc, $previous, $started, $t0, $failure, $retryAfter, $newPlan, $obs, $complete && $failure === null, $markStale, $client, [
			'auth_mode'     => $authMode,
			'pending'       => $oldPending || $ownPending,
			'sessions_lost' => $lost,
			'rejected'      => $rejected ? $credential->revision : -1,
			'generation'    => $plan?->generation() ?? $previous->generation,
			'links'         => $newPlan === null ? $previous->linksRefused : $newPlan->linksRefused,
		]);

		if ($failure !== null) {
			Log::warning($redactor->clean(sprintf('BMC %d (%s): collection failed: %s', $bmc->id, $bmc->name, $failure->value)));
		}

		return $client->requests();
	}

	/**
	 * Whether a failed session POST is known not to have created a session:
	 * the BMC answered with a status, or the request never left.
	 */
	private static function definitive(ClientError $e): bool {
		return $e->class !== FailureClass::SessionLost && ($e->status > 0 || !$e->sent);
	}

	/**
	 * @return array{0: Observation, 1: bool, 2: bool, 3: ?ClientError} readings, rediscover, complete, stopping error
	 */
	private function readTargets(RedfishClient $client, DiscoveryPlan $plan): array {
		$obs        = new Observation();
		$rediscover = false;
		$complete   = true;

		foreach ($plan->targets as $target) {
			try {
				$resource = $client->get($target->path);
			} catch (ClientError $e) {
				$complete = false;

				if (in_array($e->class, self::STOP, true)) {
					return [$obs, $rediscover, false, $e];
				}

				// Gone, unreadable, or redirected away: the plan is out of date.
				$rediscover = true;

				continue;
			}

			if (($target->id !== '' && ($resource['Id'] ?? null) !== $target->id) || ($target->type !== '' && ServiceRoot::type($resource) !== $target->type)) {
				$complete   = false;
				$rediscover = true;

				continue;
			}

			$obs->merge(Normalizer::normalize($target, $resource, $plan->family));
		}

		return [$obs, $rediscover, $complete, null];
	}

	/**
	 * @param array{auth_mode?: string, pending?: bool, sessions_lost?: int, rejected?: int, generation?: string, links?: int} $state
	 */
	private function finish(Bmc $bmc, BmcHealth $previous, int $started, float $t0, ?FailureClass $failure, int $retryAfter, ?DiscoveryPlan $plan, ?Observation $obs, bool $complete, bool $markStale, ?RedfishClient $client, array $state): void {
		$now      = $this->clock->now();
		$failures = $failure === null ? 0 : $previous->failures + 1;
		$rejected = $state['rejected'] ?? -1;

		// A failure that needs an operator is held by its own rule, not by
		// backoff, so fixing it takes effect on the next run.
		$backoff = $failure === null || $failure->needsOperator() ? 0 : $now + self::backoff($this->settings->collectInterval, $failures, $retryAfter);

		$health = new BmcHealth(
			bmcId: $bmc->id,
			runStarted: $started,
			runFinished: $now,
			runDuration: microtime(true) - $t0,
			reachability: $failure === null ? Reachability::Reachable : $failure->reachability(),
			failure: $failure,
			lastSuccess: $failure === null ? $now : $previous->lastSuccess,
			generation: $state['generation'] ?? $previous->generation,
			authMode: $state['auth_mode'] ?? '',
			requests: $client?->requests() ?? 0,
			latencyMs: $client?->meanLatencyMs() ?? 0.0,
			failures: $failures,
			backoffUntil: $backoff,
			authCredentialId: $rejected >= 0 ? $bmc->credentialId : 0,
			authRevision: $rejected >= 0 ? $rejected : 0,
			sessionPending: $state['pending'] ?? $previous->sessionPending,
			sessionsLost: $state['sessions_lost'] ?? $previous->sessionsLost,
			linksRefused: $state['links'] ?? $previous->linksRefused,
		);

		if (!$this->readings->commit($bmc->id, $this->holder, $now, $health, $plan, $obs, $complete, $markStale)) {
			Log::warning(sprintf('BMC %d: results were not written because the lease was lost or the database refused them', $bmc->id));
		}
	}
}
