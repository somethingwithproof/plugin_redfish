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
 * A fake Redfish BMC for tests. Nothing here talks to a real BMC.
 *
 *   php server.php <port> <pem> <fixture.json> <state.json> [tls1.1]
 *
 * Serves the hand-written fixture over TLS, one connection at a time, with
 * a session service and fault routes under /redfish/v1/_test/. The account
 * comes from REDFISH_FAKE_USER and REDFISH_FAKE_PASSWORD in the environment,
 * never the command line. Tests steer it through flags in the state file,
 * which also records each request (method, path, whether it carried
 * credentials) and the open sessions. The 600 second cap only stops an
 * orphan left by a crashed run.
 */

$port    = (int) ($argv[1] ?? 0);
$pem     = (string) ($argv[2] ?? '');
$fixture = json_decode((string) file_get_contents((string) ($argv[3] ?? '')), true);
$state   = (string) ($argv[4] ?? '');
$old     = ($argv[5] ?? '') === 'tls1.1';
$user    = (string) getenv('REDFISH_FAKE_USER');
$pass    = (string) getenv('REDFISH_FAKE_PASSWORD');

if (!is_array($fixture) || $state === '' || $user === '' || $pass === '') {
	fwrite(STDERR, "usage: REDFISH_FAKE_USER=.. REDFISH_FAKE_PASSWORD=.. php server.php <port> <pem> <fixture.json> <state.json> [tls1.1]\n");
	exit(2);
}

$ssl = array('local_cert' => $pem);

if ($old) {
	$ssl['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
	$ssl['ciphers']       = 'DEFAULT@SECLEVEL=0';
	$ssl['security_level'] = 0;
}

$server = stream_socket_server('tls://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(array('ssl' => $ssl)));

if ($server === false) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}

function fake_state(string $file): array {
	$s = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

	return (is_array($s) ? $s : array()) + array('flags' => array(), 'sessions' => array(), 'log' => array(), 'next' => 1);
}

function fake_save(string $file, array $s): void {
	file_put_contents($file . '.tmp', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	rename($file . '.tmp', $file);
}

function fake_reply($conn, int $status, string $body = '', array $headers = array()): void {
	$reason = array(200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved', 302 => 'Found', 307 => 'Temporary Redirect', 401 => 'Unauthorized', 404 => 'Not Found', 405 => 'Method Not Allowed', 429 => 'Too Many Requests', 503 => 'Service Unavailable');
	$head   = 'HTTP/1.1 ' . $status . ' ' . ($reason[$status] ?? 'Status') . "\r\nConnection: close\r\n";

	if ($body !== '' && !isset($headers['Content-Type'])) {
		$headers['Content-Type'] = 'application/json';
	}

	if (!isset($headers['Content-Length'])) {
		$headers['Content-Length'] = (string) strlen($body);
	}

	foreach ($headers as $name => $value) {
		$head .= "$name: $value\r\n";
	}

	@fwrite($conn, $head . "\r\n" . $body);
}

function fake_json(array $value): string {
	return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
}

$deadline = time() + 600;

while (time() < $deadline) {
	// A client that refuses the certificate aborts the handshake and
	// accept() returns false; keep serving.
	$conn = @stream_socket_accept($server, 1);

	if ($conn === false) {
		continue;
	}

	stream_set_timeout($conn, 5);

	$head = '';

	while (!str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
		$line = fgets($conn, 8192);

		if ($line === false) {
			break;
		}

		$head .= $line;
	}

	if ($head === '') {
		fclose($conn);
		continue;
	}

	[$requestLine] = explode("\r\n", $head, 2);
	[$method, $path] = explode(' ', $requestLine) + array('', '', '');
	$headers = array();

	foreach (array_slice(explode("\r\n", trim($head)), 1) as $h) {
		[$name, $value] = explode(':', $h, 2) + array('', '');
		$headers[strtolower(trim($name))] = trim($value);
	}

	$body   = '';
	$length = (int) ($headers['content-length'] ?? 0);

	while (strlen($body) < $length && !feof($conn)) {
		$body .= (string) fread($conn, $length - strlen($body));
	}

	$s     = fake_state($state);
	$flags = $s['flags'];
	$token = $headers['x-auth-token'] ?? '';
	$authed = false;

	foreach ($s['sessions'] as $id => $session) {
		if ($token !== '' && hash_equals((string) $session['token'], $token)) {
			$authed = true;
		}
	}

	if (!empty($flags['basic']) && isset($headers['authorization']) && hash_equals('Basic ' . base64_encode($user . ':' . $pass), $headers['authorization'])) {
		$authed = true;
	}

	$s['log'][] = array('method' => $method, 'path' => $path, 'auth' => $token !== '' ? 'token' : (isset($headers['authorization']) ? 'basic' : ''));
	$collection = '/redfish/v1/SessionService/Sessions';

	// Fault routes.
	if (str_starts_with($path, '/redfish/v1/_test/')) {
		fake_save($state, $s);

		switch (substr($path, strlen('/redfish/v1/_test/'))) {
			case 'redirect':
				fake_reply($conn, 302, '', array('Location' => '/redfish/v1/'));
				break;
			case 'redirect-absolute':
				fake_reply($conn, 307, '', array('Location' => 'https://bmc.test:' . $port . '/redfish/v1/'));
				break;
			case 'redirect-loop':
				fake_reply($conn, 302, '', array('Location' => '/redfish/v1/_test/redirect-loop'));
				break;
			case 'redirect-cross':
				fake_reply($conn, 302, '', array('Location' => 'https://other.test:' . $port . '/redfish/v1/'));
				break;
			case 'redirect-http':
				fake_reply($conn, 301, '', array('Location' => 'http://bmc.test:' . $port . '/redfish/v1/'));
				break;
			case 'redirect-outside':
				fake_reply($conn, 302, '', array('Location' => '/login.html'));
				break;
			case 'big':
				fake_reply($conn, 200, '{"x":"' . str_repeat('a', 3 * 1048576) . '"}');
				break;
			case 'big-chunked':
				@fwrite($conn, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
				for ($i = 0; $i < 64; $i++) {
					if (@fwrite($conn, "10000\r\n" . str_repeat('a', 65536) . "\r\n") === false) {
						break;
					}
				}
				@fwrite($conn, "0\r\n\r\n");
				break;
			case 'deep':
				fake_reply($conn, 200, str_repeat('{"a":', 64) . '1' . str_repeat('}', 64));
				break;
			case 'slow':
				sleep(6);
				fake_reply($conn, 200, '{"Id":"slow"}');
				break;
			case 'trickle':
				@fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 200\r\n\r\n");
				for ($i = 0; $i < 200; $i++) {
					if (@fwrite($conn, 'a') === false) {
						break;
					}
					usleep(100000);
				}
				break;
			case 'truncated':
				@fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n{\"Id\":");
				break;
			case 'throttle':
				fake_reply($conn, 429, '{}', array('Retry-After' => '120'));
				break;
			case 'unavailable':
				fake_reply($conn, 503, '{}', array('Retry-After' => gmdate('D, d M Y H:i:s', time() + 7200) . ' GMT'));
				break;
			case 'unauthorized':
				fake_reply($conn, 401, '{}');
				break;
			case 'list':
				fake_reply($conn, 200, '[1,2,3]');
				break;
			default:
				fake_reply($conn, 404, '{}');
		}

		fclose($conn);
		continue;
	}

	if ($method === 'POST' && $path === $collection) {
		$login = json_decode($body, true);
		$ok    = is_array($login) && ($login['UserName'] ?? null) === $user && ($login['Password'] ?? null) === $pass;

		if (!empty($flags['sessions_off'])) {
			fake_save($state, $s);
			fake_reply($conn, 405, '{}');
		} elseif (!empty($flags['throttle_login'])) {
			fake_save($state, $s);
			fake_reply($conn, 503, '{}', array('Retry-After' => '300'));
		} elseif (!$ok) {
			fake_save($state, $s);
			fake_reply($conn, 401, '{}');
		} else {
			$id = (string) $s['next']++;
			$s['sessions'][$id] = array('token' => bin2hex(random_bytes(16)), 'user' => $user);
			fake_save($state, $s);

			// The session exists, but the client never hears about it.
			if (!empty($flags['lose_login'])) {
				fclose($conn);
				continue;
			}

			fake_reply($conn, 201, fake_json(array('@odata.id' => "$collection/$id", 'Id' => $id, 'UserName' => $user)), array('X-Auth-Token' => $s['sessions'][$id]['token'], 'Location' => "$collection/$id"));
		}

		fclose($conn);
		continue;
	}

	if ($path === '/redfish/v1/' || $path === '/redfish/v1') {
		fake_save($state, $s);
		fake_reply($conn, 200, fake_json($fixture['/redfish/v1/']));
		fclose($conn);
		continue;
	}

	if (!$authed) {
		fake_save($state, $s);
		fake_reply($conn, 401, '{}');
		fclose($conn);
		continue;
	}

	if (str_starts_with($path, $collection . '/') && $method === 'DELETE') {
		$id = substr($path, strlen($collection) + 1);
		$found = isset($s['sessions'][$id]);
		unset($s['sessions'][$id]);
		fake_save($state, $s);
		fake_reply($conn, $found ? 204 : 404);
		fclose($conn);
		continue;
	}

	if ($method !== 'GET') {
		fake_save($state, $s);
		fake_reply($conn, 405, '{}');
		fclose($conn);
		continue;
	}

	if (!empty($flags['throttle'])) {
		fake_save($state, $s);
		fake_reply($conn, 429, '{}', array('Retry-After' => '120'));
		fclose($conn);
		continue;
	}

	if ($path === $collection) {
		$members = array();

		foreach (array_keys($s['sessions']) as $id) {
			$members[] = array('@odata.id' => "$collection/$id");
		}

		fake_save($state, $s);
		fake_reply($conn, 200, fake_json(array('@odata.id' => $collection, 'Members' => $members, 'Members@odata.count' => count($members))));
		fclose($conn);
		continue;
	}

	if (str_starts_with($path, $collection . '/')) {
		$id = substr($path, strlen($collection) + 1);
		fake_save($state, $s);

		if (isset($s['sessions'][$id])) {
			fake_reply($conn, 200, fake_json(array('@odata.id' => $path, '@odata.type' => '#Session.v1_7_0.Session', 'Id' => (string) $id, 'UserName' => $s['sessions'][$id]['user'])));
		} else {
			fake_reply($conn, 404, '{}');
		}

		fclose($conn);
		continue;
	}

	fake_save($state, $s);
	$resource = $fixture[$path] ?? $fixture[rtrim($path, '/')] ?? null;

	if (is_array($resource)) {
		fake_reply($conn, 200, fake_json($resource));
	} else {
		fake_reply($conn, 404, '{}');
	}

	fclose($conn);
}
