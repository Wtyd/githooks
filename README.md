

<p align="center">
    <a href="https://github.com/Wtyd/githooks/commits/" title="Last Commit"><img src="https://img.shields.io/github/last-commit/Wtyd/githooks"></a>
    <a href="https://github.com/Wtyd/githooks/issues" title="Open Issues"><img src="https://img.shields.io/github/issues/Wtyd/githooks"></a>
    <a href="https://github.com/Wtyd/githooks/blob/master/LICENSE" title="License"><img src="https://img.shields.io/github/license/Wtyd/githooks"></a>
    <a href="#2-requirements" title="PHP Versions Supported"><img alt="PHP Versions Supported" src="https://img.shields.io/badge/php-7.4%20to%208.5-777bb3.svg?logo=php&logoColor=white&labelColor=555555"></a> 
    <img src="https://img.shields.io/github/v/release/Wtyd/githooks">
</p>
<p align="center">
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Code Analysis%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Code Analysis/badge.svg"></a>
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Main Tests%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Main Tests/badge.svg"></a>
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Schedule CI%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Schedule CI/badge.svg"></a>
</p>

# 1. Wtyd/GitHooks

GitHooks is a standalone CLI tool (.phar) for managing git hooks and running QA tools in PHP projects. Built with Laravel Zero.

**Why GitHooks?**
* **Standalone binary** — distributed as `.phar`, so its dependencies don't interfere with your project.
* **Managed with Composer** — no need for Phive or other tools.
* **Unified configuration** — one file (`githooks.php`) configures all QA tools, hooks, and execution options.
* **Hooks, flows, jobs** — map git events to groups of QA tasks with parallel execution, fail-fast, and conditional execution.
* **Language agnostic** — the `custom` job type can run any command (`eslint`, `prettier`, `composer audit`, etc.), so GitHooks manages both backend and frontend QA from a single configuration.

# 2. Requirements
* PHP >= 7.4
* The QA tools you want to run (phpstan, phpcs, phpmd, etc.)

# 3. Install

#### 1. Install GitHooks as a dev dependency:
```bash
composer require --dev wtyd/githooks
```

**Note:** for PHP < 8.1 you must add the following events to the `scripts` section in your `composer.json`:

```json
"scripts": {
    "post-update-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions",
    "post-install-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
}
```
Then run `composer update wtyd/githooks`.

#### 2. Initialize the configuration file:
```bash
githooks conf:init
```

In interactive mode, GitHooks detects QA tools in `vendor/bin/` and generates a tailored `githooks.php`. You can also use `--no-interaction` to copy a template.

#### 3. Install the git hooks:
```bash
githooks hook
```

This creates a `.githooks/` directory with universal hook scripts and configures `git config core.hooksPath .githooks`. The `.githooks/` directory should be committed to version control.

To automate hook installation, add it to your `composer.json`:

```json
"scripts": {
    "post-update-cmd": [
        "vendor/bin/githooks hook"
    ],
    "post-install-cmd": [
        "vendor/bin/githooks hook"
    ]
}
```

# 4. Usage

When you commit, all configured QA tools run automatically. If all checks pass, the commit proceeds. If not, you fix the code and try again.

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

### Running manually

```bash
githooks flow qa                          # Run a flow (group of jobs)
githooks flow qa --fast                   # Only analyze staged files (accelerable jobs)
githooks flow qa --only-jobs=phpstan_src  # Run specific jobs from a flow
githooks flow qa --dry-run                # Show commands without executing
githooks job phpstan_src                  # Run a single job
githooks job phpstan_src --format=json    # JSON output for CI integration
```

# 5. Configuration

GitHooks uses a PHP configuration file (`githooks.php`) with three sections: **hooks**, **flows**, and **jobs**.

```php
<?php
return [
    // Git hooks: map git events to flows/jobs
    'hooks' => [
        'command'    => 'php7.4 vendor/bin/githooks', // optional: customize the hook script command
        'pre-commit' => ['qa'],
        'pre-push'   => [
            ['flow' => 'full', 'only-on' => ['main', 'develop']], // only on these branches
        ],
    ],

    // Flows: named groups of jobs with shared execution options
    'flows' => [
        'options' => ['fail-fast' => false, 'processes' => 2], // global defaults
        'qa'   => ['jobs' => ['phpcbf_src', 'phpcs_src', 'phpmd_src', 'parallel_lint']],
        'full' => ['jobs' => ['phpstan_src', 'phpunit_all']],
    ],

    // Jobs: individual QA tasks with declarative configuration
    'jobs' => [
        'phpcs_src' => [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'standard' => 'PSR12',
            'ignore'   => ['vendor'],
            // executablePath omitted: auto-detects vendor/bin/phpcs, then system PATH
        ],
        'phpcbf_src' => [
            'extends' => 'phpcs_src', // inherits paths, standard, ignore from phpcs
            'type'    => 'phpcbf',    // overrides type
        ],
        'phpmd_src' => [
            'type'           => 'phpmd',
            'executablePath' => 'tools/phpmd', // explicit path when not in vendor/bin
            'paths'          => ['src'],
            'rules'          => 'cleancode,codesize,naming,unusedcode',
        ],
        'parallel_lint' => [
            'type'    => 'parallel-lint',
            'paths'   => ['src'],
            'exclude' => ['vendor'],
        ],
        'phpstan_src' => [
            'type'  => 'phpstan',
            'level' => 8,
            'paths' => ['src'],
        ],
        'phpunit_all' => [
            'type'   => 'phpunit',
            'config' => 'phpunit.xml',
        ],
        'composer_audit' => [
            'type'   => 'custom', // run any command
            'script' => 'composer audit',
        ],
        'eslint_src' => [
            'type'           => 'custom',
            'executablePath' => 'npx eslint',    // structured mode: executable + paths
            'paths'          => ['resources/js'],
            'otherArguments' => '--fix',
            'accelerable'    => true,            // opt-in: filters paths to staged files with --fast
        ],
    ],
];
```

### Key concepts

* **Hooks** map git events (`pre-commit`, `pre-push`, etc.) to flows and jobs. Supports conditional execution by branch (`only-on`) and staged file patterns (`only-files`).
* **Flows** are named groups of jobs with shared options (`fail-fast`, `processes`). Reusable across hooks and directly executable from CLI.
* **Jobs** are individual QA tasks. Each declares a `type` and its arguments. Jobs can inherit from other jobs with `extends`. When `executablePath` is omitted, GitHooks auto-detects the binary in `vendor/bin/`.

See the [wiki](https://github.com/Wtyd/githooks/wiki/3x-ConfigurationFile) for the full configuration reference.

# 6. Supported Tools

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

The `custom` type replaces the deprecated `security-checker` and `script` tools. Two modes:
* **Simple**: `script` key contains the full command (`'script' => 'composer audit'`).
* **With paths**: `executablePath` + `paths` + optional `otherArguments`. Supports `--fast` acceleration when `accelerable: true`.

# 7. Commands

| Command | Description |
|---|---|
| `githooks flow <name>` | Run a flow. Options: `--fail-fast`, `--processes`, `--exclude-jobs`, `--only-jobs`, `--dry-run`, `--format`, `--fast`, `--monitor` |
| `githooks job <name>` | Run a single job. Options: `--dry-run`, `--format`, `--fast` |
| `githooks hook` | Install git hooks via `core.hooksPath` |
| `githooks hook:clean` | Remove installed hooks |
| `githooks status` | Show hook installation status |
| `githooks cache:clear [jobs...]` | Clear QA tool cache files |
| `githooks conf:init` | Generate configuration file (interactive or template) |
| `githooks conf:check` | Validate configuration with deep checks |
| `githooks conf:migrate` | Migrate v2 config to v3 format |
| `githooks system:info` | Show CPU and process configuration |

See the [wiki](https://github.com/Wtyd/githooks/wiki/3x-ConsoleCommands) for detailed documentation.

# 8. Documentation

Full documentation is available at **[wtyd.github.io/githooks](https://wtyd.github.io/githooks/)**, including:

- [Getting Started](https://wtyd.github.io/githooks/getting-started/installation/) — installation, first config, first hook.
- [Configuration Reference](https://wtyd.github.io/githooks/configuration/) — hooks, flows, jobs, options.
- [Tools Reference](https://wtyd.github.io/githooks/tools/) — all keywords for each QA tool.
- [CLI Reference](https://wtyd.github.io/githooks/cli/) — every command with options and examples.
- [How-To Guides](https://wtyd.github.io/githooks/how-to/) — parallel execution, CI/CD, frontend tools, etc.
- [Migration](https://wtyd.github.io/githooks/migration/v2-to-v3/) — from v2, GrumPHP, or CaptainHook.
- [Comparison](https://wtyd.github.io/githooks/comparison/) — GitHooks vs GrumPHP vs CaptainHook.
- [Changelog](https://wtyd.github.io/githooks/changelog/) — what's new in each version.

# 9. Contributing
Contributions are welcome! Send a [pull request](https://github.com/Wtyd/githooks/pulls) or open an [issue](https://github.com/Wtyd/githooks/issues). See the [Contributing](https://github.com/Wtyd/githooks/wiki/Contributing) guide.

# 9. License
The MIT License (MIT). Please see [License File](/LICENSE) for more information.
