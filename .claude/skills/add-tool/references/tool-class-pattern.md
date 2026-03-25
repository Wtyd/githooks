# Patrón de clase Tool

## Estructura de una Tool completa

Usa como referencia `Phpmd` (tool con más argumentos) o `Phpstan` (tool con subcomando `analyse`).

```php
<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Descripción de la librería que envuelve (e.g., "Library vimeo/psalm")
 */
class MyTool extends ToolAbstract
{
    // NAME debe coincidir con la key usada en SUPPORTED_TOOLS
    public const NAME = self::MY_TOOL;

    // Constantes para argumentos específicos de la tool
    public const CONFIG = 'config';
    public const LEVEL = 'level';
    public const PATHS = 'paths';
    public const EXCLUDE = 'exclude';

    // ARGUMENTS define el orden de construcción del comando
    // y las claves válidas de configuración
    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,   // Siempre primero (heredado de ToolAbstract)
        self::CONFIG,
        self::LEVEL,
        self::PATHS,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,        // Siempre penúltimo (heredado)
        self::IGNORE_ERRORS_ON_EXIT,    // Siempre último (heredado)
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        // Nombre del ejecutable por defecto
        $this->executable = self::MY_TOOL;

        // setArguments filtra los argumentos válidos y normaliza paths
        $this->setArguments($toolConfiguration->getToolConfiguration());

        // Default executablePath si no se proporciona
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    /**
     * Construye el comando shell.
     * El orden de iteración sigue ARGUMENTS.
     * Argumentos vacíos se saltan silenciosamente.
     */
    public function prepareCommand(): string
    {
        $command = '';
        foreach (self::ARGUMENTS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    // Algunos tools necesitan un subcomando (phpstan → "analyse")
                    $command = $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::CONFIG:
                    $command .= ' --config=' . $this->args[self::CONFIG];
                    break;
                case self::LEVEL:
                    $command .= ' --level=' . $this->args[self::LEVEL];
                    break;
                case self::PATHS:
                    // Según la tool: separados por espacio o coma
                    $command .= ' ' . implode(' ', $this->args[$option]);
                    break;
                case self::EXCLUDE:
                    // Según la tool: --exclude por cada path o coma-separated
                    foreach ($this->args[$option] as $excludePath) {
                        $command .= ' --exclude ' . $excludePath;
                    }
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    // No se añade al comando — se maneja en ProcessExecution
                    break;
                default:
                    // OTHER_ARGS_OPTION — se pasa tal cual
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        return $command;
    }
}
```

## Decisiones de diseño a tomar

Al crear una nueva tool, hay que tomar estas decisiones:

1. **¿Tiene subcomando?** (como `phpstan analyse`) → Añadirlo tras el executablePath
2. **¿Cómo se pasan los paths?** — separados por espacio (`phpstan src tests`) o coma (`phpmd src,tests`)
3. **¿Cómo se pasan los excludes?** — un flag por path (`--exclude vendor --exclude tests`) o coma-separated (`--exclude "vendor,tests"`)
4. **¿Tiene argumentos específicos?** — definir constantes para cada uno (como `LEVEL`, `CONFIG`, `RULES`)
5. **¿Es acelerarable?** — ¿tiene sentido ejecutarla solo sobre ficheros modificados en git? Si analiza fichero por fichero sí (phpcs, phpmd). Si necesita contexto global no (phpcpd, security-checker)
6. **¿El output de éxito es fiable?** — Algunas tools retornan exit 0 incluso con errores ocultos. Si es el caso, sobreescribir `isThereHiddenError()` (como hace `Phpmd`)

## Constantes heredadas de ToolAbstract

Estas constantes ya están disponibles en todas las Tools:

```php
ToolAbstract::EXECUTABLE_PATH_OPTION  = 'executablePath'
ToolAbstract::OTHER_ARGS_OPTION       = 'otherArguments'
ToolAbstract::IGNORE_ERRORS_ON_EXIT   = 'ignoreErrorsOnExit'
```
