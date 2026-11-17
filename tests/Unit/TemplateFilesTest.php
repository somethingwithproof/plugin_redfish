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

use Somethingwithproof\Redfish\SummaryScript;
use Somethingwithproof\Redfish\TemplateInstaller as T;

function redfish_template_xml(): string {
	return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/redfish-templates.xml');
}

it('ships every object the installer looks for, by the hash the builder gives it', function () {
	$xml    = redfish_template_xml();
	$hashes = [T::hash('host_template', T::DEVICE_TEMPLATE), T::hash('data_input', T::SUMMARY), T::hash('data_template', T::SUMMARY)];

	foreach (T::SUMMARY_GRAPHS as $name) {
		$hashes[] = T::hash('graph_template', $name);
	}

	foreach (T::QUERY_GRAPHS as $query => $graphs) {
		$hashes[] = T::hash('data_query', $query);

		foreach ($graphs as $graph => $template) {
			$hashes[] = T::hash('graph_template', $template);
			$hashes[] = T::hash('data_template', $template);
			$hashes[] = T::hash('data_query_graph', $query . ':' . $graph);
		}
	}

	foreach ($hashes as $hash) {
		expect($xml)->toMatch('/<hash_[0-9a-f]{6}' . $hash . '>/');
	}
});

it('gives every shipped object its own hash and leaves out the data source profile', function () {
	preg_match_all('/<hash_([0-9a-f]{38})>/', redfish_template_xml(), $m);

	expect(count($m[1]))->toBeGreaterThan(50)
		->and(count($m[1]))->toBe(count(array_unique($m[1])))
		->and(preg_grep('/^2001/', $m[1]))->toBe([]);
});

it('names its objects with the Redfish prefix and its graphs by device description', function () {
	$xml = simplexml_load_string(redfish_template_xml());
	$own = [];

	foreach ($xml->children() as $object) {
		$name = (string) $object->name;

		// Core's indexed script server input is shared, not the plugin's.
		if ($name !== 'Get Script Server Data (Indexed)') {
			$own[] = $name;
		}
	}

	expect(array_filter($own, fn (string $n): bool => !str_starts_with($n, 'Redfish')))->toBe([]);
	preg_match_all('/<title>([^<]*)<\/title>/', redfish_template_xml(), $titles);

	foreach (array_filter($titles[1]) as $title) {
		expect($title)->toStartWith('|host_description| - Redfish - ');
	}
});

it('holds no address, credential, or path of the build machine', function () {
	$text = redfish_template_xml();

	foreach (glob(dirname(__DIR__, 2) . '/resource/script_server/*.xml') as $file) {
		$text .= (string) file_get_contents($file);
	}

	expect($text)->not->toMatch('/\b\d{1,3}(\.\d{1,3}){3}\b/')
		->and($text)->not->toMatch('/password|secret|token|username/i')
		->and($text)->not->toContain('/work/')
		->and($text)->not->toContain('/var/www');
});

it('polls each summary value the script answers, under a valid data source name', function () {
	$xml = redfish_template_xml();

	foreach (SummaryScript::VALUES as $name) {
		expect($xml)->toContain('<data_name>' . $name . '</data_name>')
			->and($xml)->toContain('<data_source_name>' . $name . '</data_source_name>')
			->and($name)->toMatch('/^[a-zA-Z0-9_]{1,19}$/');
	}

	// Cacti exports an input string base64 encoded.
	expect($xml)->toContain('<input_string>' . base64_encode('<path_cacti>/plugins/redfish/scripts/ss_redfish_summary.php ss_redfish_summary <host_id>') . '</input_string>');
});

it('points each data query at its query file under resource/script_server', function () {
	$xml = redfish_template_xml();

	foreach (T::QUERIES as [, $file]) {
		expect($xml)->toContain('<xml_path>&lt;path_cacti&gt;/resource/script_server/' . $file . '</xml_path>')
			->and(is_file(dirname(__DIR__, 2) . '/resource/script_server/' . $file))->toBeTrue();
	}
});

it('stores every value as a GAUGE, which Thold can read as U', function () {
	// Thold 1.8.2 fails on a thresholded COUNTER or DERIVE source reading U.
	preg_match_all('/<data_source_type_id>(\d+)<\/data_source_type_id>/', redfish_template_xml(), $m);

	expect($m[1])->not->toBe([])
		->and(array_unique($m[1]))->toBe(['1']);
});
