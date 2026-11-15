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

/*
 * Redfish Credentials (Redfish Admin realm). Parses on PHP 8.0;
 * redfish_page_start() refuses an unsupported runtime before any plugin
 * class loads.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/redfish/setup.php');

if (!redfish_page_start()) {
	exit;
}

redfish_require_post('redfish_credentials.php', array('save', 'delete'));

$redfish_credentials = redfish_credentials();
$redfish_store       = redfish_credential_store();
$redfish_page        = new \Somethingwithproof\Redfish\CredentialsPage($redfish_credentials, $redfish_store, redfish_bmcs(), redfish_device_scope());
$redfish_user        = redfish_user_id();

switch ((string) get_nfilter_request_var('action')) {
	case 'save':
		$redfish_id       = (int) get_filter_request_var('id');
		$redfish_name     = trim((string) get_nfilter_request_var('name'));
		$redfish_username = trim((string) get_nfilter_request_var('username'));
		$redfish_existing = $redfish_id > 0 ? $redfish_credentials->find($redfish_id) : null;
		$redfish_errors   = array();

		if ($redfish_id > 0 && $redfish_existing === null) {
			header('Location: redfish_credentials.php');
			exit;
		}

		// A changed credential reaches every BMC that uses it, so each of
		// those BMCs must be on a device the user may see.
		if ($redfish_existing !== null && !$redfish_page->visible($redfish_existing)) {
			raise_message('redfish_denied', __('This credential is used by a BMC on a Cacti device you do not have access to, so it cannot be changed here.', 'redfish'), MESSAGE_LEVEL_ERROR);
			header('Location: redfish_credentials.php');
			exit;
		}

		if ($redfish_name === '' || mb_strlen($redfish_name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $redfish_name) === 1) {
			$redfish_errors['name'] = __('Name: enter 1 to 100 characters without control characters.', 'redfish');
		} elseif ($redfish_credentials->nameTaken($redfish_name, $redfish_id)) {
			$redfish_errors['name'] = __('Name: another credential already uses this name.', 'redfish');
		}

		if ($redfish_username === '' || strlen($redfish_username) > 255 || preg_match('/[\x00-\x1f\x7f]/', $redfish_username) === 1) {
			$redfish_errors['username'] = __('User Name: enter 1 to 255 characters without control characters.', 'redfish');
		}

		$redfish_raw = (string) get_nfilter_request_var('password');

		if ($redfish_existing === null && $redfish_raw === '') {
			$redfish_errors['password'] = __('Password: enter the password of the BMC account.', 'redfish');
		}

		if ($redfish_errors !== array()) {
			unset($redfish_raw);
			top_header();
			// The rejected password is never echoed back.
			$redfish_page->renderEdit($redfish_existing, array('name' => $redfish_name, 'username' => $redfish_username), $redfish_errors);
			bottom_footer();
			exit;
		}

		$redfish_saved = $redfish_credentials->save($redfish_id, $redfish_name, $redfish_username, $redfish_user);

		if ($redfish_saved > 0 && $redfish_raw !== '') {
			try {
				$redfish_store->storePassword($redfish_saved, new \Somethingwithproof\Redfish\Secret($redfish_raw), $redfish_user);
			} catch (\Somethingwithproof\Redfish\CredentialError $redfish_error) {
				raise_message('redfish_password', __('The credential was saved, but the password was not stored: %s', $redfish_error->getMessage(), 'redfish'), MESSAGE_LEVEL_ERROR);
			}
		}

		unset($redfish_raw);

		raise_message('redfish_saved', $redfish_saved > 0 ? __('The credential was saved.', 'redfish') : __('The credential could not be saved.', 'redfish'), $redfish_saved > 0 ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);

		header('Location: redfish_credentials.php');
		exit;
	case 'delete':
		if ($redfish_credentials->delete((int) get_filter_request_var('id'), $redfish_user)) {
			raise_message('redfish_deleted', __('The credential and its stored password were deleted.', 'redfish'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('redfish_deleted', __('The credential was not deleted. A credential in use by a BMC cannot be deleted.', 'redfish'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: redfish_credentials.php');
		exit;
	case 'edit':
		$redfish_id       = (int) get_filter_request_var('id');
		$redfish_existing = $redfish_id > 0 ? $redfish_credentials->find($redfish_id) : null;

		if ($redfish_existing !== null && !$redfish_page->visible($redfish_existing)) {
			raise_message('redfish_denied', __('This credential is used by a BMC on a Cacti device you do not have access to, so it cannot be changed here.', 'redfish'), MESSAGE_LEVEL_ERROR);
			header('Location: redfish_credentials.php');
			exit;
		}

		top_header();
		$redfish_page->renderEdit($redfish_existing);
		bottom_footer();
		exit;
	default:
		top_header();
		$redfish_page->renderList();
		bottom_footer();
}
