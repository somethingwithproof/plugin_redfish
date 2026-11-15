# 0010. Enforce a read-only Redfish client with an exact operation allowlist before any I/O

Status: Accepted (2026-11-15)

## Context

The plugin holds credentials for out-of-band management controllers. A Redfish account with only the ReadOnly role cannot change state, but operators often reuse an administrator account. A bug that sends `PATCH`, `PUT`, an action `POST`, or a `DELETE` of the wrong resource could power off a server or change a BMC account. BMC addresses are entered by administrators; links inside BMC responses are not. A BMC can return a body that never ends, a JSON document nested deep enough to exhaust memory, or a `Retry-After` of a day.

## Decision

- All Redfish I/O goes through `GuardedTransport`, which consults `OperationGuard` before a byte is written. Allowed: `GET` on a `/redfish/v1` path with no query, no dot segment, and no encoded slash; `POST` only to the sessions collection named by the service root; `DELETE` only on a session member the client itself created, or the one session path an earlier run of the plugin recorded for this BMC after its login succeeded. Sessions of the same user name that the plugin did not record are never listed or deleted; a login whose response was lost leaves no path and its session expires on the BMC's session timeout. Everything else raises `OperationRefused`.
- One request is in flight per client, and a run has a request budget (256 per BMC).
- Each BMC's run has a deadline tied to its lease: no request starts at or after it, and the run's own session `DELETE` may start until a second, later deadline. Each leaves room for one full request (connect plus total timeout) before the lease expires, so a slow BMC cannot outlive the lease and two workers never hold sessions on one BMC.
- `SocketTransport` connects only to the registered BMC address. It resolves the name once, refuses loopback, link-local, multicast, unspecified, and IPv4-mapped forms of those, then connects to the vetted address with the registered name as SNI and peer name. Links in responses are followed only when they name the same scheme, host, and port; a cross-host link is counted and ignored, never resolved.
- Responses are bounded: body size (setting, default 2 MiB, also enforced for chunked bodies), JSON depth 32, a connect timeout and a total deadline per request. `Retry-After`, in seconds or as an HTTP date, is honored up to 3600 seconds through the collector's backoff; a run never retries a throttled BMC.
- Stream sockets are used directly, so every connection's TLS options are visible in one class. ext-curl is not used.

## Consequences

- A source rule test fails the build if `PATCH`, `PUT`, `curl_*`, or `stream_socket_client` appear outside the transport, or if the loopback test binding is used outside tests.
- `$expand` and `$select` are recorded at discovery but not sent in milestone 2, since the guard refuses queries; enabling them later is a guard change with its own tests.
- Connections are not reused across requests; at milestone 2 counts this costs a TLS handshake per request, which the performance work in milestone 3 measures.

## Alternatives rejected

- **Rely on the ReadOnly role.** Correct for a well-configured BMC, but the plugin cannot verify the role, and a bug would then depend on operator discipline.
- **Method denylist.** A new method or action would be allowed by default.
- **ext-curl.** Hides the resolved address and TLS options behind global defaults and makes the address check harder to place before connect.
