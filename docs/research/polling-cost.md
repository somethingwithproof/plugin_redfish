# BMC polling cost and rate limits

Research for [#23](https://github.com/somethingwithproof/plugin_redfish/issues/23), 2026-11-15. Question: what is the request count `R`, latency `L` and time per node per BMC family, with and without `$select` or `$expand`; how often do BMCs refresh sensor values; do any rate-limit or degrade under repeated polling?

**Result.** `R` can be derived from vendor captures; `L`, the refresh interval and rate-limit behavior need a BMC and are *live*, so no default interval or worker count is chosen here. The derivation corrects the plan's illustrative `R = 17`: walking a Dell iDRAC10 R770 capture through the new model costs 22 requests including login and logout (20 reads), and an iDRAC9 R660xs costs 31, because of enumerating several `Chassis` members, a `ThermalMetrics` read, one request per fan rotor, and per-supply metrics. The legacy `Power` plus `Thermal` walk costs 8 and 9 on the same captures. The specification defines no rate limit, and no inbound rate-limit code was found in OpenBMC's server. Dell's mockup creator reports an average of 0.08 and 0.21 s per request over the whole tree, but over an unspecified network and including registries and schemas, so it is not a polling latency.

## Method

Request counts were derived by walking the Dell mockups ([dell/iDRAC-Redfish-Scripting@3bc6e7b](https://github.com/dell/iDRAC-Redfish-Scripting/tree/3bc6e7b67ef69745208de0516411db4bf2eda43f/iDRAC%20Redfish%20Mockup%20Clients)) the way the plan's client would: service root, `Chassis` collection, each `Chassis` member, then the linked resources. This is arithmetic over captured trees, not a measurement of a client. Nothing was sent to a BMC.

## Request counts

| Walk | iDRAC10 R770 (2 `Chassis` members, 6 fans, 2 supplies) | iDRAC9 R660xs (3 `Chassis` members, 14 fans, 2 supplies) |
|---|---|---|
| Service root | 1 | 1 |
| `Chassis` collection | 1 | 1 |
| Each `Chassis` member (needed to see which carry links) | 2 | 3 |
| `EnvironmentMetrics` (power) | 1 | 1 |
| `ThermalSubsystem`, `ThermalMetrics` (intake) | 2 | 2 |
| `Fans` collection plus one request per `Fan` | 7 | 15 |
| `PowerSubsystem` | 1 | 1 |
| `PowerSupplies` collection, each `PowerSupply`, each `Metrics` | 5 | 5 |
| **New-model reads** | **20** | **29** |
| plus login and logout | 22 | 31 |
| Legacy walk: root, collection, members, `Thermal`, `Power` | 6 reads, 8 with login and logout | 7 reads, 9 with login and logout |
| New model with `$expand=.` on the `Fans` and `PowerSupplies` collections (Dell advertises `ExpandQuery` with `MaxLevels` 1) | 12 reads | 14 reads (fans 1 instead of 15, supplies 3) |

Counts for the expanded walk assume `$expand=.` returns member bodies and does not expand `Metrics` links (`MaxLevels` 1); that is unverified (*live*). A `Sensors?$expand=.` read could return all 38 to 41 sensors in one response; response size and BMC time for it are unmeasured. The plan's `R = 17` for 8 fans and 2 supplies assumed one chassis and no `ThermalMetrics` or supply `Metrics` reads.

Consequences: (a) a chassis filter that avoids reading RAID enclosure chassis saves 1 to 2 requests, but needs `ChassisType` or link presence, which requires reading the member; reading each once per run is the floor without caching; (b) the legacy walk is cheapest on Dell and returns power, temperatures, fan rotors and supply input power, but lacks the standard `Intake` summary (inlet then comes from a vendor name rule); (c) caching the discovered URIs between runs, and only re-reading the collection structure when a `@odata.etag` or member count changes, would cut `R` to the minimum (one read per measured resource). That is a design option for M2, to be tested live. A member count is not a sufficient check: replacing one fan with another keeps the count, and a collection may have no `@odata.etag` (the property is optional in the specification). The cache therefore needs more than an ETag or count: a full rediscovery walk on a fixed period (for example hourly, a proposal) and an immediate rediscovery when a cached URI returns 404, a member `Id` or `@odata.type` differs from the cached one, or a read fails to parse. Without these a replaced or renumbered member would be missed indefinitely.

## Latency, refresh and limits

| Item | Status | Source |
|---|---|---|
| Dell mockup creator summaries: iDRAC9 capture average 0.0815 s per request, minimum 0.0100 s, maximum 4.56 s; iDRAC10 capture average 0.2124 s, minimum 0.123 s, maximum 10.4 s (the slowest were registries and schema files) | confirmed as printed; **not a polling latency**: whole-tree crawl, unknown network, one client, unknown concurrency | `README` in each mockup archive |
| Per-request `L` for the plugin's resource set, per family, with and without `$select` and `$expand` | unverified, *live* | |
| Sensor refresh interval | unverified, *live*. The standard provides `Sensor.ReadingTime` (since `v1_1_0`) to observe it; whether a family populates it is unknown | `Sensor.v1_14_0.json` |
| Rate limiting: DSP0266 1.23.0 has no 429 or "Too Many Requests" text | confirmed | spec search |
| OpenBMC server: a code search for `too_many_requests` and `429` finds only `http/http_client.hpp` (outbound event delivery) and the aggregator, none in server request handling | confirmed for the search index (may be incomplete) | [bmcweb@c9ba80e](https://github.com/openbmc/bmcweb/tree/c9ba80ef3d4ea680662af14856f333534a5ac56f) |
| `ProtocolFeaturesSupported.MultipleHTTPRequests` tells whether concurrent requests on one connection are supported; Dell's capture omits it | confirmed (capture) | `ServiceRoot.v1_22_0.json` |
| Dell advertises `$select`, `$filter`, `only` true and `excerpt` false; `$expand` with `Levels` true, `MaxLevels` 1 | confirmed (capture) | both Dell archives |
| Effect on BMC web UI latency under polling | unverified, *live* | |

## Worker count arithmetic (illustrative, not a result)

With the plan's symbols, `W >= N x R x L / T`. Using `R = 22` (iDRAC10 new model) and Dell's iDRAC10 crawl average `L = 0.21 s` only as a placeholder: at `N = 1000`, `T = 300 s`, `W` is at least 16 (4,620 s of request time); with the legacy walk `R = 8`, at least 6. Using the iDRAC9 average `L = 0.08 s` with `R = 31`: at least 9. These show how sensitive `W` is to `R`; they are not defaults. TLS handshakes per request change `L` materially on a BMC; see [transport.md](transport.md).

## Exit criteria status

| Criterion | Status |
|---|---|
| Measured `R`, `L`, refresh interval per family | `R` derived for two Dell captures; `L` and refresh *live* |
| Default interval and worker count chosen from data | not done, depends on `L` |
| Plan performance section updated with measured numbers | updated with derived `R` only, labelled as derived |

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #23 | Time each request type, 50 repetitions at 5 s and 60 s spacing, per family, TLS verified, session per run | `L`, `R` real, per-step cost |
| #23 | Same with `$expand` and `$select` where advertised | Whether they reduce load rather than just request count |
| #23 | Poll at 1-minute intervals for 24 hours while sampling the web UI | BMC degradation |
| #23 | Burst 50 parallel requests, watch for 429, 503, `Retry-After`, resets | Rate limiting and backoff defaults |
| #23 | Read `ReadingTime` repeatedly | Sensor refresh interval |
| #23 | Test `Sensors?$expand=.` | One-request alternative to the resource walk |
