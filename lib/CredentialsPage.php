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
 * Redfish Credentials (Redfish Admin). The password is write-only: the field
 * is always empty, and leaving it empty keeps the stored value
 * (REQ-CRED-003). The page shows only whether a password is stored.
 *
 * Only credentials the user may bind are listed or opened: a credential used
 * by a BMC on a device the user cannot see is left out (DeviceScope).
 */
final class CredentialsPage {
	/**
	 * File constant.
	 */
	public const FILE = 'redfish_credentials.php';

	/**
	 * Initialize a new CredentialsPage instance.
	 *
	 * @param CredentialRepository $credentials
	 * @param CredentialStore $store
	 * @param BmcRepository $bmcs
	 * @param DeviceScope $scope
	 */
	public function __construct(
		private readonly CredentialRepository $credentials,
		private readonly CredentialStore $store,
		private readonly BmcRepository $bmcs,
		private readonly DeviceScope $scope,
	) {
	}

	/**
	 * Visible.
	 *
	 * @param Credential $credential
	 * @return bool
	 */
	public function visible(Credential $credential): bool {
		return $this->scope->allowsCredential($this->bmcs, $credential->id);
	}

	/**
	 * Render list.
	 *
	 * @return void
	 */
	public function renderList(): void {
		html_start_box(__('Redfish Credentials', 'redfish'), '100%', '', '3', 'center', self::FILE . '?action=edit');

		print Html::header([__('Name', 'redfish'), __('User Name', 'redfish'), __('Password', 'redfish'), __('Used By', 'redfish'), __('Actions', 'redfish')]);

		$credentials = array_values(array_filter($this->credentials->all(), fn (Credential $c): bool => $this->visible($c)));

		if ($credentials === []) {
			print '<tr><td colspan="5"><em>' . html_escape(__('No credentials yet. Create one with a read-only BMC account.', 'redfish')) . '</em></td></tr>';
		}

		foreach ($credentials as $credential) {
			$usage = $this->credentials->usage($credential->id);

			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $credential->id) . '">' . html_escape($credential->name) . '</a></td>'
				. '<td>' . html_escape($credential->username) . '</td>'
				. '<td>' . html_escape($this->store->hasPassword($credential->id) ? __('Stored', 'redfish') : __('None stored', 'redfish')) . '</td>'
				. '<td>' . html_escape(__('%d BMCs', $usage, 'redfish')) . '</td>'
				. '<td>' . ($usage === 0 ? Html::action(self::FILE, $credential->id, 'delete', __('Delete', 'redfish')) : '') . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	/**
	 * @param array<string, mixed>  $input  rejected values to show again; never a password
	 * @param array<string, string> $errors
	 */
	public function renderEdit(?Credential $credential, array $input = [], array $errors = []): void {
		$value = static fn (string $field, string $current): string => array_key_exists($field, $input) ? Row::string($input, $field) : $current;

		print '<form method="post" action="' . html_escape(self::FILE) . '" autocomplete="off">';
		html_start_box($credential === null ? __('New Redfish Credential', 'redfish') : __('Redfish Credential: %s', html_escape($credential->name), 'redfish'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		print Html::text('name', __('Name', 'redfish'), $value('name', $credential->name ?? ''), 100);
		print Html::text('username', __('User Name', 'redfish'), $value('username', $credential->username ?? ''), 255, __('A BMC account with the ReadOnly role. The plugin never needs a role that can change BMC state.', 'redfish'));
		print Html::secret('password', __('Password', 'redfish'), $credential !== null && $this->store->hasPassword($credential->id) ? __('A password is stored. Leave blank to keep it.', 'redfish') : __('Stored encrypted with the vault key.', 'redfish'));

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="id" value="' . ($credential->id ?? 0) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'redfish')) . '">'
			. '</form>';
	}
}
