# githooks system:info

Show detected CPU count and current `processes` configuration.

## Synopsis

```
githooks system:info [--config=PATH] [--format=text|json]
```

## Options

| Option | Description |
|---|---|
| `--config=PATH` | Path to configuration file. |
| `--format=text\|json` | Output format: `text` (default, human-readable) or `json` (machine-readable, clean stdout). An unknown value warns on stderr and falls back to `text`. |

## Output

- **CPU count** detected on the system.
- **Processes** configured in the configuration file.
- **Warning** if `processes` exceeds available CPUs.

### JSON payload

```json
{
  "version": 1,
  "cpus": 8,
  "processes": 4,
  "warning": null
}
```

`processes` is `null` when no usable configuration is found. `warning` is `null`
unless `processes` over-subscribes the available CPUs.

## Examples

```bash
githooks system:info
githooks system:info --config=qa/githooks.php
githooks system:info --format=json
```

## See also

- [Configuration: Options](../configuration/options.md) — the `processes` option.
- [How-To: Parallel Execution](../how-to/parallel-execution.md)
