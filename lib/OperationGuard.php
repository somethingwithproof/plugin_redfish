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
 * The exact list of operations the client may send to a BMC (REQ-RF-006,
 * ADR 0010). Checked before any I/O:
 *
 * - GET on a /redfish/v1 path with no query, fragment, or dot segment;
 * - POST only to the session collection the BMC's own service root named,
 *   once that path is set;
 * - DELETE only on a path granted for this run: the run's own session, or a
 *   stale session of the same user found directly under the collection.
 *
 * Everything else, PATCH, PUT, any other DELETE, and every action POST,
 * raises OperationRefused. No caller can widen the list by building a
 * request by hand, because the guard sits inside the transport the client is
 * given.
 */
final class OperationGuard {
	/**
	 * Segment constant.
	 */
	private const SEGMENT = "(?:[A-Za-z0-9._~!$&'()*+,;=:@-]|%[0-9A-Fa-f]{2})+";

	/**
	 * Sessions path property.
	 */
	private string $sessionsPath = '';

	/** @var array<string, true> */
	private array $deletable = [];

	/**
	 * Readable path.
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function readablePath(string $path): bool {
		if (strlen($path) > 512 || preg_match('#^/redfish/v1(?:/' . self::SEGMENT . ')*/?$#', $path) !== 1) {
			return false;
		}

		foreach (explode('/', trim($path, '/')) as $segment) {
			// A dot segment, or an encoded slash, backslash, or dot segment,
			// could reach a path other than the one that was checked.
			if (in_array(rawurldecode($segment), ['.', '..'], true) || preg_match('/%(?:2f|5c)/i', $segment) === 1) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Set once, from the service root's Links.Sessions.
	 */
	public function allowSessions(string $path): void {
		if (!self::readablePath($path) || ($this->sessionsPath !== '' && $this->sessionsPath !== $path)) {
			throw new TransportError(FailureClass::OperationRefused, 'session collection');
		}

		$this->sessionsPath = $path;
	}

	/**
	 * Sessions path.
	 *
	 * @return string
	 */
	public function sessionsPath(): string {
		return $this->sessionsPath;
	}

	/**
	 * A session the run may delete: a direct child of the session
	 * collection, never the collection itself or anything else.
	 */
	public function grantDelete(string $path): void {
		if (!$this->isSessionMember($path)) {
			throw new TransportError(FailureClass::OperationRefused, 'delete');
		}

		$this->deletable[$path] = true;
	}

	/**
	 * Is session member.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isSessionMember(string $path): bool {
		if ($this->sessionsPath === '' || !self::readablePath($path)) {
			return false;
		}

		$base = rtrim($this->sessionsPath, '/') . '/';

		return str_starts_with($path, $base) && preg_match('#^' . self::SEGMENT . '/?$#', substr($path, strlen($base))) === 1;
	}

	/**
	 * @throws TransportError
	 */
	public function check(HttpRequest $request): void {
		$allowed = match ($request->method) {
			'GET'    => self::readablePath($request->path) && $request->body === null,
			'POST'   => $this->sessionsPath !== '' && $request->path === $this->sessionsPath,
			'DELETE' => isset($this->deletable[$request->path]) && $request->body === null,
			default  => false,
		};

		if (!$allowed) {
			throw new TransportError(FailureClass::OperationRefused, $request->method);
		}
	}
}
