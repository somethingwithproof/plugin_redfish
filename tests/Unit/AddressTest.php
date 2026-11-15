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

use Somethingwithproof\Redfish\Address;
use Somethingwithproof\Redfish\Endpoint;
use Somethingwithproof\Redfish\FailureClass;
use Somethingwithproof\Redfish\HttpRequest;
use Somethingwithproof\Redfish\Resolver;
use Somethingwithproof\Redfish\SocketTransport;
use Somethingwithproof\Redfish\TlsMode;
use Somethingwithproof\Redfish\TransportError;

it('accepts host names and IP addresses in canonical form', function (string $input, string $canonical) {
	expect(Address::normalize($input))->toBe($canonical);
})->with([
	['10.0.0.5', '10.0.0.5'],
	['bmc01.example.net', 'bmc01.example.net'],
	['BMC01.Example.NET.', 'bmc01.example.net'],
	['2001:db8::10', '2001:db8::10'],
	['[2001:DB8:0:0::10]', '2001:db8::10'],
	['::ffff:10.1.2.3', '::ffff:10.1.2.3'],
	['  idrac-r660.lab.example  ', 'idrac-r660.lab.example'],
]);

it('refuses schemes, ports, paths, user information, queries, and unsafe addresses', function (string $input) {
	expect(Address::normalize($input))->toBeNull();
})->with([
	'https://bmc', 'bmc/redfish', 'user@bmc', 'user:pw@bmc', 'bmc:443', '10.0.0.1:443', '[2001:db8::1]:443', 'bmc?x=1', 'bmc#frag',
	'127.0.0.1', '127.1', '2130706433', '0x7f.0.0.1', '017.0.0.1', 'localhost', 'x.localhost',
	'::1', '[::1]', '::ffff:127.0.0.1', '::127.0.0.1', '169.254.169.254', 'fe80::1', '[fe80::1%eth0]',
	'224.0.0.1', 'ff02::1', '0.0.0.0', '::', '255.255.255.255', '-bad.example', 'a..b', '', str_repeat('a', 64) . '.example',
]);

it('refuses an address that resolves to a rejected one at collection time, before connecting', function (array $answer) {
	$resolver = new class($answer) implements Resolver {
		public function __construct(private array $answer) {
		}

		public function resolve(string $host): array {
			return $this->answer;
		}
	};

	$transport = new SocketTransport(new Endpoint('bmc.example.net', 443, TlsMode::System), $resolver);

	try {
		$transport->send(new HttpRequest('GET', '/redfish/v1/'));
		$class = null;
	} catch (TransportError $e) {
		$class = $e->class;
	}

	expect($class)->toBe($answer === [] ? FailureClass::DnsFailed : FailureClass::AddressRefused);
})->with([
	'loopback'           => [['127.0.0.1']],
	'mapped loopback'    => [['::ffff:127.0.0.1']],
	'metadata link-local'=> [['169.254.169.254']],
	'one bad of two'     => [['10.0.0.9', '127.0.0.1']],
	'multicast'          => [['239.1.1.1']],
	'nothing'            => [[]],
]);
