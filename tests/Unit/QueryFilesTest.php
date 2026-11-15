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

use Somethingwithproof\Redfish\CactiObjectRepository;
use Somethingwithproof\Redfish\TemplateInstaller;

/*
 * Query files go only into <cacti>/resource/script_server as a real
 * directory: no write or delete follows a link out of the Cacti tree.
 */

/**
 * A scratch Cacti tree, and a directory outside it.
 *
 * @return array{0: string, 1: string}
 */
function query_tree(): array {
	$base = sys_get_temp_dir() . '/redfish-query-' . bin2hex(random_bytes(4));
	mkdir("$base/cacti/resource/script_server", 0700, true);
	mkdir("$base/outside/script_server", 0700, true);

	return ["$base/cacti", "$base/outside"];
}

function query_tree_remove(string $path): void {
	if (is_link($path) || is_file($path)) {
		unlink($path);

		return;
	}

	foreach (is_dir($path) ? array_diff((array) scandir($path), ['.', '..']) : [] as $entry) {
		query_tree_remove("$path/$entry");
	}

	if (is_dir($path)) {
		rmdir($path);
	}
}

/**
 * @return array{0: ?string, 1: list<string>} the install refusal and the files with problems
 */
function query_install(string $cacti): array {
	$GLOBALS['__redfish_options'] = [];
	$installer = new TemplateInstaller(dirname(__DIR__, 2), $cacti, new CactiObjectRepository(false));
	$refusal   = (fn (): ?string => $this->installQueryFiles())->call($installer);

	return [$refusal, $installer->queryFileProblems()];
}

it('writes the query files into a real resource directory', function () {
	[$cacti, $outside] = query_tree();
	[$refusal, $problems] = query_install($cacti);

	expect($refusal)->toBeNull()
		->and($problems)->toBe([])
		->and(count(glob("$cacti/resource/script_server/redfish_*.xml")))->toBe(count(TemplateInstaller::QUERIES));

	query_tree_remove(dirname($cacti));
});

it('refuses a resource directory that is a link or leads outside the Cacti tree', function (string $link) {
	[$cacti, $outside] = query_tree();
	query_tree_remove("$cacti/$link");

	if ($link === 'resource') {
		symlink($outside, "$cacti/resource");
	} else {
		symlink("$outside/script_server", "$cacti/resource/script_server");
	}

	[$refusal, $problems] = query_install($cacti);

	expect($refusal)->toContain('is a symbolic link, or lies outside the Cacti directory')
		->and($problems)->toBe(array_column(array_values(TemplateInstaller::QUERIES), 1))
		->and(glob("$outside/script_server/*"))->toBe([])
		->and(glob("$outside/*.xml"))->toBe([]);

	query_tree_remove(dirname($cacti));
})->with(['resource', 'resource/script_server']);
