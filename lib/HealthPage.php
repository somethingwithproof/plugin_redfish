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
 * Redfish Health (Redfish View). Shows what the collectors last recorded and
 * never contacts a BMC (REQ-UI-004). Failure detail is a failure class, never
 * a response body, and no secret is read here at all (REQ-HEALTH-005).
 */
final class HealthPage {
	public const FILE = 'redfish_health.php';

	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly HealthRepository $health,
		private readonly ReadingRepository $readings,
		private readonly DiscoveryRepository $discovery,
		private readonly CredentialStore $credentials,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
		private readonly DeviceScope $scope,
		private readonly ?MaintWindow $maint = null,
	) {
	}

	/**
	 * @param bool                                        $admin whether the viewer has Redfish Admin, which alone
	 *                                                           sees credential and template warnings
	 * @param list<array{admin: bool, message: string}> $extra warnings about Cacti objects, from DeviceWarnings
	 */
	public function render(Settings $settings, bool $admin, array $extra = []): void {
		$now    = $this->clock->now();
		$bmcs   = self::visible($this->scope->filter($this->bmcs->all()), $admin);
		$health = [];
		$has    = [];

		foreach ($bmcs as $bmc) {
			$health[$bmc->id] = $this->health->find($bmc->id) ?? new BmcHealth($bmc->id);
			$has[$bmc->id]    = $bmc->credentialId > 0 && $this->credentials->hasPassword($bmc->credentialId);
		}

		$runs = $this->health->runs();

		html_start_box(__('Redfish Warnings', 'redfish'), '100%', '', '3', 'center', '');

		$shown = 0;

		foreach (array_merge(HealthWarnings::for($bmcs, $health, $has, $admin ? $this->credentials->keyProblem() : null, $runs, $settings, $now), $extra) as $warning) {
			if ($warning['admin'] && !$admin) {
				continue;
			}

			print '<tr><td class="textError">' . html_escape($warning['message']) . '</td></tr>';
			$shown++;
		}

		if ($shown === 0) {
			print '<tr><td>' . html_escape(__('No warnings.', 'redfish')) . '</td></tr>';
		}

		html_end_box();

		html_start_box(__('Redfish Collector', 'redfish'), '100%', '', '3', 'center', '');
		print Html::header([__('Poller', 'redfish'), __('Last Run', 'redfish'), __('Duration / Interval', 'redfish'), __('BMCs', 'redfish'), __('Read', 'redfish'), __('Requests per BMC', 'redfish'), __('Workers', 'redfish')]);

		if ($runs === []) {
			print '<tr><td colspan="7"><em>' . html_escape(__('The collector has not run yet.', 'redfish')) . '</em></td></tr>';
		}

		foreach ($runs as $run) {
			print Html::row([
				(string) $run['poller_id'],
				$run['run_started'] > 0 ? Html::age($now - $run['run_started']) : __('Never', 'redfish'),
				sprintf('%.1f s / %d s', $run['run_duration'], $settings->collectInterval),
				(string) $run['bmcs'],
				(string) $run['collected'],
				$run['collected'] > 0 ? sprintf('%.1f', $run['requests'] / $run['collected']) : '-',
				(string) $run['workers'],
			]);
		}

		html_end_box();

		html_start_box(__('Redfish BMC Status', 'redfish'), '100%', '', '3', 'center', '');
		print Html::header([__('BMC', 'redfish'), __('Reachability', 'redfish'), __('Failure', 'redfish'), __('Last Success', 'redfish'), __('Source', 'redfish'), __('Authentication', 'redfish'), __('Requests', 'redfish'), __('Mean Latency', 'redfish'), __('Discovery', 'redfish'), __('Data', 'redfish')]);

		if ($bmcs === []) {
			print '<tr><td colspan="10"><em>' . html_escape(__('No BMCs yet.', 'redfish')) . '</em></td></tr>';
		}

		foreach ($bmcs as $bmc) {
			print Html::row($this->row($bmc, $health[$bmc->id], $now));
		}

		html_end_box();
	}

	/**
	 * A BMC with no Cacti device has no device permission to follow, so only
	 * Redfish administrators see it (REQ-DEV-005).
	 *
	 * @param list<Bmc> $bmcs
	 *
	 * @return list<Bmc>
	 */
	public static function visible(array $bmcs, bool $admin): array {
		return $admin ? $bmcs : array_values(array_filter($bmcs, static fn (Bmc $bmc): bool => $bmc->hostId > 0));
	}

	/**
	 * The cells of one BMC's row, as plain text.
	 *
	 * @return list<string>
	 */
	public function row(Bmc $bmc, BmcHealth $h, int $now): array {
		$found   = $this->discovery->find($bmc->id);
		$plan    = $found['plan'];
		$states  = [];
		$newest  = 0;

		foreach ($this->readings->forBmc($bmc->id) as $reading) {
			$state = $reading['value'] === null ? 'unknown' : $this->freshness->evaluate($reading['observed_at'])->value;
			$states[$state] = ($states[$state] ?? 0) + 1;
			$newest = max($newest, $reading['observed_at']);
		}

		ksort($states);

		$data = $states === [] ? '-' : implode(', ', array_map(static fn (string $s, int $n): string => "$s $n", array_keys($states), $states)) . '; ' . __('newest %s', Html::age($now - $newest), 'redfish');

		$activeMaint  = $this->maint !== null ? $this->maint->activeForHost($bmc->hostId) : null;
		$reachability = $bmc->enabled() ? $h->reachability->value : __('disabled', 'redfish');

		if ($activeMaint !== null) {
			$reachability .= ' [MAINT]';
		}

		return [
			$bmc->name,
			$reachability,
			$h->failure->value ?? '-',
			$h->lastSuccess > 0 ? Html::age($now - $h->lastSuccess) : __('Never', 'redfish'),
			$h->generation === '' ? '-' : $h->generation . ($plan === null ? '' : ' (' . trim($plan->vendor . ' ' . $plan->redfishVersion) . ')'),
			$h->authMode === '' ? '-' : $h->authMode,
			(string) $h->requests,
			$h->requests > 0 ? sprintf('%.0f ms', $h->latencyMs) : '-',
			$found['discovered_at'] > 0 ? ($found['stale'] ? __('stale', 'redfish') : __('ok', 'redfish')) . ', ' . Html::age($now - $found['discovered_at']) : __('Never', 'redfish'),
			$data,
		];
	}
}
