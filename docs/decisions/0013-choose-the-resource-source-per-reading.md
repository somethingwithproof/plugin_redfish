# 0013. Choose the subsystem or legacy source per reading, and take inlet only from a confirmed intake summary

Status: Accepted (2026-11-15). Resolves decision issue 8; refines ADR 0004.

## Context

ADR 0004 prefers the subsystem resources and keeps a legacy fallback. Milestone 1 research ([vendor matrix](../research/vendor-matrix.md), [sensors](../research/sensors.md)) showed firmware that offers one reading only in one generation: HPE iLO 6 gained `ThermalSubsystem` and `PowerSubsystem` in different releases, and Dell offers both. It also showed that `EnvironmentMetrics.TemperatureCelsius` is not an inlet or ambient source, and that fan units differ by vendor.

## Decision

- Discovery decides a source for each reading kind on each chassis and records the plan: power from `EnvironmentMetrics.PowerWatts`, else legacy `PowerControl`; temperatures from `ThermalMetrics.TemperatureReadingsCelsius`, else legacy `Thermal.Temperatures`; fans from `ThermalSubsystem.Fans`, else legacy; supplies from `PowerSubsystem.PowerSupplies` and their `Metrics`, else legacy. The legacy resource is read only for kinds the subsystem does not cover.
- Each stored reading carries its source (`redfish.environment`, `redfish.sensor`, `redfish.legacy`, `redfish.node`) and the rule that produced it.
- Inlet is taken only from `ThermalMetrics.TemperatureSummaryCelsius.Intake`, and only for families where research confirmed it is the inlet sensor (Dell). Elsewhere the value is stored under its source sensor name and inlet stays unknown. `EnvironmentMetrics.TemperatureCelsius` is never used.
- Fan units come from the referenced sensor's `ReadingUnits` or `ReadingType`, cached at discovery. Without a sensor, `SpeedPercent.Reading` is percent unless `SpeedRPM` equals it (the Lenovo shape, RPM) or the family is on the deviation list (Lenovo), which yields unknown; `SpeedRPM` alone is RPM; anything else is unknown.
- Members are keyed by chassis Id, kind, and member Id (legacy members by `DataSourceUri`, a non-numeric `MemberId`, then `Name`), never by list position. Discovery repeats on its interval, and at once after a 404, an Id or type mismatch, a parse failure, or three failures in a row.

## Consequences

- Inlet rules 2 and 3 (a `Sensor` with `PhysicalContext` `Intake`, and named vendor sensors) wait for issue 24 and more fixtures.
- The `Sensors` collection is not walked in milestone 2; readings come from the excerpts in the subsystem resources.

## Alternatives rejected

- **Choose per resource or per BMC.** Loses readings that exist only in the other generation.
- **Fall back to any temperature for inlet.** Produces a plausible but wrong inlet value, which is worse than unknown for alerting.
