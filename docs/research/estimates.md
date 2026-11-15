# Estimates for phases 1 to 4

Research for [#30](https://github.com/somethingwithproof/plugin_redfish/issues/30), 2026-11-15. Question: how large is each later milestone, and what gates it, given the milestone 1 results? Method follows [plugin_cloud's estimates](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/research/estimates.md): size from measured lines of two plugins by the same author with the same layout.

**Result.** About 7,500 to 11,500 lines of production PHP for phase 1 (milestones 2 to 4) and about 11,200 to 17,500 lines for phases 1 to 4, with tests at one to two times production lines. Size is grounded in measured lines of analogous areas; **calendar time is not estimated**. The two reference plugins were committed within days with AI assistance, so their commit spans say nothing useful about elapsed time for a volunteer project, and what gates this plugin is outside the code: real BMCs for fixtures and live checks, pilot sites, two security reviews, and maintainer decisions. The gates are listed per milestone. No multiplier is applied; ranges are a judgment from line counts of the areas named, not a model.

## Basis (measured 2026-11-15)

| Plugin | Commit | Production lines | Test lines | Files (prod / test) |
|---|---|---|---|---|
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | `c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0` (2026-11-14) | 10,479 | 20,347 | 70 / 71 |
| [plugin_smokeping](https://github.com/Cacti/plugin_smokeping) (local clone, remote `somethingwithproof/plugin_smokeping`) | `481547c54050d97f413d3f23408cf4e88e7ef39f` (2026-11-14) | 14,932 | 11,328 | 112 / 81 |

Lines are `wc -l` of `.php`, `.sh`, `.pl`, `.py`, `.inc` files on each `main` branch, excluding `vendor/` and `node_modules/`; production excludes `tests/` and `features/`. They differ from plugin_cloud's 2026-11-14 figures (10,324 and 13,249) because both repositories gained commits since. Areas in plugin_cloudflare by production lines: `src/Account` 1,052, `src/Api` 993, `src/Discovery` 979, `src/Metrics` 842, `includes/accounts_page.php` 821, `src/Storage` 788, `includes/viewer.php` 677, `src/Http` 604, root files 595, `includes/cacti_objects.php` 507, `src/Secret` 438, `src/Jobs` 377, `includes/accounts.php` 355, `includes/templates.php` 306, `includes/bootstrap.php` 216, `includes/polling.php` 171, `includes/settings.php` 168, `includes/script_server.php` 136. plugin_smokeping: `lib/ProbeCatalog.php` 929, `lib/SmokepingConfigParser.php` 881, `lib/SmokeProvisioner.php` 414, `lib/TargetRepository.php` 304, `lib/ImportPage.php` 286, `lib/CactiObjectRepository.php` 239, `lib/TargetActions.php` 236, `cli/smokeping.php` 383, `lib/Settings.php` 184.

## Per milestone

| Milestone | Production lines | Grounding | Gates outside the code |
|---|---|---|---|
| 2. Foundation | 4,500 to 6,500 | cloudflare pages and lifecycle (about 2,300 for `accounts_page`, `viewer`, `settings`, `bootstrap`), storage 788, jobs 377, HTTP 604 (the socket client, [transport.md](transport.md)); credentials are smaller than cloudflare's 438 because plugin_credentials holds the secret; the Redfish client, discovery and two normalizers are new and sized after cloudflare's discovery (979) and metrics (842) | Test harness choice with maintainers ([#13](https://github.com/somethingwithproof/plugin_redfish/issues/13)); decisions #8, #9, #10 recorded; at least one real BMC per family for the fixtures and the live checks in this directory; the connection-reuse and certificate decisions |
| 3. Cacti objects and alerting | 2,500 to 4,000, plus template XML | cloudflare `cacti_objects` 507, `templates` 306, `script_server` 136, `polling` 171, and the XML pattern; smokeping provisioner 414 and repositories; three data queries or one ([data-query.md](data-query.md)) | The live data query spike (#29) before the RRD step is fixed; decision #11 (device mapping); a Cacti with Thold and Maint for the proof ([#48](https://github.com/somethingwithproof/plugin_redfish/issues/48)); a 1,000-BMC fixture server for [#51](https://github.com/somethingwithproof/plugin_redfish/issues/51) |
| 4. Phase 1 release | 500 to 1,000, mostly tests and docs | plugin_smokeping release qualification and packaging | Security review; a pilot on at least two vendor families; the live failure-case captures in [health-mapping.md](health-mapping.md) |
| **Phase 1 total** | **7,500 to 11,500** | | |
| 5. IPMI fallback | 1,200 to 2,000 | no direct precedent in either plugin; process execution with an allowlist, a text parser per family; sized against smokeping's parser files (881 and 929 for much larger grammars) as an upper bound | Decision #14 (client); fixtures from real IPMI BMCs; the phase 2 security review |
| 6. Racks | 1,500 to 2,500 | smokeping `TargetRepository` 304, `ImportPage` 286, `TargetActions` 236, `ObjectRepository` 185 for registry and pages; the coverage rule is new | Decisions #15 and #16; the aggregate threshold check ([cacti-core.md](cacti-core.md)) |
| 7. Remote collectors | 1,000 to 1,500 | cloudflare's collector protocol; core `replicate_out` and `remote_agent` ([cacti-core.md](cacti-core.md)); highest uncertainty | Decision #17; a Cacti remote collector test bed; `remote_poller:1` and `remote_collect:1`; the phase 4 security review |
| **Phases 1 to 4 total** | **11,200 to 17,500** | | |

Tests at one to two times production lines, as in cloudflare (1.94) and smokeping (0.76); the BMC fixture and fault-injection server is test code that counts toward this.

## Uncertainty

- Milestone 2 carries the most design work: two normalizers, per-reading source selection ([vendor-matrix.md](vendor-matrix.md)), and the sensor rules in [sensors.md](sensors.md). More families than the four read here raise it.
- The BMC behavior numbers (`L`, session limits, rate limits) are *live* and can move the collector design in milestone 2 and the performance targets in milestone 3.
- If connection reuse is adopted ([transport.md](transport.md)), the transport grows beyond plugin_cloud's socket client.
- Milestone 5 is the least grounded.
- Phases need not be re-ordered by anything found in milestone 1. Two dependencies are tightened: milestone 3's per-index objects depend on the live spike (#29), and milestone 2's client depends on at least one live session test (#22).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #30 | Re-run the line counts at milestone 2 start | Drift in the basis |
| #30 | Confirm reviewer availability and fixture access with the maintainer | The gates above |
