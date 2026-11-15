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

use Somethingwithproof\Redfish\HttpRequest;
use Somethingwithproof\Redfish\HttpResponse;
use Somethingwithproof\Redfish\HttpTransport;
use Somethingwithproof\Redfish\TransportError;

/**
 * An in-memory BMC for unit tests: serves a fixture file by path, answers
 * the session POST and DELETE, and records every request. $override maps a
 * path to a response or a TransportError to throw.
 */
final class FixtureTransport implements HttpTransport {
	/** @var array<string, mixed> */
	public array $resources;

	/** @var list<HttpRequest> */
	public array $requests = [];

	/** @var array<string, HttpResponse|TransportError> */
	public array $override = [];

	/** @var array<string, string> open sessions: path => token */
	public array $sessions = [];

	public function __construct(string $fixture) {
		$decoded = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/' . $fixture . '.json'), true);
		$this->resources = is_array($decoded) ? $decoded : [];
	}

	public function send(HttpRequest $request): HttpResponse {
		$this->requests[] = $request;
		$key = $request->method . ' ' . $request->path;

		foreach ([$key, $request->path] as $k) {
			if (isset($this->override[$k])) {
				$hit = $this->override[$k];

				if ($hit instanceof TransportError) {
					throw $hit;
				}

				return $hit;
			}
		}

		$collection = '/redfish/v1/SessionService/Sessions';

		if ($request->method === 'POST' && $request->path === $collection) {
			$path = $collection . '/' . (count($this->sessions) + 1);
			$this->sessions[$path] = 'token-' . count($this->sessions);

			return new HttpResponse(201, ['x-auth-token' => $this->sessions[$path], 'location' => $path], '{}');
		}

		if ($request->method === 'DELETE') {
			$found = isset($this->sessions[$request->path]);
			unset($this->sessions[$request->path]);

			return new HttpResponse($found ? 204 : 404, [], '');
		}

		if ($request->path === $collection) {
			return new HttpResponse(200, [], (string) json_encode(['Members' => array_map(static fn ($p) => ['@odata.id' => $p], array_keys($this->sessions))]));
		}

		$resource = $this->resources[$request->path] ?? $this->resources[rtrim($request->path, '/')] ?? null;

		return is_array($resource) ? new HttpResponse(200, [], (string) json_encode($resource)) : new HttpResponse(404, [], '{}');
	}

	/**
	 * @return list<string> "METHOD path" of every request
	 */
	public function log(): array {
		return array_map(static fn (HttpRequest $r) => $r->method . ' ' . $r->path, $this->requests);
	}
}
