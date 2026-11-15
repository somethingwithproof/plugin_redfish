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

# Thold and Maint end to end (tests/bin/alert-flow.php). Run from the root
# of a disposable Cacti install with the plugin installed and enabled, and
# unchanged Thold and Maint releases at plugins/thold and plugins/maint:
#
#   REDFISH_TEST_DATABASE=disposable sh plugins/redfish/tests/bin/alert-replay.sh
#
# Installs and enables Thold and Maint, then runs each step, a forced poll,
# and the Thold check. Exits non-zero when any check failed.
set -u

[ "${REDFISH_TEST_DATABASE:-}" = disposable ] || { echo "Set REDFISH_TEST_DATABASE=disposable" >&2; exit 2; }
[ -f plugins/thold/setup.php ] && [ -f plugins/maint/setup.php ] || { echo "plugins/thold and plugins/maint are required" >&2; exit 2; }

php cli/plugin_manage.php --plugin=thold --plugin=maint --install --enable || exit 1

fail=0

for step in $(php plugins/redfish/tests/bin/alert-flow.php list); do
	echo "== step $step"
	php plugins/redfish/tests/bin/alert-flow.php "$step" || fail=1
	[ "$step" = final ] && break

	# RRDtool refuses a second update in the same second, and the steps
	# run faster than that.
	sleep 1
	php poller.php --poller=1 --force > /tmp/redfish-alert-poll.txt 2>&1 || { fail=1; tail -5 /tmp/redfish-alert-poll.txt; }

	# poller_bottom starts poller_thold.php in the background. Wait for it,
	# then run it in the foreground: a run that finds nothing new to check
	# changes nothing, because Thold clears each threshold's check flag.
	for _ in $(seq 1 60); do
		[ "$(php -r 'include("./include/cli_check.php"); print db_fetch_cell("SELECT COUNT(*) FROM processes WHERE tasktype = \"thold\"");')" = 0 ] && break
		sleep 1
	done

	php plugins/thold/poller_thold.php > /tmp/redfish-alert-thold.txt 2>&1 || { fail=1; cat /tmp/redfish-alert-thold.txt; }
done

echo "alert replay fail=$fail"
exit $fail
