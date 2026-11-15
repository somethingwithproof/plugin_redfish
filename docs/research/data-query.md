# Data query for variable fans, sensors and supplies

Research for [#29](https://github.com/somethingwithproof/plugin_redfish/issues/29), 2026-11-15. Question: can one script server data query present a variable number of fans, temperature sensors and power supplies per device, keep indexes stable across firmware updates and reboots, and build graphs through Cacti's normal APIs at 1,000 devices?

**Result.** The spike itself (a Cacti run with fixture caches at 4, 8 and 16 fans, and script server latency at 1,000 devices) was not done: this milestone was source only, and it needs a Cacti instance (*live*). From Cacti's source at `release/1.2.31` and `1.2.x`, the re-index behavior is now known, and it fixes the stable-key rule: Cacti keeps each data source keyed by the value of the query's `index_order` field and re-maps or orphans data sources by that value, so the field must be a vendor member identifier, not a position. A removed member leaves its data source and RRD file in place and flags it orphaned only transiently, and its poller entry keeps being polled unless the query XML asks for removal. The Dell captures show why a per-fan identity needs care: the same hardware has 14 fans in one generation and 6 in another, and 14 or 12 sensors with different ids. A single query for three kinds of object may offer graph types for the wrong kind; three queries are the safer starting point, to be tested in the spike.

## Pattern to copy

plugin_cloudflare at [`c08c99b`](https://github.com/somethingwithproof/plugin_cloudflare/tree/c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0): [templates/resource/cloudflare_tunnels.xml](https://github.com/somethingwithproof/plugin_cloudflare/blob/c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0/templates/resource/cloudflare_tunnels.xml) declares `<script_server>php</script_server>`, `<arg_prepend>|host_id|</arg_prepend>`, `<arg_index>index</arg_index>`, `<arg_query>query</arg_query>`, `<arg_get>get</arg_get>`, `<arg_num_indexes>num_indexes</arg_num_indexes>`, `<output_delimiter>!</output_delimiter>`, `<index_order>tunnelId</index_order>`, `<index_order_type>alphabetic</index_order_type>`, input fields (id, name) and output fields. Its entry file [ss_cloudflare.php](https://github.com/somethingwithproof/plugin_cloudflare/blob/c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0/ss_cloudflare.php) has a CLI branch (`include cli_check.php`, `call_user_func_array('ss_cloudflare', argv)`) and a script server branch, matching the dual entry requirement in [cacti-core.md](cacti-core.md). [includes/script_server.php](https://github.com/somethingwithproof/plugin_cloudflare/blob/c08c99bbdfd0cea5fbd0da22dbde43bdb91ec4f0/includes/script_server.php) answers `index`, `num_indexes`, `query <field>` and `get <field> <index>` from a database cache; `query` returns `id!value` lines, output fields return `U` at query time, and any failure returns `U` for `get` and an empty string for listings. That is the shape the plan describes.

## Re-index behavior (source read)

`run_data_query()` ([release/1.2.31 lib/data_query.php:25](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L25), [1.2.x :44](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/data_query.php#L44)):

| Behavior | Evidence (R lines; B has the same logic at about +19 lines) |
|---|---|
| Indexes come from running the script as a CLI process (`index` then `query <field>`) | `query_script_host()` :645, `exec_into_array()` :676, :701, :730 |
| The "sort field" is the `index_order` field; it is cached in `host_snmp_cache`; if it changes between runs Cacti logs "Sort Field has Changed ... Re-mapping issues may occur!" | :134-165 |
| Each existing data source stores its index value in `data_input_data` (`index_value`); at re-index Cacti looks that value up in `host_snmp_cache` under the sort field | :171-205 |
| Value found with a different `snmp_index`: `data_local.snmp_index` is updated and the data source is kept (`changed_ids`) | :284-296 |
| Value found and the data source was orphaned: `orphan` is reset to 0 and the data source is pushed out again | :299-305 |
| Value missing: `data_local.orphan` is set to 1 and the data source is kept; with `<index_transient>true</index_transient>` in the query XML the removal is treated as expected and nothing is done | :306-322 (`index_transient` at :308) |
| The orphan flag is transient: on the next re-index in which no index changes, `data_local.orphan` is reset to 0 for every data source of the query and device (:385-403). `<index_orphan_removal>true</index_orphan_removal>` makes core call `data_query_remove_disabled_items()` for the orphaned ids, which deletes their `poller_item` and `poller_output_boost` rows and boost archive rows; it does not delete the data source row or the RRD file (:405-420, :440-486). With the option unset the `poller_item` stays, so the data source keeps being polled | :385-486 |
| After re-index, titles are refreshed, then `run_data_query` hook is called on the main poller | :349-361, :73, :119 |

**Stable index key rule (proposed).** The `index_order` field is a collision-free identity for the member: the chassis `Id`, the kind, and the member `Id`, for example `System.Embedded.1/fan/CoolingController.1.Fan1`, `System.Embedded.1/psu/PSU.Slot.1`, `System.Embedded.1/sensor/SystemBoardInletTemp`; Lenovo `1/fan/Fan_1A_Tach`, `1/sensor/49L0`; DMTF `1U/fan/Bay1`. A bare member `Id` is not enough: DSP0266 1.23.0 section 9.6.6 says `Id` is unique only within the resource collection that contains it, so two chassis can each have a fan `Id` of `1`. Other reasons: section 12.3 says URIs and array indexes "should remain constant" across resets and minor configuration changes; Cacti re-maps by this value; a position-based key (legacy `MemberId` `0`..`13`) would silently re-assign graphs if a vendor reorders. The key is normalized to characters safe in `|query_*|` substitution and the `!` delimiter, and its length is checked against the `data_input_data` value column (not read). Dual-rotor fans are one index with two output fields, so a rotor count change is not an index change.

**Known behaviors to document (from the source above, not run):**

1. A firmware update that renames a member `Id` makes the old index go missing. Core flags its data source orphaned during that pass, keeps the data source row and RRD file, and (with `index_orphan_removal` unset) keeps its `poller_item`, so the data source keeps being polled: the plugin's `get` must return `U` for an index that is not in the cache, and must never reuse a retired key for a different member, or the old graph would plot another member's values. The new `Id` appears as a new index to graph, and the old graph shows a gap. The orphan flag clears on a later pass with no index change, so it is not a durable marker. The plan should leave `index_orphan_removal` unset in the first release, so history is not destroyed, and should track retired keys in its own table (proposal). Not run.
2. `index_transient` may suit fans and supplies that can be hot-removed, but it hides a failed-and-removed component from re-index; health is the plugin's own data and does not depend on this.
3. A Redfish generation change on the same BMC (for example iDRAC9 firmware gaining or losing the subsystem tree) changes fan identities from `Fan.Embedded.1A` (sensor) or position to `CoolingController.1.Fan1`; indexes would all orphan and re-create. Key from one generation per BMC and record the generation in the BMC row; changing it is an explicit operator step. Not tested.

## Object counts

From the Dell captures (sensors by `ReadingType`): iDRAC9 R660xs has 14 `Rotational`, 5 `Temperature`, 5 `Power` sensors (41 total; the rest are voltage, current, frequency, percent, airflow); iDRAC10 R770 has 12 `Rotational`, 5 `Temperature`, 5 `Power` (38 total). Counting the plan's units: iDRAC9 new-model fans 14, temperatures 5, supplies 2, plus 1 summary: 22 data sources and graphs per node (22,000 at 1,000 nodes); iDRAC10 fans 6 (two rotors in one), temperatures 5, supplies 2, plus 1: 14 per node (14,000). The plan's 17 per node (8 fans, 6 sensors, 2 supplies and 1 summary) is within this range. A temperature filter (not every `Temperature` sensor is useful) reduces graphs; the plan should state which sensors are graphed by default (inlet, exhaust, CPU) and let the rest be optional. Templates: three data templates (fan, temperature, supply), three graph templates, three data queries or one, plus the summary data template and graph template: 8 to 10 template objects total (proposal).

## Exit criteria status

| Criterion | Status |
|---|---|
| Spike code and results; stable key rule decided | rule decided from source; spike not run |
| Known re-index behavior documented | documented above from source; run *live* |
| Template count and object count per device in the plan | proposal above |

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #29 | Build the query and templates against fixture caches with 4, 8 and 16 fans; add and remove sensors between runs; run `poller_reindex_hosts.php` | The re-index table above on a real instance |
| #29 | Rename a sensor `Id` between runs and look at the RRD files and graphs | Orphan behavior and history preservation |
| #29 | One query with three kinds versus three queries: open "New Graphs" and try the graph types | Whether the plan's single query is workable |
| #29 | Time 1,000 `get` calls per second from the cache with 1,000 devices through the script server | Script server latency |
| #29 | Create graphs for 1,000 devices with `add_graphs.php` and measure | Creation time at scale |
