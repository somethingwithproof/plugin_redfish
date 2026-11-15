# Cacti core behavior

Research for [#21](https://github.com/somethingwithproof/plugin_redfish/issues/21), 2026-11-15. Question: does each Cacti core row in the plan hold at `release/1.2.31` and on the current `1.2.x` branch, and which capability set must `INFO` declare?

**Result.** All 19 functions the plan names or relies on have the same signature at both refs, and every hook the plan uses is called by core at both refs. Four plan statements need correction: hook and realm registration must be called from a function whose name contains `install`, `upgrade` or `setup`; `poller_bottom` and `poller_output` are gated by different capabilities on remote pollers; a device template has no availability field; and `1.2.x` has five secret helpers, not three. The plan's Maint statement is also corrected (no caller exists for Maint's hook). Aggregate graphs cannot be thresholded through Thold's graph button (source read; not run). Nothing here was executed: this milestone used source only, so every row is "read", not "ran". Facts already established for plugin_cloud ([cacti-core.md](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/research/cacti-core.md)) were re-checked against the refs below and are marked as such.

## Refs used

| Name | Ref | Commit |
|---|---|---|
| release/1.2.31 ("R") | `refs/tags/release/1.2.31` (annotated tag object `0b3c7c5b933ace46bafe34678fa88ced3c3b4a2f`) | `1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1` |
| 1.2.x ("B") | `refs/remotes/origin/1.2.x` | `e2d5c25893221c3cd54ceb8293fba6ca38c936e6`, `include/cacti_version` 1.2.32 |
| plugin_thold | `develop` | `dc76952ee52e8b4877b2d5b830caab1f7c43dde9` |
| plugin_maint | `develop` | `9422c7a4ce9916d0f0edf88b93afb194e0675b4a` |

The plan pinned `1.2.x` at `30010fc5ff` (not an ancestor of the current head in the local object store). `git diff --name-only 30010fc5ff origin/1.2.x` lists 8 files, and the only non-test, non-doc one is `lib/boost.php`. None of the files cited below changed between the two, so the plugin_cloud line numbers still hold.

Method: `git show` and `git grep` on the refs, read through another worktree without checking anything out.

## Function signatures

| Function | release/1.2.31 | 1.2.x | Verdict |
|---|---|---|---|
| `api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false)` | [lib/plugins.php:1030](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L1030) | [lib/plugins.php:1218](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L1218) | **confirmed** |
| `api_plugin_register_realm($plugin, $file, $display, $admin = true)` | [lib/plugins.php:1137](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L1137) | [lib/plugins.php:1372](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L1372) | **confirmed** |
| `api_plugin_db_table_create($plugin, $table, $data)` | [lib/plugins.php:461](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L461) | [lib/plugins.php:581](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L581) | **differs** (behavior, see notes) |
| `api_plugin_valid_entrypoint($plugin, $function)` | [lib/plugins.php:1124](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L1124) | [lib/plugins.php:1348](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L1348) | **confirmed** |
| `api_plugin_status_run($hook, $required_capabilities, $plugin_capabilities, $plugin = '')` | [lib/plugins.php:404](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L404) | [lib/plugins.php:514](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L514) | **confirmed** |
| `api_device_save($id, $device_template_id, $description, $hostname, ...)` | [lib/api_device.php:939](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/api_device.php#L939) | [lib/api_device.php:948](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/api_device.php#L948) | **confirmed** |
| `import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false, $import_hashes = array(), $class = '')` | [lib/import.php:25](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/import.php#L25) | [lib/import.php:38](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/import.php#L38) | **confirmed** |
| `run_data_query($host_id, $snmp_query_id, $automation = false, $force = false)` | [lib/data_query.php:25](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L25) | [lib/data_query.php:44](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/data_query.php#L44) | **confirmed** |
| `push_out_host($host_id, $local_data_id = 0, $data_template_id = 0)` | [lib/utility.php:834](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/utility.php#L834) | [lib/utility.php:911](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/utility.php#L911) | **confirmed** |
| `exec_background($filename, $args = '', $redirect_args = '')` | [lib/poller.php:132](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L132) | [lib/poller.php:189](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L189) | **differs** (behavior, see notes) |
| `register_process_start($tasktype, $taskname, $taskid = 0, $timeout = 300)` | [lib/poller.php:2383](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L2383) | [lib/poller.php:2868](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L2868) | **confirmed** |
| `unregister_process($tasktype, $taskname, $taskid = 0, $pid = -1)` | [lib/poller.php:2463](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L2463) | [lib/poller.php:2960](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L2960) | **confirmed** |
| `replicate_out($remote_poller_id = 1, $class = 'all')` | [lib/poller.php:1518](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L1518) | [lib/poller.php:1783](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L1783) | **confirmed** |
| `call_remote_data_collector($poller_id, $url, $logtype = 'WEBUI')` | [lib/functions.php:6380](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L6380) | [lib/functions.php:7340](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/functions.php#L7340) | **confirmed** |
| `read_config_option($config_name, $force = false)` | [lib/functions.php:680](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L680) | [lib/functions.php:704](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/functions.php#L704) | **confirmed** |
| `cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')` | [lib/functions.php:1273](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L1273) | [lib/functions.php:1332](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/functions.php#L1332) | **confirmed** |
| `is_device_allowed($device_id, $user_id = 0)` | [lib/auth.php:853](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/auth.php#L853) | [lib/auth.php:884](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/auth.php#L884) | **confirmed** |
| `get_allowed_devices($sql_where = '', $sql_order = 'description', $sql_limit = '', &$total_rows = 0, $user_id = 0, $device_id = 0)` | [lib/auth.php:2768](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/auth.php#L2768) | [lib/auth.php:2810](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/auth.php#L2810) | **confirmed** |
| `query_script_host($host_id, $snmp_query_id)` | [lib/data_query.php:645](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L645) | [lib/data_query.php:715](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/data_query.php#L715) | **confirmed** |

Notes on the two differing rows, re-checked from the same refs as plugin_cloud:

- `api_plugin_db_table_create()` at R skips an existing table; at B it validates the name against `^[A-Za-z0-9_]+$` and refreshes the schema of an existing table. Migrations must not rely on either.
- `exec_background()` at R concatenates `$filename` unquoted; at B it quotes it. Pass the PHP binary as `$filename` and the script and options in `$args`. `exec_background_process()` exists only at B.

## Hooks the plan uses

| Hook | release/1.2.31 call site | 1.2.x call site |
|---|---|---|
| `config_arrays` | [include/global_arrays.php:2938](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_arrays.php#L2938) | [include/global_arrays.php:2954](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/include/global_arrays.php#L2954) |
| `config_settings` | [include/global_settings.php:2939](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_settings.php#L2939) | [include/global_settings.php:3015](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/include/global_settings.php#L3015) |
| `draw_navigation_text` | [lib/functions.php:3777](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/functions.php#L3777) | [lib/functions.php:4399](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/functions.php#L4399) |
| `poller_bottom (first of 3 call sites)` | [poller.php:864](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/poller.php#L864) | [poller.php:897](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/poller.php#L897) |
| `api_device_save` | [lib/api_device.php:1036](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/api_device.php#L1036) | [lib/api_device.php:1046](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/api_device.php#L1046) |
| `device_remove (first of 2)` | [lib/api_device.php:52](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/api_device.php#L52) | [lib/api_device.php:54](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/api_device.php#L54) |
| `graph_buttons (first of 2)` | [graph.php:161](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/graph.php#L161) | [graph.php:189](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/graph.php#L189) |
| `replicate_out (in replicate_out())` | [lib/poller.php:1783](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L1783) | [lib/poller.php:2048](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L2048) |
| `remote_agent` | [remote_agent.php:107](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/remote_agent.php#L107) | [remote_agent.php:107](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/remote_agent.php#L107) |
| `poller_output` | [lib/poller.php:816](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L816) | [lib/poller.php:1020](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/poller.php#L1020) |
| `device_action_array` | [host.php:51](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/host.php#L51) | [host.php:51](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/host.php#L51) |
| `device_action_prepare` | [host.php:556](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/host.php#L556) | [host.php:591](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/host.php#L591) |
| `device_action_execute` | [host.php:359](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/host.php#L359) | [host.php:394](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/host.php#L394) |
| `device_template_change` | [lib/api_device.php:1403](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/api_device.php#L1403) | [lib/api_device.php:1428](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/api_device.php#L1428) |


Argument shapes (read, unchanged from plugin_cloud's findings): `api_device_save` receives and returns the `host` row array; `device_remove` receives an array of device ids and the return value is ignored; `graph_buttons` is called with `api_plugin_hook()`, so the payload is element `[1]`; `remote_agent` must return truthy once it handled the action, otherwise core prints `Unknown Agent Request`. `run_data_query` is a hook core calls after a re-index ([lib/data_query.php:119](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L119)); the plan does not use it and it could be a place to refresh the plugin's own cache after a re-index.

## Plan rows

| Plan statement | Evidence | Verdict |
|---|---|---|
| `host` has `poller_id`, `site_id`, `location varchar(40)`, `external_id`, SNMP columns as `varchar` | `cacti.sql` `CREATE TABLE host` at both refs: `poller_id int(10) unsigned default 1`, `site_id`, `location varchar(40)`, `external_id varchar(40)` with an index, `snmp_community varchar(100)`, `snmp_password varchar(50)`, `snmp_priv_passphrase varchar(200)` | **confirmed**. `external_id` is an unused-by-core 40 character key that could hold a BMC mapping key for decision #11 (inference) |
| `sites` has no rack column; `graph_tree_items` references `host_id` or `site_id` | `cacti.sql` at both refs: `sites` columns are name, address1/2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes; `graph_tree_items` has `host_id` and `site_id` | **confirmed** |
| `AVAIL_NONE` is 0 so a device can have no availability check | R [include/global_constants.php:139](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/include/global_constants.php#L139); B :145. `Ping::ping()` returns early for it (R lib/ping.php:639, B :751) | **confirmed**, with the plugin_cloud correction below |
| "Objects with no network address can live on a template-marked device" (availability) | `host_template` holds only `id, hash, name, class` at both refs; availability is `host.availability_method smallint unsigned default 1`. `cli/add_device.php --avail=none` stores `'0'` (R :196-199) | **wrong as worded.** A device template cannot carry availability. Set `availability_method` to 0 through `api_device_save()` on each BMC device. Re-checked, same as plugin_cloud |
| Aggregate constants `AGGREGATE_TOTAL_TYPE_SIMILAR` and `_ALL` | R include/global_constants.php:334-335, B :340-341 | **confirmed** |
| Aggregates cannot be thresholded | plugin_thold `setup.php` lines 368-376 hide the "Create Threshold" graph button when `aggregate_graphs` has a row for the graph ([setup.php](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/setup.php#L368)); its CHANGELOG records "issue#283: Do not show 'Threshold Create' icon on the Graphs page for Aggregates". Thold thresholds attach to a data source | **confirmed by source for the button; not run.** A threshold created by other means on an aggregate is not tested. Decision #16 can rely on "rack totals need a real data source to be alertable" |
| `poller_output` reaches Thold through `api_plugin_hook_function('poller_output', $rrd_update_array)` | R lib/poller.php:816, B :1020; Thold registers `thold_poller_output` | **confirmed**. On a remote poller the hook runs only for plugins declaring `remote_collect:1` |
| `remote_agent.php` dispatches unknown actions to the `remote_agent` hook after address-based authorization | R/B remote_agent.php:107. At B `remote_client_authorized()` authorizes on `$_SERVER['REMOTE_ADDR']` and not on forwarded headers (comment cites GHSA-h96j-8xhc-2p3w) | **confirmed, refined.** The plan says "address, or reverse DNS name"; at B the address is the TCP peer. A proxy in front of a collector must be listed as the poller. Not re-read in full for the reverse DNS branch |
| `call_remote_data_collector()` rejects URLs with `://`, `@`, `../` or not starting with `/` | R lib/functions.php:6400-6404 (plugin_cloud), same body at B :7360 | **confirmed** (re-checked, not re-read line by line) |
| No core secret-encryption helper at 1.2.31 | `git grep` at R for `openssl_encrypt`, `openssl_decrypt`, `openssl_public_encrypt`, `openssl_private_decrypt`, `openssl_seal`, `sodium_crypto*` outside `include/vendor` and tests: no matches. The plan says the search found "only the rrdtool proxy RSA helpers"; that was **not reproduced** with these function names (it may refer to other function names; unverified) | **confirmed for the conclusion;** the side remark is unverified |
| 1.2.x defines `cacti_secret_key()`, `cacti_encrypt_secret()`, `cacti_encrypt_secret_with_key()` | B lib/functions.php:9973, 10008, 10044; it also defines `cacti_decrypt_secret()` (:10022) and `cacti_decrypt_secret_with_key()` (:10073). AES-256-GCM, key in setting `secret_encryption_key` | **differs:** five functions, not three. The decrypt pair matters to any plugin that reads secrets |
| `composer.json` `php` | R `>=8.0`, B `>=8.2` (plugin_cloud, re-checked) | **confirmed** |

## Capability gating and the INFO line

Read at R lib/plugins.php:193-198, 255-256 and 404-440, and B :235-240, 308-309 and 514-540; the gating applies only when `$config['poller_id'] > 1` (R :186).

| Hook | Required capability on a remote poller | Source |
|---|---|---|
| `poller_bottom` | `remote_poller` | R :194, B :236 |
| `poller_top`, `poller_output`, `update_host_status`, `poller_finishing`, `poller_exiting` | `remote_collect` | R :193-198, B :235-240 |
| `graph_buttons`, `page_head`, `top_header_tabs` | `online_view` or `offline_view` | R :201-206 |
| `device_action_array`, `data_source_action_array`, `graphs_action_array` | `online_mgmt` or `offline_mgmt` | R :264-266 |
| `config_settings`, `config_arrays`, `draw_navigation_text`, `api_device_save`, `device_remove`, `replicate_out`, `remote_agent` | not listed. Unlisted hooks are not passed to `api_plugin_status_run()`: through `api_plugin_hook_function()` ([R lib/plugins.php:249-292](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L249-L292)) they run unconditionally; through `api_plugin_hook()` ([R :184-247](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L184-L247)) they run when the connection is `online`, or when the plugin declares an `offline_*` capability | read |

Consequences for the plan:

1. Phases 1 to 3 declare `remote_collect:0` and no `remote_poller`. The gated hooks, including `poller_bottom` (the collector) and `poller_output`, do not run on a remote poller; hooks not in the gating lists (settings, device hooks) do run there. No collection happens for devices assigned to a remote poller, and script server polling there would read a local database this plugin does not populate (inference, not run). Topology 1 in the plan ("the collector runs on the poller that Cacti assigns to the device") is only true for the main poller (`poller_id` 1). **Plan correction:** phases 1 to 3 support BMC devices on the main poller only; remote pollers are phase 4.
2. Phase 4 needs `remote_poller:1` for `poller_bottom` and `remote_collect:1` for `poller_output`, and Maint declares `remote_collect:0`.
3. INFO capability set decided for phases 1 to 3: `online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0`, as the plan states. Phase 4 adds `remote_poller:1, remote_collect:1` after review.

## Registration entrypoints

`api_plugin_valid_entrypoint()` ([R lib/plugins.php:1124](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/plugins.php#L1124), [B :1348](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/plugins.php#L1348)) rejects `api_plugin_register_hook()` and `api_plugin_register_realm()` unless the function that directly calls them has `install`, `upgrade` or `setup` in its name. **Plan correction:** register from `plugin_redfish_install()` and `plugin_redfish_upgrade()` (or a function with one of those substrings), not from a class method such as `HookRegistry::register()`. Re-checked from plugin_cloud; the same code at both refs.

## Maint

Maint registers `is_device_in_maintenance` but no caller of that hook exists in core at either ref; Thold and core call `plugin_maint_check_cacti_host()` directly after `api_plugin_is_enabled('maint') && function_exists(...)`. Maint declares `remote_collect:0`. Re-checked from plugin_cloud (maint `9422c7a`, thold `dc76952`; both `develop` heads were unchanged on 2026-11-15). The plan's alerting section says "Thold thresholds and Maint schedules apply with no change to either plugin". That stays true, but the plugin does not call Maint itself, and Maint suppresses notifications while collection continues.

## Script server and data query

Re-checked from plugin_cloud: the script server reads `<include file> <function> <params>`, includes once and calls the function; the file and the function's source must resolve under `base_path` (R script_server.php:264-290, 351-362; B adds `scripts_path` through `script_server_path_is_allowed()` at :793). A data query's index and query steps do not use the script server: `query_script_host()` ([R lib/data_query.php:645](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L645)) rewrites the path to `"<php binary>" -q <script>` and runs it with `exec_into_array()` (R :658, :676, :701, :730). So the plugin's script file needs both a CLI entry (`index`, `query <field>`, `get <field> <index>`) and the `ss_` function. The plan's "Script server input" row should say so. Data source names are `varchar(19)` matching `^[a-zA-Z0-9_]{1,19}$` (plugin_cloud, re-checked); the plan's per-index DS names must fit.

## Not verified here

| Item | Why | Needs |
|---|---|---|
| Throwaway plugin on 1.2.31 and 1.2.x registering `poller_bottom`, a script server query and a data source | Source-only milestone | *live* (Cacti runtime, no BMC needed) |
| Whether Thold can be forced to create a threshold on an aggregate | Only the UI button was read | *live* (Cacti with Thold) |
| `remote_collect`/`remote_poller` behavior with a real remote collector | Source read only | *live* |
| Thold and Maint behavior with this plugin's data | Not installed | *live* |

## Plan corrections

1. Compatibility, lifecycle bullet: add the entrypoint-name rule.
2. Compatibility, background work: add the `exec_background()` argument rule.
3. Plugin metadata: replace "milestone 1 confirms which capability names" with the table above; phases 1 to 3 are main-poller only.
4. Topologies, topology 1: restrict to the main poller until phase 4.
5. Cacti objects: availability is set per device, not by template.
6. Cacti core table: `1.2.x` secret helpers are five functions; the "rrdtool proxy RSA helpers" remark was not reproduced.
7. Script server row: the CLI entry point requirement.
