# 0009. Store BMC passwords in a plugin-owned vault with the key outside the database

Status: Accepted (2026-11-15). Resolves decision issue 9.

## Context

ADR 0006 requires one credential backend, encrypted at rest. The plan proposed plugin_credentials. That plugin has no release and no tag, so a dependency on it would pin this plugin to an unreleased interface that can change under it. Milestone 1 research ([credentials](../research/credentials.md)) compared it with a plugin-owned store and with secret references only. plugin_cloud already ships a reviewed vault: AES-256-GCM through ext-openssl, a 32-byte key read from a file outside the database, and additional data that binds each ciphertext to its owner and purpose.

## Decision

- Passwords are stored in `plugin_redfish_secret`, encrypted with AES-256-GCM. The additional data is `plugin_redfish/vault/v1/credential/{id}/{purpose}`, so a ciphertext copied to another credential row does not decrypt.
- The key is 32 bytes, raw or as 64 hex characters, in a file named by the `redfish_vault_key_file` setting (default `/etc/cacti/redfish-vault.key`). The file must be a regular file, not a symbolic link, and not readable or writable by other users; mode 0600 or 0640 for the poller user is expected. The key never enters the database, a log, or a page.
- A credential is a name, a user name, and a revision. BMCs reference a credential by id. The password field on the form is write-only; a blank field keeps the stored value.
- The revision rises whenever the user name or the password changes, which lets a BMC held in `auth_failed` try once with the changed credential.
- Decrypted passwords live in a `Secret` object that does not print, serialize, or export its value, and are dropped when the run ends.

## Consequences

- Backups must include the key file; without it every stored password must be entered again. The README says so.
- Losing the database alone does not expose passwords. Losing the database and the key file does.
- Remote collectors (milestone 7) need the key on each collector; that decision stays open.
- If plugin_credentials ships a release, a migration path can move entries to it; the BMC to credential reference does not change.

## Alternatives rejected

- **plugin_credentials (the plan's proposal).** No release or tag to depend on. This record supersedes that row of the plan's open decisions.
- **Secret references only** (an environment variable or file per BMC). Puts per-BMC files on every poller and leaves rotation to the operator, with no audit trail in Cacti.
- **Key in the Cacti database or `config.php`.** A database dump would then carry both the ciphertext and its key.
