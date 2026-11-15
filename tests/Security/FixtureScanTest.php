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
 * The Redfish fixtures are hand-written shapes, not captures (issue 42).
 * They must carry no credential, serial number, MAC address, or real host
 * name, so a capture pasted in by mistake fails here.
 */

function redfish_fixture_files(): array {
	return glob(dirname(__DIR__) . '/fixtures/*.json') ?: [];
}

it('ships at least the three family fixtures', function () {
	expect(array_map('basename', redfish_fixture_files()))->toContain('dell-subsystem.json', 'legacy.json', 'lenovo.json');
});

it('keeps identifying values out of every fixture', function (string $file) {
	$text = (string) file_get_contents($file);
	$data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);

	expect($data)->toBeArray()
		// No credential or token fields, with or without a value.
		->and(preg_match('/"(Password|UserName|Token|X-Auth-Token|Authorization)"\s*:/i', $text))->toBe(0)
		// Serial, part, and asset numbers are absent or obviously synthetic.
		->and(preg_match('/"(SerialNumber|PartNumber|SparePartNumber|AssetTag|SKU|ServiceTag)"\s*:\s*"(?!SYNTH-)[^"]+"/', $text))->toBe(0)
		// UUIDs are the all-zero placeholder family.
		->and(preg_match_all('/"UUID"\s*:\s*"([^"]*)"/', $text, $uuids) === 0 || array_filter($uuids[1], fn (string $u) => !str_starts_with($u, '00000000-0000-0000-0000-')) === [])->toBeTrue()
		// No MAC addresses, IPv4 addresses outside documentation ranges, or host names other than reserved test names.
		->and(preg_match('/\b[0-9A-Fa-f]{2}([:-])[0-9A-Fa-f]{2}(\1[0-9A-Fa-f]{2}){4}\b/', $text))->toBe(0)
		// Firmware versions look like addresses, so version fields and the fixture note are skipped.
		->and(preg_match('/\b(?!(192\.0\.2|198\.51\.100|203\.0\.113)\.)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', (string) preg_replace('/"(FirmwareVersion|Version|firmware|shape_after)"\s*:\s*"[^"]*"/', '', $text)))->toBe(0)
		->and(preg_match_all('/\b(?:[a-z0-9-]+\.)+(?:com|net|org|io|local|lan|corp|internal|example)\b/i', $text))->toBe(0);
})->with(fn () => redfish_fixture_files());
