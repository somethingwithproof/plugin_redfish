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
 * BMC address rules (REQ-BMC-002, REQ-BMC-003).
 *
 * An address is a host name, an IPv4 address in dotted-quad form, or an IPv6
 * address, with nothing else: no scheme, port, path, user information, or
 * query. The collector connects to whatever is registered, so the same
 * address rules run again on every resolved address at collection time,
 * which stops a name that later resolves to loopback (DNS rebinding).
 */
final class Address {
	/**
	 * The canonical form of an acceptable address, or null. Host names are
	 * lower-cased without a trailing dot; IPv6 addresses lose their brackets
	 * and take inet_ntop()'s compressed form, so one BMC cannot be entered
	 * twice under two spellings (REQ-BMC-005).
	 */
	public static function normalize(string $input): ?string {
		$address = strtolower(trim($input));

		if ($address === '' || strlen($address) > 255 || preg_match('/[^a-z0-9.:\[\]-]/', $address) === 1) {
			return null;
		}

		if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
			$address = substr($address, 1, -1);

			if (!str_contains($address, ':')) {
				return null;
			}
		}

		if (str_contains($address, ':')) {
			$packed = @inet_pton($address);

			if ($packed === false || strlen($packed) !== 16) {
				return null;
			}

			$canonical = (string) inet_ntop($packed);

			return self::ipRefused($canonical) ? null : $canonical;
		}

		if (preg_match('/^[0-9.]+$/', $address) === 1) {
			// Only plain dotted quads: 0x7f.1, 017.0.0.1, and 2130706433 are
			// read as loopback by some resolvers and never as written here.
			if (preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/', $address) !== 1) {
				return null;
			}

			return self::ipRefused($address) ? null : $address;
		}

		$address = rtrim($address, '.');
		$labels  = explode('.', $address);

		if (strlen($address) > 253 || $address === 'localhost' || str_ends_with($address, '.localhost')) {
			return null;
		}

		foreach ($labels as $label) {
			if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
				return null;
			}
		}

		// An all-digit last label makes the name look like an address to
		// some resolvers (for example 127.1).
		return ctype_digit((string) end($labels)) ? null : $address;
	}

	public static function isIp(string $address): bool {
		return filter_var($address, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Loopback, link-local, multicast, unspecified, "this network", and the
	 * limited broadcast address, in IPv4 or IPv6 form, including IPv4
	 * addresses embedded in IPv6 (::ffff:127.0.0.1). Anything that is not an
	 * IP address at all is refused too.
	 */
	public static function ipRefused(string $ip): bool {
		$packed = @inet_pton($ip);

		if ($packed === false) {
			return true;
		}

		if (strlen($packed) === 16) {
			$embedded = self::embeddedIpv4($packed);

			if ($embedded !== null) {
				$packed = $embedded;
			}
		}

		if (strlen($packed) === 4) {
			$first = ord($packed[0]);

			return $first === 0 || $first === 127 || ($first >= 224 && $first <= 239) || $packed === "\xff\xff\xff\xff"
				|| ($first === 169 && ord($packed[1]) === 254);
		}

		$first = ord($packed[0]);

		return $packed === str_repeat("\0", 16)
			|| $packed === str_repeat("\0", 15) . "\1"
			|| $first === 0xff
			|| ($first === 0xfe && (ord($packed[1]) & 0xc0) === 0x80);
	}

	/**
	 * The IPv4 address in an IPv4-mapped (::ffff:a.b.c.d) or IPv4-compatible
	 * (::a.b.c.d) IPv6 address.
	 */
	private static function embeddedIpv4(string $packed): ?string {
		$prefix = substr($packed, 0, 12);

		if ($prefix === str_repeat("\0", 10) . "\xff\xff") {
			return substr($packed, 12);
		}

		// ::, ::1 and the rest of ::/96 below 0.0.1.0 are not addresses.
		if ($prefix === str_repeat("\0", 12) && substr($packed, 12, 3) !== "\0\0\0") {
			return substr($packed, 12);
		}

		return null;
	}

	/**
	 * The host part of a URL for an address: IPv6 in brackets.
	 */
	public static function urlHost(string $address): string {
		return str_contains($address, ':') ? '[' . $address . ']' : $address;
	}
}
