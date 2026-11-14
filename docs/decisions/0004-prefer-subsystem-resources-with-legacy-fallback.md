# 0004. Prefer PowerSubsystem, ThermalSubsystem, EnvironmentMetrics, and Sensors, and fall back to Power and Thermal

Status: Accepted, pending vendor evidence (2026-11-14)

## Context

DMTF deprecated the `Power` and `Thermal` schemas from version 1.7.0 in favor of `PowerSubsystem` and `ThermalSubsystem`, and deprecated the matching `Chassis` links from `Chassis` v1_15_0, which also added links to `PowerSubsystem`, `ThermalSubsystem`, and `EnvironmentMetrics`. OpenBMC's `bmcweb` builds the legacy resources only when an option, disabled by default, is enabled. Lenovo XCC2 documents both generations. HPE iLO 6 documents links to `Power`, `Thermal`, `ThermalSubsystem`, and `EnvironmentMetrics`. Dell coverage has not been verified.

## Decision

Discovery walks each `Chassis` and prefers, in order, `Sensors`, `EnvironmentMetrics`, `ThermalSubsystem` and `PowerSubsystem` members, then the legacy `Power` and `Thermal` resources. The normalized model hides which generation answered and records the source. A property is read when present; the plugin does not require a minimum schema version beyond the properties it uses.

## Consequences

- OpenBMC and newer firmware work without legacy resources.
- Older firmware still works through the legacy path.
- Two normalizers need fixtures from every vendor family, and the vendor matrix (issue 19) decides how much of the fallback is exercised in practice.
- Inlet temperature is taken only from a reading marked as intake; vendor ambient readings are stored separately until shown equivalent.

## Alternatives rejected

- **Legacy only.** Finds nothing on OpenBMC by default and follows a deprecated schema.
- **New model only.** Excludes hardware that still ships legacy resources.
- **Per-vendor code paths.** Multiplies maintenance; vendor OEM properties are used only where the matrix shows no standard source.
