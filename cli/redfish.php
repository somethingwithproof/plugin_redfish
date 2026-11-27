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
 * Operator and CasC CLI for plugin_redfish.
 * Parses on PHP 8.0 and refuses to run below PHP 8.2.
 *
 *   php plugins/redfish/cli/redfish.php export --format=yaml|xml
 *   php plugins/redfish/cli/redfish.php validate [--file=PATH]
 *   php plugins/redfish/cli/redfish.php plan [--file=PATH]
 *   php plugins/redfish/cli/redfish.php apply [--file=PATH] --confirm=<PLAN_HASH>
 *   php plugins/redfish/cli/redfish.php health --format=text|json
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

chdir(dirname(__DIR__, 3));

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (redfish_refuse_unsupported()) {
	exit(1);
}

redfish_boot();

$redfish_stdin = '';

if (!stream_isatty(STDIN)) {
	$redfish_stdin = (string) stream_get_contents(STDIN);
}

$redfish_result = redfish_cli()->run(array_slice($_SERVER['argv'], 1), $redfish_stdin);

if ($redfish_result['stdout'] !== '') {
	fwrite(STDOUT, $redfish_result['stdout']);
}

if ($redfish_result['stderr'] !== '') {
	fwrite(STDERR, $redfish_result['stderr']);
}

exit($redfish_result['exit']);
