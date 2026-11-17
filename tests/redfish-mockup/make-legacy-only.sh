#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_redfish, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_redfish
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.
#
# Derives the legacy-only mockup from DMTF's public-rackmount1 (BSD-3-Clause,
# Copyright DMTF): the same tree with the EnvironmentMetrics, ThermalSubsystem,
# and PowerSubsystem links removed from the chassis, so discovery has only the
# Thermal and Power resources to read (ADR 0004, ADR 0013). The result is a
# modified copy; it is built at test time and never committed.
#
#   make-legacy-only.sh <public-rackmount1 dir> <output dir>
set -eu

src=$1
out=$2

[ -f "$src/Chassis/1U/index.json" ] || { echo "not public-rackmount1: $src" >&2; exit 1; }
[ ! -e "$out" ] || { echo "output exists: $out" >&2; exit 1; }

cp -R "$src" "$out"
jq 'del(.EnvironmentMetrics, .ThermalSubsystem, .PowerSubsystem)' "$src/Chassis/1U/index.json" > "$out/Chassis/1U/index.json.new"
mv "$out/Chassis/1U/index.json.new" "$out/Chassis/1U/index.json"
jq -e '(has("Thermal") and has("Power")) and ([.EnvironmentMetrics, .ThermalSubsystem, .PowerSubsystem] | all(. == null))' "$out/Chassis/1U/index.json" > /dev/null
