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

/*
 * Source rules from docs/PLAN.md "Code structure" and ADR 0004 that a parser
 * can check. The PHP 8.0 parse of the entry points themselves is proven by the
 * CI lint job on a real PHP 8.0; these checks catch the same mistakes earlier
 * and cover the rules a lint cannot.
 */

function redfish_root(): string {
	return dirname(__DIR__, 2);
}

/**
 * @return list<string> plugin PHP files outside tests/, relative to the root
 */
function redfish_sources(): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(redfish_root(), FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(redfish_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * @return list<string> files Cacti may include before the PHP version check
 */
function redfish_entry_points(): array {
	return array_values(array_filter(redfish_sources(), fn (string $f) => !str_starts_with($f, 'lib/')));
}

it('keeps every PHP source file within the gettext scan depth', function () {
	foreach (redfish_sources() as $file) {
		expect(substr_count($file, '/'))->toBeLessThanOrEqual(1, $file);
	}
});

it('keeps entry points free of PHP 8.1 and later syntax', function () {
	$banned = [T_ENUM => 'enum', T_READONLY => 'readonly'];

	foreach (redfish_entry_points() as $file) {
		$tokens = PhpToken::tokenize((string) file_get_contents(redfish_root() . '/' . $file));

		foreach ($tokens as $i => $token) {
			expect(isset($banned[$token->id]))->toBeFalse("$file:{$token->line} uses {$token->text}");

			// "never" as a return type and "new" in an initializer are 8.1.
			if ($token->is(T_STRING) && strtolower($token->text) === 'never') {
				$previous = $tokens[$i - 1]->is(T_WHITESPACE) ? $tokens[$i - 2] : $tokens[$i - 1];
				expect($previous->text)->not->toBe(':', "$file:{$token->line} uses never");
			}
		}
	}
});

it('uses no PHP 8.3 or later features anywhere', function () {
	foreach (redfish_sources() as $file) {
		$source = (string) file_get_contents(redfish_root() . '/' . $file);
		$tokens = PhpToken::tokenize($source);

		expect($source)->not->toContain('json_validate(')
			->and($source)->not->toContain('#[\\Override]')
			->and($source)->not->toContain('#[Override]');

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_CONST)) {
				continue;
			}

			// A typed constant has two identifiers between const and "=".
			$names = 0;

			for ($j = $i + 1; $j < count($tokens) && $tokens[$j]->text !== '='; $j++) {
				$names += $tokens[$j]->is(T_STRING) ? 1 : 0;
			}

			expect($names)->toBe(1, "$file:{$token->line} declares a typed constant");
		}
	}
});

it('defines exactly one class, interface, or enum per lib file, named after the file', function () {
	foreach (glob(redfish_root() . '/lib/*.php') as $path) {
		$tokens = PhpToken::tokenize((string) file_get_contents($path));
		$names  = [];

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) && !$tokens[$i - 1]->is(T_DOUBLE_COLON)) {
				$next = $tokens[$i + 1]->is(T_WHITESPACE) ? $tokens[$i + 2] : $tokens[$i + 1];

				if ($next->is(T_STRING)) {
					$names[] = $next->text;
				}
			}
		}

		expect($names)->toBe([basename($path, '.php')], basename($path))
			->and((string) file_get_contents($path))->toContain("namespace Somethingwithproof\\Redfish;");
	}
});

it('keeps lib classes away from request variables and globals', function () {
	foreach (glob(redfish_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|SESSION|SERVER|COOKIE)\b/', basename($path))
			->and($source)->not->toMatch('/\bglobal\s+\$/', basename($path))
			->and($source)->not->toContain('$GLOBALS', basename($path));
	}
});

it('issues SQL only from repository and schema classes, through prepared helpers', function () {
	foreach (redfish_sources() as $file) {
		$source = (string) file_get_contents(redfish_root() . '/' . $file);

		expect($source)->not->toMatch('/\bdb_(execute|fetch_cell|fetch_row|fetch_assoc)\s*\(/', $file);

		// include/unbooted-uninstall.php is the one exception: it runs where
		// lib/ cannot load (see its header).
		$repository = str_starts_with($file, 'lib/') && preg_match('/^(Schema|\w+Repository)\.php$/', basename($file)) === 1;

		if (!$repository && $file !== 'include/unbooted-uninstall.php') {
			expect($source)->not->toMatch('/\bdb_\w+_prepared\s*\(/', $file);
		}
	}
});

it('passes the redfish text domain to every translated plugin string', function () {
	// Labels intentionally reused from core keep the core domain.
	$core = ['Management'];

	foreach (redfish_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(redfish_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_STRING) || $token->text !== '__' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1]->is(T_FUNCTION))) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ($j = $i + 1; $j < count($tokens); $j++) {
				$depth += $tokens[$j]->text === '(' ? 1 : ($tokens[$j]->text === ')' ? -1 : 0);

				if ($depth === 0) {
					break;
				}

				if ($depth === 1 && $tokens[$j]->text !== ',' && $tokens[$j]->text !== '(') {
					$last = $tokens[$j];
				}
			}

			$first = $tokens[$i + 2];

			if ($first->is(T_CONSTANT_ENCAPSED_STRING) && in_array(trim($first->text, '\'"'), $core, true)) {
				continue;
			}

			expect($last?->text)->toBe("'redfish'", "$file:{$token->line}");
		}
	}
});

it('ships a translation template containing every plugin string', function () {
	$pot = (string) file_get_contents(redfish_root() . '/locales/po/cacti.pot');

	foreach (['The Redfish plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.'] as $msgid) {
		expect($pot)->toContain('msgid "' . $msgid . '"');
	}
});

it('formats translated strings with __() itself, never sprintf(__())', function () {
	// Core's __() hands the text domain to sprintf(), so a placeholder in
	// sprintf(__("%d", "redfish"), $n) takes "redfish" and prints 0 or the
	// domain. The values go inside the call: __("%d", $n, "redfish").
	foreach (redfish_sources() as $file) {
		expect((string) file_get_contents(redfish_root() . '/' . $file))->not->toContain('sprintf(__(', $file);
	}
});

it('uses plain placeholders in translated strings, because core\'s __() does not format positional ones', function () {
	// __() only formats a string that has %d, %s, and the like. A string with
	// only %1$d placeholders comes back raw.
	foreach (redfish_sources() as $file) {
		$source = (string) file_get_contents(redfish_root() . '/' . $file);

		preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $texts);

		foreach ($texts[1] as $text) {
			expect($text)->not->toMatch('/%[0-9]+\$/', $file);
		}
	}
});

it('logs through cacti_log() under the REDFISH tag only', function () {
	foreach (redfish_sources() as $file) {
		$code = '';

		foreach (PhpToken::tokenize((string) file_get_contents(redfish_root() . '/' . $file)) as $token) {
			$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
		}

		preg_match_all('/\bcacti_log\s*\((.*?)\);/s', $code, $calls);

		foreach ($calls[1] as $arguments) {
			expect(str_contains($arguments, "'REDFISH'"))->toBeTrue($file);
		}
	}
});

it('ships no Composer runtime dependencies', function () {
	$manifest = redfish_root() . '/composer.json';

	if (is_file($manifest)) {
		$require = json_decode((string) file_get_contents($manifest), true)['require'] ?? [];

		foreach (array_keys($require) as $package) {
			expect($package === 'php' || str_starts_with($package, 'ext-'))->toBeTrue($package);
		}
	}

	expect(is_dir(redfish_root() . '/vendor'))->toBeFalse();
});

it('never enables the loopback test binding outside tests', function () {
	foreach (redfish_sources() as $file) {
		$source = (string) file_get_contents(redfish_root() . '/' . $file);

		expect($source)->not->toMatch('/allowLoopback\s*:\s*true|,\s*true\s*\)\s*;\s*\/\/\s*loopback/', $file);
	}

	// ClientFactory::production() and the factory call in setup.php leave
	// it at its false default.
	$factory = (string) file_get_contents(redfish_root() . '/lib/ClientFactory.php');
	expect($factory)->toContain('return new self(new SystemResolver(), (float) $settings->connectTimeout, (float) $settings->requestTimeout, $settings->maxBodyBytes);');
});

it('sends HTTP only through the socket transport, with no curl or stream wrapper fetch', function () {
	foreach (redfish_sources() as $file) {
		$source = (string) file_get_contents(redfish_root() . '/' . $file);

		expect($source)->not->toMatch('/\bcurl_\w+\s*\(|\bfile_get_contents\s*\(\s*[\'"]https?:|\bfsockopen\s*\(/', $file);

		if ($file !== 'lib/SocketTransport.php') {
			expect($source)->not->toContain('stream_socket_client(', $file);
		}
	}
});

it('never names a write method other than the session POST and DELETE', function () {
	foreach (glob(redfish_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch("/'(PATCH|PUT)'/", basename($path));
	}
});

it('keeps TLS verification on except where the pin is checked or the BMC opted out', function () {
	$source = (string) file_get_contents(redfish_root() . '/lib/SocketTransport.php');

	// The only switch is $verify, computed from the BMC's mode.
	expect($source)->not->toMatch("/'verify_peer'\s*=>\s*false/")
		->and($source)->toContain('$verify  = $mode === TlsMode::System || $mode === TlsMode::CaBundle;')
		->and($source)->toContain('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
});

it('guards every test script outside tests/Unit against web requests', function () {
	$missing  = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(redfish_root() . '/tests', FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(redfish_root()) + 1);

		if ($file->getExtension() !== 'php' || str_starts_with($relative, 'tests/Unit/')) {
			continue;
		}

		// The guard must come before any other statement runs.
		$code   = (string) file_get_contents($file->getPathname());
		$tokens = array_values(array_filter(token_get_all($code), fn ($t) => !is_array($t) || !in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$head   = implode('', array_map(fn ($t) => is_array($t) ? $t[1] : $t, array_slice($tokens, 0, 40)));

		$head = (string) preg_replace('/^(?:(?:declare|namespace)\b[^;]*;)?/', '', $head);

		if (!str_starts_with($head, "if(PHP_SAPI!=='cli'&&PHP_SAPI!=='phpdbg'){exit(1);}")) {
			$missing[] = $relative;
		}
	}

	expect($missing)->toBe([]);
});
