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
 * Which BMC records the current user may see and change, through the Cacti
 * device each one is mapped to. Redfish Admin alone does not grant access to
 * a BMC on a device the user's device permissions hide: every action on an
 * existing record, and every new mapping, checks the device first.
 *
 * A BMC mapped to no device is visible to every Redfish user. A mapping to a
 * device that no longer exists is allowed, so the record can still be fixed
 * or deleted; host ids are not reused.
 *
 * A stored credential is visible when every BMC that uses it is: binding a
 * credential to a BMC sends it to that BMC's address, so only a user who
 * may already act on every BMC holding it may choose it, edit it, or see
 * its user name.
 */
final class DeviceScope {
	/**
	 * @param \Closure(int): bool $allowed whether the user may see this existing device
	 * @param \Closure(int): bool $exists  whether the device still exists
	 */
	public function __construct(
		private readonly \Closure $allowed,
		private readonly \Closure $exists,
	) {
	}

	/**
	 * Cacti's device permissions for one user.
	 */
	public static function forUser(int $userId, BmcRepository $bmcs): self {
		return new self(
			static fn (int $hostId): bool => $userId > 0 && is_device_allowed($hostId, $userId),
			static fn (int $hostId): bool => $bmcs->hostExists($hostId),
		);
	}

	public function allows(int $hostId): bool {
		return $hostId <= 0 || !($this->exists)($hostId) || ($this->allowed)($hostId);
	}

	/**
	 * Whether the user may map a BMC to this device: it must exist and be visible.
	 */
	public function allowsNew(int $hostId): bool {
		return $hostId <= 0 || (($this->exists)($hostId) && ($this->allowed)($hostId));
	}

	/**
	 * @param list<int> $hostIds
	 */
	public function allowsAll(array $hostIds): bool {
		foreach ($hostIds as $hostId) {
			if (!$this->allows($hostId)) {
				return false;
			}
		}

		return true;
	}

	public function allowsCredential(BmcRepository $bmcs, int $credentialId): bool {
		return $this->allowsAll($bmcs->credentialHosts($credentialId));
	}

	public function allowsBmc(Bmc $bmc): bool {
		return $this->allows($bmc->hostId);
	}

	/**
	 * @param list<Bmc> $bmcs
	 * @return list<Bmc>
	 */
	public function filter(array $bmcs): array {
		return array_values(array_filter($bmcs, fn (Bmc $bmc): bool => $this->allowsBmc($bmc)));
	}
}
