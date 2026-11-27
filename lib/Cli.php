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
 * Operator & CasC CLI dispatcher backing cli/redfish.php.
 *
 * Supports:
 *   export   --format=yaml|xml
 *   validate [--file=PATH] [--format=yaml|xml]
 *   plan     [--file=PATH] [--format=yaml|xml]
 *   apply    [--file=PATH] [--format=yaml|xml] --confirm=<PLAN_HASH>
 *   health   --format=text|json
 */
final class Cli {
	/**
	 * Initialize a new Cli instance.
	 *
	 * @param BmcRepository $bmcs
	 * @param CredentialRepository $credentials
	 * @param RackRepository $racks
	 * @param HealthRepository $health
	 * @param NodeCacheRepository $caches
	 * @param FreshnessEvaluator $freshness
	 * @param MaintWindow $maint
	 * @param Settings $settings
	 * @param Clock $clock
	 */
	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly CredentialRepository $credentials,
		private readonly RackRepository $racks,
		private readonly HealthRepository $health,
		private readonly NodeCacheRepository $caches,
		private readonly FreshnessEvaluator $freshness,
		private readonly MaintWindow $maint,
		private readonly Settings $settings,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Dispatch CLI arguments and return [exitCode, stdout, stderr].
	 *
	 * @param list<string> $argv arguments after script name
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	public function run(array $argv, string $stdin = ''): array {
		if ($argv === [] || in_array($argv[0], ['-h', '--help', 'help'], true)) {
			return ['exit' => 0, 'stdout' => self::usage(), 'stderr' => ''];
		}

		$command = $argv[0];
		$opts    = self::parseOptions(array_slice($argv, 1));

		return match ($command) {
			'export'   => $this->exportCommand($opts),
			'validate' => $this->validateCommand($opts, $stdin),
			'plan'     => $this->planCommand($opts, $stdin),
			'apply'    => $this->applyCommand($opts, $stdin),
			'health'   => $this->healthCommand($opts),
			default    => ['exit' => 1, 'stdout' => '', 'stderr' => "Unknown command: $command\n" . self::usage()],
		};
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function exportCommand(array $opts): array {
		$format = strtolower($opts['format'] ?? 'yaml');

		if (!in_array($format, ['yaml', 'yml', 'xml'], true)) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => "Unsupported format: $format (expected yaml or xml)\n"];
		}

		$racks       = $this->racks->all();
		$rackMembers = [];

		foreach ($racks as $rack) {
			$rackMembers[$rack->id] = $this->racks->members($rack->id);
		}

		$doc = CascProvider::buildDocument($this->bmcs->all(), $this->credentials->all(), $racks, $rackMembers);

		return ['exit' => 0, 'stdout' => CascProvider::export($doc, $format), 'stderr' => ''];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function validateCommand(array $opts, string $stdin): array {
		[$content, $format, $err] = $this->loadInput($opts, $stdin);

		if ($err !== null) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $err . "\n"];
		}

		try {
			$doc = CascProvider::parse($content, $format);
		} catch (\InvalidArgumentException $e) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $e->getMessage() . "\n"];
		}

		$errors = CascProvider::validate($doc);

		if ($errors !== []) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => implode("\n", $errors) . "\n"];
		}

		return ['exit' => 0, 'stdout' => "OK: configuration is valid.\n", 'stderr' => ''];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function planCommand(array $opts, string $stdin): array {
		[$content, $format, $err] = $this->loadInput($opts, $stdin);

		if ($err !== null) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $err . "\n"];
		}

		try {
			$desired = CascProvider::parse($content, $format);
		} catch (\InvalidArgumentException $e) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $e->getMessage() . "\n"];
		}

		$errors = CascProvider::validate($desired);

		if ($errors !== []) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => implode("\n", $errors) . "\n"];
		}

		$current = CascProvider::buildDocument($this->bmcs->all(), $this->credentials->all(), $this->racks->all());
		$plan    = CascProvider::plan($desired, $current);

		$lines = [
			sprintf('PLAN_HASH=%s', $plan['hash']),
			sprintf('Summary: %d create, %d update, %d unchanged', $plan['summary']['create'], $plan['summary']['update'], $plan['summary']['unchanged']),
		];

		foreach ($plan['actions'] as $action) {
			$lines[] = sprintf('  [%s] %s %s (%s)', $action['action'], $action['kind'], $action['name'], implode(', ', $action['changes']));
		}

		return ['exit' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function applyCommand(array $opts, string $stdin): array {
		$confirm = $opts['confirm'] ?? '';

		if ($confirm === '') {
			return ['exit' => 1, 'stdout' => '', 'stderr' => "Refused: apply requires --confirm=<PLAN_HASH>.\n"];
		}

		[$content, $format, $err] = $this->loadInput($opts, $stdin);

		if ($err !== null) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $err . "\n"];
		}

		try {
			$desired = CascProvider::parse($content, $format);
		} catch (\InvalidArgumentException $e) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => $e->getMessage() . "\n"];
		}

		$result = CascProvider::apply($desired, $confirm, $this->bmcs, $this->credentials, $this->racks, 0);

		if (!$result['ok']) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => implode("\n", $result['errors']) . "\n"];
		}

		return [
			'exit'   => 0,
			'stdout' => sprintf("Applied %d changes (PLAN_HASH=%s).\n", $result['applied'], $result['hash']),
			'stderr' => '',
		];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function healthCommand(array $opts): array {
		$format = strtolower($opts['format'] ?? 'text');

		if (!in_array($format, ['text', 'json'], true)) {
			return ['exit' => 1, 'stdout' => '', 'stderr' => "Unsupported format: $format (expected text or json)\n"];
		}

		$now   = $this->clock->now();
		$bmcs  = $this->bmcs->all();
		$runs  = $this->health->runs();
		$items = [];

		foreach ($bmcs as $bmc) {
			$h     = $this->health->find($bmc->id) ?? new BmcHealth($bmc->id);
			$maint = $this->maint->activeForHost($bmc->hostId);

			$items[] = [
				'bmc_id'        => $bmc->id,
				'name'          => $bmc->name,
				'poller_id'     => $bmc->pollerId,
				'host_id'       => $bmc->hostId,
				'admin_state'   => $bmc->adminState->value,
				'ipmi_fallback' => $bmc->ipmiFallback,
				'reachability'  => $h->reachability->value,
				'failure_class' => $h->failure->value ?? '',
				'last_success'  => $h->lastSuccess,
				'generation'    => $h->generation,
				'auth_mode'     => $h->authMode,
				'in_maint'      => $maint !== null,
				'maint_name'    => $maint['name'] ?? '',
			];
		}

		$rackScript = new RackSummaryScript($this->racks, $this->caches, $this->freshness);
		$rackList   = [];

		foreach ($this->racks->all() as $rack) {
			$summary = $rackScript->summaryFor($rack->id);

			if ($summary !== null) {
				$rackList[] = $summary->toArray();
			}
		}

		$payload = [
			'plugin'           => 'redfish',
			'timestamp'        => $now,
			'collect_interval' => $this->settings->collectInterval,
			'runs'             => $runs,
			'bmcs'             => $items,
			'racks'            => $rackList,
		];

		if ($format === 'json') {
			return ['exit' => 0, 'stdout' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 'stderr' => ''];
		}

		$lines = [sprintf('Redfish Health (BMCs: %d, Racks: %d, Poll runs: %d)', count($items), count($rackList), count($runs))];

		foreach ($items as $item) {
			$maintSuffix = $item['in_maint'] ? ' [MAINT:' . $item['maint_name'] . ']' : '';
			$lines[]     = sprintf(
				'  BMC %-20s poller=%d state=%s reach=%s failure=%s gen=%s%s',
				$item['name'],
				$item['poller_id'],
				$item['admin_state'],
				$item['reachability'],
				$item['failure_class'] === '' ? 'none' : $item['failure_class'],
				$item['generation'] === '' ? '-' : $item['generation'],
				$maintSuffix
			);
		}

		foreach ($rackList as $rack) {
			$watts  = is_float($rack['power_watts']) || is_int($rack['power_watts']) ? sprintf('%.1fW', (float) $rack['power_watts']) : 'U';
			$budget = is_float($rack['power_budget_watts']) || is_int($rack['power_budget_watts']) ? sprintf('%.1fW', (float) $rack['power_budget_watts']) : '-';
			$lines[] = sprintf(
				'  Rack %-19s power=%s budget=%s reporting=%d/%d (%.1f%%)',
				is_string($rack['name']) ? $rack['name'] : '',
				$watts,
				$budget,
				is_int($rack['members_reporting']) ? $rack['members_reporting'] : 0,
				is_int($rack['members_total']) ? $rack['members_total'] : 0,
				is_float($rack['coverage_pct']) || is_int($rack['coverage_pct']) ? (float) $rack['coverage_pct'] : 0.0
			);
		}

		return ['exit' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{0: string, 1: string, 2: ?string}
	 */
	private function loadInput(array $opts, string $stdin): array {
		$file   = $opts['file'] ?? $opts['_arg0'] ?? '';
		$format = strtolower($opts['format'] ?? '');

		if ($file !== '') {
			if (!is_file($file) || !is_readable($file)) {
				return ['', 'yaml', 'Cannot read file: ' . $file];
			}

			$content = (string) file_get_contents($file);

			if ($format === '') {
				$format = str_ends_with(strtolower($file), '.xml') ? 'xml' : 'yaml';
			}

			return [$content, $format, null];
		}

		if ($stdin === '') {
			return ['', 'yaml', 'No input file specified and stdin is empty.'];
		}

		if ($format === '') {
			$format = str_starts_with(ltrim($stdin), '<') ? 'xml' : 'yaml';
		}

		return [$stdin, $format, null];
	}

	/**
	 * @param list<string> $args
	 * @return array<string, string>
	 */
	private static function parseOptions(array $args): array {
		$opts       = [];
		$positional = 0;

		for ($i = 0, $n = count($args); $i < $n; $i++) {
			$arg = $args[$i];

			if (str_starts_with($arg, '--')) {
				$body = substr($arg, 2);
				$eq   = strpos($body, '=');

				if ($eq !== false) {
					$opts[substr($body, 0, $eq)] = substr($body, $eq + 1);
				} elseif ($i + 1 < $n && !str_starts_with($args[$i + 1], '--')) {
					$opts[$body] = $args[++$i];
				} else {
					$opts[$body] = '1';
				}
			} else {
				$opts['_arg' . $positional] = $arg;
				$positional++;
			}
		}

		return $opts;
	}

	/**
	 * Usage.
	 *
	 * @return string
	 */
	public static function usage(): string {
		return "usage: redfish.php <command> [options]\n"
			. "  export   --format=yaml|xml\n"
			. "  validate [--file=PATH] [--format=yaml|xml]\n"
			. "  plan     [--file=PATH] [--format=yaml|xml]\n"
			. "  apply    [--file=PATH] [--format=yaml|xml] --confirm=<PLAN_HASH>\n"
			. "  health   --format=text|json\n";
	}
}
