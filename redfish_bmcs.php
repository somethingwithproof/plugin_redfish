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
 * Redfish BMCs (Redfish Admin realm). Parses on PHP 8.0;
 * redfish_page_start() refuses an unsupported runtime before any plugin
 * class loads. No request here contacts a BMC.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/redfish/setup.php');

if (!redfish_page_start()) {
	exit;
}

redfish_require_post('redfish_bmcs.php', array('save', 'delete', 'retry', 'import'));

$redfish_bmcs  = redfish_bmcs();
$redfish_scope = redfish_device_scope();
$redfish_page  = new \Somethingwithproof\Redfish\BmcsPage($redfish_bmcs, redfish_credentials(), $redfish_scope);
$redfish_user  = redfish_user_id();

/**
 * The BMC named by the request, or null when it does not exist or sits on a
 * Cacti device the user may not see. Every action on an existing BMC goes
 * through here before it reads or writes anything else.
 */
function redfish_requested_bmc(\Somethingwithproof\Redfish\BmcRepository $bmcs, \Somethingwithproof\Redfish\DeviceScope $scope, int $id): ?\Somethingwithproof\Redfish\Bmc {
	$bmc = $id > 0 ? $bmcs->find($id) : null;

	if ($bmc !== null && !$scope->allowsBmc($bmc)) {
		raise_message('redfish_denied', __('You do not have access to the Cacti device of this BMC.', 'redfish'), MESSAGE_LEVEL_ERROR);

		return null;
	}

	return $bmc;
}

switch ((string) get_nfilter_request_var('action')) {
	case 'save':
		$redfish_id    = (int) get_filter_request_var('id');
		$redfish_input = array();

		foreach (\Somethingwithproof\Redfish\BmcInput::FIELDS as $redfish_field) {
			$redfish_input[$redfish_field] = (string) get_nfilter_request_var($redfish_field);
		}

		$redfish_existing = redfish_requested_bmc($redfish_bmcs, $redfish_scope, $redfish_id);

		if ($redfish_id > 0 && $redfish_existing === null) {
			header('Location: redfish_bmcs.php');
			exit;
		}

		$redfish_parsed = \Somethingwithproof\Redfish\BmcInput::parse($redfish_input, $redfish_id);
		$redfish_errors = $redfish_parsed->errors;

		if ($redfish_parsed->bmc !== null) {
			$redfish_errors = array_merge($redfish_errors, $redfish_page->databaseErrors($redfish_parsed->bmc));
		}

		if ($redfish_errors !== array() || $redfish_parsed->bmc === null) {
			top_header();
			$redfish_page->renderEdit($redfish_existing, $redfish_input, $redfish_errors);
			bottom_footer();
			exit;
		}

		$redfish_saved = $redfish_bmcs->save($redfish_parsed->bmc, $redfish_user);

		raise_message('redfish_saved', $redfish_saved > 0 ? __('The BMC was saved.', 'redfish') : __('The BMC could not be saved.', 'redfish'), $redfish_saved > 0 ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR);

		header('Location: redfish_bmcs.php');
		exit;
	case 'delete':
		$redfish_bmc = redfish_requested_bmc($redfish_bmcs, $redfish_scope, (int) get_filter_request_var('id'));

		if ($redfish_bmc !== null && $redfish_bmcs->delete($redfish_bmc->id, $redfish_user)) {
			raise_message('redfish_deleted', __('The BMC and its cached data were deleted. Its Cacti device was left in place.', 'redfish'), MESSAGE_LEVEL_INFO);
		}

		header('Location: redfish_bmcs.php');
		exit;
	case 'retry':
		$redfish_bmc = redfish_requested_bmc($redfish_bmcs, $redfish_scope, (int) get_filter_request_var('id'));

		if ($redfish_bmc !== null) {
			(new \Somethingwithproof\Redfish\HealthRepository(false))->clearHolds($redfish_bmc->id);
			raise_message('redfish_retry', __('Backoff and held failures were cleared; the collector reads the BMC on its next run.', 'redfish'), MESSAGE_LEVEL_INFO);
		}

		header('Location: redfish_bmcs.php');
		exit;
	case 'import':
		$redfish_csv = '';

		if (isset($_FILES['import_file']['tmp_name'], $_FILES['import_file']['size']) && is_string($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name']) && (int) $_FILES['import_file']['size'] <= \Somethingwithproof\Redfish\BmcImport::MAX_BYTES) {
			$redfish_csv = (string) file_get_contents($_FILES['import_file']['tmp_name']);
		}

		$redfish_import = \Somethingwithproof\Redfish\BmcImport::parse(
			$redfish_csv,
			$redfish_bmcs->credentialIds(),
			function (string $address, int $port) use ($redfish_bmcs): ?string {
				return $redfish_bmcs->addressTakenBy($address, $port, 0);
			},
			function (string $name) use ($redfish_bmcs): bool {
				return $redfish_bmcs->nameTaken($name, 0);
			},
			function (int $host) use ($redfish_scope): bool {
				return $redfish_scope->allowsNew($host);
			}
		);

		unset($redfish_csv);

		if ($redfish_import->errors !== array() || !$redfish_bmcs->import($redfish_import->bmcs, $redfish_user)) {
			top_header();
			$redfish_page->renderImport($redfish_import->errors !== array() ? $redfish_import->errors : array(__('The import could not be saved; nothing was imported.', 'redfish')));
			bottom_footer();
			exit;
		}

		raise_message('redfish_imported', __('%d BMCs were imported.', count($redfish_import->bmcs), 'redfish'), MESSAGE_LEVEL_INFO);

		header('Location: redfish_bmcs.php');
		exit;
	case 'export':
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="redfish-bmcs.json"');
		header('X-Content-Type-Options: nosniff');
		print $redfish_page->exportJson();
		exit;
	case 'edit':
		$redfish_id = (int) get_filter_request_var('id');

		$redfish_bmc = redfish_requested_bmc($redfish_bmcs, $redfish_scope, $redfish_id);

		if ($redfish_id > 0 && $redfish_bmc === null) {
			header('Location: redfish_bmcs.php');
			exit;
		}

		top_header();
		$redfish_page->renderEdit($redfish_bmc);
		bottom_footer();
		exit;
	default:
		top_header();
		$redfish_page->renderList();
		bottom_footer();
}
