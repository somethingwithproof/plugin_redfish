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
 * The checks made on the vault key file: an absolute
 * path to a regular file, not a symbolic link, not readable or writable by
 * others, and of bounded size (REQ-CRED-004). The file is
 * opened once and every check after the link test is made on that open
 * handle, so a file swapped between check and read is not read.
 */
final class SecureFile {
	public static function pathAcceptable(string $path): bool {
		return $path !== '' && strlen($path) <= 512 && $path[0] === '/' && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1 && !str_contains($path, '/../') && !str_ends_with($path, '/..');
	}

	/**
	 * @throws CredentialError
	 */
	public static function read(string $path, int $maxBytes): string {
		$handle = self::open($path, $maxBytes);

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
	public static function check(string $path, int $maxBytes): void {
		fclose(self::open($path, $maxBytes));
	}

	/**
	 * @return resource an open handle that passed every check
	 *
	 * @throws CredentialError
	 */
	private static function open(string $path, int $maxBytes) {
		if (!self::pathAcceptable($path)) {
			throw CredentialError::because('the path is not an absolute path');
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

			if (($stat['mode'] & 0006) !== 0) {
				throw CredentialError::because('the file is readable or writable by other users');
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
