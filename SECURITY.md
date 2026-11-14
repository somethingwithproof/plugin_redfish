# Security policy

## Supported versions

No version of this plugin has been released. This policy applies to the design documents now and to released code later.

## Reporting a vulnerability

Report vulnerabilities privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Do not open a public issue. If you cannot use GitHub, email Thomas Vincent at thomasvincent@gmail.com.

Include what is affected, how to reproduce it, and the versions of Cacti, PHP, and the BMC firmware involved. Do not include working BMC credentials, session tokens, or unredacted captures. You will get an acknowledgement, and fixes are prepared privately until a release is available.

Vulnerabilities in Cacti core, in another Cacti plugin, or in a BMC vendor's firmware belong to that project's own disclosure process, not this repository.

## Design-level security

The security boundaries this design relies on are described in [docs/PLAN.md](docs/PLAN.md#security). Weaknesses found in the design itself, before any code exists, may be reported publicly as issues with the `area: security` label, provided they do not include credentials or captures from a live system.
