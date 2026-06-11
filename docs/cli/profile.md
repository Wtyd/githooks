# githooks profile

Show the trend of a metric across a flow's persisted run history: an ASCII sparkline plus min / p50 / p95 / max and a trend versus the previous half of the window.

`profile` reads the runs persisted under `.githooks/history/`. A run is persisted only when history is enabled — see [Enabling history](#enabling-history).

## Synopsis

```
githooks profile <flow> [options]
githooks profile:list <flow> [options]
```

## Enabling history

History is **opt-in**. A `flow` or `flows` run persists its full JSON v2 payload to `.githooks/history/<timestamp>-<flow>.json` when either:

- `flows.options.history-size: N` is set in config with `N > 0`, or
- the run is invoked with `flow <name> --save-history` / `flows <names...> --save-history`.

The directory keeps the last `N` runs **per flow** (FIFO rotation), where `N` is `history-size` when set, otherwise `100` for `--save-history` runs. `0` (the default) disables persistence. Dry-runs are never persisted. `conf:init` adds `.githooks/history/` to `.gitignore` — the history is local by design and is not meant to travel to the repository (in CI it is ephemeral unless the pipeline caches the directory itself).

The flow name in the filename is the run identifier, so multi-flow runs (`flows a b` → `a+b`) keep their own series.

## `profile <flow>` options

| Option | Description |
|---|---|
| `--job=NAME` | Chart the metric for a single job instead of the whole flow. Not valid with `--metric=peak-cores` (a flow-level metric). |
| `--metric=METRIC` | `time` (default), `peak-memory` or `peak-cores`. `peak-memory` / `peak-cores` are only available for runs recorded with `--stats`; runs without it are reported as unavailable. |
| `--since=YYYY-MM-DD` | Only runs on or after this date. |
| `--last=N` | Only the most recent `N` runs (after `--since`). `N` must be a positive integer. |
| `--format=FORMAT` | `text` (default) or `json`. |

### Example

```console
$ githooks profile qa --job=phpstan-src
phpstan-src · last 30 runs · time
  ▁▃▆▆█▆▃▁▂▃▆█▇▆▅▅▆▆▆▇█▇▆▆▅▅▆▆▆▇
  min: 1.8s · p50: 4.0s · p95: 5.6s · max: 6.1s · trend: ↑ +18.0% vs prev 15
```

`--format=json` emits `{flow, job, metric, unit, count, excluded, values[], min, p50, p95, max, trend, sparkline}` for tooling. `trend` is `null` when there are fewer than two runs to compare.

## `profile:list <flow>` options

| Option | Description |
|---|---|
| `--format=FORMAT` | `text` (default) or `json`. |

Lists the persisted runs for the flow — timestamp, total time, passed and failed — oldest first.

```console
$ githooks profile:list qa
+----------------------------+------------+--------+--------+
| Timestamp                  | Total time | Passed | Failed |
+----------------------------+------------+--------+--------+
| 2026-06-10T09:12:03+00:00  | 9.20s      | 8      | 0      |
| 2026-06-11T08:01:55+00:00  | 9.84s      | 7      | 1      |
+----------------------------+------------+--------+--------+
```

## See also

- [`githooks flow`](flow.md) / [`githooks flows`](flows.md) — `--save-history`.
- [Configuration: Options](../configuration/options.md) — `history-size`.
