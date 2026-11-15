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
 * Checks on files the plugin trusts: the vault key file and CA bundles.
 *
 * The path must be absolute and name a regular file, not a symbolic link,
 * with no symbolic link among its parent directories. The directory holding
 * it must be owned by the poller's effective user or root and not writable
 * by group or others, so nobody else can swap the file. The file must not be
 * writable by group or others. A secret file (the vault key) must also be
 * owned by the poller's effective user and not readable by others. The file
 * is opened once and every check after the link test is made on that open
 * handle, so a file swapped between check and read is not read
 * (REQ-CRED-004, REQ-TLS-002).
 */
final class SecureFile {
	public static function pathAcceptable(string $path): bool {
		return $path !== '' && strlen($path) <= 512 && $path[0] === '/' && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1 && !str_contains($path, '/../') && !str_ends_with($path, '/..');
	}

	/**
	 * @throws CredentialError
	 */
	public static function read(string $path, int $maxBytes, bool $secret = true): string {
		$handle = self::open($path, $maxBytes, $secret);

		try {
			$content = stream_get_contents($handle, $maxBytes + 1);
		} finally {
			fclose($handle);
		}

		if (!is_string($content) || strlen($content) > $maxBytes) {
			throw CredentialError::because('the file is larger than allowed');
		}

		return $content;
	}

	/**
	 * The same checks as read(), without reading the content, for the
	 * health page.
	 *
	 * @throws CredentialError
	 */
	public static function check(string $path, int $maxBytes, bool $secret = true): void {
		fclose(self::open($path, $maxBytes, $secret));
	}

	/**
	 * @return resource an open handle that passed every check
	 *
	 * @throws CredentialError
	 */
	private static function open(string $path, int $maxBytes, bool $secret) {
		if (!self::pathAcceptable($path)) {
			throw CredentialError::because('the path is not an absolute path');
		}

		if (!function_exists('posix_geteuid')) {
			throw CredentialError::because('ext-posix is needed to check who owns the file');
		}

		$euid = posix_geteuid();
		$dir  = dirname($path);

		clearstatcache(true, $dir);

		// realpath() resolves every link on the way, so a difference means
		// a parent directory is, or passes through, a symbolic link.
		if (realpath($dir) !== $dir) {
			throw CredentialError::because('a parent directory is a symbolic link or does not exist');
		}

		$parent = lstat($dir);

		if ($parent === false || ($parent['mode'] & 0170000) !== 0040000) {
			throw CredentialError::because('a parent directory is a symbolic link or does not exist');
		}

		if (($parent['uid'] !== $euid && $parent['uid'] !== 0) || ($parent['mode'] & 0022) !== 0) {
			throw CredentialError::because('the directory holding the file must be owned by the poller user or root and not writable by group or others');
		}

		clearstatcache(true, $path);

		$link = is_link($path) || file_exists($path) ? lstat($path) : false;

		if ($link === false) {
			throw CredentialError::because('the file does not exist or cannot be read');
		}

		if (($link['mode'] & 0170000) === 0120000) {
			throw CredentialError::because('the file is a symbolic link');
		}

		// Checked before opening too: opening a FIFO without a writer blocks.
		if (($link['mode'] & 0170000) !== 0100000) {
			throw CredentialError::because('the file is not a regular file');
		}

		$handle = is_readable($path) ? fopen($path, 'rb') : false;

		if ($handle === false) {
			throw CredentialError::because('the file does not exist or cannot be read');
		}

		try {
			$stat = fstat($handle);

			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
				throw CredentialError::because('the file is not a regular file');
			}

			// The handle must be the file lstat() saw, not a replacement.
			if ($stat['dev'] !== $link['dev'] || $stat['ino'] !== $link['ino']) {
				throw CredentialError::because('the file changed while it was checked');
			}

			if (($stat['mode'] & ($secret ? 0006 : 0002)) !== 0) {
				throw CredentialError::because('the file is readable or writable by other users');
			}

			if (($stat['mode'] & 0020) !== 0) {
				throw CredentialError::because('the file is writable by its group');
			}

			if ($secret ? $stat['uid'] !== $euid : ($stat['uid'] !== $euid && $stat['uid'] !== 0)) {
				throw CredentialError::because($secret ? 'the file must be owned by the poller user' : 'the file must be owned by the poller user or root');
			}

			if ($stat['size'] > $maxBytes) {
				throw CredentialError::because('the file is larger than allowed');
			}
		} catch (CredentialError $e) {
			fclose($handle);

			throw $e;
		}

		return $handle;
	}
}
