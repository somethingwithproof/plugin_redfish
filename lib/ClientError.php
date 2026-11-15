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
 * A request the client could not complete, reduced to a failure class. It
 * never carries a response body. $retryAfter is the BMC's Retry-After in
 * seconds, already capped, or 0. $sent is true when a request may have
 * reached the BMC before the failure.
 */
final class ClientError extends \RuntimeException {
	public function __construct(
		public readonly FailureClass $class,
		public readonly int $retryAfter = 0,
		public readonly bool $sent = false,
		public readonly int $status = 0,
	) {
		parent::__construct($class->value . ($status > 0 ? ' (HTTP ' . $status . ')' : ''));
	}

	public static function fromTransport(TransportError $e): self {
		return new self($e->class, 0, $e->sent);
	}
}
