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
 * The transport and client against the fake BMC over real TLS (REQ-TEST-002,
 * issue #37). The name bmc.test resolves to 127.0.0.1 through a test
 * resolver and the loopback test binding; nothing leaves the test host.
 */

use Somethingwithproof\Redfish\Bmc;
use Somethingwithproof\Redfish\ClientError;
use Somethingwithproof\Redfish\ClientFactory;
use Somethingwithproof\Redfish\Discoverer;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\Redactor;
use Somethingwithproof\Redfish\RedfishClient;
use Somethingwithproof\Redfish\Resolver;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\SocketTransport;
use Somethingwithproof\Redfish\SystemClock;
use Somethingwithproof\Redfish\TlsMode;

function redfish_fake_client(FakeBmc $bmc, string $mode = 'ca_bundle', string $pin = '', float $total = 3.0, ?Resolver $resolver = null): RedfishClient {
	$row = Bmc::fromRow(['id' => '1', 'name' => 'fake', 'address' => 'bmc.test', 'port' => (string) $bmc->port, 'credential_id' => '1', 'tls_mode' => $mode, 'ca_file' => FakeBmc::certs() . '/ca.pem', 'pin_sha256' => $pin, 'admin_state' => 'enabled']);

	return (new ClientFactory($resolver ?? FakeBmc::resolver(), 2.0, $total, 1048576, true))->client($row, new Redactor(), new SystemClock());
}

function redfish_fake_class(callable $call): ?FailureClass {
	try {
		$call();
	} catch (ClientError $e) {
		return $e->class;
	}

	return null;
}

function redfish_login(RedfishClient $client): void {
	$client->guard()->allowSessions('/redfish/v1/SessionService/Sessions');
	$client->login(FakeBmc::USER, new Secret(FakeBmc::PASSWORD));
}

it('reads the service root over verified TLS with the registered name', function () {
	$bmc  = new FakeBmc();
	$root = redfish_fake_client($bmc)->serviceRoot();

	expect($root['Vendor'])->toBe('Dell');
});

it('follows bounded same-origin redirects and refuses the rest', function () {
	$bmc    = new FakeBmc();
	$client = redfish_fake_client($bmc);

	expect($client->get('/redfish/v1/_test/redirect')['Vendor'])->toBe('Dell')
		->and($client->get('/redfish/v1/_test/redirect-absolute')['Vendor'])->toBe('Dell')
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/redirect-loop')))->toBe(FailureClass::RedirectRefused)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/redirect-cross')))->toBe(FailureClass::RedirectRefused)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/redirect-http')))->toBe(FailureClass::RedirectRefused)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/redirect-outside')))->toBe(FailureClass::RedirectRefused);

	$paths = array_column($bmc->state()['log'], 'path');

	expect(array_count_values($paths)['/redfish/v1/_test/redirect-loop'])->toBe(4)
		->and($paths)->not->toContain('/login.html');
});

it('caps body size, JSON depth, and time', function () {
	$bmc    = new FakeBmc();
	$client = redfish_fake_client($bmc, total: 2.0);
	$start  = microtime(true);

	expect(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/big')))->toBe(FailureClass::TooLarge)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/big-chunked')))->toBe(FailureClass::TooLarge)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/deep')))->toBe(FailureClass::JsonDepth)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/truncated')))->toBe(FailureClass::Malformed)
		->and(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/list')))->toBe(FailureClass::Malformed);

	$slow = microtime(true);

	expect(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/slow')))->toBe(FailureClass::Timeout)
		->and(microtime(true) - $slow)->toBeLessThan(3.5);

	$trickle = microtime(true);

	expect(redfish_fake_class(fn () => redfish_fake_client($bmc, total: 2.0)->get('/redfish/v1/_test/trickle')))->toBe(FailureClass::Timeout)
		->and(microtime(true) - $trickle)->toBeLessThan(3.5);
});

it('reports 429 and 503 with their Retry-After, and 401 as rejected', function () {
	$bmc    = new FakeBmc();
	$client = redfish_fake_client($bmc);

	try {
		$client->get('/redfish/v1/_test/throttle');
	} catch (ClientError $e) {
		expect($e->class)->toBe(FailureClass::Throttled)->and($e->retryAfter)->toBe(120);
	}

	try {
		$client->get('/redfish/v1/_test/unavailable');
	} catch (ClientError $e) {
		expect($e->class)->toBe(FailureClass::ServiceUnavailable)->and($e->retryAfter)->toBe(3600);
	}

	expect(redfish_fake_class(fn () => $client->get('/redfish/v1/_test/unauthorized')))->toBe(FailureClass::AuthRejected)
		->and(count($bmc->state()['log']))->toBe(3);
});

it('ignores links to another host and never resolves it', function () {
	$bmc      = new FakeBmc('legacy');
	$resolver = FakeBmc::resolver();
	$client   = redfish_fake_client($bmc, resolver: $resolver);
	redfish_login($client);
	$disc = new Discoverer($client, new Endpoint('bmc.test', $bmc->port, TlsMode::CaBundle));
	$plan = $disc->discover($client->serviceRoot());

	expect($plan->linksRefused)->toBe(2)
		->and(count($plan->chassis))->toBe(1)
		->and(array_unique($resolver->asked))->toBe(['bmc.test'])
		->and(implode(' ', array_column($bmc->state()['log'], 'path')))->not->toContain('Chassis/2');

	expect($client->logout())->toBeTrue();
});

it('deletes the session a crashed run recorded, leaving other sessions of the user alone', function () {
	$bmc     = new FakeBmc('dell-subsystem', 'good', ['sessions' => ['40' => ['token' => 'operator-console', 'user' => FakeBmc::USER]], 'next' => 41]);
	$crashed = redfish_fake_client($bmc);
	redfish_login($crashed);
	$recorded = $crashed->session()?->path;

	// The crashed run never logs out; the next run deletes what it recorded.
	$next = redfish_fake_client($bmc);
	redfish_login($next);
	$next->guard()->grantDelete((string) $recorded);

	expect($recorded)->toBe('/redfish/v1/SessionService/Sessions/41')
		->and($next->deleteSession((string) $recorded))->toBeTrue()
		->and($next->logout())->toBeTrue()
		->and(array_keys($bmc->state()['sessions']))->toBe([40]);
});

it('reports a session POST that lost its response as possibly sent', function () {
	$bmc = new FakeBmc('dell-subsystem', 'good', ['flags' => ['lose_login' => true]]);
	$client = redfish_fake_client($bmc);
	$client->guard()->allowSessions('/redfish/v1/SessionService/Sessions');

	try {
		$client->login(FakeBmc::USER, new Secret(FakeBmc::PASSWORD));
		$e = null;
	} catch (ClientError $e) {
	}

	expect($e)->not->toBeNull()
		->and($e->sent)->toBeTrue()
		->and($e->status)->toBe(0)
		->and($bmc->state()['sessions'])->toHaveCount(1);
});

it('classifies TLS failures as chain, name, expiry, pin, and protocol', function (string $cert, string $mode, bool $pinGood, bool $old, ?FailureClass $expected) {
	$bmc  = new FakeBmc('dell-subsystem', $cert, [], $old);
	$good = SocketTransport::spkiPin(openssl_x509_read((string) file_get_contents(FakeBmc::certs() . '/good.pem')));

	expect(redfish_fake_class(fn () => redfish_fake_client($bmc, $mode, $pinGood ? (string) $good : base64_encode(str_repeat("\1", 32)))->serviceRoot()))->toBe($expected);
})->with([
	'trusted'            => ['good', 'ca_bundle', true, false, null],
	'unknown CA'         => ['selfsigned', 'ca_bundle', true, false, FailureClass::TlsChain],
	'system store'       => ['good', 'system', true, false, FailureClass::TlsChain],
	'wrong name'         => ['wrongname', 'ca_bundle', true, false, FailureClass::TlsName],
	'expired'            => ['expired', 'ca_bundle', true, false, FailureClass::TlsExpired],
	'pinned key'         => ['good', 'pin', true, false, null],
	'pin changed'        => ['other-key', 'pin', true, false, FailureClass::TlsPin],
	'pin wrong'          => ['good', 'pin', false, false, FailureClass::TlsPin],
	'TLS 1.1 only'       => ['good', 'ca_bundle', true, true, FailureClass::TlsProtocol],
	'opted out'          => ['selfsigned', 'unverified', true, false, null],
]);

it('refuses a CA bundle that fails the trusted-file checks before any connection', function (string $case) {
	$dir = realpath(sys_get_temp_dir()) . '/redfish-ca-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700);
	mkdir($dir . '/real', 0700);
	symlink($dir . '/real', $dir . '/alias');
	$pem = (string) file_get_contents(FakeBmc::certs() . '/ca.pem');

	$path = match ($case) {
		'symlinked parent' => (function () use ($dir, $pem) { file_put_contents($dir . '/real/ca.pem', $pem); return $dir . '/alias/ca.pem'; })(),
		'group writable'   => (function () use ($dir, $pem) { file_put_contents($dir . '/real/ca.pem', $pem); chmod($dir . '/real/ca.pem', 0664); return $dir . '/real/ca.pem'; })(),
		'open directory'   => (function () use ($dir, $pem) { chmod($dir . '/real', 0777); file_put_contents($dir . '/real/ca.pem', $pem); chmod($dir . '/real/ca.pem', 0644); return $dir . '/real/ca.pem'; })(),
		'no certificate'   => (function () use ($dir) { file_put_contents($dir . '/real/ca.pem', "not a certificate\n"); chmod($dir . '/real/ca.pem', 0644); return $dir . '/real/ca.pem'; })(),
	};

	// The resolver would fail the test if reached: the check comes first.
	$resolver = new class () implements Resolver {
		public function resolve(string $host): array {
			throw new \LogicException('resolved before the CA bundle was checked');
		}
	};

	try {
		$class = null;

		try {
			(new SocketTransport(new Endpoint('bmc.test', 443, TlsMode::CaBundle, $path), $resolver, 1.0, 2.0, 1048576, true))->send(new \Somethingwithproof\Redfish\HttpRequest('GET', '/redfish/v1/'));
		} catch (\Somethingwithproof\Redfish\TransportError $e) {
			$class = $e->class;
		}

		expect($class)->toBe(FailureClass::TlsConfig);
	} finally {
		chmod($dir . '/real', 0700);
		@unlink($dir . '/real/ca.pem');
		unlink($dir . '/alias');
		rmdir($dir . '/real');
		rmdir($dir);
	}
})->with(['symlinked parent', 'group writable', 'open directory', 'no certificate']);
