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
 * Imports the shipped device, data, and graph templates and the three data
 * queries with Cacti's own import_xml_data() (REQ-TPL-001, REQ-TPL-002), and
 * installs the data query XML files under Cacti's resource/script_server/.
 *
 * templates/redfish-templates.xml is Cacti's own export of templates that
 * tests/bin/build-templates.php builds in a disposable Cacti; it is never
 * edited by hand. Every object carries a hash derived from its name, so a
 * second import updates the objects of the first instead of adding copies,
 * and the plugin finds its objects by hash even after an operator renames
 * them. Import updates templates only; graphs, data sources, thresholds,
 * and tree entries made from them stay (REQ-TPL-004).
 *
 * Cacti 1.2.31 and 1.2.x read a data query XML only from inside
 * <path_cacti>/resource (get_data_query_array() rejects any other path after
 * resolving it), so the files are copied there. A file the plugin did not
 * write, or that was changed after it was written, is never replaced or
 * removed: the plugin records the SHA-256 of each file it wrote.
 */
final class TemplateInstaller {
	/** Bump when templates/redfish-templates.xml or a query XML changes. */
	public const VERSION = 1;

	public const SETTING         = 'redfish_templates_version';
	public const FAILURE_SETTING = 'redfish_templates_failure';
	public const FILES_SETTING   = 'redfish_query_files';

	public const DEVICE_TEMPLATE = 'Redfish BMC';
	public const SUMMARY         = 'Redfish Node Summary';

	/** Graph templates of the summary data source, on every mapped device. */
	public const SUMMARY_GRAPHS = [
		'Redfish - Node Power',
		'Redfish - Node Temperatures',
		'Redfish - Node Component Health',
		'Redfish - Node Data Age',
	];

	/** Data query name => [member kind, XML file], one query per kind. */
	public const QUERIES = [
		'Redfish Fans'           => ['fan', 'redfish_fans.xml'],
		'Redfish Temperatures'   => ['temperature', 'redfish_temperatures.xml'],
		'Redfish Power Supplies' => ['psu', 'redfish_psus.xml'],
	];

	/**
	 * Graph template per data query graph, by query. Each per-index graph
	 * template has a data template of the same name holding exactly the
	 * values it draws. Fans have one template per unit, so percent and RPM
	 * never share an axis (REQ-GRAPH-005).
	 */
	public const QUERY_GRAPHS = [
		'Redfish Fans'           => ['Fan Speed Percent' => 'Redfish - Fan Speed Percent', 'Fan Speed RPM' => 'Redfish - Fan Speed RPM'],
		'Redfish Temperatures'   => ['Temperature' => 'Redfish - Temperature'],
		'Redfish Power Supplies' => ['Power Supply' => 'Redfish - Power Supply'],
	];

	/**
	 * @param string $pluginPath the plugin directory
	 * @param string $cactiPath  Cacti's base path
	 */
	public function __construct(
		private readonly string $pluginPath,
		private readonly string $cactiPath,
		private readonly CactiObjectRepository $objects,
	) {
	}

	/**
	 * The hash the template builder gives an object, 32 hex characters as
	 * Cacti stores it.
	 */
	public static function hash(string $kind, string $name): string {
		return md5('plugin_redfish:' . $kind . ':' . $name);
	}

	public function isCurrent(): bool {
		return (int) read_config_option(self::SETTING) === self::VERSION && $this->present() && $this->queryFileProblems() === [];
	}

	/**
	 * The reason the last install failed, or '' when it succeeded.
	 */
	public function failure(): string {
		return (string) read_config_option(self::FAILURE_SETTING);
	}

	/**
	 * Install the query files and import the templates. A failure is logged
	 * and kept for the health page; success clears it.
	 */
	public function install(): bool {
		$error = $this->installQueryFiles() ?? $this->import();

		if ($error !== null) {
			Log::error($error);
			set_config_option(self::FAILURE_SETTING, $error);

			return false;
		}

		set_config_option(self::SETTING, (string) self::VERSION);
		set_config_option(self::FAILURE_SETTING, '');

		return true;
	}

	/**
	 * Remove the query files the plugin wrote and nobody changed since. The
	 * templates and every Cacti object made from them stay: they belong to
	 * the site once imported.
	 */
	public function uninstall(): void {
		$written = $this->written();

		foreach ($written as $file => $hash) {
			$target = $this->queryTarget($file);

			if ($target !== null && !is_link($target) && is_file($target) && hash_file('sha256', $target) === $hash) {
				@unlink($target);
			}
		}

		set_config_option(self::FILES_SETTING, '');
		set_config_option(self::SETTING, '');
	}

	/**
	 * Every shipped object exists, found by hash.
	 */
	public function present(): bool {
		if ($this->objects->idByHash('host_template', self::hash('host_template', self::DEVICE_TEMPLATE)) === 0) {
			return false;
		}

		if ($this->objects->idByHash('data_input', self::hash('data_input', self::SUMMARY)) === 0) {
			return false;
		}

		$perTarget = array_merge(...array_map('array_values', array_values(self::QUERY_GRAPHS)));

		foreach (array_merge([self::SUMMARY], $perTarget) as $name) {
			if ($this->objects->idByHash('data_template', self::hash('data_template', $name)) === 0) {
				return false;
			}
		}

		foreach (array_merge(self::SUMMARY_GRAPHS, $perTarget) as $name) {
			if ($this->objects->idByHash('graph_templates', self::hash('graph_template', $name)) === 0) {
				return false;
			}
		}

		foreach (array_keys(self::QUERIES) as $name) {
			if ($this->objects->idByHash('snmp_query', self::hash('data_query', $name)) === 0) {
				return false;
			}
		}

		foreach (self::QUERY_GRAPHS as $query => $graphs) {
			foreach (array_keys($graphs) as $graph) {
				if ($this->objects->queryGraphId($query, $graph) === 0) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Query files that are missing from Cacti's resource tree or differ from
	 * the shipped ones.
	 *
	 * @return list<string> file names
	 */
	public function queryFileProblems(): array {
		$problems = [];

		foreach (self::QUERIES as [, $file]) {
			$source = $this->querySource($file);
			$target = $this->queryTarget($file);

			if ($target === null || !is_file($target) || !is_file($source) || hash_file('sha256', $target) !== hash_file('sha256', $source)) {
				$problems[] = $file;
			}
		}

		return $problems;
	}

	/**
	 * @return string|null the reason, or null when every file is in place
	 */
	private function installQueryFiles(): ?string {
		$written = $this->written();

		foreach (self::QUERIES as [, $file]) {
			$source = $this->querySource($file);
			$target = $this->queryTarget($file);

			if ($target === null || !is_file($source)) {
				return sprintf('The data query file %s cannot be installed: the source or the Cacti resource directory is missing', $file);
			}

			// A link could point the write anywhere; the plugin never follows one.
			if (is_link($target)) {
				return sprintf('%s is a symbolic link; it was left as it is. Replace it with a copy of plugins/redfish/resource/script_server/%s', $target, $file);
			}

			$want = (string) hash_file('sha256', $source);

			if (file_exists($target)) {
				$have = is_file($target) ? (string) hash_file('sha256', $target) : '';

				// Matching contents the plugin did not write stay the site's:
				// no ownership is claimed, so uninstall leaves them.
				if ($have === $want) {
					continue;
				}

				if (!isset($written[$file]) || $written[$file] !== $have) {
					return sprintf('%s exists and was not written by this plugin, or was changed after; it was left as it is. Compare it with plugins/redfish/resource/script_server/%s', $target, $file);
				}
			}

			if (!$this->writeQueryFile($source, $target, $want)) {
				return sprintf('Could not write %s. Copy plugins/redfish/resource/script_server/%s there as the web server user, then reinstall the templates from the Redfish BMCs page', $target, $file);
			}

			// Recorded at once, so a later file that fails cannot lose it.
			$written[$file] = $want;
			set_config_option(self::FILES_SETTING, (string) json_encode($written));
		}

		return null;
	}

	/**
	 * Write through a temporary file in the same directory and rename it
	 * over the target, so the target is never half written and a link put
	 * there after the check is replaced, not followed.
	 */
	private function writeQueryFile(string $source, string $target, string $want): bool {
		$dir = dirname($target);

		if (!is_writable($dir)) {
			return false;
		}

		$temp = @tempnam($dir, '.redfish-');

		if ($temp === false) {
			return false;
		}

		if (@copy($source, $temp) === false || hash_file('sha256', $temp) !== $want || !@chmod($temp, 0644) || !@rename($temp, $target)) {
			@unlink($temp);

			return false;
		}

		return !is_link($target) && hash_file('sha256', $target) === $want;
	}

	/**
	 * @return string|null the reason, or null when every object imported
	 */
	private function import(): ?string {
		$file = $this->pluginPath . '/templates/redfish-templates.xml';
		$xml  = is_readable($file) ? file_get_contents($file) : false;

		if (!is_string($xml) || $xml === '') {
			return 'The template file ' . $file . ' is missing or empty';
		}

		include_once($this->cactiPath . '/lib/utility.php');
		include_once($this->cactiPath . '/lib/import.php');

		$result = import_xml_data($xml, false, $this->objects->defaultProfileId());

		if (!is_array($result) || $result === []) {
			return 'Importing the Redfish templates returned nothing';
		}

		$failed = 0;

		foreach ($result as $type => $entries) {
			if ($type === 'files' || !is_array($entries)) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) && ($entry['result'] ?? '') === 'fail') {
					$failed++;
				}
			}
		}

		if ($failed > 0) {
			return sprintf('%d Redfish template objects failed to import', $failed);
		}

		return $this->present() ? null : 'The Redfish templates are missing after import';
	}

	/**
	 * @return array<string, string> file name => SHA-256 the plugin wrote
	 */
	private function written(): array {
		$decoded = json_decode((string) read_config_option(self::FILES_SETTING), true);
		$out     = [];

		foreach (is_array($decoded) ? $decoded : [] as $file => $hash) {
			if (is_string($file) && is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 && in_array($file, array_column(self::QUERIES, 1), true)) {
				$out[$file] = $hash;
			}
		}

		return $out;
	}

	private function querySource(string $file): string {
		return $this->pluginPath . '/resource/script_server/' . $file;
	}

	private function queryTarget(string $file): ?string {
		$dir = $this->cactiPath . '/resource/script_server';

		return is_dir($dir) ? $dir . '/' . $file : null;
	}
}
