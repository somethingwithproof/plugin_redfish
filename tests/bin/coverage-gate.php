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

$cloverPath = isset($argv[1]) ? $argv[1] : 'coverage/clover.xml';
$minPct     = isset($argv[2]) ? (float) $argv[2] : 80.0;

if (!is_file($cloverPath)) {
	fwrite(STDERR, 'Clover report not found: ' . $cloverPath . PHP_EOL);
	exit(1);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false || !isset($xml->project->metrics)) {
	fwrite(STDERR, 'Invalid Clover XML report: ' . $cloverPath . PHP_EOL);
	exit(1);
}

$metrics = $xml->project->metrics;
$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$pct     = $total > 0 ? ($covered / $total) * 100.0 : 0.0;

printf("Measured line coverage: %.2f%% (%d / %d statements)\n", $pct, $covered, $total);

if ($pct < $minPct) {
	fwrite(STDERR, sprintf("Line coverage %.2f%% is below minimum threshold %.2f%%\n", $pct, $minPct));
	exit(1);
}
