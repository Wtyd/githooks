# Wtyd/GitHooks

<p align="center">
    <a href="https://github.com/Wtyd/githooks/commits/" title="Last Commit"><img src="https://img.shields.io/github/last-commit/Wtyd/githooks"></a>
    <a href="https://github.com/Wtyd/githooks/issues" title="Open Issues"><img src="https://img.shields.io/github/issues/Wtyd/githooks"></a>
    <a href="https://github.com/Wtyd/githooks/blob/master/LICENSE" title="License"><img src="https://img.shields.io/github/license/Wtyd/githooks"></a>
    <a href="#tada-php-support" title="PHP Versions Supported"><img alt="PHP Versions Supported" src="https://img.shields.io/badge/php-7.1%20to%208.0-777bb3.svg?logo=php&logoColor=white&labelColor=555555"></a> 
    <img src="https://img.shields.io/github/v/release/Wtyd/githooks">
</p>
<p align="center">
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Code Analysis%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Code Analysis/badge.svg"></a>
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Main Tests%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Main Tests/badge.svg"></a>
  <a href="https://github.com/Wtyd/githooks/actions?query=workflow%3A%22Schedule CI%22" title="Build"><img src="https://github.com/Wtyd/githooks/workflows/Schedule CI/badge.svg"></a>
</p>

GitHooks helps you to manage the code validation tools in git hooks. For example, in precommit stage, you can:
1. Validate that the code follows the project standards.
2. Verify that the code has no language syntax errors.
3. Look for errors in the code (unused variables, excessive cyclomatic complexity, etc.).

You can also create your own scripts and configure any git hook.

GitHooks centralizes the configuration of the code validation tools and makes it easy for the team to execute them in the same way every time. Further, it can be used together with javascript validation tools like [typicode/husky](https://github.com/typicode/husky) if you have hybrid projects.

# Requirements
* PHP >= 7.1
* The tools you need to check the code.
* Or your owns scripts for the hooks.

# Install
1. GitHooks must be installed like dev requirement with composer. Currently, it does not have [.phar](https://www.php.net/phar) packaging.
    ```bash
    composer require --dev wtyd/githooks
    ```
**Note:** for php 7.1 or 7.2 you must add the next `post-update-cmd` event to the `scripts` section in your `composer.json`:

```bash
"scripts": {
    "post-update-cmd": [
      "Wtyd\\GitHooks\\Utils\\ComposerUpdater::php72orMinorUpdate"
    ]
}
```
Then run `composer update wtyd/githooks`.

2. Install all needed [supported tools](#supported-tools). How you install the tools doesn't matter.

3. Initialize GitHooks with `githooks conf:init`. This command creates the configuration file in the root path (`githooks.yml`).
4. Run `githooks hook`. It Copies the script for launch GitHooks on the precommit event in `.git/hooks` directory. You can, also run `githooks hook otherHook MyScriptFile.php` for set any hook with a custom script. See the [wiki](https://github.com/Wtyd/githooks/wiki/Console%20Commands) for more information.

5. [Set the configuration file](#Set-the-configuration-file).


# Usage
When you commit, all the configured code check tools are automatically launched. If your code pass all checks, GitHooks allows you to commit. If not, you have to fix the code and try again:
<p>
    <img src="https://i.ibb.co/F0m9ZfV/Git-Hooks-OK.png" alt="Imagen todo OK">
</p>
<p>
    <img src="https://i.ibb.co/VWb6Ks4/Git-Hooks-KO.png" alt="Imagen con KO">
</p>

We can also launch all the tools one by one by command line in the way they are setted for the project:
<p>
    <img src="https://i.ibb.co/QQYNWZj/Git-Hooks-Tool.png" alt="Imagen de una herramienta">
</p>

# Supported Tools
At this moment, the supported tools are:
* [Php CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
* [Php Copy Paste Detector](https://github.com/sebastianbergmann/phpcpd)
* [Php Mess Detector](https://phpmd.org/)
* [Parallel-lint](https://github.com/php-parallel-lint/PHP-Parallel-Lint)
* [Php Stan](https://github.com/phpstan/phpstan)
* [Local PHP Security Checker](https://github.com/fabpot/local-php-security-checker)

But you can set your [own script](https://github.com/Wtyd/githooks/wiki/Console%20Commands#Hook) on any git hook.

# Set the Configuration File
The `githooks.yml` file is splitted on three parts:

## Options
Actually the only option is `execution`. This flag marks how GitHooks will run:
* `full` (the default option): executes always all tools setted against all path setted for each tool.
    For example, you setted phpcs for run in `src` and `app` directories. The commit only contains modified files from `database` directory. Phpcs will check `src` and `app` directories even if no files in these directories have been modified.
* `fast`: this option runs the tools only against files modified by commit.
    * This option only affects the following tools: phpcs, phpmd, phpstan, and parallel-lint. The rest of the tools will run as the full option.
    * **WARNING!!!** You must set the excludes of the tools either in githooks.yml or in the configuration file of eath tool since this
option overwrites the key `paths` of the tools so that they are executed only against the modified files.

## Tools
It is an array with the name of the tools that GitHooks will run. The name of the tools is their executable. If you want all the tools to be executed, the `Tools` key will be as follows:
```yml
Tools:
    - phpstan
    - security-checker
    - parallel-lint
    - phpcs
    - phpmd
    - phpcpd
```
The order in which the tools are is the order in which they will be executed.

## Setting Tools
In next step you must configure the tools with the same name as in the *Tools* key. For example, for set phpcs:
```yml
phpcs:
    executablePath: vendor/bin/phpcs
    paths: [src, tests]
    ignore: [vendor]
    standard: 'PSR12'
```

All the available options are:

| Option               | Description                                                      | Examples                                                           |
| -------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------ |
| **phpstan**          |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'phpstan'                    | phpstan, 'vendor/bin/phpstan', 'path/to/phpstan'                   |
| config               | String. Path to configuration file                               | 'phpstan.neon', 'path/to/phpstan.neon'                             |
| memory-limit         | String. Set the php memory limit while phpstan is running        | '1M', '2000M', '1G'                                                |
| paths                | Array. Paths or files against the tool will be executed          | ['./src'], ['./src', './app/MiFile.php']                           |
| level                | Integer. Default 0, max 8.                                       | 0, 1, 5, 8                                                         |
| **parallel-lint**    |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'parallel-lint'              | parallel-lint, 'vendor/bin/parallel-lint', 'path/to/parallel-lint' |
| paths                | Array. Paths or files against the tool will be executed          | [src], [src, './app/MiFile.php']                                   |
| exclude              | Array. Paths or files to exclude.                                | [vendor], [vendor, './app/MiFile.php']                             |
| **phpcs**            |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'phpcs'                      | phpcs, 'vendor/bin/phpcs', 'path/to/phpcs'                         |
| paths                | Array. Paths or files against the tool will be executed          | [src], [src, './app/MiFile.php']                                   |
| standard             | String. Rules or configuration file with the rules.              | 'PSR12', 'Squizs', 'Generic', 'PEAR', 'myrules.xml'                |
| ignore               | Array. Paths or files to exclude.                                | [vendor], [vendor, './app/MiFile.php']                             |
| error-severity       | Integer. Level of error to detect.                               | 1, 5                                                               |
| warning-severity     | Integer. Level of warning to detect.                             | 5, 7, 9                                                            |
| **phpmd**            |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'phpmd'                      | phpmd, 'vendor/bin/phpmd', 'path/to/phpmd'                         |
| paths                | Array. Paths or files against the tool will be executed          | ['./src'], ['./src', './app/MiFile.php']                           |
| rules                | String. Rules or configuration file with the rules.              | 'controversial,codesize', 'naming', 'myrules.xml'                  |
| exclude              | Array. Paths or files to exclude.                                | ['./vendor'], ['./vendor', './app/MiFile.php']                     |
| **phpcpd**           |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'phpcpd'                     | phpcpd, 'vendor/bin/phpcpd', 'path/to/phpcpd'                      |
| paths                | Array. Paths or files against the tool will be executed          | [src], [src, './app/MiFile.php']                                   |
| exclude              | Array. Paths or files to exclude.                                | [vendor], [vendor, './app/MiFile.php']                             |
| **security-checker** |                                                                  |                                                                    |
| executablePath       | String. Path to executable. Default 'local-php-security-checker' | local-php-security-checker, 'path/to/local-php-security-checker'   |


These are the options supported by GitHooks. Obviously, each tool has many other options. More precise configuration is possible with each tool configuration file.

Many of the options are *optional* as long as the tool has a properly established configuration file. The `conf:init` command copies a githooks.yml file template to the root of the project with all the options commented of each the tool. To make sure that when you finish configuring it, all the options are valid, you can launch the command `conf:check`:
<p>
    <img src="https://i.ibb.co/Qfjf0vv/Git-Hooks-Conf.png" alt="conf:check">
</p>

# Contributing
Contributions from others would be very much appreciated! Send pull [request](https://github.com/Wtyd/githooks/pulls)/[issue](https://github.com/Wtyd/githooks/issues). Check all steps for do that at Wiki section for [Contributing](https://github.com/Wtyd/githooks/wiki/Contributing). Thanks!

# License
The MIT License (MIT). Please see [License File](/LICENSE) for more information.
