# 0009. Store BMC passwords in a plugin-owned vault with the key outside the database

Status: Accepted (2026-11-15). Resolves decision issue 9.

## Context

ADR 0006 requires one credential backend, encrypted at rest. The plan proposed plugin_credentials. That plugin has no release and no tag, so a dependency on it would pin this plugin to an unreleased interface that can change under it. Milestone 1 research ([credentials](../research/credentials.md)) compared it with a plugin-owned store and with secret references only. plugin_cloud already ships a reviewed vault: AES-256-GCM through ext-openssl, a 32-byte key read from a file outside the database, and additional data that binds each ciphertext to its owner and purpose.

## Decision

- Passwords are stored in `plugin_redfish_secret`, encrypted with AES-256-GCM. The additional data is `plugin_redfish/vault/v1/credential/{id}/{purpose}`, so a ciphertext copied to another credential row does not decrypt.
- The key is 64 hexadecimal characters (optionally followed by one newline), as `openssl rand -hex 32` writes, or exactly 32 raw random bytes, in a file named by the `redfish_vault_key_file` setting (default `/etc/cacti/redfish-vault.key`). Thirty-two bytes that are all printable ASCII are refused as a likely passphrase. The file must be a regular file, not a symbolic link, owned by the poller's effective user, not writable by its group, and not readable or writable by other users; mode 0600 or 0640 is expected. No parent directory may be a symbolic link, and the directory holding the file must be owned by the poller user or root and not writable by group or others. A world-writable directory such as `/tmp` is refused even with the sticky bit. Ownership is checked with ext-posix, which Cacti 1.2 requires on Unix; without it the key is refused. The key never enters the database, a log, or a page.
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
