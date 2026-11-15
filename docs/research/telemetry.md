# Telemetry as a cheaper source than resource polling

Research for [#26](https://github.com/somethingwithproof/plugin_redfish/issues/26), 2026-11-15. Question: do the vendors in scope expose `TelemetryService` metric reports with node power, temperatures and fan speeds, and would reading one report cost fewer requests and less BMC load than the resource walk?

**Result.** Recommendation from documentation and captures: **keep resource polling as the only source in phase 1; do not adopt telemetry now.** The reasons are documented, not measured: the standard gives telemetry no privilege below `ConfigureManager` for creating or enabling definitions; Dell's iDRAC9 capture shows the sensor-style report definitions (`Sensor`, `ThermalSensor`, `FanSensor`, `PowerMetrics`, `PSUMetrics`) present but disabled and only 3 of 30 definitions with a report, so using them means an administrator enabling them on every BMC; Lenovo XCC2 ships fixed definitions for inlet air temperature (`InletAirTemp`), `PowerMetrics`, `PowerSupplyStats` and CPU temperature, with no fan report; Dell iDRAC10's capture has no `TelemetryService` link in the service root. Whether a report is cheaper per node needs a BMC (*live*). Telemetry can be revisited as an optional per-family source after phase 1, for BMCs where an administrator already enabled the reports.

## Facts

| Fact | Status | Source |
|---|---|---|
| Reading `TelemetryService`, `MetricReport`, `MetricReportDefinition` needs `Login`; PATCH, POST and DELETE on them need `ConfigureManager` | confirmed | `Redfish_1.10.0_PrivilegeRegistry.json` |
| So a `ReadOnly` account can read an existing report but cannot create, enable or change a definition | inference from the registry; vendors may differ (*live*) | |
| Dell iDRAC9 7.20.10.05 capture: `TelemetryService.v1_3_1` with 30 `MetricReportDefinitions` and 3 `MetricReports` (`OME-PMP-Power-A`, `OME-PMP-Thermal`, `CPUMemMetrics`). `Sensor` (9 metrics), `ThermalSensor`, `FanSensor`, `PowerMetrics` (12), `PSUMetrics` and `SystemUsage` are `MetricReportDefinitionEnabled` false, state `Disabled`, periodic at `PT0H1M0S`; `OME-PMP-Power-A` is enabled (53 metrics, every 5 minutes) | confirmed (capture); whether these were disabled by default or by the owner is unknown | `R660xs_iDRAC9_7_20_10_05_mockup_client` |
| Dell iDRAC10 R770 capture: no `TelemetryService` link in the service root | confirmed (capture) | `R770_iDRAC10_1_10_17_00_mockup_client` |
| Lenovo XCC2: 14 `MetricReportDefinitions` (7 plus `...Event` variants) and 7 `MetricReports`: `CPUTemp`, `InletAirTemp`, `PowerMetrics`, `PowerSupplyStats`, `CPUUtilizationStats`, `MemoryUtilizationStats`, `CPUPowerMetrics`; the documented `PowerMetrics` example reports min, average and max consumed watts over a 30 s interval (`PT30S`) with a 24 hour timespan; no fan report | confirmed (docs); report contents beyond the `PowerMetrics` example not read | [report definitions](https://pubs.lenovo.com/xcc2-restapi/telmgt_resource_metric_report_def_get_collection), [reports](https://pubs.lenovo.com/xcc2-restapi/telmgt_resource_metric_report_get_collection), [definition example](https://pubs.lenovo.com/xcc2-restapi/telmgt_resource_metric_report_def_get_metricreport) |
| HPE iLO 6 reference documents `TelemetryService`, `MetricDefinition` and `MetricReport` resources; their contents for node power or fans were not read | unverified | [iLO 6 v1.73 other resources](https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo6/ilo6_173/ilo6_other_resourcedefns173) |
| OpenBMC `bmcweb` has `telemetry_service.hpp`, `metric_report.hpp`, `metric_report_definition.hpp`; report definitions are created by clients through a POST (privilege `ConfigureManager`) | confirmed (files exist); creation path inferred | [bmcweb@c9ba80e](https://github.com/openbmc/bmcweb/tree/c9ba80ef3d4ea680662af14856f333534a5ac56f/redfish-core/lib) |
| A metric report holds `MetricValues` of `MetricProperty` (a URI), `MetricValue` (string) and `Timestamp`; values arrive as strings in the Lenovo example | confirmed (docs) | Lenovo metric report page |

## Comparison with polling

| | Resource polling | Telemetry reports |
|---|---|---|
| Needs BMC-side configuration | no | yes on Dell iDRAC9 (definitions disabled in capture); fixed definitions on Lenovo |
| Privilege | `Login` (ReadOnly) | read `Login`; enabling needs `ConfigureManager`, which the plugin must never require |
| Fan data | yes | not in Lenovo's list; Dell `FanSensor` is disabled |
| Request count | 8 to 31 per node (see [polling-cost.md](polling-cost.md)) | one read per report; at least 2 to 3 reports for power, inlet and supplies on Lenovo (`PowerMetrics`, `InletAirTemp`, `PowerSupplyStats`) |
| Timestamps | `ReadingTime` where populated | `Timestamp` per value; report period set by the definition |
| Cost per node on the BMC | unmeasured | unmeasured |

## Exit criteria status

Recommendation given (above). The plan is updated to list telemetry as "not adopted in phase 1" with this evidence; no new requirement is created. A future research issue is only warranted if a pilot site has telemetry already enabled.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #26 | List `MetricReportDefinitions` and `MetricReports` with a read-only account on each family; record which are enabled by default | Whether any family offers usable reports without configuration |
| #26 | Read the Lenovo `InletAirTemp` and `PowerSupplyStats` reports | Contents and period |
| #26 | Time one report read against the resource walk | Whether telemetry is cheaper on the BMC |
| #26 | Check HPE iLO 6 and OpenBMC for default definitions | HPE and OpenBMC rows |
