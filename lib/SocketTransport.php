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
 * HTTPS to one BMC over stream_socket_client() with its own HTTP/1.1 reader
 * (ADR 0010, from the plugin_cloud transport).
 *
 * The host name is resolved once per transport, which lives for one run, and
 * every address is checked against the address rules before any connection
 * (REQ-BMC-003), so a name that rebinds to loopback between registration and
 * collection is refused. The connection goes to the vetted address with SNI
 * and the certificate name check set to the registered host.
 *
 * TLS 1.2 or later only (REQ-TLS-005). Trust follows the BMC's TLS mode
 * (REQ-TLS-002): the system store, a CA bundle file, or a pinned SHA-256
 * public key compared before any request byte is written. A failed check is
 * classified as chain, name, expiry, pin, or protocol (REQ-TLS-004).
 *
 * One request per connection, the connect and handshake inside one total
 * deadline, at most 64 KiB of headers, a body cap, strict Content-Length, and
 * strict chunked decoding. A 3xx is returned to the caller, which decides
 * whether to follow it; the transport never follows anything itself.
 */
final class SocketTransport implements HttpTransport {
	/**
	 * Max header bytes constant.
	 */
	private const MAX_HEADER_BYTES = 65536;

	/**
	 * Max ca bytes constant.
	 */
	private const MAX_CA_BYTES = 1048576;

	/** A private copy of the checked CA bundle, which OpenSSL reads by path. */
	private ?string $caCopy = null;

	/** @var ?list<string> */
	private ?array $addresses = null;

	/**
	 * @param bool $allowLoopback test binding only: accept a name that
	 *                            resolves to 127.0.0.0/8 or ::1, so the fake
	 *                            BMC can run on the test host. Production
	 *                            code never sets it (tests/Security).
	 */
	public function __construct(
		private readonly Endpoint $endpoint,
		private readonly Resolver $resolver,
		private readonly float $connectTimeout = 5.0,
		private readonly float $totalTimeout = 20.0,
		private readonly int $maxBytes = 2097152,
		private readonly bool $allowLoopback = false,
	) {
	}

	/**
	 * Release resources held by this instance.
	 */
	public function __destruct() {
		if ($this->caCopy !== null) {
			@unlink($this->caCopy);
		}
	}

	/**
	 * Send.
	 *
	 * @param HttpRequest $request
	 * @return HttpResponse
	 * @throws TransportError
	 */
	public function send(HttpRequest $request): HttpResponse {
		$deadline = microtime(true) + $this->totalTimeout;
		$method   = $request->method;
		$path     = $request->path;
		$host     = $this->endpoint->host;
		$port     = $this->endpoint->port;

		if (preg_match('/^[A-Z]{3,7}$/', $method) !== 1 || preg_match('#^/[\x21-\x7e]*$#', $path) !== 1 || str_contains($path, '#')) {
			throw new TransportError(FailureClass::UrlRefused, 'request line');
		}

		// A CR or LF in a name or value would let a caller inject headers or a
		// second request.
		$raw = "$method $path HTTP/1.1\r\nHost: " . Address::urlHost($host) . ($port === 443 ? '' : ":$port") . "\r\nConnection: close\r\n";

		foreach ($request->headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new TransportError(FailureClass::UrlRefused, 'header');
			}

			$raw .= "$name: $value\r\n";
		}

		if ($request->body !== null || $method === 'POST') {
			$raw .= 'Content-Length: ' . strlen($request->body ?? '') . "\r\n";
		}

		$raw .= "\r\n" . ($request->body ?? '');

		$fp = $this->connect($deadline);

		try {
			return $this->exchange($fp, $raw, $method, $deadline);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * The SHA-256 of a certificate's SubjectPublicKeyInfo, base64, as
	 * libcurl's sha256// pins are written. Null when it cannot be read.
	 */
	public static function spkiPin(mixed $certificate): ?string {
		if (!$certificate instanceof \OpenSSLCertificate) {
			return null;
		}

		$key     = openssl_pkey_get_public($certificate);
		$details = $key === false ? false : openssl_pkey_get_details($key);

		if (!is_array($details) || !is_string($details['key'] ?? null)) {
			return null;
		}

		$der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $details['key']), true);

		return is_string($der) && $der !== '' ? base64_encode(hash('sha256', $der, true)) : null;
	}

	/**
	 * @return list<string>
	 */
	private function addresses(): array {
		if ($this->addresses !== null) {
			return $this->addresses;
		}

		$host      = $this->endpoint->host;
		$addresses = Address::isIp($host) ? [$host] : $this->resolver->resolve($host);

		if ($addresses === []) {
			throw new TransportError(FailureClass::DnsFailed);
		}

		// Every answer must pass: a name that returns one acceptable and one
		// loopback address is a rebinding attempt either way.
		foreach ($addresses as $address) {
			if (Address::ipRefused($address) && !($this->allowLoopback && self::loopback($address))) {
				throw new TransportError(FailureClass::AddressRefused);
			}
		}

		return $this->addresses = array_values($addresses);
	}

	/**
	 * Loopback.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function loopback(string $ip): bool {
		return $ip === '::1' || str_starts_with($ip, '127.');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sslOptions(bool $verify): array {
		$ssl = [
			'verify_peer'         => $verify,
			'verify_peer_name'    => $verify,
			'allow_self_signed'   => false,
			'peer_name'           => $this->endpoint->host,
			'SNI_enabled'         => true,
			'disable_compression' => true,
			'capture_peer_cert'   => true,
			'crypto_method'       => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
		];

		if ($verify && $this->endpoint->tlsMode === TlsMode::CaBundle) {
			$ssl['cafile'] = $this->caBundle();
		}

		return $ssl;
	}

	/**
	 * @return resource
	 */
	private function connect(float $deadline) {
		$mode = $this->endpoint->tlsMode;

		if ($mode === TlsMode::CaBundle) {
			$this->caBundle();
		}

		if ($mode === TlsMode::Pin && Bmc::normalizePin($this->endpoint->pin) === null) {
			throw new TransportError(FailureClass::TlsConfig, 'pin');
		}

		// Pin mode checks the key itself after the handshake; the chain and
		// name are not what is trusted there.
		$verify  = $mode === TlsMode::System || $mode === TlsMode::CaBundle;
		$address = $this->addresses()[0];
		[$fp, $message] = $this->open($address, $verify, $deadline);

		if ($fp === false) {
			$class = self::classify($message);

			if ($class === FailureClass::TlsChain) {
				$class = $this->chainOrExpiry($address, $deadline);
			}

			throw new TransportError($class, self::clean($message));
		}

		if ($mode === TlsMode::Pin) {
			$pin = self::spkiPin(self::peerCertificate($fp));

			if ($pin === null || !hash_equals($this->endpoint->pin, $pin)) {
				fclose($fp);

				throw new TransportError(FailureClass::TlsPin);
			}
		}

		return $fp;
	}

	/**
	 * @return array{0: resource|false, 1: string}
	 */
	private function open(string $address, bool $verify, float $deadline): array {
		$budget = min($this->connectTimeout, $deadline - microtime(true));

		if ($budget <= 0) {
			throw new TransportError(FailureClass::Timeout, 'connect');
		}

		$context  = stream_context_create(['ssl' => $this->sslOptions($verify)]);
		$warnings = [];

		set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});

		try {
			$fp = stream_socket_client('tls://' . Address::urlHost($address) . ':' . $this->endpoint->port, $errno, $errstr, $budget, STREAM_CLIENT_CONNECT, $context);
		} finally {
			restore_error_handler();
		}

		return [$fp, implode(' | ', array_merge($warnings, ["$errno $errstr"]))];
	}

	/**
	 * A chain that failed to verify is reported as expired when the leaf is
	 * outside its validity period. The second handshake only reads the
	 * certificate and sends nothing.
	 */
	private function chainOrExpiry(string $address, float $deadline): FailureClass {
		try {
			[$fp] = $this->open($address, false, $deadline);
		} catch (TransportError) {
			return FailureClass::TlsChain;
		}

		if ($fp === false) {
			return FailureClass::TlsChain;
		}

		$cert = self::peerCertificate($fp);
		fclose($fp);

		$info = $cert instanceof \OpenSSLCertificate ? openssl_x509_parse($cert) : false;

		if (!is_array($info)) {
			return FailureClass::TlsChain;
		}

		$now = time();

		return (int) ($info['validTo_time_t'] ?? PHP_INT_MAX) < $now || (int) ($info['validFrom_time_t'] ?? 0) > $now ? FailureClass::TlsExpired : FailureClass::TlsChain;
	}

	/**
	 * @param resource $fp
	 */
	private static function peerCertificate($fp): mixed {
		$options = stream_context_get_options($fp);
		$ssl     = is_array($options['ssl'] ?? null) ? $options['ssl'] : [];

		return $ssl['peer_certificate'] ?? null;
	}

	/**
	 * A CA bundle must pass the trusted-file checks (SecureFile): one any
	 * other local user could replace would let them choose what the BMC may
	 * be. It is read once through the checked handle and handed to OpenSSL
	 * as a private copy, so the file OpenSSL opens is the file that was
	 * checked.
	 */
	private function caBundle(): string {
		if ($this->caCopy !== null) {
			return $this->caCopy;
		}

		try {
			$pem = SecureFile::read($this->endpoint->caFile, self::MAX_CA_BYTES, false);
		} catch (CredentialError $e) {
			throw new TransportError(FailureClass::TlsConfig, 'CA bundle file: ' . $e->getMessage());
		}

		if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $first) !== 1 || @openssl_x509_read($first[0]) === false) {
			throw new TransportError(FailureClass::TlsConfig, 'CA bundle file holds no certificate');
		}

		$copy = tempnam(sys_get_temp_dir(), 'redfish-ca-');

		if ($copy === false || !chmod($copy, 0600) || file_put_contents($copy, $pem) !== strlen($pem)) {
			if ($copy !== false) {
				@unlink($copy);
			}

			throw new TransportError(FailureClass::TlsConfig, 'CA bundle copy');
		}

		return $this->caCopy = $copy;
	}

	/**
	 * @param resource $fp
	 */
	private function exchange($fp, string $raw, string $method, float $deadline): HttpResponse {
		// A peer that stops reading must not hold the write past the deadline.
		for ($off = 0, $length = strlen($raw); $off < $length; $off += $n) {
			$this->arm($fp, $deadline, false);
			$n = @fwrite($fp, substr($raw, $off, 65536));

			if (stream_get_meta_data($fp)['timed_out']) {
				throw new TransportError(FailureClass::Timeout, 'write');
			}

			if ($n === false || $n === 0) {
				throw new TransportError(FailureClass::ConnectRefused, 'write');
			}
		}

		// From here a POST may have reached the BMC.
		try {
			return $this->response($fp, $method, $deadline);
		} catch (TransportError $e) {
			throw new TransportError($e->class, '', true);
		}
	}

	/**
	 * @param resource $fp
	 */
	private function response($fp, string $method, float $deadline): HttpResponse {
		// Informational (1xx) responses come before the final one and are
		// skipped; their bytes count towards the same header limit.
		$raw       = '';
		$headBytes = 0;

		while (true) {
			$headEnd = strpos($raw, "\r\n\r\n");

			while ($headEnd === false) {
				if ($headBytes + strlen($raw) > self::MAX_HEADER_BYTES) {
					throw new TransportError(FailureClass::TooLarge, 'headers');
				}

				if (feof($fp)) {
					throw new TransportError(FailureClass::Malformed, 'closed in headers');
				}

				$raw    .= $this->readSome($fp, $deadline);
				$headEnd = strpos($raw, "\r\n\r\n");
			}

			$headBytes += $headEnd + 4;

			if ($headBytes > self::MAX_HEADER_BYTES) {
				throw new TransportError(FailureClass::TooLarge, 'headers');
			}

			[$status, $headers] = self::parseHead(substr($raw, 0, $headEnd));
			$rest = substr($raw, $headEnd + 4);

			if ($status === 101) {
				throw new TransportError(FailureClass::Malformed, 'unexpected 101');
			}

			if ($status >= 200) {
				break;
			}

			$raw = $rest;
		}

		// These end with their headers whatever Content-Length says
		// (RFC 9112 section 6.3).
		if ($method === 'HEAD' || $status === 204 || $status === 304) {
			return new HttpResponse($status, $headers, '');
		}

		$chunked = str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked');
		$length  = null;

		if (!$chunked && isset($headers['content-length'])) {
			if (preg_match('/^\d{1,12}$/', $headers['content-length']) !== 1) {
				throw new TransportError(FailureClass::Malformed, 'content-length');
			}

			$length = (int) $headers['content-length'];

			if ($length > $this->maxBytes) {
				throw new TransportError(FailureClass::TooLarge, 'content-length');
			}
		}

		// The raw (still chunked) body is capped too, so chunk framing cannot
		// stream an unbounded amount.
		while (!feof($fp) && ($length === null || strlen($rest) < $length)) {
			$rest .= $this->readSome($fp, $deadline);

			if (strlen($rest) > $this->maxBytes + ($chunked ? 65536 : 0)) {
				throw new TransportError(FailureClass::TooLarge, 'body');
			}
		}

		if ($length !== null && strlen($rest) < $length) {
			throw new TransportError(FailureClass::Malformed, 'short body');
		}

		$body = $chunked ? self::dechunk($rest) : ($length === null ? $rest : substr($rest, 0, $length));

		if (strlen($body) > $this->maxBytes) {
			throw new TransportError(FailureClass::TooLarge, 'body');
		}

		return new HttpResponse($status, $headers, $body);
	}

	/**
	 * @param resource $fp
	 */
	private function arm($fp, float $deadline, bool $sent): void {
		$left = $deadline - microtime(true);

		if ($left <= 0) {
			throw new TransportError(FailureClass::Timeout, '', $sent);
		}

		stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
	}

	/**
	 * fread() on a socket returns whatever has arrived, so checking the
	 * deadline between reads bounds a peer that sends one byte at a time.
	 *
	 * @param resource $fp
	 */
	private function readSome($fp, float $deadline): string {
		$this->arm($fp, $deadline, true);

		// A peer that closes without TLS close_notify raises a warning on
		// some builds; the caller sees EOF either way.
		$chunk = @fread($fp, 8192);

		if (stream_get_meta_data($fp)['timed_out']) {
			throw new TransportError(FailureClass::Timeout, 'read');
		}

		return $chunk === false ? '' : $chunk;
	}

	/**
	 * @return array{0: int, 1: array<string, string>}
	 */
	private static function parseHead(string $head): array {
		$lines = explode("\r\n", $head);

		if (preg_match('#^HTTP/1\.[01] (\d{3})(?: |$)#', (string) array_shift($lines), $m) !== 1) {
			throw new TransportError(FailureClass::Malformed, 'status line');
		}

		$headers = [];

		foreach ($lines as $line) {
			$pos = strpos($line, ':');

			if ($pos === false || $pos === 0 || $line[0] === ' ' || $line[0] === "\t") {
				throw new TransportError(FailureClass::Malformed, 'header line');
			}

			$name  = strtolower(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1), " \t");

			// Two Content-Length values that disagree are a framing attack.
			if ($name === 'content-length' && isset($headers[$name]) && $headers[$name] !== $value) {
				throw new TransportError(FailureClass::Malformed, 'content-length');
			}

			$headers[$name] = $value;
		}

		return [(int) $m[1], $headers];
	}

	/**
	 * Strict RFC 9112 chunked decoding. Trailer fields are checked for shape
	 * and ignored; the empty line that ends them is required.
	 */
	private static function dechunk(string $raw): string {
		$out = '';
		$pos = 0;

		while (true) {
			$eol = strpos($raw, "\r\n", $pos);

			if ($eol === false || preg_match('/^([0-9A-Fa-f]{1,8})(?:;[^\r\n]*)?$/', substr($raw, $pos, $eol - $pos), $m) !== 1) {
				throw new TransportError(FailureClass::Malformed, 'chunk size');
			}

			// At most eight hex digits, so the value always fits an int.
			$size = (int) hexdec($m[1]);
			$pos  = $eol + 2;

			if ($size === 0) {
				while (true) {
					$eol = strpos($raw, "\r\n", $pos);

					if ($eol === false) {
						throw new TransportError(FailureClass::Malformed, 'chunk terminator');
					}

					$line = substr($raw, $pos, $eol - $pos);
					$pos  = $eol + 2;

					if ($line === '') {
						return $out;
					}

					if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:/', $line) !== 1) {
						throw new TransportError(FailureClass::Malformed, 'trailer');
					}
				}
			}

			if (strlen($raw) < $pos + $size + 2 || substr($raw, $pos + $size, 2) !== "\r\n") {
				throw new TransportError(FailureClass::Malformed, 'chunk data');
			}

			$out .= substr($raw, $pos, $size);
			$pos += $size + 2;
		}
	}

	/**
	 * Classify.
	 *
	 * @param string $message
	 * @return FailureClass
	 */
	private static function classify(string $message): FailureClass {
		$lower = strtolower($message);

		return match (true) {
			str_contains($lower, 'did not match expected')                                                   => FailureClass::TlsName,
			str_contains($lower, 'certificate verify failed')                                                => FailureClass::TlsChain,
			str_contains($lower, 'protocol') || str_contains($lower, 'version') || str_contains($lower, 'no shared cipher') => FailureClass::TlsProtocol,
			str_contains($lower, 'timed out')                                                                => FailureClass::Timeout,
			str_contains($lower, 'refused') || str_contains($lower, 'unreachable')                           => FailureClass::ConnectRefused,
			str_contains($lower, 'ssl') || str_contains($lower, 'crypto')                                    => FailureClass::TlsProtocol,
			default                                                                                          => FailureClass::ConnectRefused,
		};
	}

	/**
	 * Clean.
	 *
	 * @param string $message
	 * @return string
	 */
	private static function clean(string $message): string {
		return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message), 0, 300);
	}
}
