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

use Somethingwithproof\Redfish\ClientError;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\FixedClock;
use Somethingwithproof\Redfish\GuardedTransport;
use Somethingwithproof\Redfish\HttpRequest;
use Somethingwithproof\Redfish\HttpResponse;
use Somethingwithproof\Redfish\HttpTransport;
use Somethingwithproof\Redfish\Link;
use Somethingwithproof\Redfish\OperationGuard;
use Somethingwithproof\Redfish\Redactor;
use Somethingwithproof\Redfish\RedfishClient;
use Somethingwithproof\Redfish\Secret;
use Somethingwithproof\Redfish\TlsMode;
use Somethingwithproof\Redfish\TransportError;

const REDFISH_SESSIONS = '/redfish/v1/SessionService/Sessions';

function redfish_client(FixtureTransport $fake, int $max = 256): RedfishClient {
	return new RedfishClient(new GuardedTransport($fake, new OperationGuard(), $max), new Endpoint('bmc.test', 443, TlsMode::System), new Redactor(), new FixedClock(1_800_000_000));
}

function redfish_class(callable $call): ?FailureClass {
	try {
		$call();
	} catch (ClientError $e) {
		return $e->class;
	} catch (TransportError $e) {
		return $e->class;
	}

	return null;
}

it('refuses PATCH, PUT, other DELETEs, and action POSTs before any I/O', function (string $method, string $path) {
	$fake  = new FixtureTransport('dell-subsystem');
	$guard = new OperationGuard();
	$guard->allowSessions(REDFISH_SESSIONS);
	$guarded = new GuardedTransport($fake, $guard, 10);

	expect(redfish_class(fn () => $guarded->send(new HttpRequest($method, $path, [], '{}'))))->toBe(FailureClass::OperationRefused)
		->and($fake->requests)->toBe([]);
})->with([
	['PATCH', '/redfish/v1/Systems/System.Embedded.1'],
	['PUT', '/redfish/v1/Systems/System.Embedded.1'],
	['DELETE', '/redfish/v1/AccountService/Accounts/2'],
	['DELETE', REDFISH_SESSIONS],
	['DELETE', REDFISH_SESSIONS . '/7'],
	['POST', '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset'],
	['POST', '/redfish/v1/Managers/iDRAC.Embedded.1/Actions/Manager.Reset'],
	['POST', REDFISH_SESSIONS . '/'],
	['GET', '/redfish/v1/Chassis?$expand=*'],
	['GET', '/redfish/v1/../../etc/passwd'],
	['GET', '/redfish/v1/%2e%2e/x'],
	['GET', '/login.html'],
	['HEAD', '/redfish/v1/'],
	['OPTIONS', '/redfish/v1/'],
]);

it('allows the session POST only once the service root named the collection', function () {
	$fake    = new FixtureTransport('dell-subsystem');
	$guard   = new OperationGuard();
	$guarded = new GuardedTransport($fake, $guard, 10);

	expect(redfish_class(fn () => $guarded->send(new HttpRequest('POST', REDFISH_SESSIONS, [], '{}'))))->toBe(FailureClass::OperationRefused);

	$guard->allowSessions(REDFISH_SESSIONS);

	expect(redfish_class(fn () => $guard->allowSessions('/redfish/v1/Other')))->toBe(FailureClass::OperationRefused)
		->and($guarded->send(new HttpRequest('POST', REDFISH_SESSIONS, [], '{}'))->status)->toBe(201)
		->and(redfish_class(fn () => $guard->grantDelete(REDFISH_SESSIONS . '/1/x')))->toBe(FailureClass::OperationRefused)
		->and(redfish_class(fn () => $guard->grantDelete('/redfish/v1/AccountService/Accounts/1')))->toBe(FailureClass::OperationRefused);
});

it('logs in, keeps the token out of logs, and deletes its own session', function () {
	$fake   = new FixtureTransport('dell-subsystem');
	$client = redfish_client($fake);
	$client->guard()->allowSessions(REDFISH_SESSIONS);
	$session = $client->login('monitor', new Secret('SENTINEL-pw'));

	expect($client->authMode())->toBe('session')
		->and($session->id())->toBe('1')
		->and($client->get('/redfish/v1/Chassis')['Members'])->toHaveCount(1)
		->and($fake->requests[1]->headers['X-Auth-Token'])->toBe($session->token->reveal())
		->and($client->logout())->toBeTrue()
		->and($fake->sessions)->toBe([])
		->and($fake->log())->toBe(['POST ' . REDFISH_SESSIONS, 'GET /redfish/v1/Chassis', 'DELETE ' . REDFISH_SESSIONS . '/1']);
});

it('classifies session POST failures', function (int $status, FailureClass $class) {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['POST ' . REDFISH_SESSIONS] = new HttpResponse($status, ['retry-after' => '30'], '{}');
	$client = redfish_client($fake);
	$client->guard()->allowSessions(REDFISH_SESSIONS);

	expect(redfish_class(fn () => $client->login('monitor', new Secret('x'))))->toBe($class);
})->with([
	[401, FailureClass::AuthRejected],
	[403, FailureClass::AuthRejected],
	[404, FailureClass::SessionsUnavailable],
	[405, FailureClass::SessionsUnavailable],
	[501, FailureClass::SessionsUnavailable],
	[429, FailureClass::Throttled],
	[503, FailureClass::ServiceUnavailable],
	[500, FailureClass::ServerError],
	[302, FailureClass::RedirectRefused],
]);

it('treats a created session it cannot address as lost', function () {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['POST ' . REDFISH_SESSIONS] = new HttpResponse(201, ['x-auth-token' => 'abc', 'location' => 'https://elsewhere.test/redfish/v1/SessionService/Sessions/9'], '');
	$client = redfish_client($fake);
	$client->guard()->allowSessions(REDFISH_SESSIONS);

	expect(redfish_class(fn () => $client->login('monitor', new Secret('x'))))->toBe(FailureClass::SessionLost);
});

it('follows at most three same-origin redirects on a GET', function () {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['/redfish/v1/a'] = new HttpResponse(302, ['location' => '/redfish/v1/b'], '');
	$fake->override['/redfish/v1/b'] = new HttpResponse(301, ['location' => 'https://BMC.test/redfish/v1/c'], '');
	$fake->override['/redfish/v1/c'] = new HttpResponse(307, ['location' => 'https://bmc.test:443/redfish/v1/Chassis'], '');
	$fake->override['/redfish/v1/d'] = new HttpResponse(308, ['location' => '/redfish/v1/a'], '');

	expect(redfish_client($fake)->get('/redfish/v1/a')['Members'])->toHaveCount(1)
		->and(redfish_class(fn () => redfish_client($fake)->get('/redfish/v1/d')))->toBe(FailureClass::RedirectRefused);
});

it('refuses redirects off the BMC, to http, or outside /redfish/', function (string $location) {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['/redfish/v1/x'] = new HttpResponse(302, ['location' => $location], '');

	expect(redfish_class(fn () => redfish_client($fake)->get('/redfish/v1/x')))->toBe(FailureClass::RedirectRefused)
		->and($fake->log())->toBe(['GET /redfish/v1/x']);
})->with([
	'https://other.test/redfish/v1/',
	'https://bmc.test:8443/redfish/v1/',
	'http://bmc.test/redfish/v1/',
	'//other.test/redfish/v1/',
	'https://user:pw@bmc.test/redfish/v1/',
	'/login.html',
	'/redfish/v1/?$expand=*',
	'',
]);

it('reports 429 and 503 with a capped Retry-After and never retries in the run', function () {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['/redfish/v1/t'] = new HttpResponse(429, ['retry-after' => '120'], '');
	$fake->override['/redfish/v1/u'] = new HttpResponse(503, ['retry-after' => 'Fri, 15 Jan 2027 10:00:00 GMT'], '');
	$fake->override['/redfish/v1/v'] = new HttpResponse(503, ['retry-after' => '999999'], '');
	$client = redfish_client($fake);

	foreach (['t' => 120, 'u' => 3600, 'v' => 3600] as $path => $retry) {
		try {
			$client->get('/redfish/v1/' . $path);
		} catch (ClientError $e) {
			expect($e->retryAfter)->toBe($retry);
		}
	}

	expect($fake->log())->toBe(['GET /redfish/v1/t', 'GET /redfish/v1/u', 'GET /redfish/v1/v'])
		->and($client->retryAfter('nonsense'))->toBe(0)
		->and($client->retryAfter('Thu, 01 Jan 2026 00:00:00 GMT'))->toBe(0);
});

it('caps JSON depth and refuses bodies that are not objects', function () {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->override['/redfish/v1/deep'] = new HttpResponse(200, [], str_repeat('{"a":', 40) . '1' . str_repeat('}', 40));
	$fake->override['/redfish/v1/list'] = new HttpResponse(200, [], '[1,2]');
	$fake->override['/redfish/v1/bad']  = new HttpResponse(200, [], '{"a":');
	$client = redfish_client($fake);

	expect(redfish_class(fn () => $client->get('/redfish/v1/deep')))->toBe(FailureClass::JsonDepth)
		->and(redfish_class(fn () => $client->get('/redfish/v1/list')))->toBe(FailureClass::Malformed)
		->and(redfish_class(fn () => $client->get('/redfish/v1/bad')))->toBe(FailureClass::Malformed);
});

it('stops at the per-run request budget', function () {
	$client = redfish_client(new FixtureTransport('dell-subsystem'), 2);
	$client->get('/redfish/v1/');
	$client->get('/redfish/v1/Chassis');

	expect(redfish_class(fn () => $client->get('/redfish/v1/Chassis')))->toBe(FailureClass::RequestBudget);
});

it('lets one request through at a time', function () {
	$inner = new class implements HttpTransport {
		public ?GuardedTransport $outer = null;
		public ?FailureClass $nested = null;

		public function send(HttpRequest $request): HttpResponse {
			try {
				$this->outer?->send(new HttpRequest('GET', '/redfish/v1/'));
			} catch (TransportError $e) {
				$this->nested = $e->class;
			}

			return new HttpResponse(200, [], '{}');
		}
	};

	$inner->outer = new GuardedTransport($inner, new OperationGuard(), 10);
	$inner->outer->send(new HttpRequest('GET', '/redfish/v1/'));

	expect($inner->nested)->toBe(FailureClass::OperationRefused);
});

it('deletes only a recorded session of its own, never others of the same user', function () {
	$fake = new FixtureTransport('dell-subsystem');
	$fake->sessions = [REDFISH_SESSIONS . '/5' => 'recorded', REDFISH_SESSIONS . '/6' => 'someone else'];
	$client = redfish_client($fake);
	$client->guard()->allowSessions(REDFISH_SESSIONS);
	$client->login('monitor', new Secret('x'));
	$client->guard()->grantDelete(REDFISH_SESSIONS . '/5');

	expect($client->deleteSession(REDFISH_SESSIONS . '/5'))->toBeTrue()
		->and(redfish_class(fn () => (new GuardedTransport($fake, $client->guard(), 5))->send(new HttpRequest('DELETE', REDFISH_SESSIONS . '/6'))))->toBe(FailureClass::OperationRefused)
		->and(array_keys($fake->sessions))->toContain(REDFISH_SESSIONS . '/6')
		->and(implode(' ', $fake->log()))->not->toContain('GET ' . REDFISH_SESSIONS);
});

it('starts no request at or after the deadline, but may still close its session', function () {
	$fake  = new FixtureTransport('dell-subsystem');
	$clock = new FixedClock(1000);
	$guard = new OperationGuard();
	$guard->allowSessions(REDFISH_SESSIONS);
	$guard->grantDelete(REDFISH_SESSIONS . '/1');
	$t = new GuardedTransport($fake, $guard, 50, $clock, 1010, 1020);

	$t->send(new HttpRequest('GET', '/redfish/v1/'));
	$clock->advance(10);

	expect(redfish_class(fn () => $t->send(new HttpRequest('GET', '/redfish/v1/'))))->toBe(FailureClass::Deadline)
		->and(redfish_class(fn () => $t->send(new HttpRequest('POST', REDFISH_SESSIONS, [], '{}'))))->toBe(FailureClass::Deadline)
		->and(redfish_class(fn () => $t->send(new HttpRequest('DELETE', REDFISH_SESSIONS . '/1'))))->toBeNull();

	$clock->advance(10);

	expect(redfish_class(fn () => $t->send(new HttpRequest('DELETE', REDFISH_SESSIONS . '/1'))))->toBe(FailureClass::Deadline)
		->and($fake->log())->toBe(['GET /redfish/v1/', 'DELETE ' . REDFISH_SESSIONS . '/1']);
});

it('follows only links on the same BMC', function () {
	$e = new Endpoint('bmc.test', 443, TlsMode::System);

	expect(Link::path('/redfish/v1/Chassis/1', $e))->toBe('/redfish/v1/Chassis/1')
		->and(Link::path('/redfish/v1/Chassis/1/Thermal#/Fans/0', $e))->toBe('/redfish/v1/Chassis/1/Thermal')
		->and(Link::path('https://bmc.test/redfish/v1/Chassis/1', $e))->toBe('/redfish/v1/Chassis/1')
		->and(Link::path('https://elsewhere.test/redfish/v1/Chassis/1', $e))->toBeNull()
		->and(Link::path('//elsewhere.test/redfish/v1/Chassis/1', $e))->toBeNull()
		->and(Link::path('http://bmc.test/redfish/v1/Chassis/1', $e))->toBeNull()
		->and(Link::path('https://bmc.test:444/redfish/v1/', $e))->toBeNull()
		->and(Link::path('/other/path', $e))->toBeNull()
		->and(Link::path(['@odata.id' => '/redfish/v1/'], $e))->toBeNull()
		->and(Link::path('/redfish/v1/a b', $e))->toBeNull();
});
