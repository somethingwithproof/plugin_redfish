# 0001. Ship read-only observe mode first, in phases that each release

Status: Accepted (2026-11-14)

## Context

A BMC controls power, firmware, and consoles for the machine it manages. A monitoring plugin that can also change BMC state would need a write path, a privileged account, and a much larger security review before it delivered any graph. Power capping is a common wish in high-performance computing, but it is a configuration change to the server, not a measurement.

## Decision

Release in phases that each ship on their own:

1. Redfish observe mode: BMC registry, credentials, collector, normalized readings, devices, graphs, Thold alerting, Maint suppression, health page.
2. IPMI fallback, off by default, behind a security review.
3. Rack registry and rack power totals.
4. Remote data collectors.

The plugin only reads. It never sets a power limit, fan mode, boot option, or any other BMC property, and it never issues power control or firmware actions. Whether a later release may write power limits is a separate decision (issue 18).

## Consequences

- Phase 1 is useful with a read-only BMC account, which limits the damage of a leaked credential.
- No phase depends on a later one, so stalled later phases do not strand earlier work.
- Operators who want Cacti to cap power wait for a separate decision and a separate review.

## Alternatives rejected

- **Read and write in one release.** Delays all value behind the write path's security review and enlarges the blast radius of every credential.
- **IPMI first.** IPMI over LAN is the weaker channel and its primary tool is archived, so it should not be the base.
