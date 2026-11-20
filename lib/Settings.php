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
 * Plugin settings, read once per request from Cacti's settings table
 * (REQ-UI-002). Each numeric setting has a default and a range; a stored value
 * outside the range reads as the nearest bound, and a value that is not a
 * whole number reads as the default.
 */
final readonly class Settings {
	/**
	 * name => [default, minimum, maximum]
	 */
	public const RANGES = [
		'redfish_collect_interval'  => [300, 60, 86400],
		'redfish_discover_interval' => [3600, 300, 604800],
		'redfish_collector_budget'  => [240, 10, 3600],
		'redfish_workers'           => [4, 1, 32],
		'redfish_delayed_after'     => [2, 1, 10],
		'redfish_stale_after'       => [4, 2, 50],
		'redfish_connect_timeout'   => [5, 1, 30],
		'redfish_request_timeout'   => [20, 2, 120],
		'redfish_max_body_kib'      => [2048, 64, 16384],
	];

	public const NAMES = [
		'redfish_vault_key_file',
		'redfish_collect_interval',
		'redfish_discover_interval',
		'redfish_collector_budget',
		'redfish_workers',
		'redfish_delayed_after',
		'redfish_stale_after',
		'redfish_connect_timeout',
		'redfish_request_timeout',
		'redfish_max_body_kib',
		'redfish_uninstall_keep_tables',
	];

	public const DEFAULT_KEY_FILE = '/etc/cacti/redfish-vault.key';

	public function __construct(
		public string $vaultKeyFile,
		public int $collectInterval,
		public int $discoverInterval,
		public int $collectorBudget,
		public int $workers,
		public int $delayedAfter,
		public int $staleAfter,
		public int $connectTimeout,
		public int $requestTimeout,
		public int $maxBodyBytes,
		public bool $keepTablesOnUninstall,
	) {
	}

	/**
	 * A setting that was never saved reads as its default. Keeping tables is
	 * the default, so an uninstall drops the registry and passwords only after
	 * an administrator has cleared the box (REQ-LIFE-004).
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		$keyFile = Row::string($row, 'redfish_vault_key_file');
		$delayed = self::ranged($row, 'redfish_delayed_after');

		return new self(
			vaultKeyFile: $keyFile === '' ? self::DEFAULT_KEY_FILE : $keyFile,
			collectInterval: self::ranged($row, 'redfish_collect_interval'),
			discoverInterval: self::ranged($row, 'redfish_discover_interval'),
			collectorBudget: self::ranged($row, 'redfish_collector_budget'),
			workers: self::ranged($row, 'redfish_workers'),
			delayedAfter: $delayed,
			// Stale must come after delayed, or no reading could be delayed.
			staleAfter: max($delayed + 1, self::ranged($row, 'redfish_stale_after')),
			connectTimeout: self::ranged($row, 'redfish_connect_timeout'),
			requestTimeout: self::ranged($row, 'redfish_request_timeout'),
			maxBodyBytes: self::ranged($row, 'redfish_max_body_kib') * 1024,
			keepTablesOnUninstall: array_key_exists('redfish_uninstall_keep_tables', $row) ? Row::bool($row, 'redfish_uninstall_keep_tables') : true,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function ranged(array $row, string $name): int {
		[$default, $min, $max] = self::RANGES[$name];

		return max($min, min($max, Row::int($row, $name, $default)));
	}

	/**
	 * Settings tab definition for the config_settings hook.
	 *
	 * The key file is a plain text box, not a Cacti filepath field: it lives
	 * on the poller that runs the collector, which need not be the server
	 * rendering this form, and the health page checks it there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		$number = static function (string $name, string $label, string $description): array {
			[$default, $min, $max] = self::RANGES[$name];

			return [
				'friendly_name' => $label,
				'description'   => __('%s Default %d, from %d to %d.', $description, $default, $min, $max, 'redfish'),
				'method'        => 'textbox',
				'default'       => (string) $default,
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => $min, 'max_range' => $max]],
				'max_length'    => '6',
				'size'          => '6',
			];
		};

		return [
			'redfish_header_vault' => [
				'friendly_name' => __('Credential Vault', 'redfish'),
				'method'        => 'spacer',
			],
			'redfish_vault_key_file' => [
				'friendly_name' => __('Vault Key File', 'redfish'),
				'description'   => __('Absolute path to the 32-byte key that encrypts stored BMC passwords. Create it yourself, outside the web root, readable only by the web server and poller user, in a directory that only root and that user can write; the plugin never creates or writes it. Back it up with the database: without it, stored passwords must be entered again.', 'redfish'),
				'method'        => 'textbox',
				'default'       => self::DEFAULT_KEY_FILE,
				'max_length'    => '255',
				'size'          => '60',
			],
			'redfish_header_collector' => [
				'friendly_name' => __('Collector', 'redfish'),
				'method'        => 'spacer',
			],
			'redfish_collect_interval'  => $number('redfish_collect_interval', __('Collection Interval', 'redfish'), __('Seconds between reads of each BMC.', 'redfish')),
			'redfish_discover_interval' => $number('redfish_discover_interval', __('Rediscovery Interval', 'redfish'), __('Seconds between full resource discoveries of each BMC. A BMC is also rediscovered at once when a known resource disappears or changes type.', 'redfish')),
			'redfish_collector_budget'  => $number('redfish_collector_budget', __('Collector Time Budget', 'redfish'), __('Seconds one collector run may spend before it stops starting new BMCs. Keep it below the poller interval; unfinished BMCs resume on the next run.', 'redfish')),
			'redfish_workers'           => $number('redfish_workers', __('Collector Workers', 'redfish'), __('BMCs read in parallel. Each BMC is read by one worker at a time.', 'redfish')),
			'redfish_connect_timeout'   => $number('redfish_connect_timeout', __('Connect Timeout', 'redfish'), __('Seconds allowed for the TCP connection and TLS handshake to a BMC.', 'redfish')),
			'redfish_request_timeout'   => $number('redfish_request_timeout', __('Request Timeout', 'redfish'), __('Seconds allowed for one whole request to a BMC, including the connection.', 'redfish')),
			'redfish_max_body_kib'      => $number('redfish_max_body_kib', __('Maximum Response Size', 'redfish'), __('KiB accepted in one BMC response body; a larger response is refused.', 'redfish')),
			'redfish_header_freshness' => [
				'friendly_name' => __('Freshness', 'redfish'),
				'method'        => 'spacer',
			],
			'redfish_delayed_after' => $number('redfish_delayed_after', __('Delayed After', 'redfish'), __('Collection intervals after which a reading is shown as delayed.', 'redfish')),
			'redfish_stale_after'   => $number('redfish_stale_after', __('Stale After', 'redfish'), __('Collection intervals after which a reading is stale and reads as unknown. Always at least one more than Delayed After.', 'redfish')),
			'redfish_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'redfish'),
				'method'        => 'spacer',
			],
			'redfish_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'redfish'),
				'description'   => __('When checked, uninstalling the plugin keeps its BMC registry, encrypted passwords, and reading cache. Clear it to have the uninstall drop them. Uninstall never touches Cacti devices, graphs, or BMCs, including devices the plugin created.', 'redfish'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}
}
