# 0012. Follow at most three same-origin HTTPS redirects on GET

Status: Accepted (2026-11-15). Supersedes the "MUST NOT follow redirects" clause of REQ-RF-001.

## Context

REQ-RF-001 (issue 37) said the client must not follow redirects. DSP0266 section 6.3 requires clients to handle redirects, and some BMCs redirect a collection without a trailing slash, or after a firmware update. Following redirects blindly would let a BMC send the client, and its credentials, to another host or to a non-Redfish path.

## Decision

- Redirects (301, 302, 303, 307, 308) are followed only for `GET`, at most three hops.
- The target must be `https`, the same host and port as the registered BMC (an absent port means 443), carry no user information, and resolve to a `/redfish/` path with no query. Anything else is refused with `redirect_refused` and nothing is sent to it.
- Each hop goes through the operation allowlist again (ADR 0010). The session token is sent only to that same origin, so it never leaves the BMC.
- A redirect in answer to the session `POST` or a `DELETE` is refused, not followed: replaying a login to a new path is not worth the risk.

## Consequences

- Issue 37's text still says the client must not follow redirects. The owner needs to update it to match this record.
- A BMC that redirects its service root to another host name needs that name registered as its address.

## Alternatives rejected

- **No redirects (REQ-RF-001 as written).** Contradicts DSP0266 6.3 and fails on BMCs that redirect within themselves.
- **Follow any redirect.** Sends credentials wherever the BMC points.
