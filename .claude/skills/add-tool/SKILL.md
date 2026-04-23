---
name: add-tool
description: >
  Guia para añadir una nueva herramienta QA al proyecto GitHooks (wtyd/githooks).
  En v3, las herramientas se implementan como Jobs en src/Jobs/.
  Usa esta skill cuando el usuario quiera añadir soporte para una herramienta de analisis de codigo
  (phpunit, psalm, rector, phpinsights, etc.), integrar una nueva tool, o cuando mencione
  "nueva tool", "añadir herramienta", "soporte para X", "nuevo job type".
---

# Añadir una nueva herramienta QA a GitHooks

En v3, cada herramienta QA se implementa como un **Job** en `src/Jobs/`.
Un Job declara un `ARGUMENT_MAP` tipado y genera el comando shell via `buildCommand()`.

## Vision general

```
1. Clase Job                → src/Jobs/MyToolJob.php
2. Registro en JobRegistry  → src/Jobs/JobRegistry.php
3. Dev-dependency           → composer.json + PreBuildCommand::DEV_DEPENDENCIES
4. Tests                    → tests/Unit/Jobs/JobBuildCommandTest.php
5. Config de QA             → qa/githooks.php + qa/githooks.dist.php
```

## Paso 1: Crear la clase Job

Fichero: `src/Jobs/MyToolJob.php`

```php
<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ThreadCapability;

class MyToolJob extends JobAbstract
{
    protected const TOOL_NAME = 'mytool';

    protected const DEFAULT_EXECUTABLE = 'vendor/bin/mytool';

    // Subcomando que va justo después del ejecutable (ej: phpstan usa 'analyse')
    // protected function getSubcommand(): string { return 'check'; }

    /**
     * Declaración de argumentos aceptados y cómo se mapean a flags CLI.
     *
     * Tipos disponibles:
     *   value    → --flag=value o -f value (usa 'separator' para elegir)
     *   boolean  → --flag (presente si truthy)
     *   paths    → lista de paths al final del comando, separados por espacio
     *   csv      → --flag=a,b,c
     *   repeat   → --flag a --flag b (repite el flag por cada valor)
     *   key_value → --key=value (pares clave-valor)
     */
    protected const ARGUMENT_MAP = [
        'config'    => ['flag' => '--config', 'type' => 'value'],
        'level'     => ['flag' => '--level',  'type' => 'value'],
        'exclude'   => ['flag' => '--exclude','type' => 'csv'],
        'no-cache'  => ['flag' => '--no-cache', 'type' => 'boolean'],
        'paths'     => ['type' => 'paths'],
    ];

    // Si la tool soporta paralelismo interno (como phpcs --parallel o psalm --threads)
    public function getThreadCapability(): ?ThreadCapability
    {
        return new ThreadCapability(
            'parallel',     // Nombre del argumento en ARGUMENT_MAP
            4,              // Threads por defecto
            1,              // Minimo threads
            true            // Controllable (GitHooks puede ajustar el valor)
        );
    }
}
```

### Elementos del ARGUMENT_MAP

| Campo | Tipo | Descripcion |
|---|---|---|
| `flag` | string | El flag CLI de la herramienta (ej: `--config`, `-l`) |
| `type` | string | Uno de: `value`, `boolean`, `paths`, `csv`, `repeat`, `key_value` |
| `separator` | string | Para `value`: `=` genera `--flag=val`, ` ` genera `--flag val` (default: ` `) |
| `required` | bool | Si es obligatorio en la config (default: false) |

### Metodos opcionales a sobrescribir

```php
// Si el comando tiene un subcomando (phpstan analyse, phpmd check...)
protected function getSubcommand(): string { return 'analyse'; }

// Si la tool aplica fixes (phpcbf: exit 1 = fixes applied, no es error)
public function isFixApplied(int $exitCode): bool { return $exitCode === 1; }

// Si necesita orden especifico de argumentos (phpmd: paths antes que rules)
public function buildCommand(): string { /* override completo */ }
```

## Paso 2: Registrar en JobRegistry

Fichero: `src/Jobs/JobRegistry.php`

Añadir al array `TYPE_MAP`:

```php
private const TYPE_MAP = [
    'phpstan'       => PhpstanJob::class,
    'phpcs'         => PhpcsJob::class,
    // ...
    'mytool'        => MyToolJob::class,
];
```

**Nota sobre validacion:** El registro en `JobRegistry` es suficiente para que el tipo pase validacion.
`JobConfiguration::fromArray()` consulta ambos registries: primero `ToolRegistry` (legacy v2) y
despues `JobRegistry` (v3). Los tipos nuevos no necesitan stubs en `ToolRegistry`.

## Paso 3 (mandatorio): Declarar como dev-dependency

**Toda tool soportada debe estar en `require-dev` de `composer.json` y en
`PreBuildCommand::DEV_DEPENDENCIES`.** Si falta uno de los dos pasos:

- Sin `require-dev` → la tool no está en `vendor/bin/` tras `composer install`;
  bloquea desarrollo, tests locales y release tests.
- Sin entrada en `DEV_DEPENDENCIES` → la tool se embebe en el `.phar` distribuido
  (aumenta el tamaño, arrastra todas sus dependencias transitivas y puede
  colisionar con el proyecto destino — es exactamente lo que pasó históricamente
  con psalm y causó los crashes "Psalm (unknown version) crashed" en release).

### 3.1 Añadir a `composer.json` (require-dev)

El constraint debe cubrir todos los tiers PHP soportados (7.4 y 8.1+). Usa `||`
para combinar majors si una sola no los cubre:

```json
"require-dev": {
    "vendor/mytool": "^1.0 || ^2.0"
}
```

Comando rápido (respeta `sort-packages: true`):

```bash
php7.4 tools/composer require --dev vendor/mytool:"^1.0 || ^2.0"
```

### 3.2 Añadir a `PreBuildCommand::DEV_DEPENDENCIES`

Fichero: `app/Commands/PreBuildCommand.php`. Orden alfabético:

```php
public const DEV_DEPENDENCIES = [
    ...
    'vendor/mytool',
    ...
];
```

### 3.3 Verificación

```bash
# La tool debe aparecer en vendor/bin
ls vendor/bin/mytool
php7.4 vendor/bin/mytool --version

# Pre-build debe retirarla (no debe quedar en composer.json tras ejecutar)
php7.4 githooks app:pre-build php7.4
grep vendor/mytool composer.json    # no debe devolver nada

# Restaurar
git restore --staged --worktree composer.json
php7.4 tools/composer update
```

## Paso 4: Tests

Añadir casos al fichero existente `tests/Unit/Jobs/JobBuildCommandTest.php`:

```php
/** @test */
function mytool_builds_correct_command()
{
    $job = new MyToolJob(new JobConfiguration('test', 'mytool', [
        'config' => 'qa/mytool.xml',
        'level' => '3',
        'paths' => ['src', 'app'],
    ]));

    $this->assertEquals(
        'vendor/bin/mytool --config qa/mytool.xml --level 3 src app',
        $job->buildCommand()
    );
}

/** @test */
function mytool_with_custom_executable()
{
    $job = new MyToolJob(new JobConfiguration('test', 'mytool', [
        'executablePath' => '/usr/local/bin/mytool',
        'paths' => ['src'],
    ]));

    $this->assertStringStartsWith('/usr/local/bin/mytool', $job->buildCommand());
}
```

Delegar tests mas completos a la skill `php-test-creator`.

## Paso 5: Configuracion de QA

**`qa/githooks.php`** — añadir job a un flow:

```php
'flows' => [
    'qa' => [
        'jobs' => [
            // ...existentes...
            'MyTool Src',
        ],
    ],
],
'jobs' => [
    // ...existentes...
    'MyTool Src' => [
        'type' => 'mytool',
        'executablePath' => 'vendor/bin/mytool',
        'config' => 'qa/mytool.xml',
        'paths' => ['src'],
    ],
],
```

**`qa/githooks.dist.php`** — añadir ejemplo comentado con todos los argumentos disponibles.

## Paso 6: Validacion de argumentos en conf:check

`conf:check` valida automaticamente los argumentos contra el `ARGUMENT_MAP` del job.
Claves no reconocidas generan warning. No hace falta tocar `conf:check`.

## Checklist

### Ficheros creados
- [ ] `src/Jobs/MyToolJob.php` con `declare(strict_types=1)`

### Ficheros modificados
- [ ] `src/Jobs/JobRegistry.php` — type añadido a `TYPE_MAP`
- [ ] `composer.json` — tool añadida a `require-dev` con constraint que cubra los tiers PHP soportados (7.4 y 8.1+)
- [ ] `app/Commands/PreBuildCommand.php` — package añadido a `DEV_DEPENDENCIES` (orden alfabético)
- [ ] `tests/Unit/Jobs/JobBuildCommandTest.php` — tests de registry y executable prefix
- [ ] `tests/Unit/Jobs/MyToolJobTest.php` — tests dedicados de la herramienta (buildCommand, argumentos, isFixApplied, cache, threads, prefix, CLI args)
- [ ] `tests/Unit/Configuration/JobConfigurationTest.php` — verificar que el tipo nuevo pasa validacion con `fromArray()`
- [ ] `qa/githooks.php` — job añadido al flow qa
- [ ] `qa/githooks.dist.php` — ejemplo documentado

### Verificacion
- [ ] `buildCommand()` genera el comando esperado
- [ ] `conf:check` muestra el job con su comando
- [ ] `vendor/bin/mytool` existe tras `composer update`
- [ ] Tras `php7.4 githooks app:pre-build php7.4` el `composer.json` resultante ya no contiene la tool en `require-dev`
- [ ] `php7.4 githooks job "Phpunit" --format=json -- --order-by=random` — 0 fallos
- [ ] `php7.4 githooks flow qa --format=json` — QA sin violaciones nuevas
- [ ] Probar `php7.4 githooks job MyTool_Src --format=json` manualmente

## Contexto legacy

El sistema legacy de Tools vive en `src/Tools/Tool/` con `ToolAbstract`, `ToolRegistry`,
`ToolsFactory`, `SUPPORTED_TOOLS`, etc. El paso 11+ de la version anterior de esta skill
documentaba ese flujo (12+ ficheros). No añadir Tools legacy nuevas — usar Jobs v3.
