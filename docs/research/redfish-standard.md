# Redfish standard and schema facts

Research for [#20](https://github.com/somethingwithproof/plugin_redfish/issues/20), 2026-11-15. Question: does each row of the plan's Redfish standard, schema and vendor tables hold, and what does the plan miss?

**Result.** Every row in the plan's "Redfish standard" and "Redfish schemas" tables is confirmed against DSP0266 1.23.0 and the DMTF JSON schemas. Four additions change the design: `ThermalSubsystem/ThermalMetrics` carries a standard `Intake` summary temperature the plan does not mention; `EnvironmentMetrics.TemperatureCelsius` is a generic chassis temperature, not ambient or inlet; the standard `ReadOnly` role can read every resource the plugin needs and can delete its own session; and the specification asks services to keep array indexes and URIs stable across resets. Whether each row holds on real BMCs is *live* and listed at the end. Nothing was run against a BMC.

## Method

DSP0266 1.23.0 was downloaded and converted with `pdftotext`; the DMTF JSON schemas were fetched from `redfish.dmtf.org/schemas/v1/` on 2026-11-15 and read with a JSON parser; the DMTF privilege registry `Redfish_1.10.0_PrivilegeRegistry.json` was read for operation-to-privilege maps. Vendor mockups and documentation are covered in [vendor-matrix.md](vendor-matrix.md).

## Specification rows

Specification: [DSP0266 1.23.0](https://www.dmtf.org/sites/default/files/standards/documents/DSP0266_1.23.0.pdf) (228 pages, status Published).

| Fact | Status | Evidence |
|---|---|---|
| Services shall support HTTP Basic and Redfish session login; Basic requires HTTPS; with multi-factor authentication on, Basic is rejected for accounts not set to bypass it | confirmed | 13.3.1 and 13.3.3 |
| Basic "can be restricted or disabled with the `HTTPBasicAuth` property in `AccountService`" | **added** | 13.3.3 note; `AccountService.HTTPBasicAuth` added in v1_15_0, defaults to `Enabled`, absent means enabled. A BMC may disable Basic, so session login must work |
| Session POST goes to the URI in `Links.Sessions` or `SessionService.Sessions` (both shall be equal); response has `X-Auth-Token`, `Location`, and a body; logout is DELETE on the session | confirmed | 13.3.4.1, 13.3.4.2, 13.3.4.4 |
| Session management, orphaned session timeout and the number of simultaneous sessions are implementation defined | confirmed | 13.3.4 |
| Services shall not use cookies to authenticate | **added** | 13.3.2.2 |
| `SessionService.SessionTimeout` is the idle threshold in seconds; schema minimum 30, maximum 86400; `AbsoluteSessionTimeout` exists from v1_2_0 | **added** | `SessionService.v1_2_0.json` |
| No request rate limit is defined; no 429 and no "Too Many Requests" in the text; `Retry-After` appears only with task monitors and a header table | confirmed | searched `spec.txt` for `429`, `Too Many Requests`, `Retry-After` on 2026-11-15 |
| TLS 1.2 with RFC 7525 recommendations or later; cipher suites only those "Recommended" in the IANA registry; the default certificate shall be replaceable; X.509 v3 | confirmed, **refined** | 13.1.1 to 13.1.3. The spec forbids non-recommended suites, so an older BMC may expose suites a modern client rejects: see [transport.md](transport.md) |
| Query parameters: `$expand`, `$filter`, `$select` optional; `ProtocolFeaturesSupported` required when any is supported; unsupported `$` parameters get 501 | confirmed | 7.3.1 |
| `$select` may not include array indexes; `$expand` options `*`, `.`, `~` and `$levels` | **added** | 7.3.2, 7.3.3. `ServiceRoot.v1_22_0` `ProtocolFeaturesSupported` also defines `ExcerptQuery`, `OnlyMemberQuery`, `TopSkipQuery` and `MultipleHTTPRequests` |
| Unauthenticated access only to the service root, metadata, OData service document, OpenAPI YAML and `/redfish` | confirmed | 13.3.2.1 |
| Resource tree stability: URIs and array element indexes "should remain constant" across resets and minor configuration changes; a client shall not expect consistency between instances | **added** | 12.3 |
| Absent resources: a removable component's resource may remain with `Status.State` of `Absent`; "properties whose support is based on the configuration ... should not be returned" while absent | **added** | 9.11.2 |
| Standard roles: `ReadOnly` has `Login` and `ConfigureSelf`; `Operator` adds `ConfigureComponents`; `Administrator` adds `ConfigureManager` and `ConfigureUsers` | **added** | 13.4.2.1, Table 41 |

## Privilege map

From `Redfish_1.10.0_PrivilegeRegistry.json` ([registries index](https://redfish.dmtf.org/registries/)):

| Entity | GET | Create session (POST) | DELETE |
|---|---|---|---|
| `Chassis`, `Sensor`, `SensorCollection`, `Power`, `Thermal`, `PowerSubsystem`, `ThermalSubsystem`, `ThermalMetrics`, `EnvironmentMetrics`, `Fan`, `PowerSupply`, `TelemetryService`, `MetricReport`, `MetricReportDefinition`, `SessionService` | `Login` | n/a | n/a |
| `SessionCollection` | `Login` | `Login` | n/a |
| `Session` | `ConfigureManager` or `ConfigureSelf` | n/a | `ConfigureManager` or `ConfigureSelf` |

**Result for the plan.** A user in the standard `ReadOnly` role can read every resource the plugin needs and can delete its own session. The plan's "documentation requires a read-only Redfish role" is achievable on a compliant BMC. Caveats: `ConfigureSelf` also lets that account change its own password, so "read-only" is not "cannot change anything"; vendors may add OEM privileges or their own role names (*live*).

## Schema rows

Latest published versions on 2026-11-15, from the schema index: `Chassis.v1_29_0`, `PowerSubsystem.v1_2_0`, `ThermalSubsystem.v1_5_0`, `EnvironmentMetrics.v1_7_0`, `Sensor.v1_14_0`, `PowerSupply.v1_8_0`, `Fan.v1_6_0`, `Power.v1_7_3`, `Thermal.v1_7_3`, `ThermalMetrics.v1_3_3`, `PowerSupplyMetrics.v1_3_0`, `TelemetryService.v1_4_1`, `MetricReport.v1_5_2`, `PhysicalContext.v1_3_0`, `ServiceRoot.v1_22_0`. These match the plan.

| Plan fact | Status | Evidence |
|---|---|---|
| `Power` and `Thermal` deprecated from 1.7.0 | confirmed | `versionDeprecated: v1_7_0` in both `Power.v1_7_3.json` and `Thermal.v1_7_3.json` |
| `Chassis` `Power` and `Thermal` links deprecated from `v1_15_0`; `PowerSubsystem`, `ThermalSubsystem`, `EnvironmentMetrics` links added in `v1_15_0`; `Sensors` added in `v1_9_0` | confirmed | `Chassis.v1_29_0.json` |
| `EnvironmentMetrics` has `PowerWatts`, `TemperatureCelsius`, `EnergykWh`, `FanSpeedsPercent` from the first version; `PowerLimitWatts` from `v1_1_0` | confirmed | no `versionAdded` on the first four; `PowerLimitWatts` `v1_1_0`. Also `AmbientTemperatureCelsius` from `v1_4_0`, `EnergyJoules` and `AbsoluteHumidity` from `v1_2_0`, `CurrentAmps` and `Voltage` from `v1_5_0` |
| `EnvironmentMetrics.TemperatureCelsius` "does not say which temperature" | confirmed, **sharpened** | schema text: "the temperature ... for this resource", a `Sensor` of `ReadingType` `Temperature`. The separate `AmbientTemperatureCelsius` is "measured at a point exterior to the `Chassis`". DMTF's own mockup `public-rackmount1` sources `TemperatureCelsius` from `CPU1Temp` ([EnvironmentMetrics](https://github.com/DMTF/Redfish-Publications/blob/fa6d23d9709aef0aba4340ef6136d332a5c9f144/mockups/public-rackmount1/Chassis/1U/EnvironmentMetrics/index.json)). The plan's fallback "`EnvironmentMetrics.TemperatureCelsius` marked ambient, not confirmed inlet" is wrong: it may be a CPU temperature |
| `Sensor.ReadingType` includes `Temperature`, `Power`, `Rotational`, `Percent`; `Intake` described as "the air intake point or points or region of the chassis" | confirmed with a wrinkle | `Sensor.v1_14_0` enum has 34 values. The quoted text is in the unversioned `PhysicalContext.json`; `PhysicalContext.v1_3_0.json` reads "The intake point of the chassis." Same meaning |
| `Fan` has `SpeedPercent` and `Status`; legacy fans `ReadingUnits` is `RPM` or `Percent` | confirmed, **sharpened** | `Fan.v1_6_0` `SpeedPercent` is a `SensorFanExcerpt` with `Reading`, `SpeedRPM`, `DataSourceUri`; "services should calculate the value of `Reading` by dividing `SpeedRPM` by `RatedSpeedRPM`". `SecondarySpeedPercent` added in `v1_5_0` for dual-rotor fans. Legacy `Thermal.Fan.ReadingUnits` enum is `RPM`, `Percent` |
| Legacy `Power.PowerControl[].PowerConsumedWatts`, `Thermal.Temperatures[].ReadingCelsius`, `Power.PowerSupplies[]` with `PowerInputWatts` and `LastPowerOutputWatts` | confirmed, **refined** | `PowerSupply.PowerInputWatts` and `PowerOutputWatts` were added in `Power` v1_5_0; `LastPowerOutputWatts` is the older average. Read `PowerOutputWatts` first, fall back to `LastPowerOutputWatts` |
| `Status.Health` is `OK`, `Warning`, `Critical` or null; `State` includes `Enabled`, `Absent`, `Degraded`, `StandbyOffline` | confirmed | `Resource.json`: `Health` enum 3 values; `State` enum 13 values (`Enabled`, `Disabled`, `StandbyOffline`, `StandbySpare`, `InTest`, `Starting`, `Absent`, `UnavailableOffline`, `Deferring`, `Quiesced`, `Updating`, `Qualified`, `Degraded`) |
| (not in plan) Standard intake temperature | **added** | `ThermalMetrics.TemperatureSummaryCelsius` has `Ambient`, `Exhaust`, `Intake`, `Internal`, each `{Reading, DataSourceUri}` ([ThermalMetrics.v1_3_3](https://redfish.dmtf.org/schemas/v1/ThermalMetrics.v1_3_3.json)); the DMTF mockup fills all four |
| (not in plan) `ReadingTime` | **added** | `Sensor.ReadingTime` since `v1_1_0`: when the value was acquired. This is the only standard staleness stamp for `age` and #23 |

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #20 | Replay session create, `X-Auth-Token` use and delete on each family with TLS verified | Session rows above hold per vendor |
| #20 | Read `HTTPBasicAuth`, `SessionTimeout` and `AccountLockout*` from `AccountService` and `SessionService` | Which auth modes work, defaults for #22 |
| #20 | Create a `ReadOnly` user and run the full read walk, then DELETE its session | Privilege map holds on each family |
| #20 | Record `@odata.type` versions and `null` versus omitted for absent members | Normalizer null rules |
| #20 | Check `ProtocolFeaturesSupported` and try `$select`, `$expand` | Whether the cheaper reads in #23 work |
