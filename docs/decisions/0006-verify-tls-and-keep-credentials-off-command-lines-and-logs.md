# 0006. Verify TLS by default and keep credentials off command lines and logs

Status: Accepted (2026-11-14)

## Context

BMC credentials control out-of-band management of a server. Redfish requires TLS 1.2 or later and lets implementations replace the default certificate, so a BMC may present a certificate that no public CA signed. Operators often respond by disabling verification globally. An IPMI client such as `ipmitool` accepts a password with `-P`, which the manual says is obscured in the process list only "if supported"; it also accepts `-E` (environment) and `-f` (file). PHP's `proc_open()` accepts an argument array and starts the process without a shell.

## Decision

- TLS verification is on for every BMC. A BMC may instead use a CA bundle of its own or a pinned public key. An explicit, logged, per-BMC setting may turn verification off; it raises a health warning for as long as it is set. No global switch exists.
- Credentials are never placed on a command line, in a URL, in a log, in an error message, or in a rendered page. Redfish session tokens receive the same treatment as passwords.
- External commands are built as argument arrays with allowlisted subcommands and validated operands. Secrets reach a child process only through its environment or a file readable only by the poller user.
- Credentials are stored through one backend, chosen in issue 9, encrypted at rest.

## Consequences

- Self-signed BMC certificates need operator work (pinning or a private CA), which the documentation covers.
- A test scans logs, arguments, and pages for sentinel secrets on every CI run.
- Unverified BMCs stay visible in the health page instead of being silently accepted.

## Alternatives rejected

- **Global "do not verify" option.** Makes the weakest BMC set the policy for all.
- **Passing passwords with `-P`.** Visible to other local users on systems where the tool does not hide it.
- **Shell strings with escaping.** Escaping errors become command injection; argument arrays avoid the shell.
