# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want alerts on node power, inlet temperature, fan failure, and power supply failure through the same path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources the Cacti poller polls. It cannot read the plugin's cache directly.

## Decision

Each node gets a "Redfish Node Summary" data source using a script server input that returns `power_watts`, `inlet_celsius`, `max_temp_celsius`, `fans_bad`, `psus_bad`, `health_code`, and `age` from the cache. Per-index data sources for each fan, temperature sensor, and power supply can be thresholded as well. Values return `U` when data is missing or stale; `age` is always numeric so a BMC that stopped answering can itself alert. Thold thresholds and Maint schedules apply with no change to either plugin.

## Consequences

- One notification path for SNMP and BMC data; no Thold or Maint change.
- Cacti's poll interval and the collector's interval are independent, so a reading can be reported twice or skipped. Thold durations should be at least one collector interval.
- Alerts on the summary do not identify which fan failed; per-index data sources do.
- Readings are stored twice: in the plugin cache and in Cacti's RRD files.

## Alternatives rejected

- **Plugin-owned incident engine.** Duplicates Thold and Maint and adds a third notification path.
- **Reading BMCs from the Cacti poll cycle.** Ties poll duration to BMC latency; see ADR 0005.
- **Relaying BMC alerts (traps or email).** Not uniform across vendors and not suppressible by Maint.
