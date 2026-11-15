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

# Scale measurements (tests/bin/scale-flow.php). Run from the root of a
# disposable Cacti install with the plugin installed and enabled:
#
#   REDFISH_TEST_DATABASE=disposable sh plugins/redfish/tests/bin/scale-replay.sh [N[:latency-ms] ...]
#
# For each BMC count (default 100, 100 with 20 ms of latency, and 1000) it
# prints METRIC lines, after a description of the machine.
set -u

[ "${REDFISH_TEST_DATABASE:-}" = disposable ] || { echo "Set REDFISH_TEST_DATABASE=disposable" >&2; exit 2; }
[ "$#" -gt 0 ] || set -- 100 100:20 1000

echo "METRIC host_cpus $(nproc 2>/dev/null || echo unknown)"
echo "METRIC host_memory_kib $(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo unknown)"
echo "METRIC php $(php -r 'print PHP_VERSION;')"

for spec in "$@"; do
	n=${spec%%:*}
	latency=0
	[ "$spec" != "$n" ] && latency=${spec#*:}
	php plugins/redfish/tests/bin/scale-flow.php "$n" "$latency" || exit 1
done
