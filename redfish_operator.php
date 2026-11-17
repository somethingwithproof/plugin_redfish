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

use Somethingwithproof\Redfish\OperatorPage;

chdir('../../');
include('./include/auth.php');
include_once('./plugins/redfish/setup.php');

if (!redfish_page_start()) {
	exit;
}
require_once('./plugins/redfish/include/operator.php');

$user    = (int) ($_SESSION['sess_user_id'] ?? 0);
$allowed = $user > 0 && api_plugin_user_realm_auth('redfish_operator.php');

if (!$allowed) {
	http_response_code(403);
	exit;
}
$actions = ['retry' => __('Retry collection', 'redfish')];
$action  = (string) get_nfilter_request_var('action');
$session = $_SESSION['redfish_triage'] ?? [];
$session = is_array($session) ? $session : [];

try {
	// Re-read on POST too: stale forms never retain revoked device access.
	$rows = redfish_operator_rows();
	$now  = time();

	if ($action !== '' && $action !== 'default') {
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		$status = OperatorPage::actionStatus($allowed, $action, $method, $method === 'POST' && csrf_check(false), array_merge(['acknowledge'], array_keys($actions)));

		if ($status !== 200) {
			http_response_code($status);

			if ($status === 405) {
				header('Allow: POST');
			}
			exit;
		}
		$id  = (string) get_nfilter_request_var('id');
		$row = null;

		foreach ($rows as $candidate) {
			if ($candidate['id'] === $id) {
				$row = $candidate;

				break;
			}
		}

		if ($row === null) {
			http_response_code(403);
			exit;
		}
		$fingerprint = (string) get_nfilter_request_var('fingerprint');

		if (!hash_equals(OperatorPage::fingerprint($row), $fingerprint)) {
			http_response_code(409);
			exit;
		}

		if ($action === 'acknowledge') {
			if (!OperatorPage::acknowledge($session, $row, $fingerprint, $user, $now)) {
				http_response_code(409);
				exit;
			}
			$_SESSION['redfish_triage'] = $session;
		} else {
			// Bound repeated requests from a browser without changing collector budgets.
			$key  = (string) $user;
			$last = $_SESSION['redfish_operation'] ?? [];

			if (($last['key'] ?? '') === $key && $now - ($last['at'] ?? 0) < 60) {
				http_response_code(429);
				header('Retry-After: 60');
				exit;
			}
			$_SESSION['redfish_operation'] = ['key' => $key, 'at' => $now];
			redfish_operator_retry((int) $row['id']);
		}
		cacti_log('Operator ' . $action . ' by user ' . $user . ' on object ' . hash('sha256', $id), false, 'REDFISH');
		header('Location: redfish_operator.php');
		exit;
	}
	top_header();
	print '<p><a href="redfish_health.php">' . html_escape(__('Monitoring view', 'redfish')) . '</a></p>';
	OperatorPage::render($rows, $session, $user, $now, $actions);
	bottom_footer();
} catch (Throwable $error) {
	cacti_log('Operator page failed (' . get_class($error) . ')', false, 'REDFISH');
	http_response_code(503);
	top_header();
	print '<p class="textError">' . html_escape(__('Observations could not be read. Ask an administrator to check the plugin log.', 'redfish')) . '</p>';
	bottom_footer();
}
