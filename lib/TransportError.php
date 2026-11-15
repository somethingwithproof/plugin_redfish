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
 * A request the transport refused or could not complete. The class is fixed;
 * the detail is diagnostic text from the local side only, never a response
 * body, and is redacted before it is logged.
 *
 * $sent is true once every byte of the request was written: a session POST
 * that fails after that point may have created a session on the BMC.
 */
final class TransportError extends \RuntimeException {
	public function __construct(
		public readonly FailureClass $class,
		string $detail = '',
		public readonly bool $sent = false,
	) {
		parent::__construct($class->value . ($detail !== '' ? ': ' . $detail : ''));
	}
}
