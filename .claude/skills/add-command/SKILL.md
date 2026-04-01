---
name: add-command
description: >
  Guia para crear o modificar comandos artisan en GitHooks (wtyd/githooks).
  Usa esta skill cuando el usuario quiera crear un nuevo comando, modificar un comando existente,
  añadir opciones al CLI, cambiar el comportamiento de un comando, o cuando mencione "nuevo comando",
  "añadir opción", "modificar command", "flag", "argumento CLI".
  También cuando se toque ConfigurationParser, FlowPreparer, FlowExecutor, o cualquier fichero en app/Commands/.
---

# Crear o modificar comandos artisan en GitHooks

GitHooks usa Laravel Zero como framework CLI. Los comandos viven en `app/Commands/` y se
auto-descubren por `config/commands.php`.

## Tipos de comando

| Tipo | DI principal | Ejemplos |
|---|---|---|
| **Flow/Job** | `ConfigurationParser`, `FlowPreparer`, `FlowExecutor` | `FlowCommand`, `JobCommand` |
| **Hook** | `ConfigurationParser`, `HookRunner` o `HookInstaller` | `HookRunCommand`, `CreateHookCommand` |
| **Config** | `ConfigurationParser`, `FileReader`, `JobRegistry` | `CheckConfigurationFileCommand`, `MigrateConfigurationFileCommand` |
| **Status/Info** | `ConfigurationParser`, `HookStatusInspector` | `StatusCommand`, `SystemInfoCommand` |
| **Build** | `Build` | `PreBuildCommand`, `BuildCommand` |
| **Legacy (deprecated)** | `ReadConfigurationFileAction`, `ToolsPreparer` | `ExecuteToolCommand` |

## Flujo v3 de un comando de ejecución

```
CLI (FlowCommand/JobCommand)
  → ConfigurationParser::parse()     → ConfigurationResult
  → FlowPreparer::prepare()          → FlowPlan
  → FlowExecutor::execute()          → FlowResult
  → FormatsOutput::renderFormattedResult()
```

## Crear un nuevo comando

### 1. Definir la clase

```php
<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

class MyCommand extends Command
{
    protected $signature = 'mycommand
                            {name : Argumento obligatorio}
                            {optionalArg? : Argumento opcional}
                            {--fail-fast : Flag booleano}
                            {--processes= : Opción con valor}
                            {--config= : Path to configuration file}';

    protected $description = 'Descripción del comando';

    private ConfigurationParser $parser;

    public function __construct(ConfigurationParser $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    public function handle(): int
    {
        // 1. LEER todas las opciones del signature
        $name = strval($this->argument('name'));
        $configFile = strval($this->option('config'));
        $failFast = (bool) $this->option('fail-fast');
        $processes = $this->option('processes');

        try {
            // 2. Parsear config
            $config = $this->parser->parse($configFile);

            // 3. Validar
            if ($config->hasErrors()) {
                foreach ($config->getValidation()->getErrors() as $error) {
                    $this->error($error);
                }
                return 1;
            }

            // 4. PROPAGAR opciones CLI al plan/ejecución
            //    Las opciones del CLI deben sobrescribir la config del fichero
            // ...

            return 0;
        } catch (GitHooksExceptionInterface $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
```

### 2. Formato del signature

**IMPORTANTE — Bug conocido con shortcuts:**

El formato `{-c|--config=}` NO funciona en Laravel Zero. Produce `The "-c" option does not exist.`
Hasta que se investigue la causa, usar solo la forma larga:

```php
// MAL — no funciona
{-c|--config= : Path to configuration file}

// BIEN — funciona
{--config= : Path to configuration file}
```

Tipos de opciones:

```php
{name : ...}              // Argumento obligatorio
{name? : ...}             // Argumento opcional
{name=default : ...}      // Argumento con valor por defecto
{--flag : ...}             // Flag booleano (true/false)
{--option= : ...}         // Opción que requiere valor
{--option=default : ...}  // Opción con valor por defecto
```

### 3. Regla crítica: toda opción DEBE leerse y propagarse

**Cada opción definida en el signature DEBE:**
1. Leerse en `handle()` con `$this->option('nombre')`
2. Propagarse al servicio correspondiente (FlowPreparer, FlowExecutor, etc.)
3. Tener un test que verifique que el valor llega al destino

Si una opción se define en el signature pero no se usa en `handle()`, es código muerto que engaña al usuario.

### 4. Registro

Los comandos se auto-descubren desde `app/Commands/`. Para ocultar de la ayuda:

```php
// config/commands.php
'hidden' => [
    Wtyd\GitHooks\App\Commands\MyCommand::class,
],
```

### 5. Inyección de dependencias

Bindings en `src/Container/RegisterBindings.php`:

```php
// Singletons principales v3
ConfigurationParser::class  → (factory con ToolRegistry + JobRegistry)
FlowPreparer::class         → (factory con JobRegistry)
FlowExecutor::class         → (factory con OutputHandler)
HookRunner::class           → (factory con FlowPreparer + FlowExecutor + FileUtils)
HookInstaller::class        → (factory con getcwd)
HookStatusInspector::class  → (factory con getcwd)
JobRegistry::class          → JobRegistry
ToolRegistry::class         → ToolRegistry
OutputHandler::class        → TextOutputHandler(Printer)
```

Si el comando necesita una nueva dependencia, añadirla en `RegisterBindings::singletons()`.

### 6. Trait FormatsOutput

Para comandos que ejecutan flows/jobs y soportan `--format` y `--monitor`:

```php
use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;

class MyCommand extends Command
{
    use FormatsOutput;

    public function handle(): int
    {
        // Antes de ejecutar: configura output handler según formato
        $this->applyFormat($this->executor);

        $result = $this->executor->execute($plan);

        // Después de ejecutar: renderiza resultado en el formato solicitado
        $this->renderFormattedResult($result);

        // Opcional: report de threads
        if ($this->option('monitor')) {
            $this->renderMonitorReport($result);
        }
    }
}
```

### 7. Manejo de errores

```php
try {
    // lógica
} catch (GitHooksExceptionInterface $e) {
    // Catch-all para excepciones del dominio
    $this->error($e->getMessage());
    return 1;
}
```

Para config inexistente, capturar también `\Throwable` porque `require` de un fichero
inexistente lanza `ErrorException`.

## Checklist

### Estructura
- [ ] Clase en `app/Commands/` con `declare(strict_types=1)`
- [ ] DI por constructor con `parent::__construct()` al final
- [ ] Signature con descripciones para todos los argumentos/opciones
- [ ] Exit code: 0 éxito, 1 error

### Regla de opciones CLI (CRITICA)
- [ ] **Toda opción del signature se lee en `handle()` con `$this->option()`**
- [ ] **Toda opción leída se propaga al servicio que la necesita**
- [ ] **No hay opciones en el signature que se ignoren silenciosamente**
- [ ] No usar shortcuts (`{-c|--config=}`) — formato roto en Laravel Zero

### DI y bindings
- [ ] Si usa dependencia nueva: registrada en `RegisterBindings::singletons()`
- [ ] Si usa FormatsOutput: `use FormatsOutput` + llamar a `applyFormat` y `renderFormattedResult`

### Testing
- [ ] Test que cada opción CLI modifica el comportamiento (no solo que el comando no crashea)
- [ ] Test con config inexistente → mensaje amigable, no stack trace
- [ ] Test con config inválida → errores descriptivos
- [ ] Delegar a skill `php-test-creator` para tests completos
- [ ] Probar manualmente con la skill `qa-tester` los edge cases

## Contexto legacy

El sistema v2 (Options/Tools/CliArguments) sigue funcionando como puente de compatibilidad.
Las clases legacy están en `src/ConfigurationFile/`, `src/Tools/`, `src/LoadTools/`.
El comando `tool` en `ExecuteToolCommand.php` está deprecated.
No añadir funcionalidad nueva al sistema legacy — solo mantener hasta v4.0.
