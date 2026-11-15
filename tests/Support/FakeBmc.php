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

// Test code: refuse to run outside the command line.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	exit(1);
}

use Somethingwithproof\Redfish\Resolver;

/**
 * Starts tests/fake-bmc/server.php on a free local port with a fixture and
 * certificate. The account reaches the server through its environment.
 */
final class FakeBmc {
	public const USER     = 'monitor';
	public const PASSWORD = 'SENTINEL-fake-bmc-password-7f3a';

	/** @var resource */
	private $process;

	public int $port;

	public string $state;

	public static function certs(): string {
		static $dir = null;

		if ($dir === null) {
			$dir = realpath(sys_get_temp_dir()) . '/redfish-fake-certs-' . getmypid();

			if (!is_dir($dir)) {
				mkdir($dir, 0700);
				exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fake-bmc/make-certs.php') . ' ' . escapeshellarg($dir), $out, $rc);

				if ($rc !== 0) {
					throw new RuntimeException('make-certs failed');
				}
			}
		}

		return $dir;
	}

	/**
	 * @param array<string, mixed> $state initial flags and sessions
	 */
	public function __construct(string $fixture = 'dell-subsystem', string $cert = 'good', array $state = [], bool $old = false) {
		$this->port  = self::freePort();
		$this->state = tempnam(sys_get_temp_dir(), 'redfish-fake-state-');
		file_put_contents($this->state, json_encode($state + ['flags' => [], 'sessions' => [], 'log' => [], 'next' => 1]));

		$args = [PHP_BINARY, __DIR__ . '/../fake-bmc/server.php', (string) $this->port, self::certs() . '/' . $cert . '.pem', __DIR__ . '/../fixtures/' . $fixture . '.json', $this->state];

		if ($old) {
			$args[] = 'tls1.1';
		}

		$env = getenv() + ['REDFISH_FAKE_USER' => self::USER, 'REDFISH_FAKE_PASSWORD' => self::PASSWORD];
		$env['REDFISH_FAKE_USER']     = self::USER;
		$env['REDFISH_FAKE_PASSWORD'] = self::PASSWORD;

		$process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

		if (!is_resource($process)) {
			throw new RuntimeException('fake BMC did not start');
		}

		$this->process = $process;
		$this->waitForPort();
	}

	public function __destruct() {
		proc_terminate($this->process);
		proc_close($this->process);
		@unlink($this->state);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return json_decode((string) file_get_contents($this->state), true) ?: [];
	}

	/**
	 * @param array<string, mixed> $flags
	 */
	public function flags(array $flags): void {
		$s          = $this->state();
		$s['flags'] = $flags;
		file_put_contents($this->state, json_encode($s));
	}

	public static function resolver(): Resolver {
		return new class implements Resolver {
			/** @var list<string> */
			public array $asked = [];

			public function resolve(string $host): array {
				$this->asked[] = $host;

				return in_array($host, ['bmc.test', 'other.test'], true) ? ['127.0.0.1'] : [];
			}
		};
	}

	private static function freePort(): int {
		$s    = stream_socket_server('tcp://127.0.0.1:0');
		$name = (string) stream_socket_get_name($s, false);
		fclose($s);

		return (int) substr($name, strrpos($name, ':') + 1);
	}

	private function waitForPort(): void {
		set_error_handler(static fn (): bool => true);

		try {
			for ($i = 0; $i < 100; $i++) {
				$fp = stream_socket_client('tcp://127.0.0.1:' . $this->port, $e, $s, 0.1);

				if ($fp !== false) {
					fclose($fp);

					return;
				}

				usleep(50000);
			}
		} finally {
			restore_error_handler();
		}

		throw new RuntimeException('fake BMC did not listen');
	}
}
