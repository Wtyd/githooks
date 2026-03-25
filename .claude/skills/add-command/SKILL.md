---
name: add-command
description: >
  Guía para crear o modificar comandos artisan en el proyecto GitHooks (wtyd/githooks), incluyendo
  opciones CLI, interacción con el sistema de configuración, y DI por constructor.
  Usa esta skill cuando el usuario quiera crear un nuevo comando, modificar un comando existente,
  añadir opciones al CLI, cambiar el comportamiento de un comando, o cuando mencione "nuevo comando",
  "añadir opción", "modificar command", "crear subcomando", "flag", "argumento CLI".
  También cuando se toque CliArguments, OptionsConfiguration, o cualquier fichero en app/Commands/.
---

# Crear o modificar comandos artisan en GitHooks

GitHooks usa Laravel Zero como framework CLI. Los comandos viven en `app/Commands/` y se
auto-descubren por `config/commands.php`.

## Tipos de comando en el proyecto

| Tipo | Base class | DI | Ejemplo |
|---|---|---|---|
| **Tool command** | `ToolCommand` (abstracto) | `ReadConfigurationFileAction`, `ToolsPreparer`, `ProcessExecutionFactory` | `ExecuteToolCommand` |
| **Config command** | `Command` (Laravel Zero) | `FileReader`, `Printer`, `ToolsPreparer` | `CheckConfigurationFileCommand` |
| **Hook command** | `Command` (Laravel Zero) | `Printer` | `CreateHookCommand`, `CleanHookCommand` |
| **Build command** | `Command` (Laravel Zero) | `Build` | `PreBuildCommand`, `BuildCommand` |

## Crear un nuevo comando

### 1. Definir la clase

```php
<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;

class MyCommand extends Command
{
    // Signature con argumentos y opciones Laravel
    protected $signature = 'mycommand
                            {arg : Descripción del argumento}
                            {optionalArg? : Argumento opcional}
                            {--o|option= : Opción con valor}
                            {--flag : Flag booleano}';

    protected $description = 'Descripción del comando';

    // DI por constructor — Laravel resuelve las dependencias del container
    public function __construct(DependencyClass $dependency)
    {
        $this->dependency = $dependency;
        parent::__construct();  // IMPORTANTE: siempre llamar a parent después de asignar
    }

    public function handle()
    {
        $arg = strval($this->argument('arg'));
        $option = strval($this->option('option'));

        try {
            // Lógica del comando
            return 0;
        } catch (SpecificException $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
```

### 2. Registro

Los comandos se auto-descubren desde `app/Commands/`. No hace falta registrar manualmente.

Para ocultar un comando de la ayuda (pero que siga siendo ejecutable):

```php
// config/commands.php
'hidden' => [
    // ...
    Wtyd\GitHooks\App\Commands\MyCommand::class,
],
```

### 3. Inyección de dependencias

El container de Laravel inyecta las dependencias por constructor. Los bindings se configuran en:

- `src/Container/RegisterBindings.php` — bindings de producción
- `app/Providers/AppServiceProvider.php` → `testsRegister()` — swaps para testing

Si tu comando necesita una nueva dependencia que aún no está registrada, añádela en `RegisterBindings`:

```php
// src/Container/RegisterBindings.php
$this->container->bind(MyInterface::class, MyImplementation::class);
```

Y si necesita un fake para tests:

```php
// app/Providers/AppServiceProvider.php → testsRegister()
$this->app->singleton(MyInterface::class, MyInterfaceFake::class);
```

## Añadir una opción CLI al comando `tool`

El flujo es: CLI → `CliArguments` → `overrideArguments()` → `ConfigurationFile`.

### 1. Añadir la opción a la signature

```php
// app/Commands/ExecuteToolCommand.php
protected $signature = 'tool
    {tool : ...}
    {execution? : ...}
    {--myOption= : Descripción de mi opción}
    // ...';
```

### 2. Pasar al CliArguments

```php
// En handle():
$configurationFile = $this->readConfigurationFileAction
    ->__invoke(new CliArguments(
        $tool,
        $execution,
        // ... opciones existentes
        strval($this->option('myOption')),  // Nuevo
    ));
```

### 3. Actualizar CliArguments

```php
// src/ConfigurationFile/CliArguments.php
protected $myOption;

public function __construct(
    // ... parámetros existentes
    string $myOption = ''
) {
    // ... asignaciones existentes
    $this->myOption = $myOption;
}

// En overrideArguments() o overrideToolArguments():
if (!empty($this->myOption)) {
    $toolConfiguration['myOption'] = $this->myOption;
}
```

### 4. Actualizar OptionsConfiguration (si es una opción global)

Si la opción aplica a todas las tools (como `execution` o `processes`):

```php
// src/ConfigurationFile/OptionsConfiguration.php
public const MY_OPTION_TAG = 'myOption';

// En el constructor, añadir validación
```

## Patrón de manejo de errores

Los comandos de tool usan una cascada de catches específicos:

```php
try {
    // lógica
} catch (ToolIsNotSupportedException $e) {
    // Tool no existe en SUPPORTED_TOOLS
} catch (WrongOptionsValueException $e) {
    // Valor inválido en Options (e.g., execution='invalid')
} catch (ConfigurationFileNotFoundException $e) {
    // No se encontró githooks.php ni githooks.yml
} catch (ConfigurationFileInterface $e) {
    // Cualquier otro error de configuración — muestra errors + warnings
    foreach ($e->getConfigurationFile()->getErrors() as $error) { ... }
    foreach ($e->getConfigurationFile()->getWarnings() as $warning) { ... }
}
```

`ConfigurationFileInterface` es el catch-all para errores de configuración. Todas las excepciones
de configuración implementan esta interfaz.

## Output

Para output se usan dos mecanismos:

1. **Métodos de `Command`** (Laravel): `$this->info()`, `$this->error()`, `$this->warn()`, `$this->table()`
2. **`Printer`** (custom): `$printer->resultSuccess()`, `$printer->resultError()`, `$printer->resultWarning()`

`Printer` usa códigos ANSI directamente y es independiente de Laravel.
Los comandos que extienden `ToolCommand` usan los métodos de `Command`.
Otros comandos inyectan `Printer` por constructor.

## Testing de comandos

Los system tests en `tests/System/Commands/` son los que verifican los comandos.
Usa la skill `php-test-creator` y consulta la referencia `system-tests.md`.

El patrón básico:
```php
$this->artisan("mycommand arg --option=value")
    ->assertExitCode(0)
    ->containsStringInOutput('expected output')
    ->expectsOutput('exact line');
```

## Checklist

- [ ] Clase creada en `app/Commands/`
- [ ] DI por constructor con `parent::__construct()` al final
- [ ] Signature con descripciones para todos los argumentos/opciones
- [ ] Manejo de errores con catches apropiados
- [ ] Exit code: 0 éxito, 1 error
- [ ] Si toca `CliArguments`: actualizado constructor + `overrideArguments()`
- [ ] Si añade binding: registrado en `RegisterBindings` + fake en `testsRegister()`
- [ ] Tests creados (delegar a `php-test-creator`)
