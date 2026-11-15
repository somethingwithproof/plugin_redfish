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
 * How a BMC's certificate is trusted (REQ-TLS-002, ADR 0011). Every mode but
 * Unverified checks the peer before a request is sent; Unverified exists
 * only as an explicit per-BMC choice that is logged when set and shown as a
 * health warning while it stays (REQ-TLS-003). There is no global switch.
 */
enum TlsMode: string {
	case System     = 'system';
	case CaBundle   = 'ca_bundle';
	case Pin        = 'pin';
	case Unverified = 'unverified';

	public function label(): string {
		return match ($this) {
			self::System     => __('Verify against the system CA store', 'redfish'),
			self::CaBundle   => __('Verify against a CA bundle file', 'redfish'),
			self::Pin        => __('Pinned public key (SHA-256)', 'redfish'),
			self::Unverified => __('Do not verify (unsafe)', 'redfish'),
		};
	}
}
