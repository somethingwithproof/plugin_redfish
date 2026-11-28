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
 * Where and how to reach one BMC: its registered address and port, and how
 * its certificate is trusted.
 */
final readonly class Endpoint {
	/**
	 * Initialize a new Endpoint instance.
	 *
	 * @param string $host
	 * @param int $port
	 * @param TlsMode $tlsMode
	 * @param string $caFile
	 * @param string $pin
	 */
	public function __construct(
		public string $host,
		public int $port,
		public TlsMode $tlsMode,
		public string $caFile = '',
		public string $pin = '',
	) {
	}

	/**
	 * For bmc.
	 *
	 * @param Bmc $bmc
	 * @return self
	 */
	public static function forBmc(Bmc $bmc): self {
		return new self($bmc->address, $bmc->port, $bmc->tlsMode, $bmc->caFile, $bmc->pin);
	}

	/**
	 * Whether an absolute URL from a response names this BMC: https, the
	 * same host (case-insensitive), and the same port.
	 */
	public function sameOrigin(string $scheme, string $host, ?int $port): bool {
		$host = strtolower(trim($host, '[]'));

		if (str_contains($host, ':')) {
			$packed = @inet_pton($host);
			$host   = $packed === false ? '' : (string) inet_ntop($packed);
		}

		return strtolower($scheme) === 'https' && $host === $this->host && ($port ?? 443) === $this->port;
	}
}
