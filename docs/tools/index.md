# Tools Reference

GitHooks provides native support for the most common PHP QA tools, plus a `custom` type that can run any command.

## Supported tools

| Tool | Type | Accelerable | Internal threading |
|---|---|---|---|
| [PHPStan](phpstan.md) | `phpstan` | Yes | Workers from `.neon` config |
| [PHP CodeSniffer](phpcs.md) | `phpcs` / `phpcbf` | Yes | `--parallel` flag |
| [PHP Mess Detector](phpmd.md) | `phpmd` | Yes | No |
| [PHPUnit](phpunit.md) | `phpunit` | No | No |
| [Paratest](paratest.md) | `paratest` | No | `--processes` flag |
| [Psalm](psalm.md) | `psalm` | Yes | `--threads` flag |
| [Parallel Lint](parallel-lint.md) | `parallel-lint` | Yes | `-j` flag |
| [PHP Copy Paste Detector](phpcpd.md) | `phpcpd` | No | No |
| [PHP CS Fixer](phpcsfixer.md) | `php-cs-fixer` | Yes | No |
| [Rector](rector.md) | `rector` | Yes | No |
| [Custom Jobs](custom.md) | `custom` | Opt-in | No |

## Accelerable

An accelerable job supports `--fast` mode: when running with `--fast`, GitHooks replaces the job's `paths` with only the staged files that fall within those paths. Jobs with no matching staged files are skipped entirely.

See [Execution Modes](../execution-modes.md) for details.

## Internal threading

Some tools support internal parallelism (running multiple analysis threads). When `processes > 1` in the flow options, GitHooks distributes the thread budget across these tools automatically.

See [How-To: Parallel Execution](../how-to/parallel-execution.md) for details.

## Auto-detection of executables

When `executablePath` is omitted, GitHooks auto-detects the binary:

1. First checks `vendor/bin/{tool}`.
2. Falls back to the tool name resolved via system PATH.

This means you usually don't need to set `executablePath` if the tool is installed via Composer.
