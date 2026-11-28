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
 * Read-only IPMI over LAN (lanplus) fallback collector (REQ-IPMI-001 to
 * REQ-IPMI-013).
 *
 * Executes ipmitool via array-form proc_open() so no shell is ever invoked.
 * Credentials are delivered via IPMITOOL_PASSWORD in the child process
 * environment (-E) or via a mode-0600 temporary file (-f) removed in finally,
 * never on CLI argv. Output size and wall-clock execution time are bounded.
 */
final class IpmiClient {
	/**
	 * Max output bytes constant.
	 */
	public const MAX_OUTPUT_BYTES = 262144;
	/**
	 * Default binary constant.
	 */
	public const DEFAULT_BINARY   = 'ipmitool';

	/**
	 * @param ?\Closure(list<string>, array<string, string>, int): array{exit: int, stdout: string, stderr: string} $runner
	 */
	public function __construct(
		private readonly string $binary = self::DEFAULT_BINARY,
		private readonly int $timeoutSeconds = 10,
		private readonly string $authMode = IpmiCommand::AUTH_ENV,
		private readonly ?\Closure $runner = null,
	) {
	}

	/**
	 * Collect all read-only IPMI datasets for one BMC and normalize them into
	 * the standard Observation model.
	 *
	 * @throws ClientError
	 */
	public function collect(Bmc $bmc, string $username, Secret $password, Redactor $redactor): Observation {
		$redactor->add($password);

		$dcmi = $this->runOperation($bmc, $username, $password, IpmiCommand::OP_DCMI_POWER, $redactor, true);
		$temp = $this->runOperation($bmc, $username, $password, IpmiCommand::OP_SDR_TEMPERATURE, $redactor, false);
		$fan  = $this->runOperation($bmc, $username, $password, IpmiCommand::OP_SDR_FAN, $redactor, true);
		$psu  = $this->runOperation($bmc, $username, $password, IpmiCommand::OP_SDR_PSU, $redactor, true);
		$chas = $this->runOperation($bmc, $username, $password, IpmiCommand::OP_CHASSIS_STATUS, $redactor, true);

		$obs = IpmiNormalizer::normalizeAll($dcmi, $temp, $fan, $psu, $chas);
		$obs->merge(Normalizer::node($obs, IpmiNormalizer::CHASSIS_ID));

		return $obs;
	}

	/**
	 * Execute a single allowlisted read-only IPMI operation.
	 *
	 * @throws ClientError
	 */
	public function runOperation(Bmc $bmc, string $username, Secret $password, string $operation, Redactor $redactor, bool $optional = false): string {
		$passwordFile = '';
		$env          = [];

		if ($this->authMode === IpmiCommand::AUTH_FILE) {
			$passwordFile = self::writePasswordFile($password);
		} else {
			$env['IPMITOOL_PASSWORD'] = $password->reveal();
		}

		try {
			$argv   = IpmiCommand::argv($this->binary, $bmc->address, $username, $operation, $this->authMode, $passwordFile, $this->timeoutSeconds);
			$result = $this->runner !== null
				? ($this->runner)($argv, $env, $this->timeoutSeconds)
				: self::executeProcess($argv, $env, $this->timeoutSeconds);
		} finally {
			if ($passwordFile !== '' && is_file($passwordFile)) {
				@unlink($passwordFile);
			}
		}

		if ($result['exit'] !== 0) {
			$err = strtolower($redactor->clean($result['stderr']));

			if (str_contains($err, 'unauthorized') || str_contains($err, 'authentication') || str_contains($err, 'invalid user') || str_contains($err, 'password')) {
				throw new ClientError(FailureClass::AuthRejected, 0, true);
			}

			if ($optional) {
				return '';
			}

			if (str_contains($err, 'timed out') || str_contains($err, 'timeout')) {
				throw new ClientError(FailureClass::Timeout, 0, true);
			}

			if (str_contains($err, 'unable to establish') || str_contains($err, 'could not open')) {
				throw new ClientError(FailureClass::ConnectRefused, 0, true);
			}

			throw new ClientError(FailureClass::ServiceUnavailable, 0, true);
		}

		return $result['stdout'];
	}

	/**
	 * Create a mode-0600 temporary password file in a private directory.
	 *
	 * @throws ClientError
	 */
	public static function writePasswordFile(Secret $password): string {
		$dir = sys_get_temp_dir() . '/redfish-ipmi-' . getmyuid();

		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new ClientError(FailureClass::CredentialUnavailable);
		}

		@chmod($dir, 0700);
		$path = $dir . '/pw-' . bin2hex(random_bytes(16));
		$fh   = @fopen($path, 'x');

		if ($fh === false) {
			throw new ClientError(FailureClass::CredentialUnavailable);
		}

		try {
			@chmod($path, 0600);
			fwrite($fh, $password->reveal());
		} finally {
			fclose($fh);
		}

		return $path;
	}

	/**
	 * Run an argv array via proc_open() without a shell, enforcing output size
	 * and wall-clock time bounds.
	 *
	 * @param list<string>          $argv
	 * @param array<string, string> $env
	 * @return array{exit: int, stdout: string, stderr: string}
	 * @throws ClientError
	 */
	public static function executeProcess(array $argv, array $env, int $timeoutSeconds): array {
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$baseEnv = [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'LC_ALL' => 'C',
		];

		$process = @proc_open($argv, $descriptors, $pipes, null, $baseEnv + $env);

		if (!is_resource($process)) {
			throw new ClientError(FailureClass::ServiceUnavailable);
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout   = '';
		$stderr   = '';
		$deadline = microtime(true) + max(1, $timeoutSeconds);
		$timedOut = false;

		try {
			while (true) {
				$status = proc_get_status($process);
				$read   = [];

				if (!feof($pipes[1])) {
					$read[] = $pipes[1];
				}

				if (!feof($pipes[2])) {
					$read[] = $pipes[2];
				}

				if ($read === [] && !$status['running']) {
					break;
				}

				$remaining = $deadline - microtime(true);

				if ($remaining <= 0.0) {
					$timedOut = true;
					proc_terminate($process);

					break;
				}

				if ($read !== []) {
					$write  = null;
					$except = null;
					$sec    = (int) floor($remaining);
					$usec   = (int) (($remaining - $sec) * 1_000_000);
					@stream_select($read, $write, $except, $sec, $usec);

					foreach ($read as $stream) {
						$chunk = fread($stream, 8192);

						if (!is_string($chunk) || $chunk === '') {
							continue;
						}

						if ($stream === $pipes[1]) {
							if (strlen($stdout) + strlen($chunk) > self::MAX_OUTPUT_BYTES) {
								proc_terminate($process);

								throw new ClientError(FailureClass::TooLarge, 0, true);
							}

							$stdout .= $chunk;
						} else {
							$stderr .= substr($chunk, 0, max(0, 16384 - strlen($stderr)));
						}
					}
				} elseif ($status['running']) {
					usleep(10000);
				}
			}
		} finally {
			fclose($pipes[1]);
			fclose($pipes[2]);
			$exit = proc_close($process);
		}

		if ($timedOut) {
			throw new ClientError(FailureClass::Timeout, 0, true);
		}

		return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
	}
}
