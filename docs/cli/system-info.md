# githooks system:info

Show detected CPU count and current `processes` configuration.

## Synopsis

```
githooks system:info [--config=PATH]
```

## Output

- **CPU count** detected on the system.
- **Processes** configured in the configuration file.
- **Warning** if `processes` exceeds available CPUs.

## Examples

```bash
githooks system:info
githooks system:info --config=qa/githooks.php
```

## See also

- [Configuration: Options](../configuration/options.md) — the `processes` option.
- [How-To: Parallel Execution](../how-to/parallel-execution.md)
