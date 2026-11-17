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

// Test code: refuse to run outside the command line.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	exit(1);
}

/*
 * Cacti device, graph, and data source permissions over HTTP for the
 * plugin's devices and actions (issue #50, REQ-PERM-001 to REQ-PERM-004).
 * Creates two BMC devices through the plugin, then logs in as:
 *
 *   admin       every realm: both BMCs, their graphs, data sources, the link
 *   rfscoped    Redfish View, device A granted by Cacti: BMC A only (an
 *               unmapped BMC is for administrators), its graphs and link,
 *               no data source pages
 *   rfrevoked   Redfish View, devices and graphs denied: nothing
 *   rfnorealm   Console and graphs, no plugin realm: graphs by Cacti's own
 *               policy, no plugin page
 *   rfadmin2    both plugin realms, but Cacti grants only device B and
 *               neither device management nor template import: every new
 *               action on BMC A is refused, and on BMC B too for want of
 *               the Cacti permission; GET and token-less POSTs do nothing
 *
 * Graph and data source access is Cacti's own; the plugin adds no layer,
 * so this shows that its objects follow it, and that no graph title or
 * legend names a BMC address or credential. Disposable databases only: it
 * resets the admin password and adds users. Must parse on PHP 8.0.
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/device-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable' || !isset($argv[1])) {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable and pass the Cacti URL\n");
	exit(2);
}

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/redfish/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Rf-' . bin2hex(random_bytes(8));
$failures = 0;

function dp_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function dp_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $body);
}

function dp_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function dp_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'redfishdp');
	list(, $login) = dp_http($root . 'index.php', $jar);
	dp_http($root . 'index.php', $jar, array('__csrf_magic' => dp_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

$bmcs = redfish_bmcs();

foreach ($bmcs->all() as $old) {
	$bmcs->delete($old->id, 1);
}

db_execute("INSERT INTO plugin_redfish_credential (name, username, revision) VALUES ('dp-secret-credential-name', 'dp-user', 1)");
$credential = (int) db_fetch_insert_id();
$made       = array();

foreach (array('a' => '192.0.2.51', 'b' => '192.0.2.52', 'c' => '192.0.2.53') as $key => $address) {
	db_execute_prepared("INSERT INTO plugin_redfish_bmc (name, address, port, poller_id, credential_id, tls_mode, admin_state) VALUES (?, ?, 443, 1, ?, 'system', 'disabled')", array('dp-bmc-' . $key, $address, $credential));
	$made[$key] = (int) db_fetch_insert_id();
}

$hostA = redfish_provisioner()->create($bmcs->find($made['a']), 1);
$hostB = redfish_provisioner()->create($bmcs->find($made['b']), 1);
$graph = (int) db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id = ? ORDER BY id LIMIT 1', array($hostA));
$ds    = (int) db_fetch_cell_prepared('SELECT id FROM data_local WHERE host_id = ? ORDER BY id LIMIT 1', array($hostA));
dp_expect($hostA > 0 && $hostB > 0 && $graph > 0 && $ds > 0, 'two BMCs have devices with graphs and data sources');

// No title, legend, data source name, or device field names the address or
// credential: they are not the plugin's to publish (REQ-PERM-002).
$text = json_encode(array(
	db_fetch_assoc_prepared('SELECT title_cache FROM graph_templates_graph WHERE local_graph_id IN (SELECT id FROM graph_local WHERE host_id IN (?, ?))', array($hostA, $hostB)),
	db_fetch_assoc_prepared('SELECT text_format FROM graph_templates_item WHERE local_graph_id IN (SELECT id FROM graph_local WHERE host_id IN (?, ?))', array($hostA, $hostB)),
	db_fetch_assoc_prepared('SELECT name_cache FROM data_template_data WHERE local_data_id IN (SELECT id FROM data_local WHERE host_id IN (?, ?))', array($hostA, $hostB)),
	db_fetch_assoc_prepared('SELECT * FROM host WHERE id IN (?, ?)', array($hostA, $hostB)),
));
dp_expect(strpos($text, '192.0.2.5') === false && strpos($text, 'dp-secret-credential-name') === false && strpos($text, 'dp-user') === false, 'no graph title, legend, data source name, or device field names a BMC address or credential');

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'redfish' AND file = 'redfish_health.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'redfish' AND file LIKE 'redfish_bmcs.php%'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 7 is View Graphs, 8 Console access, 27 the graph tools column
// where graph_buttons links appear. Policies: 1 allows all except the
// listed items, 2 denies all except them. Permission rows: type 3 device.
$users = array(
	'rfscoped'  => array('realms' => array(7, 8, 27, $view), 'policy' => 2, 'perms' => array(3 => $hostA)),
	'rfrevoked' => array('realms' => array(7, 8, 27, $view), 'policy' => 2, 'perms' => array()),
	'rfnorealm' => array('realms' => array(7, 8, 27), 'policy' => 1, 'perms' => array()),
	'rfadmin2'  => array('realms' => array(7, 8, 27, $view, $admin), 'policy' => 2, 'perms' => array(3 => $hostB)),
);

foreach ($users as $name => $spec) {
	$old = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', array($old));
	db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($old));
	db_execute_prepared('DELETE FROM user_auth WHERE id = ?', array($old));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', ?, 1, ?, ?, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name, $spec['policy'], $spec['policy'], $spec['policy']));
	$id = (int) db_fetch_insert_id();

	foreach ($spec['realms'] as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}

	foreach ($spec['perms'] as $type => $item) {
		db_execute_prepared('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, ?)', array($id, $item, $type));
	}
}

$expect = array(
	'admin'     => array('a' => true, 'c' => true, 'graph' => true, 'ds' => true, 'link' => true),
	'rfscoped'  => array('a' => true, 'c' => false, 'graph' => true, 'ds' => false, 'link' => true),
	'rfrevoked' => array('a' => false, 'c' => false, 'graph' => false, 'ds' => false, 'link' => false),
	'rfnorealm' => array('a' => false, 'c' => false, 'graph' => true, 'ds' => false, 'link' => true),
);

foreach ($expect as $user => $want) {
	$jar = dp_login($user);

	list(, $page) = dp_http($base . 'redfish_health.php', $jar);
	dp_expect((strpos($page, 'dp-bmc-a') !== false) === $want['a'], "$user " . ($want['a'] ? 'sees' : 'does not see') . ' the BMC on device A on the health page');
	dp_expect((strpos($page, 'dp-bmc-c') !== false) === $want['c'], "$user " . ($want['c'] ? 'sees' : 'does not see') . ' the unmapped BMC on the health page');

	list($url, $body) = dp_http($root . 'graph.php?action=view&rra_id=all&local_graph_id=' . $graph, $jar);
	$shown = strpos($url, 'permission_denied') === false && strpos($body, "id='wrapper_" . $graph . "'") !== false;
	dp_expect($shown === $want['graph'], "$user " . ($want['graph'] ? 'may' : 'may not') . ' view a graph of device A');
	dp_expect((strpos($body, 'graph_view.php?action=preview&amp;reset=1&amp;host_id=' . $hostA . '&amp;rfilter=Redfish') !== false) === $want['link'], "$user " . ($want['link'] ? 'gets' : 'does not get') . ' the graph link to the device\'s Redfish graphs');

	list($url, $body) = dp_http($root . 'data_sources.php?action=ds_edit&id=' . $ds, $jar);
	$shown = strpos($url, 'permission_denied') === false && strpos($body, 'Node Summary') !== false;
	dp_expect($shown === $want['ds'], "$user " . ($want['ds'] ? 'may' : 'may not') . ' edit a data source of device A');

	if (preg_match('/(Fatal error|Parse error|Uncaught)/', $page . $body) === 1) {
		dp_expect(false, "$user pages render without PHP errors");
	}

	unlink($jar);
}

// A plugin administrator whom Cacti grants only device B, and neither
// device management nor template import.
$devices = function () {
	return (int) db_fetch_cell('SELECT COUNT(*) FROM host') . '/' . (int) db_fetch_cell('SELECT COUNT(*) FROM graph_local') . '/' . (string) db_fetch_cell("SELECT value FROM settings WHERE name = 'redfish_templates_version'");
};
$before = $devices();
$jar    = dp_login('rfadmin2');

list(, $list) = dp_http($base . 'redfish_bmcs.php', $jar);
dp_expect(strpos($list, 'dp-bmc-a') === false && strpos($list, 'dp-bmc-b') !== false && strpos($list, 'dp-bmc-c') !== false, 'a restricted administrator lists only BMCs on allowed devices, and unmapped ones');

list(, $plan) = dp_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => dp_token($list), 'action' => 'provision', 'id' => $made['a']));
dp_expect(strpos($plan, 'dp-bmc-a') === false && strpos($plan, 'Confirming adds') === false, 'a restricted administrator gets no plan for a BMC on a denied device');

list(, $plan) = dp_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => dp_token($list), 'action' => 'provision', 'id' => $made['c']));
dp_expect(strpos($plan, 'device management permission') !== false && strpos($plan, 'Confirming adds') === false, 'without Cacti\'s device management permission the plan is refused with the reason');

foreach (array(array('provision_confirm', $made['a']), array('provision_confirm', $made['b']), array('provision_confirm', $made['c']), array('templates', 0)) as $post) {
	dp_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => dp_token($list), 'action' => $post[0], 'id' => $post[1]));
}

dp_http($base . 'redfish_bmcs.php?action=provision_confirm&id=' . $made['c'], $jar);
unlink($jar);

$jar = dp_login('admin');
list(, $list) = dp_http($base . 'redfish_bmcs.php', $jar);
dp_http($base . 'redfish_bmcs.php?action=provision_confirm&id=' . $made['c'], $jar);
dp_http($base . 'redfish_bmcs.php', $jar, array('action' => 'provision_confirm', 'id' => $made['c']));
dp_expect($devices() === $before && $bmcs->find($made['c'])->hostId === 0, 'no refused action, GET, or POST without the token created a device or graph or reinstalled the templates: ' . $before . ' then ' . $devices());

// Control: the admin's confirmed POST creates the device. Cacti renews
// the session after a rejected token, so the form is fetched again.
list(, $list) = dp_http($base . 'redfish_bmcs.php', $jar);
list(, $done) = dp_http($base . 'redfish_bmcs.php', $jar, array('__csrf_magic' => dp_token($list), 'action' => 'provision_confirm', 'id' => $made['c']));
$hostC = $bmcs->find($made['c'])->hostId;
dp_expect($hostC > 0 && strpos($done, 'The device was created') !== false, 'the same confirmation from an administrator with device management creates the device');
unlink($jar);

// A device permission revoked between two requests applies to the next one.
$scoped = (int) db_fetch_cell("SELECT id FROM user_auth WHERE username = 'rfscoped'");
$jar    = dp_login('rfscoped');
db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($scoped));
list(, $page) = dp_http($base . 'redfish_health.php', $jar);
dp_expect(strpos($page, 'dp-bmc-a') === false, 'a device permission revoked during a session hides the BMC on the next request');
unlink($jar);

// The BMCs go; their devices and graphs stay, as a deleted BMC leaves them.
foreach ($made as $id) {
	$bmcs->delete($id, 1);
}

db_execute_prepared('DELETE FROM plugin_redfish_credential WHERE id = ?', array($credential));

exit($failures > 0 ? 1 : 0);
