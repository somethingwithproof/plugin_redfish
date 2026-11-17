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
 * Redfish BMCs (Redfish Admin): list, edit, delete, import, and export.
 * No page request contacts a BMC (REQ-UI-004); the collector does that.
 */
final class BmcsPage {
	public const FILE = 'redfish_bmcs.php';

	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly CredentialRepository $credentials,
		private readonly DeviceScope $scope,
	) {
	}

	public function renderList(): void {
		html_start_box(__('Redfish BMCs', 'redfish'), '100%', '', '3', 'center', self::FILE . '?action=edit');

		print Html::header([__('Name', 'redfish'), __('Address', 'redfish'), __('Port', 'redfish'), __('Credential', 'redfish'), __('TLS Verification', 'redfish'), __('Basic Fallback', 'redfish'), __('Device', 'redfish'), __('State', 'redfish'), __('Actions', 'redfish')]);

		$bmcs  = $this->scope->filter($this->bmcs->all());
		$names = [];

		foreach ($this->credentials->all() as $credential) {
			$names[$credential->id] = $credential->name;
		}

		if ($bmcs === []) {
			print '<tr><td colspan="9"><em>' . html_escape(__('No BMCs yet.', 'redfish')) . '</em></td></tr>';
		}

		foreach ($bmcs as $bmc) {
			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $bmc->id) . '">' . html_escape($bmc->name) . '</a></td>'
				. '<td>' . html_escape($bmc->address) . '</td>'
				. '<td>' . $bmc->port . '</td>'
				. '<td>' . html_escape($names[$bmc->credentialId] ?? __('Missing', 'redfish')) . '</td>'
				. '<td>' . html_escape($bmc->tlsMode->label()) . '</td>'
				. '<td>' . html_escape($bmc->basicFallback ? __('Allowed', 'redfish') : __('Off', 'redfish')) . '</td>'
				. '<td>' . html_escape($bmc->hostId > 0 ? (string) $bmc->hostId : '-') . '</td>'
				. '<td>' . html_escape($bmc->enabled() ? __('Enabled', 'redfish') : __('Disabled', 'redfish')) . '</td>'
				. '<td>' . Html::action(self::FILE, $bmc->id, 'retry', __('Retry Now', 'redfish'))
				. ' ' . Html::action(self::FILE, $bmc->id, 'provision', $bmc->hostId > 0 ? __('Add Graphs', 'redfish') : __('Create Device', 'redfish'))
				. ' ' . Html::action(self::FILE, $bmc->id, 'delete', __('Delete', 'redfish')) . '</td>'
				. '</tr>';
		}

		html_end_box();

		$this->renderImport([]);

		print '<p><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=export') . '">' . html_escape(__('Export the BMC registry as JSON (no secrets)', 'redfish')) . '</a></p>';

		print '<p>' . Html::action(self::FILE, 0, 'templates', __('Reinstall the Redfish Templates', 'redfish')) . '</p>';
	}

	/**
	 * The confirmation page of a device creation or graph attach: what will
	 * be added, and a form that does it (REQ-DEV-002). Names only the BMC,
	 * never its address or credential.
	 *
	 * @param list<string> $plan
	 */
	public function renderProvision(Bmc $bmc, array $plan, ?string $refusal): void {
		html_start_box($bmc->hostId > 0 ? __('Add Redfish graphs for %s', html_escape($bmc->name), 'redfish') : __('Create a Cacti device for %s', html_escape($bmc->name), 'redfish'), '100%', '', '3', 'center', '');

		if ($refusal !== null) {
			print '<tr><td class="textError">' . html_escape($refusal) . '</td></tr>';
		} elseif ($plan === []) {
			print '<tr><td>' . html_escape(__('Everything is already in place. Confirming indexes the data queries again and adds graphs for new members only.', 'redfish')) . '</td></tr>';
		} else {
			print '<tr><td>' . html_escape(__('Confirming adds:', 'redfish')) . '<ul>';

			foreach ($plan as $line) {
				print '<li>' . html_escape($line) . '</li>';
			}

			print '</ul>' . html_escape(__('Existing devices, graphs, and thresholds are not changed.', 'redfish')) . '</td></tr>';
		}

		html_end_box();

		if ($refusal === null) {
			print Html::action(self::FILE, $bmc->id, 'provision_confirm', __('Confirm', 'redfish'));
		}

		print ' <a class="ui-button" href="' . html_escape(self::FILE) . '">' . html_escape(__('Cancel', 'redfish')) . '</a>';
	}

	/**
	 * @param list<string> $errors
	 */
	public function renderImport(array $errors): void {
		print '<form method="post" action="' . html_escape(self::FILE) . '" enctype="multipart/form-data">';
		html_start_box(__('Import BMCs', 'redfish'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		print '<tr><td>' . html_escape(__('CSV File', 'redfish')) . '<br><small>' . html_escape(__('Header row with columns: %s. Name a stored credential; never put a password in the file. Every row must be valid or nothing is imported.', implode(', ', BmcImport::COLUMNS), 'redfish')) . '</small></td>'
			. '<td><input type="file" name="import_file" accept=".csv,text/csv"></td></tr>';

		html_end_box();

		print '<input type="hidden" name="action" value="import">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Import', 'redfish')) . '">'
			. '</form>';
	}

	/**
	 * @param array<string, mixed>  $input
	 * @param array<string, string> $errors
	 */
	public function renderEdit(?Bmc $bmc, array $input = [], array $errors = []): void {
		$value = static fn (string $field, string $current): string => array_key_exists($field, $input) ? Row::string($input, $field) : $current;
		$flag  = static fn (string $field, bool $current): bool => $input === [] ? $current : Row::bool($input, $field);

		$credentials = [0 => __('Choose a credential', 'redfish')];

		// The BMC's current credential stays listed so a save that changes
		// nothing else keeps it; others only when the user may bind them.
		foreach ($this->credentials->all() as $credential) {
			if ($credential->id === $bmc?->credentialId || $this->scope->allowsCredential($this->bmcs, $credential->id)) {
				$credentials[$credential->id] = $credential->name;
			}
		}

		$hosts   = [0 => __('None', 'redfish')] + array_filter($this->bmcs->hostChoices(), fn (int $id): bool => $this->scope->allowsNew($id), ARRAY_FILTER_USE_KEY);
		$hostId  = (int) $value('host_id', (string) ($bmc->hostId ?? 0));

		// A device deleted since it was mapped stays selected, so saving the
		// form does not silently drop the mapping.
		if ($hostId > 0 && !isset($hosts[$hostId])) {
			$hosts[$hostId] = __('Deleted device (ID %d)', $hostId, 'redfish');
		}

		$modes = [];

		foreach (TlsMode::cases() as $mode) {
			$modes[$mode->value] = $mode->label();
		}

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($bmc === null ? __('New Redfish BMC', 'redfish') : __('Redfish BMC: %s', html_escape($bmc->name), 'redfish'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		print Html::text('name', __('Name', 'redfish'), $value('name', $bmc->name ?? ''), 100);
		print Html::text('address', __('Address', 'redfish'), $value('address', $bmc->address ?? ''), 253, __('Host name or IP address of the BMC, without https:// or a path.', 'redfish'));
		print Html::text('port', __('Port', 'redfish'), $value('port', (string) ($bmc->port ?? 443)), 5);
		print Html::select('credential_id', __('Credential', 'redfish'), $credentials, $value('credential_id', (string) ($bmc->credentialId ?? 0)));
		print Html::select('tls_mode', __('TLS Verification', 'redfish'), $modes, $value('tls_mode', $bmc->tlsMode->value ?? TlsMode::System->value), __('Do not verify is logged when set and shown as a warning on the health page while it stays.', 'redfish'));
		print Html::text('ca_file', __('CA Bundle File', 'redfish'), $value('ca_file', $bmc->caFile ?? ''), 512, __('For the CA bundle mode: absolute path on the poller to a PEM file that others cannot write.', 'redfish'));
		print Html::text('pin_sha256', __('Public Key Pin', 'redfish'), $value('pin_sha256', $bmc->pin ?? ''), 51, __('For the pinned mode: base64 SHA-256 of the BMC public key, as sha256//... or the bare hash. A changed key fails until this is updated.', 'redfish'));
		print Html::checkbox('basic_fallback', __('Allow Basic Authentication Fallback', 'redfish'), $flag('basic_fallback', $bmc->basicFallback ?? false), __('Used only when the BMC offers no session service. Every request then carries the password; the health page warns while it is in use.', 'redfish'));
		print Html::select('host_id', __('Device', 'redfish'), $hosts, (string) $hostId, __('The Cacti device of the server this BMC manages. Leave it at none to have the plugin create a device from the list.', 'redfish'));
		print Html::checkbox('enabled', __('Enabled', 'redfish'), $flag('enabled', $bmc?->enabled() ?? true));

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="poller_id" value="' . Bmc::MAIN_POLLER . '">'
			. '<input type="hidden" name="id" value="' . ($bmc->id ?? 0) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'redfish')) . '">'
			. '</form>';
	}

	/**
	 * Further checks against the database for a parsed BMC.
	 *
	 * @return array<string, string>
	 */
	public function databaseErrors(Bmc $bmc): array {
		$errors = [];
		$holder = $this->bmcs->addressHolder($bmc->address, $bmc->port, $bmc->id);

		// A BMC on a hidden device is not named, nor its address confirmed
		// beyond what the user typed.
		if ($holder !== null) {
			$errors['address'] = $this->scope->allowsBmc($holder)
				? __('Address: %s port %d is already registered as %s.', $bmc->address, $bmc->port, $holder->name, 'redfish')
				: __('Address: this address and port are already registered.', 'redfish');
		}

		if ($this->bmcs->nameTaken($bmc->name, $bmc->id)) {
			$errors['name'] = __('Name: another BMC already uses this name.', 'redfish');
		}

		$before = $bmc->id > 0 ? $this->bmcs->find($bmc->id) : null;

		// The poller sends the credential to the address, so a new BMC, or a
		// change to where or how it connects or to its credential, needs a
		// credential the user may bind: every BMC already using it must be
		// on a device the user may see. A hidden credential reads as
		// missing.
		if (!$this->bmcs->credentialExists($bmc->credentialId) || ($this->rebinds($before, $bmc) && !$this->scope->allowsCredential($this->bmcs, $bmc->credentialId))) {
			$errors['credential_id'] = __('Credential: choose a stored credential.', 'redfish');
		}

		// A mapping to a device deleted since may stay; a new mapping must
		// name an existing device the user may see. Both cases read the same,
		// so the message does not tell whether a hidden device exists.
		if ($bmc->hostId > 0 && $before?->hostId !== $bmc->hostId && !$this->scope->allowsNew($bmc->hostId)) {
			$errors['host_id'] = __('Device: no Cacti device has this ID.', 'redfish');
		}

		// The script server finds a BMC by its device (ADR 0015).
		if (!isset($errors['host_id']) && $bmc->hostId > 0 && $this->bmcs->findByHost($bmc->hostId, $bmc->id) !== null) {
			$errors['host_id'] = __('Device: another BMC is already mapped to this device.', 'redfish');
		}

		return $errors;
	}

	private function rebinds(?Bmc $before, Bmc $after): bool {
		return $before === null
			|| $before->address !== $after->address
			|| $before->port !== $after->port
			|| $before->tlsMode !== $after->tlsMode
			|| $before->caFile !== $after->caFile
			|| $before->pin !== $after->pin
			|| $before->credentialId !== $after->credentialId;
	}

	/**
	 * The export document. JSON_HEX_* keeps it inert if opened as HTML.
	 */
	public function exportJson(): string {
		return (string) json_encode(['plugin' => 'redfish', 'bmcs' => $this->bmcs->export($this->scope)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
}
