# 0014. Name the plugin `redfish` under `Somethingwithproof\Redfish`, with a provisional Pest harness

Status: Accepted, provisional (2026-11-15). Decision issues 12 and 13 stay open.

## Context

The plan and issue 33 named the namespace `Cacti\Redfish`. The repository lives at `somethingwithproof/plugin_redfish` and is not a Cacti project; a `Cacti\` namespace would suggest an affiliation that does not exist. The sibling plugins from the same owner (plugin_cloud and others) use `Somethingwithproof\<Name>`. Issue 13 asks which test harness maintained plugins use; Cacti core's 1.2.x branch locks a Pest harness.

## Decision

- Directory and plugin name `redfish`; tables `plugin_redfish_*`; settings `redfish_*`; text domain `redfish`; log tag `REDFISH`; realms "Redfish View" and "Redfish Admin".
- PHP namespace `Somethingwithproof\Redfish\`, loaded from `lib/` by a small autoloader registered after the PHP version check.
- Tests use Pest from Cacti core's locked harness, run against a separate checkout, with PHPUnit configuration files in the plugin. No Composer runtime dependency.

## Consequences

- Issue 33 and the plan's code structure section say `Cacti\Redfish`; the plan now notes this record, and issue 33's text needs the owner to update it.
- If issue 12 renames the plugin, the slug, tables, and settings move together through a migration; the namespace can change without a data migration.
- If issue 13 picks another harness, the tests move; their assertions do not depend on Pest beyond its `it()` and `expect()` syntax.

## Alternatives rejected

- **`Cacti\Redfish`.** Implies the Cacti project ships or endorses the plugin.
- **No namespace.** Collides with core and other plugins' global functions and classes.
