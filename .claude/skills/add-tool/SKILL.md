---
name: add-tool
description: >
  Guía completa para añadir una nueva QA tool al proyecto GitHooks (wtyd/githooks).
  Usa esta skill cuando el usuario quiera añadir soporte para una herramienta de análisis de código
  (como phpunit, psalm, rector, phpinsights, etc.), integrar una nueva tool, o cuando mencione
  "nueva tool", "añadir herramienta", "soporte para X", "integrar X tool", "add tool".
  También se activa cuando se trabaje en los stubs vacíos de Phpunit.php o Psalm.php.
allowed-tools: Read, Edit, Write, Glob, Grep, Bash(php7.1 *), Bash(git add *), Bash(git commit *), Bash(git mv *), Bash(git status), Bash(git diff *), Bash(git log *), Agent, WebFetch, WebSearch
---

# Añadir una nueva QA Tool a GitHooks

Añadir una tool toca 12+ ficheros en un orden específico. Esta skill guía el proceso completo.

## Visión general del flujo

```
1. Clase Tool + Fake          →  src/Tools/Tool/
2. Registro en ToolAbstract   →  src/Tools/Tool/ToolAbstract.php
3. ConfigurationFileBuilder   →  tests/Utils/ConfigurationFileBuilder.php
4. ConsoleTestCase bindings   →  tests/Utils/TestCase/ConsoleTestCase.php
5. PhpFileBuilder (si aplica) →  tests/Utils/PhpFileBuilder.php
6. DataProviders existentes   →  tests/Integration/ + tests/System/
7. Config de QA               →  qa/githooks.php + qa/githooks.dist.yml
8. GitHub Actions             →  .github/workflows/main-tests.yml + release.yml
9. Tests                      →  delegar a skill php-test-creator
```

## Paso 1: Crear la clase Tool

Lee `references/tool-class-pattern.md` para el patrón completo.

Fichero: `src/Tools/Tool/MyTool.php`

Elementos obligatorios:
- `const NAME` — el nombre de la tool (debe coincidir con la key en `SUPPORTED_TOOLS`)
- `const ARGUMENTS` — array ordenado de todas las opciones de configuración aceptadas
- Constructor que reciba `ToolConfiguration`, llame a `$this->setArguments()` y asigne default `executablePath`
- `prepareCommand(): string` — construye el comando shell iterando `ARGUMENTS` con un `switch`

Si la tool tiene argumentos específicos (como `level` en phpstan o `rules` en phpmd), define constantes para ellos.

## Paso 2: Crear la clase Fake

Fichero: `src/Tools/Tool/MyToolFake.php`

```php
<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\Tools\Tool\TestToolTrait;

class MyToolFake extends MyTool
{
    use TestToolTrait;
}
```

`TestToolTrait` hace públicos `prepareCommand()`, `getArguments()` y `getExecutablePath()` para tests.

Las Fake viven en `src/` (no en `tests/`) porque el container de Laravel las resuelve por nombre de clase.

## Paso 3: Registrar en ToolAbstract

Fichero: `src/Tools/Tool/ToolAbstract.php`

1. Añadir constante con el nombre:
```php
public const MY_TOOL = 'mytool';
```

2. Añadir al array `SUPPORTED_TOOLS`:
```php
self::MY_TOOL => MyTool::class,
```

3. Añadir al array `EXCLUDE_ARGUMENT` (si tiene opción de exclude):
```php
self::MY_TOOL => MyTool::EXCLUDE,  // o '' si no tiene exclude
```

## Paso 4: Actualizar ConfigurationFileBuilder

Fichero: `tests/Utils/ConfigurationFileBuilder.php`

1. Añadir la tool a `$this->tools` en el constructor:
```php
$this->tools = [
    // ...tools existentes...
    ToolAbstract::MY_TOOL,
];
```

2. Añadir la configuración por defecto a `$this->configurationTools`:
```php
ToolAbstract::MY_TOOL => [
    MyTool::EXECUTABLE_PATH_OPTION => $this->mainToolExecutablePaths . 'mytool',
    MyTool::PATHS => [$rootPath . '/src'],
    // ... resto de argumentos con valores por defecto razonables
    MyTool::IGNORE_ERRORS_ON_EXIT => false,
],
```

3. (Opcional) Añadir método setter si la tool necesita configuración especial:
```php
public function setMyToolConfiguration(array $configuration): ConfigurationFileBuilder
{
    $this->configurationTools[ToolAbstract::MY_TOOL] = $configuration;
    return $this;
}
```

## Paso 5: Actualizar ConsoleTestCase::bindFakeTools()

Fichero: `tests/Utils/TestCase/ConsoleTestCase.php`

Añadir el import y el binding:
```php
use Wtyd\GitHooks\Tools\Tool\MyTool;
use Wtyd\GitHooks\Tools\Tool\MyToolFake;

// En bindFakeTools():
$this->app->bind(MyTool::class, MyToolFake::class);
```

## Paso 6: Actualizar PhpFileBuilder (si la tool analiza código)

Fichero: `tests/Utils/PhpFileBuilder.php`

Si la tool detecta errores en código PHP, añadir:

1. Constante: `public const MY_TOOL = 'mytool';`
2. Método que genere un error detectable:
```php
public function addMyToolError(): string
{
    return "\n" . '    // código PHP que la tool detectará como error' . "\n";
}
```
3. Case en `buildWithErrors()`:
```php
case self::MY_TOOL:
    $file .= $this->addMyToolError();
    break;
```

## Paso 7: Actualizar DataProviders existentes

Estos tests ya tienen `allToolsProvider` o similar — añadir la nueva tool:

- `tests/Integration/IgnoreErrorsOnExitFlagTest.php` → `allToolsProvider()`
- `tests/System/Commands/ExecuteToolCommandTest.php` → `allToolsOKDataProvider()`, `allToolsKODataProvider()`, `allToolsAtSameTimeDataProvider()`, `onlyConfiguredToolsAtSameTimeDataProvider()`, `exit1DataProvider()`
- `tests/System/Release/ExecuteToolTest.php` → `allToolsProvider()`, `it_returns_exit_0_when_executes_all_tools_and_all_pass()`

## Paso 8: Actualizar configuración de QA

**`qa/githooks.php`** — añadir la tool a la lista de tools y su bloque de configuración:
```php
'Tools' => ['phpstan', 'phpmd', 'phpcs', ..., 'mytool'],
'mytool' => [
    'executablePath' => 'path/to/mytool',
    'paths' => ['./src/', './app/'],
    // ... configuración para el propio proyecto
],
```

**`qa/githooks.dist.yml`** — añadir la configuración de ejemplo en YAML.

## Paso 9: Actualizar GitHub Actions

Lee `references/ci-tool-integration.md` para los detalles de cada workflow.

**`main-tests.yml`** — añadir la tool en el step `Install PHP`:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan:1.4, mytool
```

**`release.yml`** → job `test_rc` → step `Install PHP`:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan, parallel-Lint, phpcpd, mytool
```

## Paso 10: Crear tests

Delega a la skill `php-test-creator` para generar los tests siguiendo los patrones del proyecto.

## Paso 11: FastExecution (si la tool es "acelerarable")

Si la tool acepta paths y tiene sentido ejecutarla solo sobre ficheros modificados en git:

Fichero: `src/LoadTools/FastExecution.php` → añadir al array `ACCELERABLE_TOOLS`:
```php
private const ACCELERABLE_TOOLS = [
    ToolAbstract::PHPCS,
    // ...
    ToolAbstract::MY_TOOL,
];
```

No todas las tools son acelerables. `security-checker` no tiene paths, `phpcpd` necesita todos los ficheros para detectar duplicados.

## Checklist final

### Ficheros creados
- [ ] `src/Tools/Tool/MyTool.php`
- [ ] `src/Tools/Tool/MyToolFake.php`

### Ficheros modificados
- [ ] `src/Tools/Tool/ToolAbstract.php` — constante + `SUPPORTED_TOOLS` + `EXCLUDE_ARGUMENT`
- [ ] `tests/Utils/ConfigurationFileBuilder.php` — `$tools` + `$configurationTools`
- [ ] `tests/Utils/TestCase/ConsoleTestCase.php` — `bindFakeTools()`
- [ ] `tests/Utils/PhpFileBuilder.php` — (si analiza código)
- [ ] `tests/Integration/IgnoreErrorsOnExitFlagTest.php` — `allToolsProvider`
- [ ] `tests/System/Commands/ExecuteToolCommandTest.php` — todos los dataProviders
- [ ] `tests/System/Release/ExecuteToolTest.php` — providers + tests `all tools`
- [ ] `qa/githooks.php`
- [ ] `qa/githooks.dist.yml`
- [ ] `.github/workflows/main-tests.yml`
- [ ] `.github/workflows/release.yml`
- [ ] `src/LoadTools/FastExecution.php` — (si es acelerarable)

### Verificación
- [ ] `vendor/bin/phpunit --order-by random` pasa
- [ ] `php githooks tool mytool` funciona localmente
- [ ] `php githooks tool all full` incluye la nueva tool
- [ ] `php githooks conf:check` muestra la tool en la tabla

## Permisos necesarios

### Lectura de ficheros
- `src/Tools/Tool/` — Clases de tools existentes como referencia y `ToolAbstract.php`
- `src/Tools/ToolsFactory.php` — Factory de tools (imports)
- `src/ConfigurationFile/` — `ToolConfiguration.php`, `ConfigurationFile.php`
- `src/LoadTools/FastExecution.php` — Si la tool es acelerarable
- `qa/githooks.php`, `qa/githooks.dist.yml` — Configuración del proyecto
- `tests/Unit/Tools/Tool/` — Tests de otras tools como referencia
- `tests/Utils/ConfigurationFileBuilder.php` — Builder de configuración para tests
- `composer.json` — Dependencias

### Escritura de ficheros
- `src/Tools/Tool/{NuevaTool}.php` — Clase de la tool
- `src/Tools/Tool/{NuevaTool}Fake.php` — Fake para testing
- `src/Tools/Tool/ToolAbstract.php` — Constantes y `SUPPORTED_TOOLS`
- `src/Tools/ToolsFactory.php` — Import de la nueva clase
- `qa/githooks.php` — Configuración del proyecto
- `qa/githooks.dist.yml` — Configuración de distribución
- `tests/Unit/Tools/Tool/{NuevaTool}Test.php` — Tests unitarios
- `tests/Utils/ConfigurationFileBuilder.php` — Configuración del builder
- `composer.json` — Dependencia en `require-dev` (si aplica)
- `qa/{config}.xml` — Fichero de configuración de la tool (si aplica)

### Comandos Bash
- `php7.1 vendor/bin/phpunit --order-by random` — Tests completos
- `php7.1 githooks tool {nombre} full` — Verificar tool individual
- `php7.1 githooks tool all full` — QA completo
- `php7.1 -l src/Tools/Tool/{NuevaTool}.php` — Verificar sintaxis PHP 7.1
- `git add` / `git commit` — Commits (solo si el usuario lo pide)

### Agentes
- **Explore** — Para investigar documentación de la tool externa (flags CLI, config)
- **general-purpose** — Para fetch de documentación web de la tool (verificar flags reales)
