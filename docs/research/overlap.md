# Overlap with existing Cacti templates and plugins

Research for [#27](https://github.com/somethingwithproof/plugin_redfish/issues/27), 2026-11-15. Question: what do the shipped `Dell_iDrac` and `HPE_iLO` packages, the lm-sensors queries, plugin_hmib, plugin_apcupsd and plugin_credentials cover, and where could this plugin duplicate or collide with them?

**Result.** The plan's "Why this plugin" claims hold. Neither shipped SNMP package contains any watts-based data: a case-insensitive search for "watt" in the decoded package XML and the query files of both returns nothing, and the iDRAC package reads only status codes and temperature, the iLO package only status codes, temperatures in degrees and a three-state fan speed. The Cacti organization has no public Redfish or IPMI collector. Collisions are avoidable by naming. Importing the packages and checking graph names on a device that uses both (the issue's method) was not done; it needs a Cacti instance (*live*). Asking the template maintainers whether they want the templates kept is an owner action and was not done.

## Method

The `.xml.gz` packages were read with `git show` from both refs (`release/1.2.31` `1e8eaca2`, `1.2.x` `e2d5c258`), decoded (each is a wrapper whose `files` entries are base64 of the real XML), and parsed. `Dell_iDrac.xml.gz` and `HPE_iLO.xml.gz` are byte-identical at the two refs. Organization repositories were listed with the GitHub REST API (34 public repositories on 2026-11-15; names and descriptions only).

## Coverage

| Capability | Existing source | What it reads | Gap this plugin fills |
|---|---|---|---|
| iDRAC status, power supply state, temperature, cooling state | `Dell_iDrac` package (version 1.2.31): SNMP queries `idrac_power_supply` (name `.1.3.6.1.4.1.674.10892.5.4.600.12.1.8`, status `.600.12.1.5`), `idrac_temperature` (name, `Temperature*10` `.700.20.1.6`, status), `idrac_cooling` (name, status), plus disks, controllers, battery | Status enumerations and a temperature in tenths of a degree; no power draw, no fan RPM, no inlet label | Watts, inlet, fan RPM or percent, supply input and output watts, over HTTPS without an SNMP agent on the BMC |
| iLO status, temperatures, fans | `HPE_iLO` package (version 1.2.29): `hpe_ilo_temp` (`cpqHeTemperatureCelsius`, locale, threshold, condition), `hpe_ilo_fans` (present, type, speed "1=other, 2=normal, 3=high", condition), CPU, memory, drives, NIC | Temperature in degrees; fan speed as a category; no watts | Node power, fan numeric speed |
| lm-sensors | `resource/script_server/netsnmp_lmsensors_fan.xml`, `_thermal.xml`, `_voltage.xml` at `1.2.x` | Net-SNMP lm-sensors data from the host OS | BMC data that survives OS failure |
| Host resources | plugin_hmib `develop` `81d6e14`, README: HOST-RESOURCES-MIB CPU, memory, storage, installed software; INFO `compat = 1.2.25`, `remote_collect:0` | OS-level SNMP | Out-of-band hardware data |
| UPS | plugin_apcupsd `develop` `aa7cada`, README: apcupsd, NUT and SNMP UPS status; INFO `compat = 1.2.29`, `remote_collect:1` | UPS power state | Server node power |
| Credentials | plugin_credentials, see [credentials.md](credentials.md) | stores credentials, no BMC reads | the collector |
| Redfish or IPMI collector in the Cacti organization | none: 34 public repositories listed; names and descriptions matching `redfish`, `ipmi`, `bmc`, `sensor`, `power`, `thermal`, `idrac`, `ilo`, `hardware`, `ups`, `apc` are `plugin_hmib` and `plugin_apcupsd` only | | |
| Node power in watts in any shipped template | none found by text search of both packages | | |

The package files carry their own authorship and contact fields; these are not reproduced here.

## Naming and collision rules

Proposed, from what the packages use:

1. Existing graph titles have the form `|host_description| - HPE iLo - Temperatures` and `|host_description| -  iDrac - Temperature |query_temperatureName|`. Name this plugin's graphs `|host_description| - Redfish - ...` so a device with both an SNMP package and Redfish shows two clearly different sets.
2. Existing data templates are `Dell iDrac - ...` and `HPE iLO - ...`; use a `Redfish - ` prefix for data templates, graph templates and the data query (`Redfish Sensors`), and the plan's `Redfish Node Summary`.
3. Template XML hashes are fixed per template: generate new, unique hashes and never reuse a hash from another package (hash collisions would overwrite templates on import). Not run: the import behavior was not tested.
4. Data source names inside this plugin use its own prefix and must satisfy `^[a-zA-Z0-9_]{1,19}$` (see [cacti-core.md](cacti-core.md)); the existing packages use SNMP-style names that do not constrain ours.
5. Realm and menu names: "Redfish View" and "Redfish Admin" (plan) do not collide with plugin_hmib or plugin_apcupsd names as far as their READMEs show; realm file names were not read.
6. A device that has both SNMP and Redfish data will have two devices or one device with two sets of graphs depending on decision #11; the naming rule above makes either unambiguous.

## Plan "Why this plugin" claims

| Claim | Verdict |
|---|---|
| The `1.2.x` branch ships `Dell_iDrac` and `HPE_iLO` and lm-sensors script server queries | confirmed; the first two are identical at 1.2.31, and three lm-sensors files (`fan`, `thermal`, `voltage`) exist at `1.2.x` |
| Whether either reports node power in watts was not established | **established: neither does**, by text search of the packages; the SNMP OIDs they use were listed, but whether the same MIBs offer power draw elsewhere (for example Dell's power-usage table) is not covered |
| plugin_credentials defines a type "IPMI / Redfish (BMC)" without a collector | confirmed |
| plugin_hmib reads Host Resources MIB; plugin_apcupsd reads UPS state | confirmed |
| The organization has no Redfish or IPMI collector plugin | confirmed on 2026-11-15 for public repositories (34); private repositories not visible |

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #27 | Import both packages on 1.2.31 and list data sources, graph templates and data queries | Static findings above |
| #27 | Put one device on an SNMP package and on Redfish templates and list graph names | No naming collision at runtime |
| #27 | Ask the template maintainers whether the SNMP templates stay | Owner action |
