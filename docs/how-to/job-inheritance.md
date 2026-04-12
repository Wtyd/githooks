# Job Inheritance

Use the `extends` keyword to share configuration between similar jobs.

## The problem

You have phpcs and phpcbf analyzing the same paths with the same standard. Duplicating the config is error-prone:

```php
// Don't repeat yourself
'phpcs_src' => ['type' => 'phpcs', 'paths' => ['src'], 'standard' => 'PSR12', 'ignore' => ['vendor']],
'phpcbf_src' => ['type' => 'phpcbf', 'paths' => ['src'], 'standard' => 'PSR12', 'ignore' => ['vendor']],
```

## The solution

```php
'phpcs_src' => [
    'type'     => 'phpcs',
    'paths'    => ['src'],
    'standard' => 'PSR12',
    'ignore'   => ['vendor'],
],
'phpcbf_src' => [
    'extends' => 'phpcs_src',  // inherits paths, standard, ignore
    'type'    => 'phpcbf',     // overrides type
],
```

## Base job with multiple children

```php
'phpmd_base' => [
    'type'    => 'phpmd',
    'rules'   => 'cleancode,codesize,naming,unusedcode',
    'exclude' => ['vendor'],
],
'phpmd_src' => [
    'extends' => 'phpmd_base',
    'paths'   => ['src'],
],
'phpmd_app' => [
    'extends' => 'phpmd_base',
    'paths'   => ['app'],
],
'phpmd_light' => [
    'extends' => 'phpmd_base',
    'rules'   => 'unusedcode',   // overrides parent's rules
    'paths'   => ['tests'],
],
```

The parent job (`phpmd_base`) can also be used directly in flows.

## Chained inheritance

```php
'phpstan_base' => ['type' => 'phpstan', 'level' => 8],
'phpstan_src'  => ['extends' => 'phpstan_base', 'paths' => ['src']],
'phpstan_app'  => ['extends' => 'phpstan_src', 'paths' => ['app']],  // inherits level from base
```

## Rules

- The child inherits **all keys** from the parent.
- The child can **override any key**.
- Chained inheritance works (A extends B extends C).
- Circular references are detected and reported as errors.
- The `extends` key is removed from the resolved job.

See [Configuration: Jobs](../configuration/jobs.md#job-inheritance-extends) for the full reference.
