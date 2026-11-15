# HTTPS transport for BMCs

Research supporting the plan's Redfish client, 2026-11-15. Not an M1 issue by itself; it reuses [plugin_cloud's transport spike](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/research/transport.md) and [ADR 0009](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/decisions/0009-http-transport.md) and records what changes when the peer is a BMC. Question: does the plugin_cloud decision (a minimal `stream_socket_client('tls://...')` HTTP/1.1 client, not the stream wrapper and not ext-curl) carry over to BMCs?

**Result.** Yes for the choice, with four BMC-specific changes to settle before M2: TLS trust and pinning, old TLS versions and cipher suites, connection reuse, and the Redfish client being a request chain rather than a single call. The plugin_cloud evidence (the stream wrapper cannot bound total time or header size, passes malformed chunked bodies through, and needs `allow_url_fopen`; ext-curl is absent from stock Debian and Ubuntu PHP) is about PHP and applies unchanged. It was measured against local test servers, not BMCs, so nothing about BMC TLS behavior is measured here. No new ADR is written; the choice for this repository is for the maintainer, and ADR 0006's TLS options are the existing record.

## What carries over (plugin_cloud evidence, not re-run)

| Finding | Source |
|---|---|
| Stream wrapper failed 6 requirements on PHP 8.2 and 8.4: slow headers held a request 30 s against a 4 s budget, a total budget below the connect timeout was ignored, a stalled peer was reported as a generic failure, 64 MiB of headers were buffered, malformed and truncated chunked bodies were returned as data | plugin_cloud `docs/research/transport.md` |
| A socket client with a strict response parser met all 54 checks (27 cases, two PHP versions) | same |
| libcurl 8.14.1 accepted conflicting `Content-Length`; ext-curl missing from stock Debian 13, Ubuntu 22.04 and 24.04 `php-cli` | same |
| Cacti 1.2.31 requires `ext-openssl` and not `ext-curl`; core itself uses the stream wrapper for remote collector calls | [cacti-core.md](cacti-core.md); plugin_cloud |

## BMC-specific points

| Point | Status | Notes |
|---|---|---|
| TLS 1.2 or later and "Recommended" cipher suites only | confirmed (DSP0266 13.1) | A BMC that follows the spec offers modern suites. Older firmware may offer TLS 1.0 or 1.1 or weak ciphers; PHP's OpenSSL may refuse them by default. The PHP SSL context has `security_level` and `min_proto_version` options ([php.net](https://www.php.net/manual/en/context.ssl.php)); lowering them would be a per-BMC, logged exception like ADR 0006's unverified mode. Which fleet BMCs need it is *live* |
| Default certificates | unverified (*live*) | The spec requires replaceability, not CA signing. Dell's sample scripts skip certificate validation by default ("script ignores validating SSL cert" in `--ssl` help, e.g. `SensorCollectionREDFISH.py`) and Lenovo's guide examples use `curl -k`, which suggests untrusted default certificates but proves nothing about a given BMC |
| Pinning | design note | PHP's `peer_fingerprint` pins the certificate digest (`md5`, `sha1`, or an array keyed by algorithm). The plan says "pinned public key" (libcurl's `CURLOPT_PINNEDPUBLICKEY` semantics). With a socket client, a public-key pin means `capture_peer_cert`, then hashing the key after the handshake; a certificate pin is simpler but breaks on certificate renewal. Whether `peer_fingerprint` is enforced when `verify_peer` is off was not tested here |
| Host name checks | design note | BMCs are often addressed by IP, and a default certificate may not carry that IP or name. PHP supports `peer_name` for the expected name. With a pin, name checking may be skipped for that BMC by explicit setting; this is exactly ADR 0006's per-BMC option |
| Connection reuse | open design question | plugin_cloud's client sends `Connection: close`, one TLS handshake per request. A Redfish run is 8 to 31 requests ([polling-cost.md](polling-cost.md)), so it would be up to 31 handshakes on an embedded CPU. The spec requires HTTP/1.1 resources and services accept keep-alive (the spec's own examples use `Connection: keep-alive`, sections on update and import), and `MultipleHTTPRequests` says whether concurrent requests are supported. A client that reuses one connection serially per BMC per run keeps the parser's strict framing and cuts handshakes to one. The cost of a handshake on each BMC family is *live*; this should be measured before M2 fixes the transport contract |
| Redirects | confirmed need | Services may answer an HTTP session POST with a redirect to HTTPS (spec 13.3.4.2); the plan's "no redirects" stays, and the client must start at HTTPS |
| Response size and parsing | carry over | `$expand=.` on `Sensors` could return tens of kilobytes; the body cap must be sized for it (unmeasured) |
| Proxy environment variables | carry over | ignored; BMCs are on a management network, direct |

## Live checks

| Check | What it settles |
|---|---|
| For each family, record certificate issuer, key type and size, SANs and negotiated TLS version and cipher with a verifying client | Pinning and name-check rules; whether PHP's defaults connect |
| Time a TLS handshake and a GET with and without connection reuse | Whether keep-alive is worth the client complexity |
| Check `peer_fingerprint` behavior with `verify_peer` on and off against a local TLS test server | Pinning mechanism |
