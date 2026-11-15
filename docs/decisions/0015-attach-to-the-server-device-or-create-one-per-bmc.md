# 0015. Attach BMC data to the server's Cacti device, or create one device per BMC

Status: Accepted (2026-11-15). Resolves decision issue 11.

## Context

A BMC reports on one server. Operators usually already monitor that server in Cacti over SNMP or an agent, and want its BMC graphs, thresholds, and permissions on the same device. Others have no device for the server, or monitor only the BMC. Cacti's device permissions, graph permissions, trees, Thold thresholds, and Maint schedules all key on the device, so the choice decides who sees the BMC's data and how it alerts. The research for this issue compared mapping onto the server device, always creating a separate BMC device, and leaving the choice to each site; it left the default open.

## Decision

- When the operator maps a BMC to an existing Cacti device, the plugin attaches its data queries and summary graphs to that device and changes nothing else about it.
- Otherwise the plugin creates one device per BMC from the shipped "Redfish BMC" device template, and only after an administrator confirms a plan that lists what will be added (REQ-DEV-002).
- Device creation goes through Cacti's `api_device_save()`. It requires Cacti's device management realm in addition to Redfish Admin. The new device has availability checks off and a host name in the reserved `.invalid` domain, so Cacti never contacts the BMC and the device page never shows the BMC's address. It is polled by the BMC's poller.
- If the creating user's device permissions would hide the new device, the plugin removes it again with `api_device_remove()` and reports why.
- A device carries at most one BMC, because the script server finds the BMC by its device.
- The `device_remove` hook unmaps the BMC of a deleted device. The BMC, its credential, and its cache stay, visible only to administrators until it is mapped again.
- The `api_device_new` hook logs a mapped device moved to another poller than its BMC's. The health page shows the mismatch; the plugin does not move either back.

## Consequences

- BMC data inherits the server device's permissions, trees, thresholds, and maintenance schedules, with no Thold or Maint change.
- A plugin-created device is an ordinary Cacti device. Deleting it never deletes the BMC, and uninstalling the plugin never deletes it.
- A user who may manage Redfish BMCs but not Cacti devices can map a BMC to a device they can see, but cannot create one.
- Mapping a second BMC to a device is refused; a server with two management controllers needs a second device.

## Alternatives rejected

- **Always a separate BMC device.** Splits one server's graphs, permissions, and maintenance across two devices.
- **Map only, never create.** Leaves BMC-only sites with no way to graph or alert without hand-building devices.
- **Create devices automatically on discovery.** Adds Cacti objects without an administrator's confirmation and bypasses device permissions.
- **Write the `host` table directly.** Skips Cacti's own validation, hooks, and poller cache updates.
