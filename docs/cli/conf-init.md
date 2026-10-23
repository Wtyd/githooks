# alfil conf:init

Generate the configuration file `alfil.php`.

## Synopsis

```
alfil conf:init [options]
```

## Interactive mode (default)

When run interactively, `conf:init` detects QA tools installed in `vendor/bin/` and guides you through the setup:

1. Shows detected tools and asks which to include.
2. Asks for source directories (comma-separated, default `src`).
3. Asks which hook events to configure (`pre-commit`, `pre-push`, both, or none).
4. Generates a tailored `alfil.php` with the selected tools, paths, and hooks.

```bash
alfil conf:init
```

## Non-interactive mode

With `--no-interaction` (or `-n`), copies a template file with examples of all supported job types.

```bash
alfil conf:init -n
```

## Options

| Option | Description |
|---|---|
| `-n`, `--no-interaction` | Copy template instead of interactive setup. |

## See also

- [Getting Started: Your First Config](../getting-started/first-config.md)
- [`alfil conf:check`](conf-check.md) — validate the generated file.
