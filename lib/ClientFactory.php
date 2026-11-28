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
 * Builds one guarded client per BMC per run. $transportFor replaces the
 * socket transport in tests; the guard always wraps whatever it returns.
 */
final class ClientFactory {
	/**
	 * Max requests constant.
	 */
	public const MAX_REQUESTS = 256;

	/**
	 * Initialize a new ClientFactory instance.
	 *
	 * @param Resolver $resolver
	 * @param float $connectTimeout
	 * @param float $totalTimeout
	 * @param int $maxBytes
	 * @param bool $allowLoopback
	 * @param \Closure|null $transportFor
	 */
	public function __construct(
		private readonly Resolver $resolver,
		private readonly float $connectTimeout,
		private readonly float $totalTimeout,
		private readonly int $maxBytes,
		private readonly bool $allowLoopback = false,
		private readonly ?\Closure $transportFor = null,
	) {
	}

	/**
	 * Production.
	 *
	 * @param Settings $settings
	 * @return self
	 */
	public static function production(Settings $settings): self {
		return new self(new SystemResolver(), (float) $settings->connectTimeout, (float) $settings->requestTimeout, $settings->maxBodyBytes);
	}

	/**
	 * @param int $deadline      no request starts at or after this time
	 * @param int $closeDeadline the session DELETE may start until this time
	 */
	public function client(Bmc $bmc, Redactor $redactor, Clock $clock, int $deadline = PHP_INT_MAX, int $closeDeadline = PHP_INT_MAX): RedfishClient {
		$endpoint  = Endpoint::forBmc($bmc);
		$transport = $this->transportFor === null ? new SocketTransport($endpoint, $this->resolver, $this->connectTimeout, $this->totalTimeout, $this->maxBytes, $this->allowLoopback) : ($this->transportFor)($endpoint);

		if (!$transport instanceof HttpTransport) {
			throw new \LogicException('transport factory returned no transport');
		}

		return new RedfishClient(new GuardedTransport($transport, new OperationGuard(), self::MAX_REQUESTS, $clock, $deadline, $closeDeadline), $endpoint, $redactor, $clock);
	}
}
