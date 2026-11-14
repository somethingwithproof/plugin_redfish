# 0003. Use Redfish as the primary protocol and IPMI as an optional fallback

Status: Accepted (2026-11-14)

## Context

Redfish is the DMTF standard for BMC management over HTTPS with TLS 1.2 or later, with session login and per-resource schemas. IPMI over LAN predates it. IPMI 2.0 authentication lets a client obtain a salted hash of a valid user's password before it authenticates, and cipher suite 0 permits access without a valid password; US-CERT advised isolating IPMI and disabling cipher 0. The `ipmitool` repository is archived, and its `dcmi power reading` output is text meant for people.

## Decision

Redfish is the primary protocol and the only one in phase 1. IPMI is a phase 2 fallback for BMCs that lack usable Redfish. It is disabled by default, enabled per BMC, uses only an allowlist of read-only commands, and ships after a security review. The IPMI client is chosen in issue 14.

## Consequences

- Most current server BMCs are covered without the weaker channel.
- Older hardware waits for phase 2 and accepts its weaker security properties explicitly.
- Two protocol paths feed one normalized model, so readings from both carry a source label.

## Alternatives rejected

- **IPMI as the base.** Weaker authentication, text parsing, and an unmaintained primary tool.
- **No IPMI at all.** Leaves older fleets with no BMC data in Cacti, where SNMP templates may not cover power.
- **SNMP to vendor MIBs.** Already covered by shipped vendor templates and vendor specific.
