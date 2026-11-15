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
 * Unit test bootstrap. Stubs the few Cacti helpers the plugin calls, so the
 * suite needs no database or Cacti checkout. Each stub records its calls in
 * $GLOBALS for tests to read.
 */

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 3);
}

$GLOBALS['__redfish_log'] = [];

if (!function_exists('__')) {
	// Core's __() (include/global_languages.php) passes every argument to
	// sprintf(), the trailing text domain included, when the text has a
	// placeholder. A placeholder therefore takes the domain unless the caller
	// supplied values before it: __('%d s', $n, 'redfish'), never
	// sprintf(__('%d s', 'redfish'), $n).
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', $args[0]) !== 1) {
			return $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__redfish_log'][] = ['message' => $string, 'environ' => $environ, 'level' => $level];

		return true;
	}
}

$GLOBALS['__redfish_options'] = [];

if (!function_exists('read_config_option')) {
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__redfish_options'][$config_name] ?? '';
	}
}

// Repositories in unit tests talk to these stubs. A test reads the
// statements from $GLOBALS['__redfish_db_exec'] and queues query results in
// $GLOBALS['__redfish_db_rows'] (keyed by a substring of the SQL).
$GLOBALS['__redfish_db_exec'] = [];
$GLOBALS['__redfish_db_rows'] = [];

function redfish_test_rows(string $sql): mixed {
	foreach ($GLOBALS['__redfish_db_rows'] as $needle => $rows) {
		if (str_contains($sql, $needle)) {
			return $rows;
		}
	}

	return [];
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$GLOBALS['__redfish_db_exec'][] = ['sql' => $sql, 'params' => $params];

		return true;
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		return redfish_test_rows($sql);
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false, $log_function = null) {
		$rows = redfish_test_rows($sql);

		return is_array($rows) && isset($rows[0]) ? $rows[0] : [];
	}
}

// Transactions are recorded as BEGIN, COMMIT, and ROLLBACK statements.
// @@in_transaction answers from $GLOBALS['__redfish_in_tx'] in order, then 1,
// so a test can play InnoDB ending a transaction on a deadlock.
$GLOBALS['__redfish_in_tx'] = [];

if (!function_exists('db_begin_transaction')) {
	function db_begin_transaction($db_conn = false) {
		$GLOBALS['__redfish_db_exec'][] = ['sql' => 'BEGIN', 'params' => []];

		return true;
	}

	function db_commit_transaction($db_conn = false) {
		$GLOBALS['__redfish_db_exec'][] = ['sql' => 'COMMIT', 'params' => []];

		return true;
	}

	function db_rollback_transaction($db_conn = false) {
		$GLOBALS['__redfish_db_exec'][] = ['sql' => 'ROLLBACK', 'params' => []];

		return true;
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false, $log_function = null) {
		if (str_contains($sql, '@@in_transaction')) {
			return $GLOBALS['__redfish_in_tx'] === [] ? 1 : array_shift($GLOBALS['__redfish_in_tx']);
		}

		$rows = redfish_test_rows($sql);

		return is_array($rows) && isset($rows[0]) ? reset($rows[0]) : 0;
	}
}

if (!function_exists('db_fetch_insert_id')) {
	function db_fetch_insert_id($db_conn = false) {
		return 1;
	}
}

$GLOBALS['config'] = [
	'base_path'    => '/var/www/cacti',
	'library_path' => '/var/www/cacti/lib',
	'poller_id'    => 1,
	'is_web'       => false,
];

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $width, $div, $cell_padding, $align, $add_text, $collapsing = false, $table_id = '') {
		print '<table><caption>' . $title . '</caption>';
	}

	function html_end_box($trailing_br = true, $div = false) {
		print '</table>';
	}
}

if (!function_exists('db_affected_rows')) {
	function db_affected_rows($db_conn = false) {
		return 1;
	}
}

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/include/autoload.php';
require_once __DIR__ . '/Support/FixtureTransport.php';
require_once __DIR__ . '/Support/FakeBmc.php';
