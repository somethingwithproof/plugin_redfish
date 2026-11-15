# Vendor coverage matrix

Research for [#19](https://github.com/somethingwithproof/plugin_redfish/issues/19) and the evidence for decision [#8](https://github.com/somethingwithproof/plugin_redfish/issues/8), 2026-11-15. Question: for Dell iDRAC, HPE iLO, Lenovo XCC and OpenBMC, which Redfish resources, schema versions and authentication modes exist, and how much of the legacy fallback is needed?

**Result.** From documentation and vendor-published captures, no family is served by one resource generation alone. Dell iDRAC9 7.20.10.05 and iDRAC10 1.10.17.00 each expose both generations in the vendor captures: `Sensors`, `EnvironmentMetrics` (power only), `ThermalSubsystem` with `ThermalMetrics.TemperatureSummaryCelsius.Intake`, `PowerSubsystem`, and the legacy `Power` and `Thermal`. HPE iLO 6 gained each resource at a different firmware (`ThermalSubsystem` and `Sensors` in 1.64, `EnvironmentMetrics.PowerWatts` in 1.68, `PowerSubsystem` in 1.79). Lenovo XCC2 documents both generations. OpenBMC `bmcweb` builds the new model only by default. The evidence supports ADR 0004 (new model first, legacy fallback) with one change: choose the source per reading, not per resource, because a single BMC offers some readings only in one generation. Fixtures from real BMCs, the vendor session limits, and a pilot-fleet inventory are *live* and not done. Dell's developer portal returned HTTP 403 to automated requests, so Dell's own Redfish API guide was not read; Dell facts come from Dell's published mockups and scripts.

## Method and sources

| Source | Pin | Used for |
|---|---|---|
| Dell iDRAC mockup clients (Redfish Mockup Creator captures) | [dell/iDRAC-Redfish-Scripting@3bc6e7b](https://github.com/dell/iDRAC-Redfish-Scripting/tree/3bc6e7b67ef69745208de0516411db4bf2eda43f/iDRAC%20Redfish%20Mockup%20Clients), `R660xs_iDRAC9_7_20_10_05` (captured 2025-04-03) and `R770_iDRAC10_1_10_17_00` (captured 2025-02-21) | Dell resource trees and value shapes |
| HPE iLO 6 Redfish reference | [chassis](https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo6/ilo6_173/ilo6_chassis_resourcedefns173), [other resources](https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo6/ilo6_173/ilo6_other_resourcedefns173), [changelog](https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo6/ilo6_changelog), iLO 6 v1.73 reference, fetched 2026-11-15 | HPE resources and firmware gates |
| Lenovo XCC2 REST API guide | [power, thermal and redundancy](https://pubs.lenovo.com/xcc2-restapi/power_thermal_redundancy), [authentication](https://pubs.lenovo.com/xcc2-restapi/authentication_methods), fetched 2026-11-15 | Lenovo resources, sessions, telemetry |
| OpenBMC bmcweb | [openbmc/bmcweb@c9ba80e](https://github.com/openbmc/bmcweb/tree/c9ba80ef3d4ea680662af14856f333534a5ac56f), the head of `master` on 2026-11-15 | OpenBMC behavior from source |
| DMTF mockups | [DMTF/Redfish-Publications@fa6d23d](https://github.com/DMTF/Redfish-Publications/tree/fa6d23d9709aef0aba4340ef6136d332a5c9f144/mockups/public-rackmount1) | A reference tree |

Mockups are third-party data read with a JSON parser in an isolated scratch directory; nothing in them was executed. A mockup shows the shape one firmware returned for one machine on the capture date, not what every machine of that family returns. Fixtures for the test suite still need real captures per issue #19; the Dell mockups could be a starting point for fixtures only after their license and redaction status are checked (not done).

## Matrix

Status: confirmed (read in a vendor capture or vendor documentation), unverified, or *live*.

| | Dell iDRAC9 7.20.10.05 (R660xs) | Dell iDRAC10 1.10.17.00 (R770) | HPE iLO 6 (docs for 1.73, changelog to 1.79) | Lenovo XCC2 | OpenBMC bmcweb `c9ba80e` |
|---|---|---|---|---|---|
| Redfish version in service root | `1.20.1` (confirmed, mockup) | `1.20.1` (confirmed) | DSP0266 1.20.1, schema bundle 2024.1 (confirmed, changelog) | unverified | follows its schema bundle (unverified) |
| `Chassis` type | `#Chassis.v1_25_1`, three chassis (system plus two RAID enclosures) (confirmed) | `v1_25_1`, two chassis (confirmed) | `v1_25_1` (confirmed, docs) | unverified | unverified |
| `Sensors` collection | yes, 41 sensors (confirmed) | yes, 38 (confirmed) | yes, from 1.64 (confirmed, changelog) | yes (docs) | yes (all sensors are shown in the collection when `redfish-new-powersubsystem-thermalsubsystem`, default `enabled`, is on; [meson.options](https://github.com/openbmc/bmcweb/blob/c9ba80ef3d4ea680662af14856f333534a5ac56f/meson.options#L319)) |
| `EnvironmentMetrics` | `v1_3_2`; `PowerWatts` only, plus Dell OEM `AverageFanPWM`; no `TemperatureCelsius` (confirmed) | same shape (confirmed) | `v1_3_2`; `PowerWatts.Reading` since 1.68, `EnergykWh` since 1.73; no `TemperatureCelsius` documented (confirmed, docs) | `FanSpeedsPercent`, `PowerWatts` from "Sys Power", `TemperatureCelsius` from "Ambient Temp" (confirmed, docs) | `FanSpeedsPercent`, `PowerLimitWatts`, `PowerWatts` handlers; no `TemperatureCelsius` in `environment_metrics.hpp` (confirmed, source) |
| `ThermalSubsystem`, `ThermalMetrics`, `Fans` | yes: `ThermalSubsystem.v1_3_2`, `ThermalMetrics` with `Intake`, `Exhaust`, `Internal`; 14 `Fan` resources, one per rotor (confirmed) | yes: `ThermalSubsystem.v1_3_2`, `ThermalMetrics` with `Intake`, `Exhaust`, `Internal`; 6 `Fan` resources with `SecondarySpeedPercent` for the second rotor (confirmed) | `ThermalSubsystem.v1_3_2`, `ThermalMetrics.v1_3_1` from 1.64; `TemperatureSummaryCelsius` documents `Ambient` only (confirmed, docs) | `ThermalSubsystem`, `ThermalMetrics`, `Fan`; `Intake` "maps to `Inlet Water Temp` or `Ambient Temp`" (confirmed, docs) | `thermal_subsystem.hpp`, `fan.hpp`; `thermal_metrics.hpp` fills `TemperatureReadingsCelsius` only (confirmed, source) |
| `PowerSubsystem`, `PowerSupply` | yes: `PowerSubsystem.v1_1_2` with `PowerSupplies` slots 1 and 2 (confirmed) | yes: `PowerSubsystem.v1_1_2`, `PowerSupply.v1_6_0`, `PowerSupplyMetrics.v1_1_2`, 2 supplies (confirmed) | `PowerSubsystem` and `Batteries` new in 1.79; no `PowerSupply` pages in the 1.73 reference (confirmed, docs) | `PowerSubsystem`, `PowerSupply`, `PowerSupplyMetrics` (docs) | `power_subsystem.hpp`, `power_supply.hpp` (source) |
| Legacy `Power` and `Thermal` | yes, `v1_7_3`, 14 fans, 4 temperatures, 2 supplies (confirmed) | yes, `v1_7_3`, 12 fans (confirmed) | yes, `v1_7_1` (confirmed, docs) | yes, none marked deprecated (docs) | built only when `redfish-allow-deprecated-power-thermal` is enabled; default `disabled`; the option text says it "will be removed June 2026", yet it is still present at the 2026-11-13 head (confirmed, [meson.options](https://github.com/openbmc/bmcweb/blob/c9ba80ef3d4ea680662af14856f333534a5ac56f/meson.options#L341)) |
| Session login and `X-Auth-Token` | yes (`Links.Sessions` present) | yes | yes (docs) | yes; maximum 16 open sessions, default `SessionTimeout` 300 s, range 30 to 86400 (docs) | yes; default timeout 1800 s, range 30 to 86400; no session-count limit found in `sessions.hpp` or `redfish_sessions.hpp` (source) |
| Basic authentication | assumed (spec requires it); Dell's scripts use it | same | unverified | yes (docs) | yes, default on |
| `ProtocolFeaturesSupported` | `$select`, `$filter`, `only` true; `$expand` true with `MaxLevels` 1; `excerpt` false (confirmed) | identical (confirmed) | unverified | unverified | unverified |
| `TelemetryService` | link present (iDRAC9 only) (confirmed) | link absent from service root (confirmed) | `TelemetryService`, `MetricDefinition`, `MetricReport` documented | 14 report definitions, 7 reports (docs) | `telemetry_service.hpp`, `metric_report*.hpp` (source) |
| `ReadOnly` role | `Login`, `ConfigureSelf` (confirmed, mockup) | `ConfigureSelf`, `Login` (confirmed) | unverified | unverified | unverified |
| Default certificate | unverified (*live*) | unverified | unverified | unverified | unverified |

*Live* for every cell marked unverified, plus the real firmware inventory of the fleet. Pilot families beyond these four (Supermicro, Cisco, Huawei, Inspur) were not read.

## Differences that change the plan

1. **Per-reading source selection.** On Dell the readings come from different resources even in the new model: power from `EnvironmentMetrics.PowerWatts` (there is no `TemperatureCelsius`), inlet from `ThermalMetrics.TemperatureSummaryCelsius.Intake` (the sensor's `PhysicalContext` is `SystemBoard`, not `Intake`), fans from `ThermalSubsystem/Fans` with `SpeedRPM` and no percent. The plan's order ("prefer `Sensors`, `EnvironmentMetrics`, `ThermalSubsystem` and `PowerSubsystem`, then legacy") works per reading if the normalizer falls through reading by reading and records the source of each. Per-resource fallthrough would not.
2. **Firmware gates.** An HPE iLO 6 below 1.68 has no `EnvironmentMetrics.PowerWatts`; below 1.64 no `Sensors` or `ThermalSubsystem`. The compatibility section must list supported firmware per reading, not per family.
3. **Chassis selection.** iDRAC9 returned three `Chassis` members and iDRAC10 two; only `System.Embedded.1` has power and thermal data. Discovery must filter chassis by the presence of the links it needs, not read all members.
4. **Fan property names do not carry units.** See [sensors.md](sensors.md): Lenovo's documented `SpeedPercent.Reading` example is an RPM value, and Dell's `SpeedPercent` excerpt carries only `SpeedRPM`.
5. **Fan counts differ by generation.** iDRAC10 R770 reports 6 `Fan` resources (primary and secondary rotor) and 12 legacy `Thermal.Fans[]` rotors; iDRAC9 R660xs reports 14 `Fan` resources and 14 legacy rotors. The same hardware has a different fan count and identities in each generation, so one data query must not mix generations (see [data-query.md](data-query.md)).

## Decision #8: evidence and recommendation

The evidence supports keeping ADR 0004's direction. Recommendation for the maintainer (the decision is theirs): new model first and legacy fallback, chosen per reading. The legacy normalizer is needed for HPE iLO 6 below 1.79 (no `PowerSubsystem` or `PowerSupply` in the 1.73 reference) and for older generations not read here; it is not needed for the two Dell captures, which carry both. "Legacy only" would find nothing on OpenBMC by default; "new only" would exclude HPE iLO 6 power supplies below 1.79 and any pre-subsystem firmware (*live* to size). Not recorded as decided on the issue.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #19 | Capture service root, `Chassis` tree, `Sensors`, `Thermal`, `Power`, `EnvironmentMetrics`, `ThermalSubsystem`, `PowerSubsystem`, `TelemetryService` per family and firmware, redacted | Fixtures; every "unverified" cell |
| #19 | Record `ProtocolFeaturesSupported` and try `$select`, `$expand`, `excerpt` | Cheaper read paths |
| #19 | Create a read-only account per family | Role names and privileges |
| #19, #8 | Inventory the fleet's actual families and firmware | Whether the legacy normalizer is needed in practice |
| #19 | Capture older Dell generations (iDRAC8 and earlier) and HPE iLO 5 | Whether the legacy normalizer is needed for older fleets; not read here |
