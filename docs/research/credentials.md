# plugin_credentials as the BMC credential store

Research for [#28](https://github.com/somethingwithproof/plugin_redfish/issues/28), 2026-11-15. Question: is plugin_credentials stable enough to depend on, with attention to `credentials_get()`, the `ipmi` type for Redfish, key management, release status, remote collectors, failure behavior and uninstall?

**Result.** Recommendation for decision [#9](https://github.com/somethingwithproof/plugin_redfish/issues/9): use plugin_credentials, as the plan proposes, with four conditions drawn from its source: its API has no access check and no release or tag exists; the secret key is shared with every other secret in the installation; the plugin does not run on remote collectors; and uninstalling it drops its tables, including every stored credential, without checking dependents. The function and type the plan names exist at the pinned commit. Installation and a read through a test plugin, the maintainers' view on API stability, and the access-control behavior in a running Cacti were not done (*live*; the maintainer question is an owner action). Source read at [plugin_credentials@ead8956](https://github.com/Cacti/plugin_credentials/tree/ead895662c031872f92c7bdeb67f9a6df03f1b4d), the head of `main` on 2026-11-15.

## Facts

| Fact | Status | Source |
|---|---|---|
| `credentials_get(int $id): array\|false` returns metadata plus a `fields` map with secrets decrypted, or false for an unknown id; a provider-backed secret that fails to resolve is `false` in its field and is logged without the value | confirmed | [includes/functions.php:286](https://github.com/Cacti/plugin_credentials/blob/ead895662c031872f92c7bdeb67f9a6df03f1b4d/includes/functions.php#L286), README "Programmatic use" |
| `credentials_get()` performs no permission check and does not test `enabled`; it returns `enabled` in the result. Access control is on the pages `credentials.php` and `credential_providers.php` through the core Users/Groups permission | confirmed (source and README); not exercised | functions.php:286-330; README "Access control" |
| So any PHP code running inside Cacti that includes the file can read any credential; the plugin must check `enabled`, and must never expose the function to request input | inference from the source | |
| Type `ipmi`, name "IPMI / Redfish (BMC)", tier 3, class Device: `username` (required), `password` (secret, required, max 255), `protocol` (drop-down `ipmi` or `redfish`, default `ipmi`) | confirmed | [includes/arrays.php:394-422](https://github.com/Cacti/plugin_credentials/blob/ead895662c031872f92c7bdeb67f9a6df03f1b4d/includes/arrays.php#L394-L422) |
| `protocol` is one label per credential; nothing enforces it for a consumer. One credential can hold a username and password usable for both Redfish and IPMI, but the label can name only one. Whether BMC accounts for the two protocols are the same is a site matter | confirmed (arrays.php); policy is a decision for M5 | |
| Encryption: all secret fields use the core helpers (AES-256-GCM, key in setting `secret_encryption_key`); for Cacti below 1.2.32 it defines a byte-compatible copy of the helpers | confirmed | README; [includes/functions.php:35](https://github.com/Cacti/plugin_credentials/blob/ead895662c031872f92c7bdeb67f9a6df03f1b4d/includes/functions.php#L35); core helpers in [cacti-core.md](cacti-core.md) |
| One installation key protects every encrypted secret, including those of other plugins; `cacti_secret_key()` throws `RuntimeException` if the stored key is missing or corrupt | confirmed (core source) | `lib/functions.php:9973` at `1.2.x` |
| External providers ship: HashiCorp Vault, OpenBao, AWS Secrets Manager, Azure Key Vault, Google Cloud Secret Manager, CyberArk Conjur; a provider failure fails closed (secret is `false`) and is logged; resolution is cached per request | confirmed (README, CHANGELOG 1.1) | README |
| INFO: version 1.1, `compat = 1.2.29`, `capabilities = online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0` | confirmed | `INFO` |
| Releases and tags: none (GitHub API: 0 releases, 0 tags); not archived; 0 open issues; last push 2026-11-11 | confirmed 2026-11-15 | GitHub API |
| `plugin_credentials_uninstall()` is "Nothing extra to do" and does not check dependents, but its tables are created with `api_plugin_db_table_create()`, whose ownership the framework records in `plugin_db_changes`; `api_plugin_uninstall($plugin, $tables = true)` then calls `api_plugin_db_changes_remove()`, which runs `DROP TABLE IF EXISTS` on them. A default uninstall therefore **deletes every stored credential and provider**, and reinstalling does not restore them | confirmed (source) | [setup.php:93-100](https://github.com/Cacti/plugin_credentials/blob/ead895662c031872f92c7bdeb67f9a6df03f1b4d/setup.php#L93-L100), [setup.php:185](https://github.com/Cacti/plugin_credentials/blob/ead895662c031872f92c7bdeb67f9a6df03f1b4d/setup.php#L185), core [lib/plugins.php:570-583, 801-838](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L801-L838) |
| Install registers hooks `config_arrays`, `draw_navigation_text`, `page_head` only; the function is a plain include and works whether or not the plugin is enabled, if the file exists | confirmed (setup.php:83-86); runtime not run | |
| With `remote_collect:0`, its hooks do not run on remote collectors; whether its tables and the `secret_encryption_key` setting exist on a collector depends on replication, which was not read | unverified | for #17 |

## Recommendation for decision #9

Use plugin_credentials, with these conditions written into the requirements:

1. **Guarded call.** Load `includes/functions.php` only when `api_plugin_is_enabled('credentials')` and the file exists, then check `function_exists('credentials_get')`, as Thold does for Maint ([cacti-core.md](cacti-core.md)). Missing plugin means the BMC is `credentials_unavailable`, never an empty password.
2. **Check `enabled`, `type` and `fields.protocol`.** Refuse a disabled credential, a type other than `ipmi`, and `protocol` `ipmi` for the Redfish client (and the reverse for the IPMI client), with a clear health reason. Treat a `false` password as failure, per the README.
3. **Reference only.** BMC rows store the credential id, never the secret (ADR 0006). The plugin lists credentials of type `ipmi` for selection but never reads secret fields outside the collector.
4. **No release pin possible yet.** Declare a minimum commit in documentation until upstream tags a release; this is a risk in the plan ("pre-release credential plugin") that stays open and the README states no API stability policy (it documents the function by example only).
5. **Uninstall.** Documentation must warn that uninstalling plugin_credentials with Cacti's default removes its tables and every stored credential, so back up the `credentials` and `credential_providers` tables first; reinstalling does not restore them, and the collector must report the missing credentials as a failure for every BMC that used one.
6. **Remote collectors.** Not available at `remote_collect:0`; phase 4 decision #17 stays open.

Changes to propose upstream first, as separate issues on plugin_credentials: a documented stability statement for `credentials_get()`; an optional `credentials_get_for($id, $consumer)` or a documented way to restrict which plugins may read a type (today any code can); a release tag; and a statement of remote collector behavior.

## Live checks and owner actions

| Issue | Check | What it settles |
|---|---|---|
| #28 | Install on 1.2.31 and `1.2.x`, create an `ipmi` credential with `protocol` `redfish`, read it from a test plugin | The function works on both |
| #28 | Search logs, debug output and exports for the sentinel password after a read | No secret leakage through the API |
| #28 | Disable plugin_credentials and run the collector | Failure behavior |
| #28 | Ask maintainers about releases and API stability | Owner action; not done |
| #28 | Check what a restricted Cacti user can reach through the plugin's own pages | Access control in practice |
