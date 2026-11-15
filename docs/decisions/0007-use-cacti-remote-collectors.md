# 0007. Reach isolated BMC networks through Cacti remote data collectors, not a custom bridge

Status: Accepted (2026-11-14)

## Context

BMC networks are often isolated from the main Cacti server. Cacti has remote data collectors. Its `remote_agent` hook lets plugins add actions, `call_remote_data_collector()` calls them with a relative URL, and `replicate_out` copies configuration to collectors. `remote_agent` authorizes callers by matching their address, or the reverse DNS name of their address, to an enabled poller's hostname.

## Decision

In phase 4, the collector, clients, and script server input run on the remote data collector that can reach the BMCs. Plugin configuration tables replicate through `replicate_out`, readings stay in the collector's local database and reach the main server through the normal poller output path, and the main server asks for health and discovery through read-only `remote_agent` actions that take identifiers only. Phases 1 to 3 do not use this. (Research 2026-11-15: with the planned `INFO` capabilities, hooks for devices on a remote poller do not run on that poller until phase 4 adds `remote_poller:1` and `remote_collect:1`, so phases 1 to 3 support BMC devices on the main poller only. See [Cacti core](../research/cacti-core.md).) The plugin is Cacti 1.2.31 or later and requires no core patch.

## Consequences

- No new daemon, socket, credential store, or protocol.
- A remote BMC network needs a full Cacti remote collector.
- Credentials must exist on the collector. plugin_credentials declares `remote_collect:0` today, so issue 17 decides how secrets reach collectors.
- Authorization is address-based. Documentation requires https and a restricted network between pollers.

## Alternatives rejected

- **Custom bridge service.** A new credential holder and protocol on a network that already holds BMC credentials.
- **Direct SSH from the main server.** Needs key management and a privileged remote command path.
