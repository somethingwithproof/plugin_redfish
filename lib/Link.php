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
 * Links in BMC responses (REQ-RF-005). A link is followed only when it is a
 * path on the same BMC: a relative path, or an absolute https URL with the
 * registered host and port. Anything else, including a scheme-relative
 * //host link, is refused and counted, never fetched.
 */
final class Link {
	/**
	 * The path of an @odata.id or Location value, without its fragment, or
	 * null when it points anywhere but this BMC.
	 */
	public static function path(mixed $link, Endpoint $endpoint): ?string {
		if (!is_string($link) || $link === '' || strlen($link) > 1024 || preg_match('/[\x00-\x20\x7f]/', $link) === 1) {
			return null;
		}

		$hash = strpos($link, '#');

		if ($hash !== false) {
			$link = substr($link, 0, $hash);
		}

		if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
			$path = $link;
		} else {
			$parts = parse_url($link);

			if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
				|| !$endpoint->sameOrigin($parts['scheme'], $parts['host'], $parts['port'] ?? null)) {
				return null;
			}

			$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		}

		return OperationGuard::readablePath($path) ? $path : null;
	}

	/**
	 * The @odata.id of a JSON object member, or null.
	 *
	 * @param array<array-key, mixed> $object
	 */
	public static function of(array $object, string ...$keys): mixed {
		$value = $object;

		foreach ($keys as $key) {
			if (!is_array($value) || !array_key_exists($key, $value)) {
				return null;
			}

			$value = $value[$key];
		}

		return is_array($value) ? ($value['@odata.id'] ?? null) : null;
	}
}
