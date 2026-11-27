<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Audit

This document records the algorithmic audit and PHP runtime profiling performed on `plugin_redfish` under PHP 8.4.25 (CLI, 512 MiB memory limit, Apple Silicon arm64) using `hrtime(true)` wall-clock timers and `memory_get_peak_usage(true)` peak RSS measurements at fleet scale (10,000 to 15,000 BMCs and 120,000+ sensor readings).

## Bottlenecks Found and Fixed

1. **`NodeCache::membersOf()` and `NodeCache::memberAt()` (`lib/NodeCache.php`, `lib/MemberQuery.php`, `lib/SummaryScript.php`, `lib/IndexKey.php`)**
   - **Root cause:** `membersOf()` filtered all members and ran `usort()` invoking `IndexKey::of()` (which called `preg_match()`) on every comparison ($O(M \log M)$ regexes per call). `memberAt()` scanned all members linearly ($O(M)$) calling `IndexKey::of()`. `MemberQuery::answer()` also called `membersOf()` before dispatching `'get'` requests, making per-sensor polling $O(M^2 \log M)$.
   - **Fix:** Added lazy per-kind grouping (`membersOfUnsorted()`), Schwartzian-transformed cached sorting in `membersOf()`, and an $O(1)$ per-kind index hash map in `memberAt()`. Updated `MemberQuery::answer()` to dispatch `'get'` and `'num_indexes'` without sorting, updated `SummaryScript` to use `membersOfUnsorted()`, and replaced hot-path PCRE checks in `IndexKey::of()` and `IndexKey::plausible()` with `strspn()`.

2. **`ThermalDeltaAnalyzer::extractSamples()` and `analyze()` (`lib/ThermalDeltaAnalyzer.php`)**
   - **Root cause:** `extractSamples()` invoked sorted `membersOf('temperature')` on every BMC and ran regexes across all temperature sensors even after both inlet and exhaust sensors were found. `analyze()` duplicated `$inlets`, `$deltas`, and `$parsed` arrays and ran a separate standard-deviation pass.
   - **Fix:** Switched `extractSamples()` to `membersOfUnsorted('temperature')` with fast `str_contains()` pre-filtering and early loop exit once both sensors are resolved. Updated `analyze()` to compute online Welford variance in a single pass, sort vectors in place, and remove the intermediate `$parsed` copy.

3. **`Redactor::add()` (`lib/Redactor.php`)**
   - **Root cause:** Every `add()` call checked duplicates with linear `in_array()` and re-sorted the full secret list with `usort()`, yielding $O(K^2 \log K)$ cost over $K$ secrets. Sorting by length was redundant because `clean()` collects all match spans and merges overlapping intervals after sorting spans.
   - **Fix:** Stored known secret forms in an `array<string, int>` map (`form => byte length`) for $O(1)$ deduplication and $O(K)$ registration.

4. **`Discoverer::discover()`, `Discoverer::members()`, `Member::key()`, and `Observation::merge()` (`lib/Discoverer.php`, `lib/Member.php`, `lib/Observation.php`)**
   - **Root cause:** `Discoverer::discover()` copied accumulated targets via `array_merge()` in the chassis loop; `Discoverer::members()` deduplicated paths with `in_array()`; `Member::key()` allocated a closure and ran `preg_replace()` twice even on clean ASCII identifiers; `Observation::merge()` recomputed reading composite keys per item.
   - **Fix:** Replaced loop `array_merge()` with `array_push()`, switched `members()` deduplication to a hash set, added a `strcspn()` fast path in `Member::key()`, and copied already-keyed maps directly in `Observation::merge()`.

## Before and After Benchmarks

Measured with `hrtime(true)` and `memory_get_peak_usage(true)` on PHP 8.4.25 (exact same synthetic fleet workload before and after fixes):

| Workload | Before (ms) | After (ms) | Speedup | Peak RSS Before | Peak RSS After |
| --- | ---: | ---: | ---: | ---: | ---: |
| `NodeCache` `membersOf` + `memberAt` (200 BMCs x 60 sensors, 12,000 gets) | 2,775.25 | 12.05 | 230.3x | 24.00 MiB | 24.00 MiB |
| `SummaryScript::values()` (10,000 BMCs) | 4,566.13 | 244.77 | 18.7x | 24.00 MiB | 24.00 MiB |
| `ThermalDeltaAnalyzer::extractSamples()` (15,000 BMCs) | 3,261.88 | 19.53 | 167.0x | 30.00 MiB | 30.00 MiB |
| `ThermalDeltaAnalyzer::analyze()` (15,000 BMCs) | 12.58 | 11.32 | 1.11x | 46.00 MiB | 40.00 MiB |
| `Normalizer::normalize()` + `Observation::merge()` (10,000 BMCs, 120,000 sensors) | 326.02 | 309.98 | 1.05x | 46.00 MiB | 40.00 MiB |
| `Redactor::add()` (1,500 secrets) + `clean()` (500 calls) | 2,673.85 | 73.06 | 36.6x | 46.00 MiB | 40.00 MiB |

## Complexity Summary

- Per-sensor script-server lookup (`MemberQuery` + `NodeCache`): $O(M^2 \log M) \rightarrow O(M)$ amortized ($O(1)$ per `get`).
- Order-independent node summary and thermal extraction: $O(B \cdot M \log M) \rightarrow O(B \cdot M)$ with zero regex work on clean keys and early termination.
- Secret registration in `Redactor`: $O(K^2 \log K) \rightarrow O(K)$.
- Fleet thermal analysis peak memory at 15,000 BMCs: reduced by 6.00 MiB (-13.0%) by eliminating intermediate array copies.
