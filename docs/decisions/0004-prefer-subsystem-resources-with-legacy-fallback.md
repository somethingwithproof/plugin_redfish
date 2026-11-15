# 0004. Prefer PowerSubsystem, ThermalSubsystem, EnvironmentMetrics, and Sensors, and fall back to Power and Thermal

Status: Accepted, pending vendor evidence (2026-11-14)

## Context

DMTF deprecated the `Power` and `Thermal` schemas from version 1.7.0 in favor of `PowerSubsystem` and `ThermalSubsystem`, and deprecated the matching `Chassis` links from `Chassis` v1_15_0, which also added links to `PowerSubsystem`, `ThermalSubsystem`, and `EnvironmentMetrics`. OpenBMC's `bmcweb` builds the legacy resources only when an option, disabled by default, is enabled. Lenovo XCC2 documents both generations. HPE iLO 6 documents links to `Power`, `Thermal`, `ThermalSubsystem`, and `EnvironmentMetrics`. Dell coverage has not been verified.

Correction (2026-11-15, milestone 1 research): the statement that Dell coverage was not verified is out of date. Dell's published iDRAC9 7.20.10.05 and iDRAC10 1.10.17.00 mockups carry both generations, including `ThermalSubsystem` and `PowerSubsystem`; HPE iLO 6 gained `ThermalSubsystem` and `Sensors` in firmware 1.64, `EnvironmentMetrics.PowerWatts` in 1.68 and `PowerSubsystem` in 1.79. See [vendor matrix](../research/vendor-matrix.md).

## Decision

Discovery walks each `Chassis` and prefers, in order, `Sensors`, `EnvironmentMetrics`, `ThermalSubsystem` and `PowerSubsystem` members, then the legacy `Power` and `Thermal` resources. The choice is made per reading, not per resource, because a single BMC can offer one reading only in one generation (research issue 19, pending the maintainer's decision on issue 8). The normalized model hides which generation answered and records the source. A property is read when present; the plugin does not require a minimum schema version beyond the properties it uses.

## Consequences

- OpenBMC and newer firmware work without legacy resources.
- Older firmware still works through the legacy path.
- Two normalizers need fixtures from every vendor family, and the vendor matrix (issue 19) decides how much of the fallback is exercised in practice.
- Inlet temperature is taken only from a reading marked as intake; vendor ambient readings are stored separately until shown equivalent.
- Research (2026-11-15) refines this: the standard marker is `ThermalMetrics.TemperatureSummaryCelsius.Intake`, then a `Sensor` with `PhysicalContext` `Intake`; Dell labels its inlet sensor `SystemBoard`, so a named vendor rule is needed there; `EnvironmentMetrics.TemperatureCelsius` is not an inlet or ambient source. See [sensors](../research/sensors.md).

## Alternatives rejected

- **Legacy only.** Finds nothing on OpenBMC by default and follows a deprecated schema.
- **New model only.** Excludes hardware that still ships legacy resources.
- **Per-vendor code paths.** Multiplies maintenance; vendor OEM properties are used only where the matrix shows no standard source.
