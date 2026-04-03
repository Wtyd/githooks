

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
Are many other tools and composer plugins for manage git hooks. But GitHooks offers:
* Standalone app. GitHooks is a binary ([.phar](https://www.php.net/phar)) so its dependencies don't interfere with your application's dependencies.
* Is managed with Composer. You don't need other tools like Phive or others.
* Crentralizes all QA tools configuration (all of supported tools at least).
* It abstracts developers away from how QA tools have to be executed by using only the `githooks tool name-of-the-tool` command.
* You can also create your own scripts and configure any git hook.

Further, it can be used together with javascript validation tools like [typicode/husky](https://github.com/typicode/husky) if you have hybrid projects.

# 2. Requirements
* PHP >= 7.4
* The tools you need to check the code.
* Or your owns scripts for the hooks.

# 3. Install
#### 1. GitHooks must be installed like dev requirement with composer:
```bash
composer require --dev wtyd/githooks
```
**Note:** for php < 8.1 you must add the next `post-update-cmd` and `post-install-cmd` events to the `scripts` section in your `composer.json`:

```json
"scripts": {
    "post-update-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions",
    "post-install-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
}
```
Then run `composer update wtyd/githooks`.
#### 2. Install all needed [supported tools](#5-supported-tools). How you install the tools doesn't matter.

#### 3. Initialize GitHooks with `githooks conf:init`. This command creates the configuration file in the root path (`githooks.php`).
#### 4. Run `githooks hook`. It Copies the script for launch GitHooks on the pre-commit event in `.git/hooks` directory. You can, also run `githooks hook otherHook MyScriptFile.php` for set any hook with a custom script. See the [wiki](https://github.com/Wtyd/githooks/wiki/Console%20Commands#hook) for more information.

To ensure that it is configured automatically, we can configure the command in the `post-update-cmd` and `post-install-cmd` events of the `composer.json` file (`scripts` section):

```json
"scripts": {
    "post-update-cmd": [
    "vendor/bin/githooks hook" // or "vendor/bin/githooks hook pre-commit MyScriptFile.php"
    ],
    "post-install-cmd": [
    "vendor/bin/githooks hook"
    ]
}
```

#### 5. [Set the configuration file](#6-set-the-configuration-file).

# 4. Usage
When you commit, all the configured code check tools are automatically launched. If your code pass all checks, GitHooks allows you to commit. If not, you have to fix the code and try again.

**All checks passed:**
```
✔️ parallel-lint - OK. Time: 150ms
✔️ phpcs - OK. Time: 890ms
✔️ phpstan - OK. Time: 2.34s
✔️ phpmd - OK. Time: 1.23s

Total Runtime: 3.45s

Results: 4/4 passed ✔️
```

**Some checks failed:**
```
✔️ parallel-lint - OK. Time: 150ms
✔️ phpcs - OK. Time: 890ms
❌ phpstan - KO. Time: 2.34s
  ┌───────────────────────────────────────────────────────────────────────────────
  │ /src/Foo.php:12  Access to undefined property $bar
  │ /src/Foo.php:34  Method doSomething() has no return type
  └───────────────────────────────────────────────────────────────────────────────
✔️ phpmd - OK. Time: 1.23s

Total Runtime: 3.45s

Results: 3/4 passed
  ❌ phpstan
```

You can also run GitHooks whenever you want. All tools at same time or one by one:
```bash
githooks tool all  # Run all tools
githooks tool phpcs  # Run only phpcs
```

# 5. Supported Tools
At this moment, the supported tools are:
* [Php CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) (phpcs and phpcbf)
* [Php Copy Paste Detector](https://github.com/sebastianbergmann/phpcpd)
* [Php Mess Detector](https://phpmd.org/)
* [Parallel-lint](https://github.com/php-parallel-lint/PHP-Parallel-Lint)
* [Php Stan](https://github.com/phpstan/phpstan)
* [PHPUnit](https://phpunit.de/)
* [Psalm](https://psalm.dev/)
* **Script** — Generic tool type for running any QA tool not natively supported by GitHooks (e.g. php-cs-fixer, infection, pdepend). Only requires `executablePath`.
* ~~[Local PHP Security Checker](https://github.com/fabpot/local-php-security-checker)~~ Since Composer 2.4 local php security checker is replaced by `composer audit`

You can also set your [own script](https://github.com/Wtyd/githooks/wiki/Console%20Commands#set-your-own-script) on any git hook.

# 6. Set the Configuration File
The `githooks.yml` file is splitted on three parts:

## 6.1. Options
### 6.1.1. Execution
The `execution` flag marks how GitHooks will run:
* `full` (the default option): executes always all tools setted against all path setted for each tool.
    For example, you setted phpcs for run in `src` and `app` directories. The commit only contains modified files from `database` directory. Phpcs will check `src` and `app` directories even if no files in these directories have been modified.
* `fast`: this option runs the tools only against files modified by commit.
    * This option only affects the following tools: phpcs, phpcbf, phpmd, phpstan, psalm and parallel-lint. The rest of the tools will run as the full option.
    * **WARNING!!!** You must set the excludes of the tools either in `githooks.yml` or in the configuration file of each tool since this
option overwrites the key `paths` of the tools so that they are executed only against the modified files.

#### Per-tool execution mode
You can override the global execution mode for individual tools by adding an `execution` key to the tool's configuration:

```php
'Options' => [
    'execution' => 'full', // Global default
],
'phpcs' => [
    'paths' => ['src'],
    // No execution key: inherits global 'full'
],
'phpcbf' => [
    'execution' => 'fast', // Override: only modified files
    'paths' => ['src'],
],
```

**Priority:** CLI argument > per-tool setting > global Options > default (`full`).

When you pass execution from the CLI (e.g. `githooks tool all fast`), it overrides both global and per-tool settings for that run.

### 6.1.2. Processes
Run multiple tools in multiple processes at same time (`tool all` command). The default number of processes is 1.

## 6.2. Tools
It is an array with the name of the tools that GitHooks will run. The name of the tools is their executable. If you want all the tools to be executed, the `Tools` key will be as follows:

```php
'Tools' => [
        'security-checker',
        'phpstan',
        'parallel-lint',
        'phpcbf',
        'phpcs',
        'phpmd',
        'phpcpd',
        'phpunit',
        'psalm',
        'script',
],
```

The order in which the tools are is the order in which they will be executed.

## 6.3. Setting Tools
In next step you must configure the tools with the same name as in the *Tools* key. For example, for set phpcs:
```php
'phpcs' => [
        'executablePath' => 'phpcs',
        'execution' => 'fast', // Optional: override global execution mode (full/fast)
        'paths' => ['./'],
        'standard' => './myRules.xml', // or predefined rules: Squiz, PSR12, Generic, PEAR
        'ignore' => ['vendor'],
],
```

The `script` tool type allows you to run any external tool not natively supported by GitHooks. Unlike other tools, `executablePath` is **required** (there is no default). You can set a custom `name` to use in the `Tools` array and CLI instead of `script`:
```php
'script' => [
        'name' => 'php-cs-fixer', // Optional: use 'php-cs-fixer' in Tools array and CLI
        'executablePath' => 'vendor/bin/php-cs-fixer',
        'otherArguments' => 'fix --dry-run --config=.php-cs-fixer.php',
        'ignoreErrorsOnExit' => false,
],
```
With `name` set, use the custom name in the `Tools` array and run it with `githooks tool php-cs-fixer`.

All the available options are in the [wiki](https://github.com/Wtyd/githooks/wiki/ConfigurationFile).

# 7. Contributing
Contributions from others would be very much appreciated! Send [pull request](https://github.com/Wtyd/githooks/pulls)/[issue](https://github.com/Wtyd/githooks/issues). Check all steps for do that at Wiki section for [Contributing](https://github.com/Wtyd/githooks/wiki/Contributing). Thanks!

# 8. License
The MIT License (MIT). Please see [License File](/LICENSE) for more information.
