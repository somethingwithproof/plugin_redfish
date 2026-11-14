# 0005. Collect from BMCs out of band and serve Cacti from a cache

Status: Accepted (2026-11-14)

## Context

A Redfish read of a node needs a login and several resource reads, and BMC latency and session limits vary by vendor. Cacti's poller expects each data source to return promptly, and script server calls run inside the poll cycle. A script that logs in to a BMC on every call would multiply logins and tie the poll cycle's length to the slowest BMC.

## Decision

A collector process, started from `poller_bottom` by `exec_background()` and guarded by `register_process_start()`, reads BMCs on its own schedule with a time budget, one worker at a time per BMC, and writes normalized readings to plugin tables. The script server input and the plugin pages read only that cache. No page request and no poll-cycle call contacts a BMC.

## Consequences

- A slow or dead BMC costs collector time, not Cacti poll time.
- Per-BMC load is controlled in one place: sessions, concurrency, backoff.
- Data has two ages: the collector's and Cacti's poll. `age` exposes the first.
- The collector needs its own health reporting and failure handling.

## Alternatives rejected

- **Direct reads from a data input script.** One login per data source per poll and poll duration coupled to BMC latency.
- **Long-lived daemon.** A new service to install and supervise; the Cacti poller already starts background work.
