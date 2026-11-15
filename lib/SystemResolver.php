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
 * The system resolver: IPv4 through gethostbynamel(), which honours
 * /etc/hosts, then IPv6 from DNS. An IP literal is returned as it is.
 */
final class SystemResolver implements Resolver {
	public function resolve(string $host): array {
		if (Address::isIp($host)) {
			return [$host];
		}

		$addresses = @gethostbynamel($host);
		$addresses = $addresses === false ? [] : $addresses;

		if ($addresses === []) {
			$records = @dns_get_record($host, DNS_AAAA);

			foreach ($records === false ? [] : $records as $record) {
				if (isset($record['ipv6']) && is_string($record['ipv6'])) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		return $addresses;
	}
}
