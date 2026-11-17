# Redfish operator guide

This guide covers the development version of plugin_redfish after milestones 2 and 3. There is no tagged release yet. CI tests Cacti 1.2.31 and the current 1.2.x branch on PHP 8.2 through 8.5, including the plugin's install and graph flows. Those jobs use fixture BMCs and the DMTF Redfish mockup server; they do not qualify a real vendor or firmware line.

## Before installing

- Use Cacti 1.2.31 or later and PHP 8.2 or later. The plugin deliberately refuses to install or enable on PHP versions below 8.2.
- Run Cacti's poller with network access to the BMC management network. The current collector uses HTTPS to Redfish on TCP 443. It does not use IPMI or UDP 623; IPMI is a later phase.
- Choose an account that can read the resources the plugin collects. Do not use an administrator account. Vendor-specific role names and permissions are not qualified against real BMC firmware yet; verify the account against the resources your BMC exposes.
- Decide how the poller and web server will read the credential key, and how the database and that key will be backed up together.

## Install and prepare the vault

Install the plugin under Cacti's `plugins/redfish` directory, then enable it through Cacti's Plugin Management page. The plugin checks its PHP requirement and creates its tables through Cacti's plugin API. After enabling it, open **Configuration → Settings → Redfish** and set the vault key path if you are not using `/etc/cacti/redfish-vault.key`.

Create a 32-byte random key outside the web root. For a hexadecimal key file:

```sh
openssl rand -hex 32
```

Write the output to the configured path with permissions that let the web server and poller read it but prevent other users from reading or replacing it. Keep the containing directory writable only by root and the service account that owns the key. The plugin does not create or repair the key file. Back up the key with the Cacti database; a database restore without the matching key cannot decrypt stored passwords.

Set the collector budget below Cacti's poller interval. The defaults are a 300-second collection interval and a 120-second collector budget. The collector handles unfinished BMCs on a later run. Keep the rediscovery interval, worker count, connection timeout, request timeout, and response-size limit appropriate for the management network; the settings page gives the valid range and default for each.

## Configure TLS and register a BMC

Keep certificate verification enabled. Use a CA bundle for a private certificate authority or pin the BMC's SPKI SHA-256 fingerprint. A per-BMC unverified setting disables peer verification for that BMC and leaves a warning on the health page; use it only as a temporary diagnostic exception on an isolated management network.

Create a credential entry with the read-only account and password. Then add the BMC with its DNS name or management address, HTTPS port, credential, and TLS mode. Import files must refer to credentials by name and must not contain passwords. CSV import is an administrator action; review the rows and target addresses before importing.

The BMC form and health page show collection state and data age. Investigate authentication, TLS, DNS, timeout, response-size, and stale-data warnings before creating alert thresholds. The current code has not been qualified against real BMC firmware. The vendor matrix in [research/vendor-matrix.md](research/vendor-matrix.md) records what is known and what remains unverified; do not infer firmware support from the mockup CI job.

## Create a device and graphs

On the BMC's page, choose **Create Device** to make a separate Cacti device, or **Add Graphs** to attach BMC queries to an existing server device. Review the plan before confirming. The plugin does not probe the BMC through Cacti's device availability checks. The creator needs both Redfish administration access and the Cacti device-management permission. A new device that the creator cannot see is rolled back.

After provisioning, use Cacti's graph creation flow to add the Redfish summary and member graphs. A BMC member that becomes absent or disappears reads as unknown (`U`); the plugin keeps its graph and RRD history. After a firmware update or component replacement, run **Add Graphs** again to reindex current members. Remove obsolete graphs manually only after their history is no longer needed.

## Configure alerts

Redfish summary and per-member values are ordinary Cacti data sources. Create Thold thresholds for the values that matter to the site and use Maint schedules to suppress notifications during planned work. Choose a threshold duration of at least one collector interval so a repeated poll of the same cached reading does not appear to be a new observation. Keep the `age` threshold enabled if you need to detect a BMC that has stopped answering. Verify a threshold's firing and recovery behavior with your own Thold and Maint versions before relying on it operationally.

## Back up, restore, and handle failures

Back up the Cacti database and the exact vault key file as one recovery set. Store the key backup separately from the database backup and restrict access to both. Test restoration in a non-production Cacti instance before relying on the backup. Restoring only the database or losing the key requires re-entering BMC passwords.

For a restore drill, create an isolated Cacti instance with the same plugin version, restore a copy of the database, and install the matching vault key at the path configured in that instance. Preserve the key's owner and restrictive file permissions. Keep the test instance off production BMC networks or restrict it to a dedicated fixture BMC. Confirm that the credential can be decrypted, collection completes, and the health page reports a current read. Then repeat with the key unavailable: collection must fail closed, the Cacti pages must remain available, and the health page must report the credential failure. Record the Cacti, PHP, database, and plugin versions and the outcome; do not put the key or passwords in the report. This is a procedure, not a completed restore drill.

If the key is missing, unreadable, or invalid, collection fails closed; do not replace it with a new key and expect existing credentials to work. Restore the matching key or re-enter credentials. If a BMC is unavailable, the plugin retains cached readings and marks them stale; stale values become unknown rather than zero. Check the health page, poller logs, DNS, management-network reachability, account access, and certificate trust. Do not disable TLS verification globally to work around a certificate problem.

Disabling the plugin stops collection. On uninstall, the default is to keep the plugin's tables. The uninstall setting can request table removal; export any needed BMC and credential metadata first. The plugin leaves Cacti devices, graphs, and BMC configuration in place. Removing the plugin does not shut down or reconfigure a BMC.

## Compatibility and qualification limits

No real vendor firmware line is currently declared supported. The development suite includes hand-written Dell, HPE, and Lenovo shapes and the DMTF mockup server, but those are test inputs, not real-device qualification. OpenBMC and other vendor families also need redacted captures and fixture runs before support can be stated. See [research/vendor-matrix.md](research/vendor-matrix.md) and issue [#19](https://github.com/somethingwithproof/plugin_redfish/issues/19).

The plugin is not yet released. Phase 1 pilot reports, real-BMC latency measurements, backup/restore drills, and security qualification remain open. Do not use the current development build as evidence that a particular BMC, firmware, fleet size, or recovery procedure has been qualified.
