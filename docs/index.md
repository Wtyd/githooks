---
hide:
  - navigation
---

# GitHooks

**One config. Every QA tool. Every git hook.**

GitHooks is a standalone CLI tool for managing git hooks and running QA tools in PHP projects. Distributed as a `.phar` binary, it keeps its dependencies completely isolated from your project.

<div class="grid cards" markdown>

-   :material-package-variant-closed:{ .lg .middle } **Standalone binary**

    ---

    Distributed as `.phar` — its dependencies never interfere with your project's `composer.json`.

-   :material-file-cog:{ .lg .middle } **Unified configuration**

    ---

    One file (`githooks.php`) configures all QA tools, hooks, and execution options.

-   :material-layers-triple:{ .lg .middle } **Hooks, Flows, Jobs**

    ---

    Declarative architecture with parallel execution, fail-fast, conditional execution by branch or staged files.

-   :material-language-javascript:{ .lg .middle } **Language agnostic**

    ---

    The `custom` job type runs any command — ESLint, Prettier, `composer audit`, shell scripts — from the same config.

</div>

## Quick start

```bash
composer require --dev wtyd/githooks   # Install
githooks conf:init                     # Generate configuration (interactive)
githooks hook                          # Install git hooks
```

That's it. On your next commit, all configured QA tools run automatically.

## What it looks like

**All checks passed:**
```
  parallel_lint - OK. Time: 150ms
  phpcs_src - OK. Time: 890ms
  phpstan_src - OK. Time: 2.34s
  phpmd_src - OK. Time: 1.23s

Results: 4/4 passed in 3.45s
```

**Some checks failed:**
```
  parallel_lint - OK. Time: 150ms
  phpcs_src - OK. Time: 890ms
  phpstan_src - KO. Time: 2.34s
  phpmd_src - OK. Time: 1.23s

  phpstan_src:
    /src/Foo.php:12  Access to undefined property $bar
    /src/Foo.php:34  Method doSomething() has no return type

Results: 3/4 passed in 3.45s
```

## Supported tools

| Tool | Type | Description |
|---|---|---|
| [PHP CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) | `phpcs` / `phpcbf` | Code style checking and auto-fixing |
| [PHPStan](https://github.com/phpstan/phpstan) | `phpstan` | Static analysis |
| [PHP Mess Detector](https://phpmd.org/) | `phpmd` | Code quality rules |
| [Parallel-lint](https://github.com/php-parallel-lint/PHP-Parallel-Lint) | `parallel-lint` | Syntax checking |
| [PHPUnit](https://phpunit.de/) | `phpunit` | Unit testing |
| [Psalm](https://psalm.dev/) | `psalm` | Static analysis |
| [PHP Copy Paste Detector](https://github.com/sebastianbergmann/phpcpd) | `phpcpd` | Duplicate code detection |
| Any tool | `custom` | Run any command via `script` or `executablePath` + `paths` |

## Sample configuration

```php
<?php
return [
    'hooks' => [
        'pre-commit' => ['qa'],
        'pre-push'   => [
            ['flow' => 'full', 'only-on' => ['main', 'develop']],
        ],
    ],

    'flows' => [
        'options' => ['fail-fast' => false, 'processes' => 2],
        'qa'   => ['jobs' => ['phpcs_src', 'phpmd_src', 'phpstan_src', 'parallel_lint']],
        'full' => ['jobs' => ['phpstan_src', 'phpunit_all']],
    ],

    'jobs' => [
        'phpcs_src' => [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'standard' => 'PSR12',
        ],
        'phpmd_src' => [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode,codesize,naming,unusedcode',
        ],
        'phpstan_src' => [
            'type'  => 'phpstan',
            'level' => 8,
            'paths' => ['src'],
        ],
        'parallel_lint' => [
            'type'    => 'parallel-lint',
            'paths'   => ['src'],
            'exclude' => ['vendor'],
        ],
        'phpunit_all' => [
            'type'   => 'phpunit',
            'config' => 'phpunit.xml',
        ],
        'composer_audit' => [
            'type'   => 'custom',
            'script' => 'composer audit',
        ],
    ],
];
```

[Get started](getting-started/installation.md){ .md-button .md-button--primary }
[Configuration reference](configuration/index.md){ .md-button }
[Compare with alternatives](comparison.md){ .md-button }
