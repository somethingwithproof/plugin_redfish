# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-observe-mode-first.md) | Ship read-only observe mode first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-redfish-first-ipmi-optional-fallback.md) | Use Redfish as the primary protocol and IPMI as an optional fallback | Accepted |
| [0004](0004-prefer-subsystem-resources-with-legacy-fallback.md) | Prefer the subsystem, metrics, and sensor resources and fall back to Power and Thermal | Accepted, pending vendor evidence |
| [0005](0005-collect-out-of-band-and-serve-from-cache.md) | Collect from BMCs out of band and serve Cacti from a cache | Accepted |
| [0006](0006-verify-tls-and-keep-credentials-off-command-lines-and-logs.md) | Verify TLS by default and keep credentials off command lines and logs | Accepted |
| [0007](0007-use-cacti-remote-collectors.md) | Reach isolated BMC networks through Cacti remote data collectors, not a custom bridge | Accepted |
| [0008](0008-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0009](0009-plugin-owned-credential-vault.md) | Store BMC passwords in a plugin-owned vault with the key outside the database | Accepted |
| [0010](0010-read-only-client-with-an-operation-allowlist.md) | Enforce a read-only Redfish client with an exact operation allowlist before any I/O | Accepted |
| [0011](0011-tls-trust-modes.md) | Trust BMC certificates through the system store, a CA bundle, or an SPKI pin, with a logged per-BMC opt-out | Accepted |
| [0012](0012-bounded-same-origin-redirects.md) | Follow at most three same-origin HTTPS redirects on GET | Accepted |
| [0013](0013-choose-the-resource-source-per-reading.md) | Choose the subsystem or legacy source per reading, and take inlet only from a confirmed intake summary | Accepted |
| [0014](0014-plugin-name-namespace-and-provisional-harness.md) | Name the plugin `redfish` under `Somethingwithproof\Redfish`, with a provisional Pest harness | Accepted, provisional |

Open decisions are tracked as issues labelled `type: decision`.
