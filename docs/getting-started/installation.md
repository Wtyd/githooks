# Installation

## Requirements

- **PHP >= 7.4**
- **Git** (2.9+ recommended for `core.hooksPath` support)
- The QA tools you want to run (phpstan, phpcs, phpmd, etc.)

## Install via Composer

```bash
composer require --dev wtyd/githooks
```

GitHooks is distributed as a `.phar` binary embedded in the Composer package. Its dependencies are self-contained and do not mix with your project's dependencies.

### PHP < 8.1

If your project runs on PHP < 8.1, add the following events to the `scripts` section of your `composer.json`:

```json
"scripts": {
    "post-update-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions",
    "post-install-cmd": "Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"
}
```

Then run:

```bash
composer update wtyd/githooks
```

This ensures the correct `.phar` binary (compiled for PHP 7.4) is used.

## Verify installation

```bash
vendor/bin/githooks --version
```

## Next step

[Create your first configuration :material-arrow-right:](first-config.md)
