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
 * The run's Redfish session: its token, held only in this process
 * (REQ-CRED-006), and the path that deletes it.
 */
final readonly class Session {
	/**
	 * Initialize a new Session instance.
	 *
	 * @param Secret $token
	 * @param string $path
	 */
	public function __construct(
		public Secret $token,
		public string $path,
	) {
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return rawurldecode((string) basename(rtrim($this->path, '/')));
	}
}
