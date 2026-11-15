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
 * Page permissions and POST-only actions over HTTP (#28). Logs in to a
 * running Cacti as three users and checks each plugin page:
 *
 *   admin    Redfish View and Redfish Admin: both pages load
 *   viewer   Console and Redfish View: health loads, the admin pages are denied
 *   norealm  Console only: every page is denied
 *
 * Then shows that a delete sent as GET, or as POST without the csrf-magic
 * token, has no effect, and that the same delete as a proper POST works.
 * Last, it saves a credential and a BMC through the forms and searches
 * every response, the Cacti log, and every plugin table for the password
 * (#35).
 * Disposable databases only: it resets the admin password and adds users.
 * Must parse on PHP 8.0.
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/redfish/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Perm-' . bin2hex(random_bytes(8));
$failures = 0;

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'redfish' AND file = 'redfish_health.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'redfish' AND file LIKE 'redfish_bmcs.php%'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 8 is Console access.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8)) as $name => $realms) {
	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', 1, 1, 1, 1, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name));
	$id = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}
}

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'redfishperm');
	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('redfish_bmcs.php' => 'Redfish BMCs', 'redfish_credentials.php' => 'Redfish Credentials', 'redfish_health.php' => 'Redfish Warnings');

// A disabled BMC whose name is markup and whose credential does not exist.
db_execute_prepared("INSERT INTO plugin_redfish_bmc (name, address, port, poller_id, credential_id, tls_mode, admin_state) VALUES (?, '192.0.2.30', 443, 1, 999, 'system', 'enabled')", array('<b id="redfishprobe">Probe</b>'));
$probe = (int) db_fetch_insert_id();

$all = array('redfish_bmcs.php' => true, 'redfish_credentials.php' => true, 'redfish_health.php' => true);

foreach (array('admin' => $all, 'viewer' => array('redfish_health.php' => true) + array_fill_keys(array('redfish_bmcs.php', 'redfish_credentials.php'), false), 'norealm' => array_fill_keys(array_keys($all), false)) as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = strpos($body, $pages[$page]) !== false;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");

		// Credential warnings are for Redfish Admin only.
		if ($page === 'redfish_health.php' && $allowed) {
			$credential = strpos($body, 'its credential is missing') !== false;
			perm_expect($credential === ($user === 'admin'), "$user " . ($user === 'admin' ? 'sees' : 'does not see') . ' credential warnings on redfish_health.php');
		}
	}

	unlink($jar);
}

$jar = perm_login('admin');
$exists = function () use ($probe) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_bmc WHERE id = ?', array($probe)) === 1;
};

// The probe's name is markup; every page that prints it must escape it.
foreach (array('redfish_bmcs.php', 'redfish_bmcs.php?action=edit&id=' . $probe, 'redfish_health.php') as $page) {
	list($code, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<b id="redfishprobe">') === false && strpos($body, '&lt;b id=&quot;redfishprobe&quot;&gt;') !== false, "$page escapes the BMC name (HTTP $code, " . strlen($body) . ' bytes)');
}

perm_http($base . 'redfish_bmcs.php?action=delete&id=' . $probe, $jar);
perm_expect($exists(), 'a delete sent as GET has no effect');

perm_http($base . 'redfish_bmcs.php', $jar, array('action' => 'delete', 'id' => $probe));
perm_expect($exists(), 'a delete POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'redfish_bmcs.php', $jar);
perm_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $probe));
perm_expect(!$exists(), 'the same delete as a POST with the token removes the BMC');

// Device permissions bind Redfish Admin too: a user with both realms whose
// Cacti device policy hides one device can neither see nor change a BMC
// mapped to it, nor move a BMC onto it, nor change a credential it uses.
db_execute("INSERT INTO host (description, hostname, status) VALUES ('perm-visible', '192.0.2.40', 3)");
$visibleHost = (int) db_fetch_insert_id();
db_execute("INSERT INTO host (description, hostname, status) VALUES ('perm-hidden', '192.0.2.41', 3)");
$hiddenHost = (int) db_fetch_insert_id();
db_execute("INSERT INTO plugin_redfish_credential (name, username, revision) VALUES ('perm-hidden-cred', 'monitor', 1)");
$hiddenCred = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_redfish_bmc (name, address, port, poller_id, host_id, credential_id, tls_mode, admin_state) VALUES ('perm-hidden-bmc', '192.0.2.42', 443, 1, ?, ?, 'system', 'enabled')", array($hiddenHost, $hiddenCred));
$hiddenBmc = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_redfish_bmc (name, address, port, poller_id, host_id, credential_id, tls_mode, admin_state) VALUES ('perm-visible-bmc', '192.0.2.43', 443, 1, ?, ?, 'system', 'enabled')", array($visibleHost, $hiddenCred));
$visibleBmc = (int) db_fetch_insert_id();
db_execute_prepared("INSERT INTO plugin_redfish_health (bmc_id, backoff_until, failures) VALUES (?, 2000000000, 3), (?, 2000000000, 3)", array($hiddenBmc, $visibleBmc));

db_execute("DELETE FROM user_auth WHERE username = 'scoped'");
db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES ('scoped', ?, 0, 'scoped', '', '', 2, 1, 2, 2, 'on')", array(password_hash($password, PASSWORD_DEFAULT)));
$scoped = (int) db_fetch_insert_id();

foreach (array(8, $view, $admin) as $realm) {
	db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $scoped));
}

// Graph, device, and template policies deny (2) with one device grant
// (type 3). Under Cacti's default permissive graph authorization a device
// is visible when its graphs or templates are, so all three must deny.
db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)', array($scoped, $visibleHost));

$bmcRow = function ($id) {
	return db_fetch_row_prepared('SELECT b.tls_mode, b.host_id, h.backoff_until FROM plugin_redfish_bmc AS b LEFT JOIN plugin_redfish_health AS h ON h.bmc_id = b.id WHERE b.id = ?', array($id));
};
$hiddenBefore = $bmcRow($hiddenBmc);
$scopedJar    = perm_login('scoped');

list(, $scopedList) = perm_http($base . 'redfish_bmcs.php', $scopedJar);
perm_expect(strpos($scopedList, 'perm-visible-bmc') !== false && strpos($scopedList, 'perm-hidden-bmc') === false, 'a device-scoped admin sees only BMCs on visible devices');
list(, $scopedExport) = perm_http($base . 'redfish_bmcs.php?action=export', $scopedJar);
perm_expect(strpos($scopedExport, '192.0.2.43') !== false && strpos($scopedExport, '192.0.2.42') === false, 'the export leaves out BMCs on hidden devices');
list(, $scopedHealth) = perm_http($base . 'redfish_health.php', $scopedJar);
perm_expect(strpos($scopedHealth, 'perm-visible-bmc') !== false && strpos($scopedHealth, 'perm-hidden-bmc') === false, 'the health page leaves out BMCs on hidden devices');
list(, $scopedEdit) = perm_http($base . 'redfish_bmcs.php?action=edit&id=' . $hiddenBmc, $scopedJar);
perm_expect(strpos($scopedEdit, '192.0.2.42') === false, 'the edit form of a BMC on a hidden device does not open');
list(, $newForm) = perm_http($base . 'redfish_bmcs.php?action=edit', $scopedJar);
perm_expect(strpos($newForm, 'perm-visible (') !== false && strpos($newForm, 'perm-hidden (') === false, 'the device list offers only visible devices');

$token = perm_token($scopedList);
$hiddenSave = array('__csrf_magic' => $token, 'action' => 'save', 'id' => $hiddenBmc, 'name' => 'perm-hidden-bmc', 'address' => '192.0.2.42', 'port' => '443', 'poller_id' => 1, 'host_id' => $hiddenHost, 'credential_id' => $hiddenCred, 'tls_mode' => 'unverified', 'enabled' => 'on');
perm_http($base . 'redfish_bmcs.php', $scopedJar, $hiddenSave);
perm_http($base . 'redfish_bmcs.php', $scopedJar, array('__csrf_magic' => $token, 'action' => 'retry', 'id' => $hiddenBmc));
perm_http($base . 'redfish_bmcs.php', $scopedJar, array('__csrf_magic' => $token, 'action' => 'delete', 'id' => $hiddenBmc));
perm_expect($bmcRow($hiddenBmc) == $hiddenBefore, 'save, retry, and delete of a BMC on a hidden device change nothing');

perm_http($base . 'redfish_bmcs.php', $scopedJar, array('__csrf_magic' => $token, 'action' => 'save', 'id' => $visibleBmc, 'name' => 'perm-visible-bmc', 'address' => '192.0.2.43', 'port' => '443', 'poller_id' => 1, 'host_id' => $hiddenHost, 'credential_id' => $hiddenCred, 'tls_mode' => 'system', 'enabled' => 'on'));
perm_expect((int) $bmcRow($visibleBmc)['host_id'] === $visibleHost, 'a BMC cannot be moved onto a hidden device');

$revision = (int) db_fetch_cell_prepared('SELECT revision FROM plugin_redfish_credential WHERE id = ?', array($hiddenCred));
list(, $credForm) = perm_http($base . 'redfish_credentials.php', $scopedJar);
perm_http($base . 'redfish_credentials.php', $scopedJar, array('__csrf_magic' => perm_token($credForm), 'action' => 'save', 'id' => $hiddenCred, 'name' => 'perm-hidden-cred', 'username' => 'changed', 'password' => ''));
perm_expect((int) db_fetch_cell_prepared('SELECT revision FROM plugin_redfish_credential WHERE id = ?', array($hiddenCred)) === $revision, 'a credential used by a BMC on a hidden device cannot be changed');

// Control: the same retry on the visible BMC works.
perm_http($base . 'redfish_bmcs.php', $scopedJar, array('__csrf_magic' => $token, 'action' => 'retry', 'id' => $visibleBmc));
perm_expect((int) $bmcRow($visibleBmc)['backoff_until'] === 0, 'the same retry on a BMC on a visible device clears its backoff');

unlink($scopedJar);
db_execute_prepared('DELETE FROM plugin_redfish_health WHERE bmc_id IN (?, ?)', array($hiddenBmc, $visibleBmc));
db_execute_prepared('DELETE FROM plugin_redfish_bmc WHERE id IN (?, ?)', array($hiddenBmc, $visibleBmc));
db_execute_prepared('DELETE FROM plugin_redfish_credential WHERE id = ?', array($hiddenCred));
db_execute_prepared('DELETE FROM host WHERE id IN (?, ?)', array($visibleHost, $hiddenHost));
db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($scoped));

// A password saved through the form must come back in no response, no
// log line, and no plugin table.
$keyFile = sys_get_temp_dir() . '/redfish-perm-' . bin2hex(random_bytes(4)) . '.key';
file_put_contents($keyFile, bin2hex(random_bytes(32)));
chmod($keyFile, 0600);
db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('redfish_vault_key_file', ?)", array($keyFile));

$secret    = 'SENTINEL-' . bin2hex(random_bytes(16));
$probeName = 'Secret probe ' . bin2hex(random_bytes(3));

// Each response counts as evidence only if the request did what it should:
// [label, HTTP code, body, text the page must show].
$responses = array();

list($code, $form) = perm_http($base . 'redfish_credentials.php?action=edit', $jar);
$responses[] = array('form', $code, $form, 'name="password"');
list($code, $saved) = perm_http($base . 'redfish_credentials.php', $jar, array('__csrf_magic' => perm_token($form), 'action' => 'save', 'id' => 0, 'name' => $probeName, 'username' => 'monitor', 'password' => $secret));
$responses[] = array('save', $code, $saved, 'The credential was saved.');

$credential = (int) db_fetch_cell_prepared('SELECT id FROM plugin_redfish_credential WHERE name = ?', array($probeName));
perm_expect($credential > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_redfish_secret WHERE credential_id = ?', array($credential)) === 1, 'a credential saved through the form stores its password in the vault');

// A failed save echoes the form back, never the password.
list($code, $failed) = perm_http($base . 'redfish_credentials.php', $jar, array('__csrf_magic' => perm_token($saved), 'action' => 'save', 'id' => 0, 'name' => '', 'username' => 'monitor', 'password' => $secret));
$responses[] = array('failed save', $code, $failed, 'Name: enter 1 to 100 characters');

list($code, $bmcForm) = perm_http($base . 'redfish_bmcs.php?action=edit', $jar);
$responses[] = array('bmc form', $code, $bmcForm, 'name="credential_id"');
list($code, $bmcSaved) = perm_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => perm_token($bmcForm), 'action' => 'save', 'id' => 0, 'name' => $probeName, 'address' => '192.0.2.31', 'port' => '443', 'poller_id' => 1, 'host_id' => 0, 'credential_id' => $credential, 'tls_mode' => 'system', 'enabled' => 'on'));
$responses[] = array('bmc save', $code, $bmcSaved, 'The BMC was saved.');

foreach (array('list' => array('redfish_credentials.php', 'Stored'), 'edit' => array('redfish_credentials.php?action=edit&id=' . $credential, 'A password is stored'), 'bmcs' => array('redfish_bmcs.php', $probeName), 'export' => array('redfish_bmcs.php?action=export', '"address": "192.0.2.31"'), 'health' => array('redfish_health.php', $probeName)) as $label => $page) {
	list($code, $body) = perm_http($base . $page[0], $jar);
	$responses[] = array($label, $code, $body, $page[1]);
}

foreach ($responses as $response) {
	list($label, $code, $body, $marker) = $response;
	perm_expect($code === 200 && strpos($body, $marker) !== false, "the $label request succeeded (HTTP $code) and its page shows what it should");
}

$leaks = array();

foreach ($responses as $response) {
	if (strpos($response[2], $secret) !== false) {
		$leaks[] = $response[0] . ' response';
	}
}

$log = (string) @file_get_contents($config['base_path'] . '/log/cacti.log');

if (strpos($log, $secret) !== false) {
	$leaks[] = 'cacti.log';
}

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'plugin\\_redfish\\_%' OR TABLE_NAME = 'settings')") as $table) {
	foreach (db_fetch_assoc('SELECT * FROM `' . $table['t'] . '`') as $row) {
		if (strpos(implode("\0", array_map('strval', $row)), $secret) !== false) {
			$leaks[] = $table['t'];
		}
	}
}

perm_expect(count($responses) === 10 && $leaks === array(), 'the password appears in no response, log, or table' . ($leaks === array() ? '' : ': ' . implode(', ', array_unique($leaks))));

db_execute("DELETE FROM settings WHERE name = 'redfish_vault_key_file'");
unlink($keyFile);
unlink($jar);

exit($failures > 0 ? 1 : 0);
