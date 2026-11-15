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
 * The members and readings one run produced for one BMC.
 */
final class Observation {
	/** @var array<string, Member> */
	public array $members = [];

	/** @var array<string, Reading> keyed by member and metric */
	public array $readings = [];

	public function member(Member $member): void {
		// The first source to name a member wins; a later one only adds readings.
		$this->members[$member->key] ??= $member;
	}

	public function add(Reading $reading): void {
		$this->readings[$reading->member . "\0" . $reading->metric] = $reading;
	}

	public function merge(self $other): void {
		foreach ($other->members as $member) {
			$this->member($member);
		}

		foreach ($other->readings as $reading) {
			$this->add($reading);
		}
	}

	public function find(string $member, string $metric): ?Reading {
		return $this->readings[$member . "\0" . $metric] ?? null;
	}
}
