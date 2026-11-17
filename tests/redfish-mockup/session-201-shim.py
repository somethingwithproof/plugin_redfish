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
# Runs DMTF's Redfish Mockup Server (BSD-3-Clause, image dmtf/redfish-mockup-server)
# with one change: a successful POST to a session collection answers 201 Created
# instead of 204 No Content, as DSP0266 section 12.4 requires and as plugin_redfish
# expects. The server already sends the Location header and a fixed X-Auth-Token.
#
# Mounted into the pinned image and started with --entrypoint python. Everything
# else, including argument parsing, TLS, and short-form handling, is the server's
# own main(). Test code: it runs only as a process in the CI container, is never
# under the web root of an install (tests/ is export-ignore), and refuses to run
# when imported instead of started.
import sys

if __name__ != "__main__":
    raise SystemExit("session-201-shim.py runs only as a program")

sys.path.insert(0, "/usr/src/app")
import redfishMockupServer as server

_send_response = server.RfMockupServer.send_response


def send_response(self, code, message=None):
    if code == 204 and self.command == "POST" and self.path.rstrip("/").endswith("SessionService/Sessions"):
        code = 201
    return _send_response(self, code, message)


server.RfMockupServer.send_response = send_response

if __name__ == "__main__":
    sys.argv[0] = "redfishMockupServer.py"
    server.main()
