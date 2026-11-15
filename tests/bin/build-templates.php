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
 * Build the Redfish templates in a disposable Cacti database with Cacti's
 * own save function, and write Cacti's export of them (get_item_xml with
 * dependencies) to templates/redfish-templates.xml. The plugin ships that
 * export, not this script's data:
 *
 *   REDFISH_TEST_DATABASE=disposable php plugins/redfish/tests/bin/build-templates.php [export-file]
 *
 * Hashes come from TemplateInstaller::hash(), so a rebuild updates the rows
 * it made and exports the same hashes. Must parse on PHP 8.0.
 *
 * Every value is a GAUGE. Maximums are plausibility bounds that turn a
 * misread into a gap, not limits of real hardware: 100 kW per node or
 * supply, 200 degrees Celsius, 100 percent and 100,000 RPM per fan.
 * Temperatures may be below zero. Every stored value is drawn on the graph
 * of its own data template, because Cacti creates an RRD file with only the
 * values a graph draws (docs/research/data-query.md).
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/functions.php');
require_once($config['base_path'] . '/lib/export.php');
require_once($config['base_path'] . '/plugins/redfish/setup.php');

if (getenv('REDFISH_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set REDFISH_TEST_DATABASE=disposable\n");
	exit(2);
}

if (!redfish_boot()) {
	fwrite(STDERR, "PHP 8.2 or later is required\n");
	exit(2);
}

use Somethingwithproof\Redfish\SummaryScript;
use Somethingwithproof\Redfish\TemplateInstaller as T;

const REDFISH_GAUGE = 1;

const REDFISH_MAX_WATTS   = '100000';
const REDFISH_MAX_CELSIUS = '200';
const REDFISH_MIN_CELSIUS = '-60';
const REDFISH_MAX_RPM     = '100000';

/** Core's "Get Script Server Data (Indexed)" input and its three index fields. */
const REDFISH_INDEXED_INPUT = '332111d8b54ac8ce939af87a7eac0c06';
const REDFISH_INDEXED_FIELDS = array('172b4b0eacee4948c6479f587b62e512', '30fb5d5bcf3d66bb5abe88596f357c26', '31112c85ae4ff821d3b288336288818c');

function redfish_save($table, array $row) {
	$id = (int) db_fetch_cell_prepared("SELECT id FROM `$table` WHERE hash = ?", array($row['hash']));

	$row['id'] = $id > 0 ? $id : 0;
	$saved     = sql_save($row, $table);

	if (!$saved) {
		fwrite(STDERR, "Saving $table {$row['hash']} failed\n");
		exit(1);
	}

	return (int) $saved;
}

function redfish_color($hex) {
	$hex = strtoupper($hex);
	$id  = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));

	if ($id === 0) {
		db_execute_prepared('INSERT INTO colors (hex) VALUES (?)', array($hex));
		$id = (int) db_fetch_cell_prepared('SELECT id FROM colors WHERE hex = ?', array($hex));
	}

	return $id;
}

function redfish_gprint($name, $text) {
	return redfish_save('graph_templates_gprint', array('hash' => T::hash('gprint', $name), 'name' => $name, 'gprint_text' => $text));
}

/**
 * A data template with one RRD item per entry of $items.
 *
 * @param array<string, array{0: int, 1: string, 2: int, 3?: string}> $items name => [type, maximum, input field id, minimum]
 */
function redfish_data_template($name, $inputId, $title, array $items, array $indexFields = array(), array $hostFields = array()) {
	$dtId  = redfish_save('data_template', array('hash' => T::hash('data_template', $name), 'name' => $name));
	$dtdId = (int) db_fetch_cell_prepared('SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($dtId));
	$dtdId = (int) sql_save(array(
		'id'                          => $dtdId,
		'data_template_id'            => $dtId,
		'local_data_id'               => 0,
		'local_data_template_data_id' => 0,
		'data_input_id'               => $inputId,
		'name'                        => $title,
		'active'                      => 'on',
		'rrd_step'                    => 300,
		'data_source_profile_id'      => 1,
	), 'data_template_data');

	// The index fields are set per data source when Cacti creates one from
	// the data query.
	foreach ($indexFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, 'on', ''));
	}

	// A field with a device type code gets its value from the device when
	// Cacti builds the poller cache, but only when the template has a row
	// for it, left empty and not overridable per data source.
	foreach ($hostFields as $fieldId) {
		db_execute_prepared('REPLACE INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES (?, ?, ?, ?)', array($fieldId, $dtdId, '', ''));
	}

	$rrd = array();

	foreach ($items as $item => $spec) {
		$rrd[$item] = redfish_save('data_template_rrd', array(
			'hash'                => T::hash('data_template_item', $name . ':' . $item),
			'data_template_id'    => $dtId,
			'local_data_id'       => 0,
			'rrd_maximum'         => $spec[1],
			'rrd_minimum'         => isset($spec[3]) ? $spec[3] : '0',
			'rrd_heartbeat'       => 600,
			'data_source_type_id' => $spec[0],
			'data_source_name'    => $item,
			'data_input_field_id' => $spec[2],
		));
	}

	return array('id' => $dtId, 'rrd' => $rrd);
}

/**
 * A graph template from a list of drawn items. Each item may carry legends:
 * a list of [GPRINT type, label] printed after it; the last legend ends the
 * line.
 *
 * @param list<array<string, mixed>> $items
 */
function redfish_graph_template($name, $title, $label, $base, array $items, $upper = '', $rigid = false, $lower = '0') {
	$gtId  = redfish_save('graph_templates', array('hash' => T::hash('graph_template', $name), 'name' => $name));
	$gtgId = (int) db_fetch_cell_prepared('SELECT id FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	sql_save(array(
		'id'                            => $gtgId,
		'graph_template_id'             => $gtId,
		'local_graph_id'                => 0,
		'local_graph_template_graph_id' => 0,
		'image_format_id'               => 1,
		't_title'                       => '',
		'title'                         => $title,
		'height'                        => 150,
		'width'                         => 600,
		'upper_limit'                   => $upper === '' ? '100' : $upper,
		'lower_limit'                   => $lower,
		'vertical_label'                => $label,
		'auto_scale'                    => $rigid ? '' : 'on',
		'auto_scale_opts'               => 2,
		'auto_scale_rigid'              => $rigid ? 'on' : '',
		'base_value'                    => $base,
		'slope_mode'                    => 'on',
	), 'graph_templates_graph');

	db_execute_prepared('DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0', array($gtId));

	$sequence = 0;
	$first    = 0;

	$add = function (array $row) use (&$sequence, $gtId, $name) {
		$sequence++;

		return redfish_save('graph_templates_item', $row + array(
			'hash'                      => T::hash('graph_template_item', $name . ':' . $sequence),
			'graph_template_id'         => $gtId,
			'local_graph_id'            => 0,
			'task_item_id'              => 0,
			'color_id'                  => 0,
			'alpha'                     => 'FF',
			'graph_type_id'             => GRAPH_ITEM_TYPE_COMMENT,
			'cdef_id'                   => 0,
			'consolidation_function_id' => 1,
			'text_format'               => '',
			'value'                     => '',
			'hard_return'               => '',
			'gprint_id'                 => 0,
			'sequence'                  => $sequence,
		));
	};

	foreach ($items as $item) {
		$legends = isset($item['legends']) ? $item['legends'] : array();
		$id      = $add(array(
			'task_item_id'  => $item['rrd'],
			'graph_type_id' => $item['type'],
			'color_id'      => isset($item['color']) ? redfish_color($item['color']) : 0,
			'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
			'text_format'   => $item['text'],
			'gprint_id'     => in_array($item['type'], array(GRAPH_ITEM_TYPE_GPRINT_LAST, GRAPH_ITEM_TYPE_GPRINT_AVERAGE, GRAPH_ITEM_TYPE_GPRINT_MAX, GRAPH_ITEM_TYPE_GPRINT_MIN), true) ? $item['gprint'] : 0,
			'hard_return'   => $legends === array() ? 'on' : '',
		));

		$first = $first === 0 && $item['type'] !== GRAPH_ITEM_TYPE_GPRINT_LAST ? $id : $first;

		foreach ($legends as $i => $legend) {
			$add(array(
				'task_item_id'  => $item['rrd'],
				'graph_type_id' => $legend[0],
				'cdef_id'       => isset($item['cdef']) ? $item['cdef'] : 0,
				'text_format'   => $legend[1],
				'gprint_id'     => $item['gprint'],
				'hard_return'   => $i === count($legends) - 1 ? 'on' : '',
			));
		}
	}

	// One graph template input, for the first item's color: Cacti 1.2.32
	// and later refuse a graph template export with no <inputs> element.
	$inputId = redfish_save('graph_template_input', array(
		'hash'              => T::hash('graph_template_input', $name . ':color'),
		'graph_template_id' => $gtId,
		'name'              => 'First Item Color',
		'description'       => 'The color of the first item',
		'column_name'       => 'color_id',
	));

	db_execute_prepared('DELETE FROM graph_template_input_defs WHERE graph_template_input_id = ?', array($inputId));
	db_execute_prepared('INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (?, ?)', array($inputId, $first));

	return $gtId;
}

$gNum   = redfish_gprint('Redfish - Number', '%8.1lf');
$gCount = redfish_gprint('Redfish - Count', '%5.0lf');

$last = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'), array(GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'Average:'), array(GRAPH_ITEM_TYPE_GPRINT_MAX, 'Maximum:'));
$now  = array(array(GRAPH_ITEM_TYPE_GPRINT_LAST, 'Current:'));

/* ---- summary data input, data template, and graphs ---- */

$inputId = redfish_save('data_input', array(
	'hash'         => T::hash('data_input', T::SUMMARY),
	'name'         => T::SUMMARY,
	'input_string' => '<path_cacti>/plugins/redfish/scripts/ss_redfish_summary.php ss_redfish_summary <host_id>',
	'type_id'      => DATA_INPUT_TYPE_PHP_SCRIPT_SERVER,
));

$field = function ($dataName, $friendly, $inOut, $sequence, $typeCode = '') use ($inputId) {
	return redfish_save('data_input_fields', array(
		'hash'          => T::hash('data_input_field', T::SUMMARY . ':' . $dataName),
		'data_input_id' => $inputId,
		'name'          => $friendly,
		'data_name'     => $dataName,
		'input_output'  => $inOut,
		'update_rra'    => $inOut === 'out' ? 'on' : '',
		'sequence'      => $sequence,
		'type_code'     => $typeCode,
		'regexp_match'  => '',
		'allow_nulls'   => '',
	));
};

$hostField = $field('host_id', 'Device ID', 'in', 0, 'host_id');

$summaryLimits = array(
	'power_watts'      => array(REDFISH_MAX_WATTS, '0'),
	'inlet_celsius'    => array(REDFISH_MAX_CELSIUS, REDFISH_MIN_CELSIUS),
	'max_temp_celsius' => array(REDFISH_MAX_CELSIUS, REDFISH_MIN_CELSIUS),
	'fans_bad'         => array('1000', '0'),
	'psus_bad'         => array('1000', '0'),
	'health_code'      => array('2', '0'),
	'age'              => array('U', '0'),
);
$summaryItems  = array();

foreach (SummaryScript::VALUES as $i => $name) {
	$summaryItems[$name] = array(REDFISH_GAUGE, $summaryLimits[$name][0], $field($name, ucfirst(str_replace('_', ' ', $name)), 'out', $i + 1), $summaryLimits[$name][1]);
}

$summary = redfish_data_template(T::SUMMARY, $inputId, '|host_description| - Redfish - Node Summary', $summaryItems, array(), array($hostField));
$s       = $summary['rrd'];

$overview = array(
	redfish_graph_template('Redfish - Node Power', '|host_description| - Redfish - Node Power', 'watts', 1000, array(
		array('rrd' => $s['power_watts'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => '2471A3', 'text' => 'Power consumed', 'gprint' => $gNum, 'legends' => $last),
	)),
	redfish_graph_template('Redfish - Node Temperatures', '|host_description| - Redfish - Node Temperatures', 'degrees Celsius', 1000, array(
		array('rrd' => $s['inlet_celsius'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'text' => 'Inlet', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $s['max_temp_celsius'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'C0392B', 'text' => 'Hottest sensor', 'gprint' => $gNum, 'legends' => $last),
	), '', false, ''),
	redfish_graph_template('Redfish - Node Component Health', '|host_description| - Redfish - Node Component Health', 'components', 1000, array(
		array('rrd' => $s['fans_bad'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'B9770E', 'text' => 'Fans not OK', 'gprint' => $gCount, 'legends' => $now),
		array('rrd' => $s['psus_bad'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '7D3C98', 'text' => 'Power supplies not OK', 'gprint' => $gCount, 'legends' => $now),
		array('rrd' => $s['health_code'], 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => 'Node health (0 OK, 1 Warning, 2 Critical):', 'gprint' => $gCount),
	), '5'),
	redfish_graph_template('Redfish - Node Data Age', '|host_description| - Redfish - Node Data Age', 'seconds', 1000, array(
		array('rrd' => $s['age'], 'type' => GRAPH_ITEM_TYPE_AREA, 'color' => '7F8C8D', 'text' => 'Seconds since the last successful read', 'gprint' => $gNum, 'legends' => $last),
	)),
);

/* ---- per-member data templates, data queries, and graphs ---- */

$indexedInput  = (int) db_fetch_cell_prepared('SELECT id FROM data_input WHERE hash = ?', array(REDFISH_INDEXED_INPUT));
$indexedFields = array();

foreach (REDFISH_INDEXED_FIELDS as $hash) {
	$indexedFields[] = (int) db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash = ?', array($hash));
}

if ($indexedInput === 0 || in_array(0, $indexedFields, true)) {
	fwrite(STDERR, "Cacti's script server indexed input is missing\n");
	exit(1);
}

$health = array(REDFISH_GAUGE, '2', 0);

// Query graph name => [data query, values with [type, maximum, field, minimum], title suffix].
$groups = array(
	'Fan Speed Percent' => array('Redfish Fans', array('fan_percent' => array(REDFISH_GAUGE, '100', 0), 'fan_health' => $health), 'Fan Speed'),
	'Fan Speed RPM'     => array('Redfish Fans', array('fan_rpm' => array(REDFISH_GAUGE, REDFISH_MAX_RPM, 0), 'fan_health' => $health), 'Fan Speed'),
	'Temperature'       => array('Redfish Temperatures', array('temperature' => array(REDFISH_GAUGE, REDFISH_MAX_CELSIUS, 0, REDFISH_MIN_CELSIUS), 'temp_health' => $health), 'Temperature'),
	'Power Supply'      => array('Redfish Power Supplies', array('psu_input_watts' => array(REDFISH_GAUGE, REDFISH_MAX_WATTS, 0), 'psu_output_watts' => array(REDFISH_GAUGE, REDFISH_MAX_WATTS, 0), 'psu_health' => $health), 'Power Supply'),
);

$dts = array();

foreach ($groups as $graphName => $group) {
	$dts[$graphName] = redfish_data_template(T::QUERY_GRAPHS[$group[0]][$graphName], $indexedInput, '|host_description| - Redfish - |query_redfishName| - ' . $group[2], $group[1], $indexedFields);
}

$healthLegend = function ($rrd, $what) use ($gCount) {
	return array('rrd' => $rrd, 'type' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'text' => $what . ' health (0 OK, 1 Warning, 2 Critical):', 'gprint' => $gCount);
};

$fp = $dts['Fan Speed Percent']['rrd'];
$fr = $dts['Fan Speed RPM']['rrd'];
$t  = $dts['Temperature']['rrd'];
$p  = $dts['Power Supply']['rrd'];

$queryGraphs = array(
	'Fan Speed Percent' => redfish_graph_template('Redfish - Fan Speed Percent', '|host_description| - Redfish - |query_redfishName| - Fan Speed (percent)', 'percent', 1000, array(
		array('rrd' => $fp['fan_percent'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Speed', 'gprint' => $gNum, 'legends' => $last),
		$healthLegend($fp['fan_health'], 'Fan'),
	), '100', true),
	'Fan Speed RPM'     => redfish_graph_template('Redfish - Fan Speed RPM', '|host_description| - Redfish - |query_redfishName| - Fan Speed (RPM)', 'RPM', 1000, array(
		array('rrd' => $fr['fan_rpm'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Speed', 'gprint' => $gNum, 'legends' => $last),
		$healthLegend($fr['fan_health'], 'Fan'),
	)),
	'Temperature'       => redfish_graph_template('Redfish - Temperature', '|host_description| - Redfish - |query_redfishName| - Temperature', 'degrees Celsius', 1000, array(
		array('rrd' => $t['temperature'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => 'C0392B', 'text' => 'Temperature', 'gprint' => $gNum, 'legends' => $last),
		$healthLegend($t['temp_health'], 'Sensor'),
	), '', false, ''),
	'Power Supply'      => redfish_graph_template('Redfish - Power Supply', '|host_description| - Redfish - |query_redfishName| - Power Supply', 'watts', 1000, array(
		array('rrd' => $p['psu_input_watts'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '2471A3', 'text' => 'Input', 'gprint' => $gNum, 'legends' => $last),
		array('rrd' => $p['psu_output_watts'], 'type' => GRAPH_ITEM_TYPE_LINE2, 'color' => '1E8449', 'text' => 'Output', 'gprint' => $gNum, 'legends' => $last),
		$healthLegend($p['psu_health'], 'Supply'),
	)),
);

$descriptions = array(
	'Redfish Fans'           => 'Fans of a server, read over Redfish and answered from the plugin cache',
	'Redfish Temperatures'   => 'Temperature sensors of a server, read over Redfish and answered from the plugin cache',
	'Redfish Power Supplies' => 'Power supplies of a server, read over Redfish and answered from the plugin cache',
);
$queries = array();

foreach (T::QUERIES as $queryName => $query) {
	$queryId  = redfish_save('snmp_query', array(
		'hash'          => T::hash('data_query', $queryName),
		'xml_path'      => '<path_cacti>/resource/script_server/' . $query[1],
		'name'          => $queryName,
		'description'   => $descriptions[$queryName],
		'data_input_id' => $indexedInput,
	));

	$queries[] = $queryId;

	foreach (T::QUERY_GRAPHS[$queryName] as $graphName => $templateName) {
		$sqgId = redfish_save('snmp_query_graph', array(
			'hash'              => T::hash('data_query_graph', $queryName . ':' . $graphName),
			'snmp_query_id'     => $queryId,
			'name'              => $graphName,
			'graph_template_id' => $queryGraphs[$graphName],
		));

		$template = $dts[$graphName];

		// Every item of the graph's data template maps to the query field
		// of the same name.
		db_execute_prepared('DELETE FROM snmp_query_graph_rrd WHERE snmp_query_graph_id = ?', array($sqgId));

		foreach ($template['rrd'] as $item => $rrdId) {
			db_execute_prepared('INSERT INTO snmp_query_graph_rrd (snmp_query_graph_id, data_template_id, data_template_rrd_id, snmp_field_name) VALUES (?, ?, ?, ?)', array($sqgId, $template['id'], $rrdId, $item));
		}

		redfish_save('snmp_query_graph_sv', array(
			'hash'                => T::hash('data_query_sv_graph', $queryName . ':' . $graphName . ':title'),
			'snmp_query_graph_id' => $sqgId,
			'sequence'            => 1,
			'field_name'          => 'title',
			'text'                => '|host_description| - Redfish - |query_redfishName| - ' . $groups[$graphName][2],
		));

		redfish_save('snmp_query_graph_rrd_sv', array(
			'hash'                => T::hash('data_query_sv_data_source', $queryName . ':' . $graphName . ':name'),
			'snmp_query_graph_id' => $sqgId,
			'data_template_id'    => $template['id'],
			'sequence'            => 1,
			'field_name'          => 'name',
			'text'                => '|host_description| - Redfish - |query_redfishName|',
		));
	}
}

/* ---- device template ---- */

$htId = redfish_save('host_template', array('hash' => T::hash('host_template', T::DEVICE_TEMPLATE), 'name' => T::DEVICE_TEMPLATE));

db_execute_prepared('DELETE FROM host_template_graph WHERE host_template_id = ?', array($htId));
db_execute_prepared('DELETE FROM host_template_snmp_query WHERE host_template_id = ?', array($htId));

foreach ($overview as $gtId) {
	db_execute_prepared('INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)', array($htId, $gtId));
}

foreach ($queries as $queryId) {
	db_execute_prepared('INSERT INTO host_template_snmp_query (host_template_id, snmp_query_id) VALUES (?, ?)', array($htId, $queryId));
}

$xml = get_item_xml('host_template', $htId, true);

// The export follows the data source profile the data templates use. The
// plugin must not overwrite a site's profile, so the shipped file leaves it
// out and import resolves the profile by its hash, which is Cacti's stock one.
$xml = preg_replace('#\s*<hash_2001[0-9a-f]{34}>.*?</hash_2001[0-9a-f]{34}>#s', '', $xml, -1, $removed);

if ($removed !== 1) {
	fwrite(STDERR, "Expected one data source profile in the export, found $removed\n");
	exit(1);
}

$file = isset($argv[1]) ? $argv[1] : $config['base_path'] . '/plugins/redfish/templates/redfish-templates.xml';

file_put_contents($file, $xml);
print "Wrote $file\n";
