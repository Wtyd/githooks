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
