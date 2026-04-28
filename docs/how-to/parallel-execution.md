# Parallel Execution & Thread Budget

Speed up your QA runs by distributing work across multiple CPU cores.

## Enable parallel execution

Set `processes` in your flow options:

```php
'flows' => [
    'options' => [
        'processes' => 4,  // total CPU cores budget
    ],
    'qa' => ['jobs' => ['phpcs-src', 'phpstan_src', 'phpmd_src', 'parallel_lint']],
],
```

Or override from the CLI:

```bash
githooks flow qa --processes=4
```

## How thread budgeting works

The `processes` value is the **total CPU cores** available, not the number of parallel jobs. GitHooks distributes threads across jobs that support internal parallelism:

| Tool | Internal parallelism flag |
|---|---|
| phpcs / phpcbf | `--parallel` |
| parallel-lint | `-j` |
| psalm | `--threads` |
| paratest | `--processes` |
| phpstan | Worker count from `.neon` config (read-only) |

For example, with `processes: 4` and two threadable jobs (phpcs and parallel-lint), each gets approximately 2 threads.

## Reserve cores explicitly (`cores`)

Sometimes you want a specific job to always get a fixed amount of cores regardless of what the rest of the flow does — typically for paratest workers, phpstan with many workers declared in `.neon`, or custom jobs running their own parallel runner. Declare [`cores: N`](../configuration/jobs.md#reserving-cores-explicitly-cores) on the job:

```php
'jobs' => [
    'phpcs-src' => [
        'type'  => 'phpcs',
        'paths' => ['src'],
        'cores' => 2,   // reserves 2 cores + passes --parallel=2 to phpcs
    ],
    'psalm-src' => [
        'type'  => 'psalm',
        'paths' => ['src'],
        'cores' => 4,   // reserves 4 cores + passes --threads=4 to psalm
    ],
],
```

The allocator reserves the declared amount and, for tools with controllable threading, passes the right flag (`--parallel`, `--threads`, `-j`, `--processes`) automatically. You don't need to remember each tool's specific option.

## Running paratest inside a flow

[Paratest](https://github.com/paratestphp/paratest) is a parallel driver for PHPUnit. It reuses the same CLI — `--filter`, `--group`, `-c` — and adds `--processes=N` to control worker count. GitHooks ships with a dedicated `type: paratest`:

```php
'jobs' => [
    'paratest_all' => [
        'type'          => 'paratest',
        'configuration' => 'phpunit.xml',
        'cores'         => 4,   // reserves 4 cores + passes --processes=4
    ],
],
'flows' => [
    'options' => ['processes' => 8],
    'qa'     => ['jobs' => ['phpcs-src', 'phpstan_src', 'paratest_all']],
],
```

With `processes: 8` and paratest declaring `cores: 4`, the allocator leaves 4 cores for the remaining jobs in the flow. See [Paratest](../tools/paratest.md) for the full keyword reference.

## Monitor thread usage

Use `--monitor` to see the actual thread usage after execution:

```bash
githooks flow qa --processes=4 --monitor
```

## Check your system

```bash
githooks system:info
```

Shows detected CPU count and current `processes` configuration. Warns if `processes` exceeds available CPUs.

## Tips

- Start with `processes` equal to your CPU core count.
- PHPStan worker count is configured in the `.neon` file (`maximumNumberOfProcesses`), not via GitHooks. It is accounted for in the budget but not adjustable at runtime.
- Use `--monitor` to verify that the budget is being distributed as expected.

## Memory budget and 2D scheduling

When `processes` alone is not enough — typical of monolith QA where
`phpstan` and `phpunit` can together breach a 6 GB CI runner — declare
a flow `memory-budget` and per-job `memory` reservations. The allocator
then admits jobs by **both** axes (cores AND memory), so two heavy
analyzers do not start simultaneously even when cores are free.

```php
'flows' => [
    'options' => [
        'processes'     => 10,
        'memory-budget' => ['warn-above' => 5500, 'fail-above' => 6000],
        'allocator'     => 'greedy',
    ],
    'qa' => ['jobs' => ['phpstan-src', 'phpunit', 'phpcs', 'phpmd-src']],
],
'jobs' => [
    'phpstan-src' => ['type' => 'phpstan', 'cores' => 2, 'memory' => 2000],
    'phpunit'     => ['type' => 'phpunit', 'cores' => 4, 'memory' => 1500],
    'phpcs'       => ['type' => 'phpcs',   'cores' => 1, 'memory' => 256],
    'phpmd-src'   => ['type' => 'phpmd',   'cores' => 2, 'memory' => 800],
],
```

When the simultaneous RSS sum crosses `fail-above`, the runtime kills
the jobs in flight (`process->stop`) and skips the queued ones. The
flow exits `1` even if every individual job had passed up to that
point — that is the conceptual key of the feature.

### Calibrating with `--stats`

Run any flow with `--stats` first **without** thresholds to discover real
peaks. The canonical 5-column table prints after the `Results:` line:

```bash
githooks flow qa --stats
```

Then declare conservative `warn-above`/`fail-above` based on the table.

### FIFO vs greedy

| Strategy | When to pick |
|---|---|
| `fifo` (default) | Predictable order. Use it when CI parity matters or when most jobs are similar in cost. |
| `greedy` | A heavy job declared late blocks lighter ones in FIFO; greedy lets them slip in while the heavy one waits for resources. |

The strategy applies in both 1D mode (cores only) and 2D mode. See
[Memory budget](../configuration/options.md#memory-budget-memory-budget)
for the full configuration reference.

### Platform support

| Platform | Sampler | Notes |
|---|---|---|
| Linux | `/proc/<PID>/status` walked across the process tree | Native, lowest overhead. |
| macOS | `ps -o pid=,ppid=,rss= -ax` once per sample | Single subprocess invocation per tick (~ms). |
| Windows | not available in v3.3 | Runtime emits one stderr warning and disables thresholds. The 2D allocator still schedules using declared `memory:` reservations and `--stats` still reports cores. Sampler is reserved for a future iteration. |

In every platform the RSS values reported reflect the **entire process
tree** rooted at the job's PID — Symfony spawns commands under a shell
wrapper, and the heavy analyzers (php phpstan, php phpunit) run as
child processes whose memory must be summed for the figure to make
sense.
