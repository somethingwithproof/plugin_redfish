# Sensor semantics and inlet temperature

Research for [#24](https://github.com/somethingwithproof/plugin_redfish/issues/24), 2026-11-15. Question: which temperature is the air inlet on each family, how do units, scaling and null readings appear, and which fan unit does each report?

**Result.** The plan's inlet rule (a `Sensor` with `PhysicalContext` of `Intake`) finds nothing on either Dell capture, because Dell labels both inlet and exhaust temperatures `SystemBoard`. A better rule exists in the standard: `ThermalSubsystem/ThermalMetrics.TemperatureSummaryCelsius.Intake`, which both Dell captures (iDRAC9 7.20.10.05 and iDRAC10 1.10.17.00) and the DMTF mockup fill. The plan's fallback to `EnvironmentMetrics.TemperatureCelsius` should be removed, because the schema defines it as a temperature "for this resource" and DMTF's own mockup sources it from a CPU sensor. Fan units cannot be inferred from property names: Lenovo's documented example puts an RPM value in `SpeedPercent.Reading`, and Dell's `SpeedPercent` excerpt holds only `SpeedRPM`. Whether any Dell, HPE or Lenovo "ambient" or "inlet" reading equals the physically measured intake needs hardware (*live*); nothing was compared with a physical measurement.

## Proposed rules (for review, not yet implemented)

`inlet_celsius`, in order, first present and non-null wins, with the rule name stored as the source:

1. `ThermalMetrics.TemperatureSummaryCelsius.Intake.Reading` (rule `summary.intake`).
2. A `Sensor` with `ReadingType` `Temperature` and `PhysicalContext` `Intake` (rule `sensor.intake`); a legacy `Thermal.Temperatures[]` member with `PhysicalContext` `Intake` (rule `legacy.intake`).
3. A vendor-name rule, per family, enabled only where a fixture shows the name: Dell sensor `Id` `SystemBoardInletTemp` (`PhysicalContext` `SystemBoard`) in both captures; Lenovo `ThermalMetrics` `Intake` documented as the `Ambient Temp` or `Inlet Water Temp` sensor (rule `vendor.dell_inlet`, `vendor.lenovo_ambient`).
4. Otherwise `U`. `EnvironmentMetrics.TemperatureCelsius` is never used for inlet.

`ambient_celsius` is filled from `ThermalMetrics.TemperatureSummaryCelsius.Ambient` or `EnvironmentMetrics.AmbientTemperatureCelsius` (defined as measured outside the chassis) when a BMC provides one, and is never copied to `inlet_celsius`. A Lenovo `Ambient Temp` reading reached through rule 3 is `inlet_celsius` only if the pilot confirms it measures intake; until then it is stored under both labels with the source name (the plan's own "ambient, not confirmed inlet" rule).

## Facts

| Fact | Status | Source |
|---|---|---|
| Dell iDRAC9 7.20.10.05: sensor `SystemBoardInletTemp`, name "System Board Inlet Temp", `ReadingType` `Temperature`, `ReadingUnits` `Cel`, `PhysicalContext` `SystemBoard`; exhaust `SystemBoardExhaustTemp` also `SystemBoard`; no sensor with `Intake` | confirmed (capture) | [Sensors/SystemBoardInletTemp](https://github.com/dell/iDRAC-Redfish-Scripting/tree/3bc6e7b67ef69745208de0516411db4bf2eda43f/iDRAC%20Redfish%20Mockup%20Clients) in `R660xs_iDRAC9_7_20_10_05_mockup_client.zip` |
| Dell iDRAC9 legacy `Thermal.Temperatures[]` "System Board Inlet Temp" has `PhysicalContext` `SystemBoard`; `PhysicalContext` values present are `CPU` and `SystemBoard` only | confirmed (capture) | same archive |
| Dell iDRAC9 and iDRAC10: `ThermalSubsystem/ThermalMetrics.TemperatureSummaryCelsius` with `Intake` (DataSourceUri `.../Sensors/SystemBoardInletTemp`; reading 19 on iDRAC9, 25 on iDRAC10), `Exhaust` and `Internal`; no `Ambient` | confirmed (capture) | both archives |
| Dell iDRAC `EnvironmentMetrics` has `PowerWatts` (source sensor `SystemBoardPwrConsumption`) and no `TemperatureCelsius` | confirmed (capture) | both archives |
| Lenovo XCC2 `EnvironmentMetrics.TemperatureCelsius` equals the "Ambient Temp" sensor; `ThermalMetrics.TemperatureSummaryCelsius.Intake` maps to "Inlet Water Temp" or "Ambient Temp"; the documented `TemperatureReadingsCelsius` example marks sensor `49L0` with `PhysicalContext` `Intake` | confirmed (docs) | [EnvironmentMetrics](https://pubs.lenovo.com/xcc2-restapi/environmentmetrics_properties_get), [ThermalMetrics](https://pubs.lenovo.com/xcc2-restapi/thermal_metrics_properties_get) |
| Lenovo sensor `Id` format is `<SensorNumber>L<OwnerLUN>` (for example `244L0`); `Name` comes from the SDR | confirmed (docs) | [Sensor properties](https://pubs.lenovo.com/xcc2-restapi/resource_sensor_sensor_properties_get) |
| HPE iLO 6 `ThermalMetrics.v1_3_1` documents `TemperatureReadingsCelsius[].PhysicalContext` with the full enum including `Intake`, and `TemperatureSummaryCelsius.Ambient`; no `Intake` or `Exhaust` summary entry is documented | confirmed (docs) | [iLO 6 v1.73 other resources](https://servermanagementportal.ext.hpe.com/docs/redfishservices/ilos/ilo6/ilo6_173/ilo6_other_resourcedefns173) |
| HPE `EnvironmentMetrics` documents `PowerWatts`, `EnergyJoules`, `EnergykWh`; no `TemperatureCelsius` | confirmed (docs) | same page |
| OpenBMC `bmcweb` `sensor_utils.hpp`, `sensors.hpp`, `fan.hpp`, `thermal_metrics.hpp` and `environment_metrics.hpp` contain no `PhysicalContext`, `Intake` or `TemperatureSummaryCelsius` handling; GitHub code search for `PhysicalContext` returns only generated enum and schema files | confirmed for those files (search index may be incomplete) | [bmcweb@c9ba80e](https://github.com/openbmc/bmcweb/tree/c9ba80ef3d4ea680662af14856f333534a5ac56f) |
| So on OpenBMC (as built from this source) the plugin cannot identify inlet from a standard property; only sensor names configured by the platform integrator are available | inference from the above | |
| DMTF mockup `public-rackmount1` fills `ThermalMetrics` `Intake` (sensor `IntakeTemp`, context `Intake`), `Ambient` (`Room`), `Exhaust`, `Internal`, and sets `EnvironmentMetrics.TemperatureCelsius` from `CPU1Temp` | confirmed (mockup) | [DMTF mockup](https://github.com/DMTF/Redfish-Publications/tree/fa6d23d9709aef0aba4340ef6136d332a5c9f144/mockups/public-rackmount1/Chassis/1U) |
| Dell iDRAC9 legacy and sensor readings are numbers with `ReadingUnits` `Cel`; a sensor with no value is `null`; iDRAC9 `ReadingRangeMin` and `Max` are `null` while iDRAC10's are set | confirmed (capture) | both archives |
| `Sensor.Reading` is `number` or `null`; schema says it "shall not be returned if `Enabled` is false"; `ReadingTime` is the acquisition time | confirmed | `Sensor.v1_14_0.json` |
| Sensor refresh interval and whether `ReadingTime` is populated per family | unverified | *live* |

## Fan units

| Source | What the fan carries | Status |
|---|---|---|
| DMTF `Fan.v1_6_0` | `SpeedPercent` is an excerpt with `Reading` (percent), `SpeedRPM`, `DataSourceUri`; `Reading` should be `SpeedRPM / RatedSpeedRPM` | confirmed |
| Dell iDRAC9 and iDRAC10 `ThermalSubsystem/Fans/*` (`Fan.v1_5_2`) | `SpeedPercent` holds `{DataSourceUri, SpeedRPM}` with no `Reading` (iDRAC9 `{SpeedRPM: 8040}`); iDRAC10 also `SecondarySpeedPercent` for the second rotor | confirmed (capture) |
| Dell legacy `Thermal.Fans[]` and `Sensors/Fan*` | `Reading` 8040 `ReadingUnits` `RPM`; sensor `ReadingType` `Rotational` | confirmed (capture) |
| Lenovo XCC2 `Fan` | documented example: `SpeedPercent.Reading` 8611 and `SpeedRPM` 8611, `Id` `Fan_1A_Tach`. The property named `SpeedPercent` holds an RPM value; the docs say `SpeedRPM` is "same as Reading" | confirmed (docs; the example may be illustrative) |
| OpenBMC | `fan.hpp` fills `SpeedPercent` only from a sensor of `ReadingType` `Percent`; fan tach sensors are `Rotational` unless `redfish-allow-rotational-fans` is disabled; its default is still `enabled` at the 2026-11-13 head, though the option text says it defaults to disabled in June 2026 and the code is removed by June 2027 | confirmed (source) |
| HPE iLO 6 | `Fan.v1_5_1` and legacy `Thermal.Fans[]`; units not read | unverified |

**Rule.** Take the unit from the `Sensor` at `DataSourceUri` (`ReadingType` and `ReadingUnits`), or from legacy `ReadingUnits`. Never from the property name. A fan excerpt with only `SpeedRPM` is RPM. A fan whose unit cannot be established is stored as `U` for `fan_speed` with its health still recorded. Store the unit with the value, as the plan says. Dual-rotor fans store two indexes (primary and secondary) under one fan identity.

## Fixtures needed (exit criterion)

One fixture per rule: `summary.intake` (Dell iDRAC10 or DMTF), `sensor.intake` (DMTF), `vendor.dell_inlet` (Dell), `vendor.lenovo_ambient` (Lenovo, live), no inlet available (OpenBMC, live), dual-rotor fan (Dell iDRAC10), RPM in `SpeedPercent.Reading` (Lenovo, live), `Percent` fan (OpenBMC rotational disabled, live).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #24 | List every temperature sensor with context, name and reading per family | Rule coverage |
| #24 | Compare the `Intake` summary with an independent inlet measurement | Whether `Ambient Temp` equals inlet on Lenovo, and iDRAC inlet accuracy |
| #24 | Capture fan resources on Lenovo, HPE, OpenBMC with and without the rotational option | Fan unit table |
| #24 | Read `ReadingTime` on each family | Sensor refresh interval for #23 and the `age` definition |
