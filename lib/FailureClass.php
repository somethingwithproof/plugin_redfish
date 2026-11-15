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
 * Every failure the client, transport, or collector reports is reduced to one
 * of these classes before it is stored or shown (REQ-HEALTH-001). BMC
 * response text never travels further than the client.
 */
enum FailureClass: string {
	case AuthRejected          = 'auth_rejected';
	case CredentialUnavailable = 'credential_unavailable';
	case SessionsUnavailable   = 'sessions_unavailable';
	case SessionLost           = 'session_lost';
	case SessionBudget         = 'session_budget';
	case NotRedfish            = 'not_redfish';
	case NoChassis             = 'no_chassis';
	case TlsChain              = 'tls_chain';
	case TlsName               = 'tls_name';
	case TlsExpired            = 'tls_expired';
	case TlsPin                = 'tls_pin';
	case TlsProtocol           = 'tls_protocol';
	case TlsConfig             = 'tls_config';
	case Timeout               = 'timeout';
	case ConnectRefused        = 'connect_refused';
	case DnsFailed             = 'dns_failed';
	case AddressRefused        = 'address_refused';
	case UrlRefused            = 'url_refused';
	case OperationRefused      = 'operation_refused';
	case RedirectRefused       = 'redirect_refused';
	case RequestBudget         = 'request_budget';
	case Deadline              = 'deadline';
	case Throttled             = 'throttled';
	case ServiceUnavailable    = 'service_unavailable';
	case ServerError           = 'server_error';
	case UnexpectedStatus      = 'unexpected_status';
	case NotFound              = 'not_found';
	case TooLarge              = 'too_large';
	case Malformed             = 'malformed';
	case JsonDepth             = 'json_depth';

	public function reachability(): Reachability {
		return match ($this) {
			self::AuthRejected => Reachability::AuthFailed,
			self::CredentialUnavailable, self::AddressRefused, self::UrlRefused, self::OperationRefused, self::RedirectRefused, self::RequestBudget, self::TlsConfig => Reachability::Config,
			self::SessionsUnavailable, self::NotRedfish, self::NoChassis => Reachability::Unsupported,
			self::TlsChain, self::TlsName, self::TlsExpired, self::TlsPin, self::TlsProtocol => Reachability::TlsFailed,
			self::Timeout, self::Deadline, self::SessionLost, self::SessionBudget => Reachability::TimedOut,
			self::ConnectRefused, self::DnsFailed => Reachability::Unreachable,
			self::Throttled, self::ServiceUnavailable, self::ServerError => Reachability::Unavailable,
			self::UnexpectedStatus, self::NotFound, self::TooLarge, self::Malformed, self::JsonDepth => Reachability::Malformed,
		};
	}

	/**
	 * A class that waits for an operator instead of backing off: retrying a
	 * rejected password can lock a shared BMC account, and a session budget
	 * that ran out needs someone to look at the BMC first.
	 */
	public function needsOperator(): bool {
		return $this === self::AuthRejected || $this === self::SessionBudget;
	}
}
