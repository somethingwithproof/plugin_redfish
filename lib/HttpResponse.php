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
 * Class representing http response.
 */
final readonly class HttpResponse {
	/**
	 * @param array<string, string> $headers lower-case names
	 */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {
	}

	/**
	 * Header.
	 *
	 * @param string $name
	 * @return string
	 */
	public function header(string $name): string {
		return $this->headers[strtolower($name)] ?? '';
	}
}
