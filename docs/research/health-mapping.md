# Fan and power supply health, absence and redundancy

Research for [#25](https://github.com/somethingwithproof/plugin_redfish/issues/25), 2026-11-15. Question: how does each family report a failed, absent or degraded fan or power supply, and what mapping to `health_code`, `fans_bad`, `psus_bad` and absent counts gives alerts operators expect?

**Result.** The standard says how absence should look (a resource that stays with `Status.State` `Absent`, with the removed component's properties null or omitted), and OpenBMC's source implements exactly that; but every vendor capture read here shows only healthy hardware (all 41 and 38 Dell sensors are `OK` and `Enabled`, every fan and supply is `OK`). Failure shapes for Dell, HPE and Lenovo are therefore *unverified* and need a pulled fan or supply on real hardware. A mapping is proposed below from the standard and the OpenBMC source, with the unverified cases marked. One shape worth designing for appears in a Dell capture: the iDRAC10 `PowerSubsystem` reports `Status.Health` `Warning` while both supplies report `OK`, so node health must be built from members, not from a subsystem rollup.

## Facts

| Fact | Status | Source |
|---|---|---|
| `Status.Health` is `OK`, `Warning`, `Critical`; `State` has 13 values; a removable component's resource "should exist and should return the `Absent` value for the `State` property", with unknown properties null or omitted and subordinate resources not populated | confirmed | `Resource.json`; DSP0266 1.23.0 section 9.11.2 |
| The spec warns that when an absent resource is populated again it "may represent a different configuration or physical item" | confirmed | same section |
| OpenBMC: `Status.Health` is `Critical` when D-Bus `OperationalStatus.Functional` is false, otherwise `OK`; `Warning` is never produced by this code. `Status.State` is `Absent` when `Inventory.Item.Present` is false (takes priority), `UnavailableOffline` when `State.Decorator.Availability.Available` is false, otherwise `Enabled`; with no such interface it defaults to `Enabled` | confirmed (source) | [resource_utils.hpp](https://github.com/openbmc/bmcweb/blob/c9ba80ef3d4ea680662af14856f333534a5ac56f/redfish-core/include/utils/resource_utils.hpp#L29-L192) |
| Lenovo XCC2 `Fan.Status.State` is `Enabled`, `Disabled` or `Absent`; `Health` is `OK`, `Warning` or `Critical`; sensor `Health` may be `null` | confirmed (docs) | [Fan](https://pubs.lenovo.com/xcc2-restapi/fan_properties_get), [Sensor](https://pubs.lenovo.com/xcc2-restapi/resource_sensor_sensor_properties_get) |
| Dell iDRAC9 and iDRAC10 captures: every sensor, fan and supply `Health` `OK`, `State` `Enabled` | confirmed (healthy only) | both mockups |
| Dell iDRAC10 `PowerSubsystem.Status` is `{State: Enabled, Health: Warning}` while `PSU.Slot.1` and `PSU.Slot.2` are `OK`; its `PowerSupplyRedundancy` entry is `RedundancyType` `NotRedundant`, `Status` `{Health: OK, State: Disabled}`. Why the subsystem is `Warning` is not stated in the capture | confirmed (capture); cause unverified | `R770_iDRAC10_1_10_17_00_mockup_client`, `PowerSubsystem/index.json` |
| Dell legacy `Power.Redundancy[]` ("PSU Redundancy", `Mode` `N+m`, `MinNumNeeded` 2, `Status` `{OK, Disabled}`); legacy `Thermal.Redundancy[]` "System Board Fan Redundancy" `{OK, Enabled}` on iDRAC9 with an empty `RedundancySet` | confirmed (capture) | both mockups |
| Dell `ThermalSubsystem.FanRedundancy[]` lists six fans in an `NPlusM` group with `MinNeededInGroup` null; DMTF's mockup sets `MinNeededInGroup` 1 and `MinNeededForFaultTolerance` 2 | confirmed (captures) | iDRAC10 and DMTF mockups |
| Dell iDRAC9 `PowerSupply.LineInputStatus` and iDRAC10 `PowerSupply.LineInputStatus` are `Normal`; a loss of AC input could be visible there before health changes | observed healthy only; failure value unverified | `PowerSubsystem/PowerSupplies/*` |
| HPE iLO 6 absent and failed shapes (`Status.State`, OEM redundancy properties) | unverified | docs were read for resource lists only |

## Proposed mapping

Input is each fan or supply member after discovery. `member_code` is 0 OK, 1 Warning, 2 Critical, or unknown (null, stored as `U`). A member is counted once per node; dual-rotor fans count as one fan with the worse rotor.

| `Status.State` | `Status.Health` | Result | Counted in |
|---|---|---|---|
| `Absent` | any, null | absent; no value, no health | `fans_absent` or `psus_absent`; not in `*_bad` |
| `Enabled` | `OK` | 0 | none |
| `Enabled` | `Warning` | 1 | `fans_bad` or `psus_bad` |
| `Enabled` | `Critical` | 2 | `*_bad` |
| `Enabled` | null or missing | unknown | `*_unknown`; never `OK` |
| `Degraded` | any | at least 1 | `*_bad` |
| `UnavailableOffline`, `StandbyOffline`, `Deferring`, `Quiesced`, `Updating`, `Qualified`, `InTest`, `Starting`, `StandbySpare`, `Disabled` | any | unknown, with the state stored | `*_unknown` |
| member missing from the listing but present in the previous run | | `member_missing` event | `*_missing`; not hidden |

Node `health_code` is the maximum of member codes and the chassis' own `Status.Health` if present; an unknown member does not raise it above 0 but sets a separate `health_unknown` count so Thold can alert on it. This is a policy, to be reviewed: treating `UnavailableOffline` as unknown follows from not having seen what any vendor returns for a failed fan. Redundancy: the plugin records `Redundancy` and `FanRedundancy` / `PowerSupplyRedundancy` status when present and exposes `redundancy_lost` as a separate value from member failures; it does not derive redundancy from member counts, because Dell shows `NotRedundant` with all members healthy.

A `fans_bad` value counts members whose health is not OK. The plan's summary table says "absent members counted separately", which this mapping keeps. Absent versus failed is the distinction operators care about: a supply that was never fitted must not page.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #25 | On a lab server per family, pull a supply, then a hot-plug fan, and capture `Chassis`, `PowerSubsystem`, `ThermalSubsystem`, legacy `Power` and `Thermal` before and after | Every unverified row above; whether the member disappears, becomes `Absent`, or reads null |
| #25 | Unplug one AC feed with both supplies present | `LineInputStatus`, redundancy status, health rollups |
| #25 | Record the list of `Status.Health` and `Status.State` values seen per family | Mapping table completeness |
| #25 | Find out why Dell iDRAC10 `PowerSubsystem` is `Warning` with healthy members | Whether subsystem health should feed node health |

Cases that cannot be captured get "unverified" in the plan's compatibility section.
