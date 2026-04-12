# Parallel Execution & Thread Budget

Speed up your QA runs by distributing work across multiple CPU cores.

## Enable parallel execution

Set `processes` in your flow options:

```php
'flows' => [
    'options' => [
        'processes' => 4,  // total CPU cores budget
    ],
    'qa' => ['jobs' => ['phpcs_src', 'phpstan_src', 'phpmd_src', 'parallel_lint']],
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
| phpstan | Worker count from `.neon` config (read-only) |

For example, with `processes: 4` and two threadable jobs (phpcs and parallel-lint), each gets approximately 2 threads.

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
