# 0011. Trust BMC certificates through the system store, a CA bundle, or an SPKI pin, with a logged per-BMC opt-out

Status: Accepted (2026-11-15). Resolves decision issue 10.

## Context

ADR 0006 sets verification on by default and rules out a global switch. Many BMCs present a self-signed certificate or one from a private CA. Operators need a way to trust such a BMC without turning verification off, and a way to see when they did turn it off.

## Decision

Each BMC has one TLS mode:

| Mode | Verification | Use |
|---|---|---|
| `system` (default) | Chain and name against the system CA store | BMCs with publicly or enterprise-trusted certificates already in the store |
| `ca_bundle` | Chain and name against a PEM file named on the BMC | A private CA that signs BMC certificates |
| `pin` | The SHA-256 of the certificate's SubjectPublicKeyInfo, base64, must match | Self-signed certificates; survives reissue with the same key |
| `unverified` | None | An explicit opt-out |

- TLS 1.2 and 1.3 only. In `pin` mode the chain is not checked; the peer key is compared with `hash_equals` before the request is written.
- Failures are classified as `tls_chain`, `tls_name`, `tls_expired`, `tls_pin`, `tls_protocol`, or `tls_config`; the health page shows the class. A chain failure is retried once without verification only to read the certificate dates, so an expired certificate is reported as expired; no request is sent on that connection.
- Setting or leaving `unverified` is logged with the user id, and every enabled BMC in that mode raises a warning on the health page for as long as it stays.
- A change of address, port, or trust settings marks discovery stale. Any edit of a BMC clears its held failures and backoff.

## Consequences

- A pinned BMC whose key changes stops collecting with `tls_pin` until an administrator enters the new pin; that is the intended failure.
- The fake BMC tests cover each class with generated certificates.

## Alternatives rejected

- **CA bundle only.** Forces operators with self-signed BMCs to build a CA or turn verification off.
- **Trust on first use.** Accepts whatever answers the first request, which is the moment an attacker on the path is most useful.
- **Certificate (not key) pin.** Breaks on every routine reissue with the same key.
