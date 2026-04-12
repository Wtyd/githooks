# githooks conf:migrate

Migrate a v2 configuration file (Options/Tools format) to v3 format (hooks/flows/jobs).

## Synopsis

```
githooks conf:migrate [--config=PATH]
```

## What it does

- Creates an automatic backup (`.v2.bak`) of the original file.
- If the source is YAML, converts to PHP and removes the `.yml` file.
- Converts `script` tool to `custom` job type.
- Drops `usePhpcsConfiguration` (not supported in v3).
- Generates a v3 file with a `qa` flow containing all the original tools as jobs.

## Examples

```bash
githooks conf:migrate
githooks conf:migrate --config=qa/githooks.php
```

!!! note
    The generated v3 file may need manual review. Check flow names, hook mappings, and job configurations after migration.

## See also

- [Migration: v2 to v3](../migration/v2-to-v3.md) — complete migration guide.
- [`githooks conf:check`](conf-check.md) — validate the migrated file.
