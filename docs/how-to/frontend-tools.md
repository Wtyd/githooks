# Frontend Tools

GitHooks can run non-PHP tools using the `custom` job type. This lets you manage backend and frontend QA from a single configuration.

## ESLint

```php
'eslint_src' => [
    'type'             => 'custom',
    'executable-path'  => 'npx eslint',
    'paths'            => ['resources/js'],
    'other-arguments'  => '--fix',
    'accelerable'      => true,  // opt-in for --fast
],
```

With `--fast`, only staged JavaScript files within `resources/js/` are analyzed.

## Prettier

```php
'prettier' => [
    'type'             => 'custom',
    'executable-path'  => 'npx prettier',
    'paths'            => ['resources/js', 'resources/css'],
    'other-arguments'  => '--check',
    'accelerable'      => true,
],
```

## Stylelint

```php
'stylelint' => [
    'type'             => 'custom',
    'executable-path'  => 'npx stylelint',
    'paths'            => ['resources/css'],
    'other-arguments'  => '--fix',
    'accelerable'      => true,
],
```

## Simple mode for scripts

If you don't need path filtering or `--fast` acceleration, use the simple `script` mode:

```php
'npm_build' => [
    'type'   => 'custom',
    'script' => 'npm run build',
],
```

## Mixing PHP and frontend tools

```php
'flows' => [
    'qa' => [
        'jobs' => ['phpcs_src', 'phpstan_src', 'eslint_src', 'prettier'],
    ],
],

'hooks' => [
    'pre-commit' => [
        ['flow' => 'qa', 'only-files' => ['**/*.php', '**/*.js', '**/*.css']],
    ],
],
```

See [Custom Jobs](../tools/custom.md) for the full reference.
