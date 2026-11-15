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

/*
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until redfish_boot() has confirmed PHP 8.2 or later (ADR 0008).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI (REQ-COMPAT-001 to REQ-COMPAT-003).
 */

function redfish_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Extensions the plugin needs that PHP can be built without. Cacti 1.2.31's
 * composer.json already requires them. ext-curl is deliberately not used:
 * TLS goes through stream sockets, which need ext-openssl (ADR 0010).
 *
 * @return list<string>
 */
function redfish_required_extensions(): array {
	return array('openssl');
}

/**
 * @return list<string>
 */
function redfish_missing_extensions(): array {
	$missing = array();

	foreach (redfish_required_extensions() as $extension) {
		if (!extension_loaded($extension)) {
			$missing[] = $extension;
		}
	}

	return $missing;
}

function redfish_boot(): bool {
	if (!redfish_php_supported() || redfish_missing_extensions() !== array()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming what is missing.
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function redfish_refuse_unsupported(): bool {
	if (!redfish_php_supported()) {
		$message = __('The Redfish plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'redfish');
	} elseif (redfish_missing_extensions() !== array()) {
		$message = __('The Redfish plugin requires the PHP extensions %s, which this server does not load, so the plugin was not installed or enabled.', implode(', ', redfish_missing_extensions()), 'redfish');
	} else {
		return false;
	}

	cacti_log('ERROR: ' . $message, false, 'REDFISH');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('redfish_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_redfish_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_redfish_install(): void {
	if (redfish_refuse_unsupported()) {
		return;
	}

	plugin_redfish_upgrade(true);
}

function plugin_redfish_check_config(): bool {
	if (redfish_refuse_unsupported()) {
		return false;
	}

	return plugin_redfish_upgrade(true);
}

/**
 * Common start of every page script, after include/auth.php has authorized
 * the realm. Refuses on an unsupported runtime or a disabled plugin, because
 * Cacti keeps plugin realms registered while a plugin is disabled.
 */
function redfish_page_start(): bool {
	if (redfish_boot() && api_plugin_is_enabled('redfish')) {
		return true;
	}

	top_header();

	html_start_box(__('Redfish', 'redfish'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(redfish_php_supported() ? __('The Redfish plugin is not enabled.', 'redfish') : __('The Redfish plugin requires PHP %s or later.', '8.2.0', 'redfish')) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST, which csrf-magic checks
 * (REQ-UI-003). A GET that names one is sent back to the page without effect.
 *
 * @param list<string> $actions
 */
function redfish_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

function redfish_user_id(): int {
	return isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
}

function redfish_settings(): \Somethingwithproof\Redfish\Settings {
	return (new \Somethingwithproof\Redfish\SettingsRepository(false))->load();
}

function redfish_bmcs(): \Somethingwithproof\Redfish\BmcRepository {
	return new \Somethingwithproof\Redfish\BmcRepository(false, new \Somethingwithproof\Redfish\SystemClock());
}

/**
 * The current user's device permissions over BMC records.
 */
function redfish_device_scope(): \Somethingwithproof\Redfish\DeviceScope {
	return \Somethingwithproof\Redfish\DeviceScope::forUser(redfish_user_id(), redfish_bmcs());
}

function redfish_credentials(): \Somethingwithproof\Redfish\CredentialRepository {
	return new \Somethingwithproof\Redfish\CredentialRepository(false, new \Somethingwithproof\Redfish\SystemClock());
}

function redfish_credential_store(): \Somethingwithproof\Redfish\CredentialStore {
	return new \Somethingwithproof\Redfish\CredentialStore(redfish_credentials(), redfish_settings()->vaultKeyFile);
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one (REQ-LIFE-001, REQ-LIFE-002).
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_redfish_upgrade(bool $grant = false): bool {
	if (!redfish_boot()) {
		return false;
	}

	$info   = plugin_redfish_version();
	$schema = new \Somethingwithproof\Redfish\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	redfish_setup_hooks($grant);

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms.
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function redfish_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// A hook repaired while the plugin is enabled must be active, or core
	// never calls it; on install and while disabled, core decides.
	$enabled = (new \Somethingwithproof\Redfish\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Redfish\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('redfish', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Redfish\Pages::realms() as $realm) {
		api_plugin_register_realm('redfish', implode(',', $realm['files']), $realm['name'], $grant);
	}
}

/**
 * Abort an uninstall that was told to keep the tables but could not untrack
 * them. Core drops every tracked table once plugin_redfish_uninstall()
 * returns and ignores its result, so only stopping the request keeps the
 * tables; the plugin then stays installed and the uninstall can be retried.
 * PHP 8.0 syntax, because the unbooted path uses it too.
 */
function redfish_uninstall_stop(): void {
	cacti_log('ERROR: Uninstall stopped: the plugin tables could not be untracked, so Cacti would have dropped the tables it was told to keep', false, 'REDFISH');

	throw new RuntimeException('The Redfish plugin uninstall was stopped to keep its tables. See the Cacti log.');
}

/**
 * Uninstall never contacts a BMC and never touches a Cacti device, graph, or
 * RRD file (REQ-LIFE-004). Core drops the tracked plugin tables afterwards
 * unless the administrator left "Keep Plugin Tables on Uninstall" checked,
 * which is the default.
 */
function plugin_redfish_uninstall(): void {
	if (!redfish_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		redfish_uninstall_unbooted();

		return;
	}

	$schema = new \Somethingwithproof\Redfish\Schema(false);

	if (redfish_settings()->keepTablesOnUninstall) {
		if (!$schema->untrackTables()) {
			redfish_uninstall_stop();
		}

		\Somethingwithproof\Redfish\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

function redfish_config_arrays(): void {
	global $menu;

	if (!redfish_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Redfish\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/redfish/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Redfish\Pages::upgradeTriggers(), true)) {
		plugin_redfish_upgrade();
	}
}

function redfish_config_settings(): void {
	global $tabs, $settings;

	if (!redfish_boot()) {
		return;
	}

	$tabs['redfish']     = __('Redfish', 'redfish');
	$settings['redfish'] = \Somethingwithproof\Redfish\Settings::form();
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function redfish_draw_navigation_text($nav) {
	if (!redfish_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Redfish\Pages::navigation());
}

/**
 * poller_bottom hook: start this poller's collector in the background
 * (REQ-COLL-001). The collector itself reads only BMCs of its poller.
 */
function redfish_poller_bottom(): void {
	global $config;

	if (!redfish_boot()) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$php = redfish_php_binary();

	if ($php === '') {
		cacti_log('ERROR: The Redfish collector was not started because the PHP binary path setting is empty or not an executable file', false, 'REDFISH');

		return;
	}

	// Core accepts an argument array and escapes each element, although its
	// docblock declares a string.
	// @phpstan-ignore argument.type
	exec_background($php, array('-q', __DIR__ . '/poller_redfish.php', '--poller=' . (int) $config['poller_id']));
}

/**
 * The PHP binary Cacti is configured with, or '' when it is not an
 * executable file: exec_background() silently does nothing for a binary
 * that does not exist, so a bare 'php' fallback would never start anything.
 */
function redfish_php_binary(): string {
	$php = trim((string) read_config_option('path_php_binary'));

	return $php !== '' && is_file($php) && is_executable($php) ? $php : '';
}

function redfish_collector(int $pollerId, int $worker): \Somethingwithproof\Redfish\Collector {
	$clock    = new \Somethingwithproof\Redfish\SystemClock();
	$settings = redfish_settings();

	return new \Somethingwithproof\Redfish\Collector(
		redfish_bmcs(),
		redfish_credential_store(),
		new \Somethingwithproof\Redfish\LeaseRepository(false, $clock),
		new \Somethingwithproof\Redfish\HealthRepository(false),
		new \Somethingwithproof\Redfish\DiscoveryRepository(false),
		new \Somethingwithproof\Redfish\ReadingRepository(false),
		\Somethingwithproof\Redfish\ClientFactory::production($settings),
		$settings,
		$clock,
		'p' . $pollerId . 'w' . $worker . '-' . getmypid() . '-' . bin2hex(random_bytes(4))
	);
}
