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
 * A small, read-only Redfish client for one BMC and one run (ADR 0010).
 *
 * It sends only what the operation guard allows, follows a redirect only on
 * a GET, at most three hops, to an https /redfish/ path on the same host and
 * port (ADR 0012), caps JSON nesting depth (REQ-RF-003), and turns every
 * failure into a ClientError with a failure class. A 429 or 503 is never
 * retried inside the run: its Retry-After, capped, goes to the collector's
 * backoff (REQ-RF-007).
 *
 * Authentication is one session per run, deleted by the caller in a finally
 * path, or Basic where the BMC allows it and sessions are unavailable
 * (REQ-RF-004). The token and password stay in this object and the request
 * headers; the Redactor knows both.
 */
final class RedfishClient {
	public const SERVICE_ROOT    = '/redfish/v1/';
	public const MAX_REDIRECTS   = 3;
	public const RETRY_AFTER_CAP = 3600;
	public const MAX_DEPTH       = 32;
	public const MAX_SESSIONS    = 64;

	private ?Session $session = null;

	private ?Secret $basic = null;

	private float $latency = 0.0;

	public function __construct(
		private readonly GuardedTransport $transport,
		private readonly Endpoint $endpoint,
		private readonly Redactor $redactor,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The service root, read without credentials as DSP0266 allows.
	 *
	 * @return array<string, mixed>
	 * @throws ClientError
	 */
	public function serviceRoot(): array {
		return $this->fetch(self::SERVICE_ROOT, false);
	}

	/**
	 * @return array<string, mixed>
	 * @throws ClientError
	 */
	public function get(string $path): array {
		return $this->fetch($path, true);
	}

	public function guard(): OperationGuard {
		return $this->transport->guard();
	}

	/**
	 * Create the run's session at the collection the guard allows.
	 *
	 * @throws ClientError
	 */
	public function login(string $username, Secret $password): Session {
		$this->redactor->add($password);

		$body = json_encode(['UserName' => $username, 'Password' => $password->reveal()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if ($body === false) {
			throw new ClientError(FailureClass::CredentialUnavailable);
		}

		$response = $this->send(new HttpRequest('POST', $this->guard()->sessionsPath(), $this->headers(false) + ['Content-Type' => 'application/json'], $body));
		unset($body);

		if ($response->status === 200 || $response->status === 201) {
			$token = $response->header('x-auth-token');
			$path  = Link::path($response->header('location'), $this->endpoint);

			if ($path === null) {
				$decoded = $this->decode($response->body, true);
				$path    = $decoded === null ? null : Link::path($decoded['@odata.id'] ?? null, $this->endpoint);
			}

			// A session the run cannot address or use stays open on the BMC;
			// the caller's pending marker makes the next run sweep it.
			if ($token === '' || strlen($token) > 1024 || $path === null || !$this->guard()->isSessionMember($path)) {
				throw new ClientError(FailureClass::SessionLost, 0, true, $response->status);
			}

			$secret = new Secret($token);
			$this->redactor->add($secret);
			$this->guard()->grantDelete($path);

			return $this->session = new Session($secret, $path);
		}

		throw match (true) {
			in_array($response->status, [401, 403], true)      => new ClientError(FailureClass::AuthRejected, 0, true, $response->status),
			in_array($response->status, [404, 405, 501], true) => new ClientError(FailureClass::SessionsUnavailable, 0, true, $response->status),
			default                                            => $this->statusError($response),
		};
	}

	/**
	 * Authenticate every request with Basic instead of a session. Only over
	 * this TLS transport; there is no plain HTTP path.
	 */
	public function useBasic(string $username, Secret $password): void {
		$this->redactor->add($password);
		$this->basic = new Secret(base64_encode($username . ':' . $password->reveal()));
		$this->redactor->add($this->basic);
	}

	public function authMode(): string {
		return match (true) {
			$this->session !== null => 'session',
			$this->basic !== null   => 'basic',
			default                 => '',
		};
	}

	/**
	 * Delete the run's session. True when it is gone: deleted now, or
	 * already gone (404).
	 */
	public function logout(): bool {
		if ($this->session === null) {
			return true;
		}

		$path          = $this->session->path;
		$ok            = $this->deleteSession($path);
		$this->session = $ok ? null : $this->session;

		return $ok;
	}

	/**
	 * Sessions of the same user other than the run's own, under the session
	 * collection, each granted for deletion. Used after a run whose session
	 * POST lost its response, so a session the BMC created then is closed
	 * (docs/research/sessions.md item 3).
	 *
	 * @return list<string>
	 * @throws ClientError
	 */
	public function staleSessions(string $username): array {
		$own        = $this->session?->path;
		$collection = $this->get($this->guard()->sessionsPath());
		$members    = is_array($collection['Members'] ?? null) ? array_slice($collection['Members'], 0, self::MAX_SESSIONS) : [];
		$stale      = [];

		foreach ($members as $member) {
			$path = is_array($member) ? Link::path($member['@odata.id'] ?? null, $this->endpoint) : null;

			if ($path === null || $path === $own || !$this->guard()->isSessionMember($path)) {
				continue;
			}

			try {
				$session = $this->get($path);
			} catch (ClientError $e) {
				if ($e->class === FailureClass::NotFound) {
					continue;
				}

				throw $e;
			}

			if (($session['UserName'] ?? null) === $username) {
				$this->guard()->grantDelete($path);
				$stale[] = $path;
			}
		}

		return $stale;
	}

	public function deleteSession(string $path): bool {
		try {
			$response = $this->send(new HttpRequest('DELETE', $path, $this->headers(true)));
		} catch (ClientError) {
			return false;
		}

		return ($response->status >= 200 && $response->status < 300) || $response->status === 404;
	}

	public function requests(): int {
		return $this->transport->requests();
	}

	public function meanLatencyMs(): float {
		$requests = $this->transport->requests();

		return $requests > 0 ? round($this->latency / $requests * 1000, 1) : 0.0;
	}

	/**
	 * @return array<string, mixed>
	 * @throws ClientError
	 */
	private function fetch(string $path, bool $auth): array {
		for ($hop = 0; ; $hop++) {
			$response = $this->send(new HttpRequest('GET', $path, $this->headers($auth)));

			if (!in_array($response->status, [301, 302, 303, 307, 308], true)) {
				break;
			}

			// DSP0266 section 6.3 expects clients to follow redirects. Only a
			// bounded number, and only to this BMC, so a credential is never
			// sent anywhere else.
			$next = Link::path($response->header('location'), $this->endpoint);

			if ($hop >= self::MAX_REDIRECTS || $next === null || str_contains($next, '?')) {
				throw new ClientError(FailureClass::RedirectRefused, 0, false, $response->status);
			}

			$path = $next;
		}

		if ($response->status !== 200) {
			throw match (true) {
				in_array($response->status, [401, 403], true) => new ClientError(FailureClass::AuthRejected, 0, false, $response->status),
				in_array($response->status, [404, 410], true) => new ClientError(FailureClass::NotFound, 0, false, $response->status),
				default                                       => $this->statusError($response),
			};
		}

		$decoded = $this->decode($response->body, false);

		if ($decoded === null) {
			throw new ClientError(FailureClass::Malformed);
		}

		return $decoded;
	}

	/**
	 * @return ?array<string, mixed> a JSON object, or null when the body is
	 *                               empty and $optional is set
	 * @throws ClientError
	 */
	private function decode(string $body, bool $optional): ?array {
		if (trim($body) === '' && $optional) {
			return null;
		}

		try {
			$decoded = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		} catch (\JsonException $e) {
			if ($optional) {
				return null;
			}

			throw new ClientError($e->getCode() === JSON_ERROR_DEPTH ? FailureClass::JsonDepth : FailureClass::Malformed);
		}

		if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
			if ($optional) {
				return null;
			}

			throw new ClientError(FailureClass::Malformed);
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	private function statusError(HttpResponse $response): ClientError {
		$class = match (true) {
			$response->status === 429                                => FailureClass::Throttled,
			$response->status === 503                                => FailureClass::ServiceUnavailable,
			$response->status >= 500                                 => FailureClass::ServerError,
			$response->status >= 300 && $response->status < 400      => FailureClass::RedirectRefused,
			default                                                  => FailureClass::UnexpectedStatus,
		};

		return new ClientError($class, $this->retryAfter($response->header('retry-after')), true, $response->status);
	}

	/**
	 * Retry-After in seconds from delta-seconds or an HTTP date, capped at
	 * RETRY_AFTER_CAP; 0 when absent or unreadable.
	 */
	public function retryAfter(string $value): int {
		$value = trim($value);

		if ($value === '') {
			return 0;
		}

		if (preg_match('/^\d{1,10}$/', $value) === 1) {
			return min(self::RETRY_AFTER_CAP, (int) $value);
		}

		$time = strtotime($value);

		return $time === false ? 0 : max(0, min(self::RETRY_AFTER_CAP, $time - $this->clock->now()));
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(bool $auth): array {
		$headers = ['Accept' => 'application/json', 'OData-Version' => '4.0', 'User-Agent' => 'plugin_redfish'];

		if ($auth && $this->session !== null) {
			$headers['X-Auth-Token'] = $this->session->token->reveal();
		} elseif ($auth && $this->basic !== null) {
			$headers['Authorization'] = 'Basic ' . $this->basic->reveal();
		}

		return $headers;
	}

	/**
	 * @throws ClientError
	 */
	private function send(HttpRequest $request): HttpResponse {
		$start = microtime(true);

		try {
			return $this->transport->send($request);
		} catch (TransportError $e) {
			throw ClientError::fromTransport($e);
		} finally {
			$this->latency += microtime(true) - $start;
		}
	}
}
