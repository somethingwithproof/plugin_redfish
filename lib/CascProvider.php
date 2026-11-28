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
 * Configuration-as-Code (CasC) provider for plugin_redfish.
 *
 * Supports pure-PHP YAML and XML export/import without external dependencies.
 * Credentials are ALWAYS represented as vault references ("vault:redfish:<name>"):
 * raw passwords, private keys, or tokens are never exported and are rejected
 * during parse/validate. Applies require an exact SHA-256 plan confirmation hash.
 */
final class CascProvider {
	/**
	 * Provider id constant.
	 */
	public const PROVIDER_ID = 'redfish';
	/**
	 * Schema version constant.
	 */
	public const SCHEMA_VERSION = 1;
	/**
	 * Vault prefix constant.
	 */
	public const VAULT_PREFIX = 'vault:redfish:';

	/**
	 * Secret key pattern constant.
	 */
	private const SECRET_KEY_PATTERN = '/pass|secret|token|private_key|vault_key|auth_token/i';

	/**
	 * Build a canonical CasC document from current BMCs, credentials, and racks.
	 * Zero raw secrets or vault key material ever appear in the output.
	 *
	 * @param list<Bmc>        $bmcs
	 * @param list<Credential> $credentials
	 * @param list<Rack>       $racks
	 * @param array<int, list<array{host_id: int, rack_id: int, bmc_id: int, unit_position: int}>> $rackMembers
	 * @return array<string, mixed>
	 */
	public static function buildDocument(array $bmcs, array $credentials, array $racks, array $rackMembers = []): array {
		$credById = [];
		$credList = [];

		usort($credentials, static fn (Credential $a, Credential $b): int => $a->name <=> $b->name);

		foreach ($credentials as $cred) {
			$credById[$cred->id] = $cred->name;
			$credList[]          = [
				'name'      => $cred->name,
				'username'  => $cred->username,
				'vault_ref' => self::VAULT_PREFIX . $cred->name,
			];
		}

		usort($bmcs, static fn (Bmc $a, Bmc $b): int => $a->name <=> $b->name);
		$bmcList = [];

		foreach ($bmcs as $bmc) {
			$credName  = $credById[$bmc->credentialId] ?? '';
			$bmcList[] = [
				'name'           => $bmc->name,
				'address'        => $bmc->address,
				'port'           => $bmc->port,
				'poller_id'      => $bmc->pollerId,
				'host_id'        => $bmc->hostId,
				'credential_ref' => $credName !== '' ? self::VAULT_PREFIX . $credName : '',
				'tls_mode'       => $bmc->tlsMode->value,
				'ca_file'        => $bmc->caFile,
				'pin_sha256'     => $bmc->pin,
				'basic_fallback' => $bmc->basicFallback,
				'ipmi_fallback'  => $bmc->ipmiFallback,
				'admin_state'    => $bmc->adminState->value,
			];
		}

		usort($racks, static fn (Rack $a, Rack $b): int => [$a->siteId, $a->name] <=> [$b->siteId, $b->name]);
		$rackList = [];

		foreach ($racks as $rack) {
			$members = $rackMembers[$rack->id] ?? [];
			$hosts   = [];

			foreach ($members as $m) {
				$hosts[] = [
					'host_id'       => $m['host_id'],
					'bmc_id'        => $m['bmc_id'],
					'unit_position' => $m['unit_position'],
				];
			}

			$rackList[] = [
				'site_id'            => $rack->siteId,
				'name'               => $rack->name,
				'power_budget_watts' => $rack->powerBudgetWatts,
				'min_coverage_pct'   => $rack->minCoveragePct,
				'notes'              => $rack->notes,
				'members'            => $hosts,
			];
		}

		return [
			'provider'       => self::PROVIDER_ID,
			'schema_version' => self::SCHEMA_VERSION,
			'credentials'    => $credList,
			'bmcs'           => $bmcList,
			'racks'          => $rackList,
		];
	}

	/**
	 * Serialize a CasC document as YAML or XML.
	 *
	 * @param array<string, mixed> $document
	 */
	public static function export(array $document, string $format = 'yaml'): string {
		$fmt = strtolower(trim($format));

		if ($fmt === 'xml') {
			return self::toXml($document);
		}

		if ($fmt === 'yaml' || $fmt === 'yml') {
			return self::toYaml($document);
		}

		throw new \InvalidArgumentException('Unsupported export format: ' . $format);
	}

	/**
	 * Parse YAML or XML text into a normalized document array.
	 * Refuses documents containing raw secret keys or XML external entities.
	 *
	 * @return array<string, mixed>
	 */
	public static function parse(string $content, string $format = 'yaml'): array {
		$fmt = strtolower(trim($format));

		if ($fmt === 'xml') {
			$doc = self::fromXml($content);
		} elseif ($fmt === 'yaml' || $fmt === 'yml') {
			$doc = self::fromYaml($content);
		} else {
			throw new \InvalidArgumentException('Unsupported format: ' . $format);
		}

		return $doc;
	}

	/**
	 * Validate a parsed CasC document. Returns a list of human-readable errors.
	 *
	 * @param array<string, mixed> $document
	 * @return list<string>
	 */
	public static function validate(array $document): array {
		$errors = [];

		self::scanForSecretKeys($document, '', $errors);

		if (($document['provider'] ?? '') !== self::PROVIDER_ID) {
			$errors[] = 'provider must be "redfish".';
		}

		$version = $document['schema_version'] ?? null;

		if (!is_int($version) && !(is_string($version) && ctype_digit($version))) {
			$errors[] = 'schema_version must be an integer.';
		} elseif ((int) $version !== self::SCHEMA_VERSION) {
			$errors[] = sprintf('Unsupported schema_version %d (expected %d).', (int) $version, self::SCHEMA_VERSION);
		}

		$credNames   = [];
		$credentials = is_array($document['credentials'] ?? null) ? $document['credentials'] : [];

		foreach ($credentials as $idx => $cred) {
			if (!is_array($cred)) {
				$errors[] = sprintf('credentials[%d]: must be an object.', (int) $idx);

				continue;
			}

			$name     = Row::string($cred, 'name');
			$username = Row::string($cred, 'username');
			$vaultRef = Row::string($cred, 'vault_ref');

			if ($name === '' || mb_strlen($name) > 100) {
				$errors[] = sprintf('credentials[%d].name: required, 1-100 characters.', (int) $idx);
			} else {
				$credNames[$name] = true;
			}

			if ($username === '' || mb_strlen($username) > 255) {
				$errors[] = sprintf('credentials[%d].username: required, 1-255 characters.', (int) $idx);
			}

			if ($vaultRef !== '' && !str_starts_with($vaultRef, self::VAULT_PREFIX)) {
				$errors[] = sprintf('credentials[%d].vault_ref: must start with %s.', (int) $idx, self::VAULT_PREFIX);
			}
		}

		$bmcs    = is_array($document['bmcs'] ?? null) ? $document['bmcs'] : [];
		$seenBmc = [];

		foreach ($bmcs as $idx => $entry) {
			if (!is_array($entry)) {
				$errors[] = sprintf('bmcs[%d]: must be an object.', (int) $idx);

				continue;
			}

			$credRef  = Row::string($entry, 'credential_ref');
			$credName = '';

			if ($credRef === '' || !str_starts_with($credRef, self::VAULT_PREFIX)) {
				$errors[] = sprintf('bmcs[%d].credential_ref: must reference a vault credential (%s<name>).', (int) $idx, self::VAULT_PREFIX);
			} else {
				$credName = substr($credRef, strlen(self::VAULT_PREFIX));

				if ($credNames !== [] && !isset($credNames[$credName])) {
					$errors[] = sprintf('bmcs[%d].credential_ref: unknown credential "%s".', (int) $idx, $credName);
				}
			}

			$tlsMode    = Row::string($entry, 'tls_mode');
			$adminState = Row::string($entry, 'admin_state');
			$parsed     = BmcInput::parse([
				'name'           => Row::string($entry, 'name'),
				'address'        => Row::string($entry, 'address'),
				'port'           => (string) Row::int($entry, 'port', 443),
				'poller_id'      => (string) Row::int($entry, 'poller_id', Bmc::MAIN_POLLER),
				'host_id'        => (string) Row::int($entry, 'host_id', 0),
				'credential_id'  => '1',
				'tls_mode'       => $tlsMode !== '' ? $tlsMode : TlsMode::System->value,
				'ca_file'        => Row::string($entry, 'ca_file'),
				'pin_sha256'     => Row::string($entry, 'pin_sha256'),
				'basic_fallback' => Row::bool($entry, 'basic_fallback') ? 'on' : '',
				'ipmi_fallback'  => Row::bool($entry, 'ipmi_fallback') ? 'on' : '',
				'enabled'        => ($adminState !== '' ? $adminState : AdminState::Enabled->value) === AdminState::Enabled->value ? 'on' : '',
			], 0);

			foreach ($parsed->errors as $field => $msg) {
				$errors[] = sprintf('bmcs[%d].%s: %s', (int) $idx, $field, $msg);
			}

			$bmcName = Row::string($entry, 'name');

			if ($bmcName !== '') {
				if (isset($seenBmc[$bmcName])) {
					$errors[] = sprintf('bmcs[%d].name: duplicate BMC name "%s".', (int) $idx, $bmcName);
				}

				$seenBmc[$bmcName] = true;
			}
		}

		$racks     = is_array($document['racks'] ?? null) ? $document['racks'] : [];
		$seenRacks = [];

		foreach ($racks as $idx => $rack) {
			if (!is_array($rack)) {
				$errors[] = sprintf('racks[%d]: must be an object.', (int) $idx);

				continue;
			}

			$siteId = Row::int($rack, 'site_id', 0, 0);
			$name   = trim(Row::string($rack, 'name'));
			$budget = Row::float($rack, 'power_budget_watts');
			$cov    = Row::float($rack, 'min_coverage_pct') ?? Rack::DEFAULT_MIN_COVERAGE_PCT;

			if ($name === '' || mb_strlen($name) > 100) {
				$errors[] = sprintf('racks[%d].name: required, 1-100 characters.', (int) $idx);
			} else {
				$key = $siteId . ':' . $name;

				if (isset($seenRacks[$key])) {
					$errors[] = sprintf('racks[%d].name: duplicate rack "%s" in site %d.', (int) $idx, $name, $siteId);
				}

				$seenRacks[$key] = true;
			}

			if ($budget !== null && $budget <= 0.0) {
				$errors[] = sprintf('racks[%d].power_budget_watts: must be positive when set.', (int) $idx);
			}

			if ($cov < 0.0 || $cov > 100.0) {
				$errors[] = sprintf('racks[%d].min_coverage_pct: must be between 0 and 100.', (int) $idx);
			}
		}

		return $errors;
	}

	/**
	 * Compute a deterministic diff plan and canonical SHA-256 PLAN_HASH.
	 *
	 * @param array<string, mixed> $desired
	 * @param array<string, mixed> $current
	 * @return array{
	 *     hash: string,
	 *     actions: list<array{kind: string, action: string, name: string, changes: list<string>}>,
	 *     summary: array{create: int, update: int, unchanged: int}
	 * }
	 */
	public static function plan(array $desired, array $current): array {
		$actions   = [];
		$create    = 0;
		$update    = 0;
		$unchanged = 0;

		$curCreds = self::indexByKey(is_array($current['credentials'] ?? null) ? $current['credentials'] : [], 'name');
		$desCreds = self::indexByKey(is_array($desired['credentials'] ?? null) ? $desired['credentials'] : [], 'name');

		foreach ($desCreds as $name => $entry) {
			if (!isset($curCreds[$name])) {
				$actions[] = ['kind' => 'credential', 'action' => 'create', 'name' => $name, 'changes' => ['username']];
				$create++;
			} else {
				$diff = self::diffFields($curCreds[$name], $entry, ['username']);

				if ($diff !== []) {
					$actions[] = ['kind' => 'credential', 'action' => 'update', 'name' => $name, 'changes' => $diff];
					$update++;
				} else {
					$unchanged++;
				}
			}
		}

		$curBmcs = self::indexByKey(is_array($current['bmcs'] ?? null) ? $current['bmcs'] : [], 'name');
		$desBmcs = self::indexByKey(is_array($desired['bmcs'] ?? null) ? $desired['bmcs'] : [], 'name');
		$bmcCols = ['address', 'port', 'poller_id', 'host_id', 'credential_ref', 'tls_mode', 'ca_file', 'pin_sha256', 'basic_fallback', 'ipmi_fallback', 'admin_state'];

		foreach ($desBmcs as $name => $entry) {
			if (!isset($curBmcs[$name])) {
				$actions[] = ['kind' => 'bmc', 'action' => 'create', 'name' => $name, 'changes' => $bmcCols];
				$create++;
			} else {
				$diff = self::diffFields($curBmcs[$name], $entry, $bmcCols);

				if ($diff !== []) {
					$actions[] = ['kind' => 'bmc', 'action' => 'update', 'name' => $name, 'changes' => $diff];
					$update++;
				} else {
					$unchanged++;
				}
			}
		}

		$curRacks = self::indexRacks(is_array($current['racks'] ?? null) ? $current['racks'] : []);
		$desRacks = self::indexRacks(is_array($desired['racks'] ?? null) ? $desired['racks'] : []);
		$rackCols = ['power_budget_watts', 'min_coverage_pct', 'notes'];

		foreach ($desRacks as $key => $entry) {
			if (!isset($curRacks[$key])) {
				$actions[] = ['kind' => 'rack', 'action' => 'create', 'name' => $key, 'changes' => $rackCols];
				$create++;
			} else {
				$diff = self::diffFields($curRacks[$key], $entry, $rackCols);

				if ($diff !== []) {
					$actions[] = ['kind' => 'rack', 'action' => 'update', 'name' => $key, 'changes' => $diff];
					$update++;
				} else {
					$unchanged++;
				}
			}
		}

		$canonical = (string) json_encode(['provider' => self::PROVIDER_ID, 'actions' => $actions], JSON_UNESCAPED_SLASHES);
		$hash      = hash('sha256', $canonical);

		return [
			'hash'    => $hash,
			'actions' => $actions,
			'summary' => ['create' => $create, 'update' => $update, 'unchanged' => $unchanged],
		];
	}

	/**
	 * Constant-time check for --confirm=<PLAN_HASH>.
	 */
	public static function verifyPlanHash(string $expectedHash, string $confirmHash): bool {
		$clean = strtolower(trim($confirmHash));

		return $clean !== '' && hash_equals(strtolower($expectedHash), $clean);
	}

	/**
	 * Apply a validated CasC document to the repositories after verifying the
	 * plan confirmation hash.
	 *
	 * @param array<string, mixed> $desired
	 * @return array{ok: bool, hash: string, applied: int, errors: list<string>}
	 */
	public static function apply(
		array $desired,
		string $confirmHash,
		BmcRepository $bmcs,
		CredentialRepository $credentials,
		RackRepository $racks,
		int $userId = 0,
	): array {
		$errors = self::validate($desired);

		if ($errors !== []) {
			return ['ok' => false, 'hash' => '', 'applied' => 0, 'errors' => $errors];
		}

		$current = self::buildDocument($bmcs->all(), $credentials->all(), $racks->all());
		$plan    = self::plan($desired, $current);

		if (!self::verifyPlanHash($plan['hash'], $confirmHash)) {
			return [
				'ok'      => false,
				'hash'    => $plan['hash'],
				'applied' => 0,
				'errors'  => ['Plan confirmation hash does not match expected hash ' . $plan['hash'] . '.'],
			];
		}

		$applied  = 0;
		$credByNm = [];

		foreach ($credentials->all() as $c) {
			$credByNm[$c->name] = $c;
		}

		foreach (is_array($desired['credentials'] ?? null) ? $desired['credentials'] : [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$name     = Row::string($entry, 'name');
			$username = Row::string($entry, 'username');
			$existing = $credByNm[$name] ?? null;
			$savedId  = $credentials->save($existing !== null ? $existing->id : 0, $name, $username, $userId);

			if ($savedId > 0) {
				$found = $credentials->find($savedId);

				if ($found !== null) {
					$credByNm[$name] = $found;
				}

				if ($existing === null || $existing->username !== $username) {
					$applied++;
				}
			}
		}

		$bmcByNm = [];

		foreach ($bmcs->all() as $b) {
			$bmcByNm[$b->name] = $b;
		}

		foreach (is_array($desired['bmcs'] ?? null) ? $desired['bmcs'] : [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$name     = Row::string($entry, 'name');
			$credRef  = Row::string($entry, 'credential_ref');
			$credName = str_starts_with($credRef, self::VAULT_PREFIX) ? substr($credRef, strlen(self::VAULT_PREFIX)) : '';
			$credId   = isset($credByNm[$credName]) ? $credByNm[$credName]->id : 0;

			if ($credId <= 0) {
				continue;
			}

			$existing   = $bmcByNm[$name] ?? null;
			$tlsMode    = Row::string($entry, 'tls_mode');
			$adminState = Row::string($entry, 'admin_state');
			$parsed     = BmcInput::parse([
				'name'           => $name,
				'address'        => Row::string($entry, 'address'),
				'port'           => (string) Row::int($entry, 'port', 443),
				'poller_id'      => (string) Row::int($entry, 'poller_id', Bmc::MAIN_POLLER),
				'host_id'        => (string) Row::int($entry, 'host_id', 0),
				'credential_id'  => (string) $credId,
				'tls_mode'       => $tlsMode !== '' ? $tlsMode : TlsMode::System->value,
				'ca_file'        => Row::string($entry, 'ca_file'),
				'pin_sha256'     => Row::string($entry, 'pin_sha256'),
				'basic_fallback' => Row::bool($entry, 'basic_fallback') ? 'on' : '',
				'ipmi_fallback'  => Row::bool($entry, 'ipmi_fallback') ? 'on' : '',
				'enabled'        => ($adminState !== '' ? $adminState : AdminState::Enabled->value) === AdminState::Enabled->value ? 'on' : '',
			], $existing !== null ? $existing->id : 0);

			if ($parsed->bmc !== null && $bmcs->save($parsed->bmc, $userId) > 0) {
				$applied++;
			}
		}

		foreach (is_array($desired['racks'] ?? null) ? $desired['racks'] : [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$siteId   = Row::int($entry, 'site_id', 0, 0);
			$name     = trim(Row::string($entry, 'name'));
			$existing = $racks->findByName($siteId, $name);
			$rack     = new Rack(
				id: $existing !== null ? $existing->id : 0,
				siteId: $siteId,
				name: $name,
				powerBudgetWatts: Row::float($entry, 'power_budget_watts'),
				minCoveragePct: Row::float($entry, 'min_coverage_pct') ?? Rack::DEFAULT_MIN_COVERAGE_PCT,
				notes: Row::string($entry, 'notes'),
			);

			$rackId = $racks->save($rack, $userId);

			if ($rackId > 0) {
				$applied++;

				foreach (is_array($entry['members'] ?? null) ? $entry['members'] : [] as $member) {
					if (!is_array($member)) {
						continue;
					}

					$hostId = Row::int($member, 'host_id', 0, 0);

					if ($hostId > 0) {
						$racks->assignHost($rackId, $hostId, Row::int($member, 'bmc_id', 0, 0), Row::int($member, 'unit_position', 0, 0), $userId);
					}
				}
			}
		}

		return ['ok' => true, 'hash' => $plan['hash'], 'applied' => $applied, 'errors' => []];
	}

	/**
	 * Descriptor for the casc_register_providers hook.
	 *
	 * @param \Closure(): BmcRepository        $bmcs
	 * @param \Closure(): CredentialRepository $credentials
	 * @param \Closure(): RackRepository       $racks
	 * @return array<string, mixed>
	 */
	public static function registration(\Closure $bmcs, \Closure $credentials, \Closure $racks): array {
		return [
			'slug'          => self::PROVIDER_ID,
			'schemaVersion' => '1.0',
			'export'        => static function (array $options = []) use ($bmcs, $credentials, $racks): array {
				$rackRepo    = $racks();
				$allRacks    = $rackRepo->all();
				$rackMembers = [];

				foreach ($allRacks as $rack) {
					$rackMembers[$rack->id] = $rackRepo->members($rack->id);
				}

				return self::buildDocument($bmcs()->all(), $credentials()->all(), $allRacks, $rackMembers);
			},
			'validate'      => static function (array $desired): array {
				$doc = self::normalizeDesiredDocument($desired);

				return self::validate($doc);
			},
			'plan'          => static function (array $desired, array $options = []) use ($bmcs, $credentials, $racks): array {
				$doc     = self::normalizeDesiredDocument($desired);
				$current = self::buildDocument($bmcs()->all(), $credentials()->all(), $racks()->all());

				return self::plan($doc, $current);
			},
			'apply'         => static function (array $desired, string $confirm, array $options = []) use ($bmcs, $credentials, $racks): array {
				$doc    = self::normalizeDesiredDocument($desired);
				$userId = Row::int($options, 'user_id', 0, 0);

				return self::apply($doc, $confirm, $bmcs(), $credentials(), $racks(), $userId);
			},
		];
	}

	/**
	 * Ensure a desired array document carries default provider and schema_version keys.
	 *
	 * @param array<array-key, mixed> $desired
	 * @return array<string, mixed>
	 */
	private static function normalizeDesiredDocument(array $desired): array {
		$doc = [];

		foreach ($desired as $k => $v) {
			$doc[(string) $k] = $v;
		}

		if (!array_key_exists('provider', $doc)) {
			$doc['provider'] = self::PROVIDER_ID;
		}

		if (!array_key_exists('schema_version', $doc)) {
			$doc['schema_version'] = self::SCHEMA_VERSION;
		}

		return $doc;
	}

	/**
	 * @param array<array-key, mixed> $node
	 * @param list<string>            $errors
	 */
	private static function scanForSecretKeys(array $node, string $prefix, array &$errors): void {
		foreach ($node as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

			if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
				$errors[] = sprintf('%s: secret or credential fields are forbidden in CasC files; use vault_ref / credential_ref.', $path);
			}

			if (is_array($value)) {
				self::scanForSecretKeys($value, $path, $errors);
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $items
	 * @return array<string, array<string, mixed>>
	 */
	private static function indexByKey(array $items, string $key): array {
		$indexed = [];

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$k = Row::string($item, $key);

			if ($k !== '') {
				$clean = [];

				foreach ($item as $fk => $fv) {
					$clean[(string) $fk] = $fv;
				}

				$indexed[$k] = $clean;
			}
		}

		ksort($indexed);

		return $indexed;
	}

	/**
	 * @param array<array-key, mixed> $racks
	 * @return array<string, array<string, mixed>>
	 */
	private static function indexRacks(array $racks): array {
		$indexed = [];

		foreach ($racks as $rack) {
			if (!is_array($rack)) {
				continue;
			}

			$name = trim(Row::string($rack, 'name'));

			if ($name === '') {
				continue;
			}

			$key   = Row::int($rack, 'site_id', 0, 0) . ':' . $name;
			$clean = [];

			foreach ($rack as $fk => $fv) {
				$clean[(string) $fk] = $fv;
			}

			$indexed[$key] = $clean;
		}

		ksort($indexed);

		return $indexed;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param list<string>         $fields
	 * @return list<string>
	 */
	private static function diffFields(array $before, array $after, array $fields): array {
		$changed = [];

		foreach ($fields as $field) {
			$a = $before[$field] ?? null;
			$b = $after[$field] ?? null;

			if ($a !== $b) {
				$changed[] = $field;
			}
		}

		return $changed;
	}

	/**
	 * Minimal deterministic YAML serializer for CasC documents.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private static function toYaml(array $data, int $indent = 0): string {
		$pad = str_repeat('  ', $indent);
		$out = '';

		if (array_is_list($data)) {
			foreach ($data as $item) {
				if (is_array($item)) {
					if ($item === []) {
						$out .= $pad . "- []\n";

						continue;
					}

					$first = true;

					foreach ($item as $k => $v) {
						$prefix = $first ? $pad . '- ' : $pad . '  ';
						$first  = false;

						if (is_array($v)) {
							if ($v === []) {
								$out .= $prefix . (string) $k . ": []\n";
							} else {
								$out .= $prefix . (string) $k . ":\n" . self::toYaml($v, $indent + 2);
							}
						} else {
							$out .= $prefix . (string) $k . ': ' . self::yamlScalar($v) . "\n";
						}
					}
				} else {
					$out .= $pad . '- ' . self::yamlScalar($item) . "\n";
				}
			}

			return $out;
		}

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				if ($value === []) {
					$out .= $pad . (string) $key . ": []\n";
				} else {
					$out .= $pad . (string) $key . ":\n" . self::toYaml($value, $indent + 1);
				}
			} else {
				$out .= $pad . (string) $key . ': ' . self::yamlScalar($value) . "\n";
			}
		}

		return $out;
	}

	/**
	 * Yaml scalar.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function yamlScalar(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		$s = is_string($value) ? $value : '';

		if ($s === '' || preg_match('/[:#\n\r\t"\'\\[\]{},&*!?|><=%@`]|^\s|\s$|^(?:true|false|null|yes|no|on|off|~|-?[0-9]+(?:\.[0-9]+)?)$/i', $s) === 1) {
			return '"' . str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s) . '"';
		}

		return $s;
	}

	/**
	 * Minimal indentation-based YAML parser for CasC documents.
	 *
	 * @return array<string, mixed>
	 */
	private static function fromYaml(string $yaml): array {
		$rawLines = preg_split('/\r?\n/', $yaml);

		if (!is_array($rawLines)) {
			return [];
		}

		/** @var list<array{indent: int, text: string}> $lines */
		$lines = [];

		foreach ($rawLines as $raw) {
			$trimmed = ltrim($raw, ' ');

			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				continue;
			}

			$lines[] = ['indent' => strlen($raw) - strlen($trimmed), 'text' => rtrim($trimmed)];
		}

		$pos = 0;
		$doc = self::parseYamlMap($lines, $pos, 0);

		return $doc;
	}

	/**
	 * @param list<array{indent: int, text: string}> $lines
	 * @return array<string, mixed>
	 */
	private static function parseYamlMap(array $lines, int &$pos, int $minIndent): array {
		$map   = [];
		$count = count($lines);

		while ($pos < $count) {
			$line = $lines[$pos];

			if ($line['indent'] < $minIndent || str_starts_with($line['text'], '- ')) {
				break;
			}

			$colon = strpos($line['text'], ':');

			if ($colon === false) {
				$pos++;

				continue;
			}

			$key  = trim(substr($line['text'], 0, $colon));
			$rest = ltrim(substr($line['text'], $colon + 1));
			$pos++;

			if ($rest === '[]') {
				$map[$key] = [];
			} elseif ($rest === '{}') {
				$map[$key] = [];
			} elseif ($rest === '') {
				if ($pos < $count && $lines[$pos]['indent'] > $line['indent']) {
					$childIndent = $lines[$pos]['indent'];
					$map[$key]   = str_starts_with($lines[$pos]['text'], '- ')
						? self::parseYamlList($lines, $pos, $childIndent)
						: self::parseYamlMap($lines, $pos, $childIndent);
				} else {
					$map[$key] = null;
				}
			} else {
				$map[$key] = self::parseYamlScalar($rest);
			}
		}

		return $map;
	}

	/**
	 * @param list<array{indent: int, text: string}> $lines
	 * @return list<mixed>
	 */
	private static function parseYamlList(array $lines, int &$pos, int $listIndent): array {
		$list  = [];
		$count = count($lines);

		while ($pos < $count) {
			$line = $lines[$pos];

			if ($line['indent'] !== $listIndent || !str_starts_with($line['text'], '- ')) {
				break;
			}

			$after = ltrim(substr($line['text'], 2));
			$pos++;

			if ($after === '[]') {
				$list[] = [];

				continue;
			}

			$colon = strpos($after, ':');

			if ($colon !== false) {
				$item = [];
				$key  = trim(substr($after, 0, $colon));
				$rest = ltrim(substr($after, $colon + 1));

				if ($rest === '[]') {
					$item[$key] = [];
				} elseif ($rest === '') {
					if ($pos < $count && $lines[$pos]['indent'] > $listIndent + 2) {
						$childIndent = $lines[$pos]['indent'];
						$item[$key]  = str_starts_with($lines[$pos]['text'], '- ')
							? self::parseYamlList($lines, $pos, $childIndent)
							: self::parseYamlMap($lines, $pos, $childIndent);
					} else {
						$item[$key] = null;
					}
				} else {
					$item[$key] = self::parseYamlScalar($rest);
				}

				if ($pos < $count && $lines[$pos]['indent'] > $listIndent && !str_starts_with($lines[$pos]['text'], '- ')) {
					$more = self::parseYamlMap($lines, $pos, $lines[$pos]['indent']);
					$item = array_merge($item, $more);
				}

				$list[] = $item;
			} else {
				$list[] = self::parseYamlScalar($after);
			}
		}

		return $list;
	}

	/**
	 * Parse yaml scalar.
	 *
	 * @param string $raw
	 * @return mixed
	 */
	private static function parseYamlScalar(string $raw): mixed {
		if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
			$inner = substr($raw, 1, -1);

			return str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $inner);
		}

		if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
			return str_replace("''", "'", substr($raw, 1, -1));
		}

		$lower = strtolower($raw);

		if ($lower === 'null' || $lower === '~') {
			return null;
		}

		if ($lower === 'true') {
			return true;
		}

		if ($lower === 'false') {
			return false;
		}

		if (preg_match('/^-?[0-9]+$/', $raw) === 1) {
			return (int) $raw;
		}

		if (preg_match('/^-?[0-9]+\.[0-9]+$/', $raw) === 1) {
			return (float) $raw;
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private static function toXml(array $document): string {
		$dom                     = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput       = true;
		$root                    = $dom->createElement('redfish_casc');
		$provider = Row::string($document, 'provider');
		$root->setAttribute('provider', $provider !== '' ? $provider : self::PROVIDER_ID);
		$root->setAttribute('schema_version', (string) Row::int($document, 'schema_version', self::SCHEMA_VERSION));
		$dom->appendChild($root);

		$credsNode = $dom->createElement('credentials');
		$root->appendChild($credsNode);

		foreach (is_array($document['credentials'] ?? null) ? $document['credentials'] : [] as $cred) {
			if (!is_array($cred)) {
				continue;
			}

			$item = $dom->createElement('credential');
			self::appendXmlFields($dom, $item, $cred);
			$credsNode->appendChild($item);
		}

		$bmcsNode = $dom->createElement('bmcs');
		$root->appendChild($bmcsNode);

		foreach (is_array($document['bmcs'] ?? null) ? $document['bmcs'] : [] as $bmc) {
			if (!is_array($bmc)) {
				continue;
			}

			$item = $dom->createElement('bmc');
			self::appendXmlFields($dom, $item, $bmc);
			$bmcsNode->appendChild($item);
		}

		$racksNode = $dom->createElement('racks');
		$root->appendChild($racksNode);

		foreach (is_array($document['racks'] ?? null) ? $document['racks'] : [] as $rack) {
			if (!is_array($rack)) {
				continue;
			}

			$item = $dom->createElement('rack');

			foreach ($rack as $k => $v) {
				if ($k === 'members' && is_array($v)) {
					$membersNode = $dom->createElement('members');

					foreach ($v as $member) {
						if (!is_array($member)) {
							continue;
						}

						$mItem = $dom->createElement('member');
						self::appendXmlFields($dom, $mItem, $member);
						$membersNode->appendChild($mItem);
					}

					$item->appendChild($membersNode);
				} else {
					self::appendXmlScalar($dom, $item, (string) $k, $v);
				}
			}

			$racksNode->appendChild($item);
		}

		return (string) $dom->saveXML();
	}

	/**
	 * @param array<array-key, mixed> $fields
	 */
	private static function appendXmlFields(\DOMDocument $dom, \DOMElement $parent, array $fields): void {
		foreach ($fields as $key => $value) {
			if (!is_array($value)) {
				self::appendXmlScalar($dom, $parent, (string) $key, $value);
			}
		}
	}

	/**
	 * Append xml scalar.
	 *
	 * @param \DOMDocument $dom
	 * @param \DOMElement $parent
	 * @param string $name
	 * @param mixed $value
	 * @return void
	 */
	private static function appendXmlScalar(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value): void {
		$el = $dom->createElement($name);

		if ($value === null) {
			$el->setAttribute('null', 'true');
		} elseif (is_bool($value)) {
			$el->setAttribute('type', 'bool');
			$el->appendChild($dom->createTextNode($value ? 'true' : 'false'));
		} elseif (is_int($value)) {
			$el->setAttribute('type', 'int');
			$el->appendChild($dom->createTextNode((string) $value));
		} elseif (is_float($value)) {
			$el->setAttribute('type', 'float');
			$el->appendChild($dom->createTextNode((string) $value));
		} else {
			$el->appendChild($dom->createTextNode(is_string($value) ? $value : ''));
		}

		$parent->appendChild($el);
	}

	/**
	 * Parse XML safely (refuses DOCTYPE / entity expansion).
	 *
	 * @return array<string, mixed>
	 */
	private static function fromXml(string $xml): array {
		if (preg_match('/<!DOCTYPE\b|<!ENTITY\b/i', $xml) === 1) {
			throw new \InvalidArgumentException('XML DOCTYPE and ENTITY declarations are refused.');
		}

		$previous = libxml_use_internal_errors(true);
		$dom      = new \DOMDocument();

		try {
			if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA)) {
				throw new \InvalidArgumentException('Malformed XML document.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$root = $dom->documentElement;

		if ($root === null || $root->tagName !== 'redfish_casc') {
			throw new \InvalidArgumentException('Unexpected XML root element.');
		}

		$doc = [
			'provider'       => $root->getAttribute('provider'),
			'schema_version' => ctype_digit($root->getAttribute('schema_version')) ? (int) $root->getAttribute('schema_version') : 0,
			'credentials'    => [],
			'bmcs'           => [],
			'racks'          => [],
		];

		foreach ($root->childNodes as $section) {
			if (!$section instanceof \DOMElement) {
				continue;
			}

			if ($section->tagName === 'credentials') {
				foreach ($section->childNodes as $child) {
					if ($child instanceof \DOMElement && $child->tagName === 'credential') {
						$doc['credentials'][] = self::readXmlFields($child);
					}
				}
			} elseif ($section->tagName === 'bmcs') {
				foreach ($section->childNodes as $child) {
					if ($child instanceof \DOMElement && $child->tagName === 'bmc') {
						$doc['bmcs'][] = self::readXmlFields($child);
					}
				}
			} elseif ($section->tagName === 'racks') {
				foreach ($section->childNodes as $child) {
					if ($child instanceof \DOMElement && $child->tagName === 'rack') {
						$rack            = self::readXmlFields($child);
						$rack['members'] = [];

						foreach ($child->childNodes as $sub) {
							if ($sub instanceof \DOMElement && $sub->tagName === 'members') {
								foreach ($sub->childNodes as $mNode) {
									if ($mNode instanceof \DOMElement && $mNode->tagName === 'member') {
										$rack['members'][] = self::readXmlFields($mNode);
									}
								}
							}
						}

						$doc['racks'][] = $rack;
					}
				}
			}
		}

		return $doc;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function readXmlFields(\DOMElement $element): array {
		$fields = [];

		foreach ($element->childNodes as $child) {
			if (!$child instanceof \DOMElement || $child->tagName === 'members') {
				continue;
			}

			if ($child->getAttribute('null') === 'true') {
				$fields[$child->tagName] = null;

				continue;
			}

			$type = $child->getAttribute('type');
			$text = $child->textContent;

			$fields[$child->tagName] = match ($type) {
				'bool'  => $text === 'true',
				'int'   => (int) $text,
				'float' => (float) $text,
				default => $text,
			};
		}

		return $fields;
	}
}
