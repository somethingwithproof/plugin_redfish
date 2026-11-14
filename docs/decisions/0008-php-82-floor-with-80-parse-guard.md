# 0008. Require PHP 8.2, keep entry points parseable on PHP 8.0

Status: Accepted (2026-11-14)

## Context

The Cacti `1.2.x` branch declares `"php": ">=8.2"` in `composer.json`. The released 1.2.31 declares `>=8.0`, so a 1.2.31 site can run PHP 8.0 or 8.1. Cacti includes plugin files on every request, so a parse error in a plugin breaks the whole Cacti UI.

## Decision

- The plugin requires PHP 8.2 and may use language features through 8.2. Nothing from 8.3 or later.
- `setup.php` and every file Cacti includes before the version check use only PHP 8.0 syntax.
- Install and `check_config` refuse when PHP is older than 8.2; hook shims return early before the autoloader is registered.
- CI lints those entry points under PHP 8.0.

## Consequences

- Unsupported runtimes get a clear refusal instead of a broken Cacti.
- Two syntax levels exist in one codebase; the boundary is enforced by CI.

## Alternatives rejected

- **PHP 8.0 or 8.1 floor.** The 1.2.x branch no longer supports them.
- **8.2 everywhere without a guard.** A 1.2.31 site on an older PHP would lose its whole UI when the plugin is enabled.
