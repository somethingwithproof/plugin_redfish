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
 * Applies the operation guard, the request budget, and the per-BMC deadline
 * before the inner transport sees a request, and lets one request through at
 * a time (REQ-RF-008): a re-entrant send, which no code path makes, is
 * refused instead of opening a second connection.
 *
 * The deadline comes from the collector's lease on the BMC. No request
 * starts at or after $deadline, except the DELETE of the run's own session,
 * which may start until $closeDeadline. Both leave room for one full request
 * before the lease expires, so no request outlives the lease and another
 * worker never holds a session on the BMC at the same time.
 */
final class GuardedTransport implements HttpTransport {
	private bool $busy = false;

	private int $sent = 0;

	public function __construct(
		private readonly HttpTransport $inner,
		private readonly OperationGuard $guard,
		private readonly int $maxRequests,
		private readonly ?Clock $clock = null,
		private readonly int $deadline = PHP_INT_MAX,
		private readonly int $closeDeadline = PHP_INT_MAX,
	) {
	}

	public function send(HttpRequest $request): HttpResponse {
		$this->guard->check($request);

		if ($this->clock !== null && $this->clock->now() >= ($request->method === 'DELETE' ? $this->closeDeadline : $this->deadline)) {
			throw new TransportError(FailureClass::Deadline);
		}

		if ($this->sent >= $this->maxRequests) {
			throw new TransportError(FailureClass::RequestBudget);
		}

		if ($this->busy) {
			throw new TransportError(FailureClass::OperationRefused, 'concurrent request');
		}

		$this->busy = true;
		$this->sent++;

		try {
			return $this->inner->send($request);
		} finally {
			$this->busy = false;
		}
	}

	public function requests(): int {
		return $this->sent;
	}

	public function guard(): OperationGuard {
		return $this->guard;
	}
}
