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
 * Form rows shared by the plugin pages. Every value is escaped for its
 * context here, so page code passes plain text (REQ-UI-003). Forms that
 * change state are POST; csrf-magic adds its token to each.
 */
final class Html {
	public static function text(string $name, string $label, string $value, int $length, string $help = ''): string {
		return '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label>' . ($help === '' ? '' : '<br><small>' . html_escape($help) . '</small>') . '</td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '" maxlength="' . $length . '" size="' . min(60, $length) . '"></td></tr>';
	}

	/**
	 * A write-only field: always rendered empty, so a stored secret never
	 * reaches the browser (REQ-CRED-003).
	 */
	public static function secret(string $name, string $label, string $help): string {
		return '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label><br><small>' . html_escape($help) . '</small></td>'
			. '<td><input type="password" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="" autocomplete="new-password" size="40" maxlength="' . CredentialStore::MAX_PASSWORD_BYTES . '"></td></tr>';
	}

	public static function checkbox(string $name, string $label, bool $checked, string $help = ''): string {
		return '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label>' . ($help === '' ? '' : '<br><small>' . html_escape($help) . '</small>') . '</td>'
			. '<td><input type="checkbox" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="on"' . ($checked ? ' checked' : '') . '></td></tr>';
	}

	/**
	 * @param array<string|int, string> $options value => label
	 */
	public static function select(string $name, string $label, array $options, string $current, string $help = ''): string {
		$html = '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label>' . ($help === '' ? '' : '<br><small>' . html_escape($help) . '</small>') . '</td><td><select id="' . html_escape($name) . '" name="' . html_escape($name) . '">';

		foreach ($options as $value => $text) {
			$html .= '<option value="' . html_escape((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . html_escape($text) . '</option>';
		}

		return $html . '</select></td></tr>';
	}

	public static function action(string $file, int $id, string $action, string $label): string {
		return '<form method="post" action="' . html_escape($file) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	/**
	 * @param list<string> $cells plain text
	 */
	public static function row(array $cells, string $class = 'tableRow'): string {
		return '<tr class="' . html_escape($class) . '"><td>' . implode('</td><td>', array_map('html_escape', $cells)) . '</td></tr>';
	}

	/**
	 * @param list<string> $cells plain text
	 */
	public static function header(array $cells): string {
		return '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $cells)) . '</th></tr>';
	}

	public static function age(int $seconds): string {
		$seconds = max(0, $seconds);

		return match (true) {
			$seconds < 120    => __('%d seconds ago', $seconds, 'redfish'),
			$seconds < 7200   => __('%d minutes ago', intdiv($seconds, 60), 'redfish'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'redfish'),
			default           => __('%d days ago', intdiv($seconds, 86400), 'redfish'),
		};
	}
}
