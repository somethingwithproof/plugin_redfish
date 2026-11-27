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

/**
 * Scan every production PHP file in plugin_redfish with token_get_all() and
 * return any class, interface, trait, enum, constant, property, method, or
 * named function that lacks a preceding PHPDoc block.
 *
 * @return list<string>
 */
function redfish_missing_phpdoc_symbols(): array {
	$root     = dirname(__DIR__, 2);
	$files    = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen($root) + 1);

		if (
			$file->getExtension() === 'php'
			&& !str_starts_with($relative, 'tests/')
			&& !str_starts_with($relative, '.git/')
			&& !str_starts_with($relative, 'vendor/')
		) {
			$files[] = $relative;
		}
	}

	sort($files);

	$missing = [];

	foreach ($files as $relative) {
		$tokens     = token_get_all((string) file_get_contents($root . '/' . $relative));
		$count      = count($tokens);
		$braceDepth = 0;
		$parenDepth = 0;
		$typeStack  = [];

		$prevSignificant = static function (int $pos) use ($tokens): array|string|null {
			for ($j = $pos - 1; $j >= 0; $j--) {
				$t = $tokens[$j];

				if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}

				return $t;
			}

			return null;
		};

		$nextSignificant = static function (int $pos) use ($tokens, $count): array|string|null {
			for ($j = $pos + 1; $j < $count; $j++) {
				$t = $tokens[$j];

				if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}

				return $t;
			}

			return null;
		};

		$hasDocBlock = static function (int $pos, bool $allowTypeTokens = false) use ($tokens): bool {
			$attrDepth = 0;

			for ($j = $pos - 1; $j >= 0; $j--) {
				$t = $tokens[$j];

				if ($attrDepth > 0) {
					if ($t === ']') {
						$attrDepth++;
					} elseif (is_array($t) && $t[0] === T_ATTRIBUTE) {
						$attrDepth--;
					}

					continue;
				}

				if ($t === ']') {
					$attrDepth = 1;

					continue;
				}

				if (is_array($t)) {
					if ($t[0] === T_DOC_COMMENT) {
						return true;
					}

					if (in_array($t[0], [T_WHITESPACE, T_FINAL, T_ABSTRACT, T_READONLY, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR], true)) {
						continue;
					}

					if ($allowTypeTokens && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE], true)) {
						continue;
					}

					return false;
				}

				if ($allowTypeTokens && in_array($t, ['?', '|', '&'], true)) {
					continue;
				}

				return false;
			}

			return false;
		};

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token)) {
				if ($token === '{') {
					$braceDepth++;
				} elseif ($token === '}') {
					$braceDepth--;

					if ($typeStack !== [] && end($typeStack) === $braceDepth) {
						array_pop($typeStack);
					}
				} elseif ($token === '(') {
					$parenDepth++;
				} elseif ($token === ')') {
					$parenDepth--;
				}

				continue;
			}

			$id   = $token[0];
			$line = $token[2];

			if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$braceDepth++;

				continue;
			}

			if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
				$prev = $prevSignificant($i);
				$next = $nextSignificant($i);

				if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
					continue;
				}

				if (!is_array($next) || $next[0] !== T_STRING) {
					continue;
				}

				$typeStack[] = $braceDepth;

				if (!$hasDocBlock($i)) {
					$missing[] = sprintf('%s:%d %s %s', $relative, $line, $token[1], $next[1]);
				}
			} elseif ($id === T_FUNCTION) {
				$prev = $prevSignificant($i);

				if (is_array($prev) && $prev[0] === T_USE) {
					continue;
				}

				$next = $nextSignificant($i);

				if ($next === '&') {
					$k = $i + 1;

					while ($k < $count && $tokens[$k] !== '&') {
						$k++;
					}

					$next = $nextSignificant($k);
				}

				if (!is_array($next) || $next[0] !== T_STRING) {
					continue;
				}

				$kind = ($typeStack !== [] && $braceDepth === end($typeStack) + 1) ? 'method' : 'function';

				if (!$hasDocBlock($i)) {
					$missing[] = sprintf('%s:%d %s %s', $relative, $line, $kind, $next[1]);
				}
			} elseif ($id === T_CONST) {
				$prev = $prevSignificant($i);

				if (is_array($prev) && $prev[0] === T_USE) {
					continue;
				}

				$next = $nextSignificant($i);
				$name = is_array($next) ? $next[1] : '<const>';

				if (!$hasDocBlock($i)) {
					$missing[] = sprintf('%s:%d const %s', $relative, $line, $name);
				}
			} elseif ($id === T_VARIABLE && $parenDepth === 0 && $typeStack !== [] && $braceDepth === end($typeStack) + 1) {
				$prev = $prevSignificant($i);

				if ($prev === '=') {
					continue;
				}

				$isProperty = false;

				for ($j = $i - 1; $j >= 0; $j--) {
					$p = $tokens[$j];

					if (is_array($p) && in_array($p[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
						$isProperty = true;

						break;
					}

					if ($p === ';' || $p === '{' || $p === '}') {
						break;
					}
				}

				if ($isProperty && !$hasDocBlock($i, true)) {
					$missing[] = sprintf('%s:%d property %s', $relative, $line, $token[1]);
				}
			}
		}
	}

	return $missing;
}

it('enforces 100% PHPDoc coverage across every production PHP symbol', function () {
	$missing = redfish_missing_phpdoc_symbols();

	expect($missing)->toBe([], "Missing PHPDoc blocks:\n" . implode("\n", $missing));
});
