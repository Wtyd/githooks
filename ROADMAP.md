# GitHooks Roadmap

Evolución post v3.0.0.

---

## v3.0.0 — De tools a flows/hooks/jobs (completada)

Release mayor: PHP 7.4 mínimo, ToolRegistry, arquitectura hooks/flows/jobs, configuración split, ejecución con thread budget, output estructurado (JSON/JUnit), comandos `flow`, `job`, `hook`, `status`, `system:info`, `conf:migrate`, `cache:clear`, auto-restage tras phpcbf, fast mode para custom jobs, `--monitor`, `--dry-run`, `--only-jobs`, auto-detección de `executablePath`, validación profunda en `conf:check`.

Incluye features planificadas originalmente para versiones posteriores:
- ~~v3.1.0~~: `--format=json|junit`, agrupación de errores, `githooks status`, `$GITHOOKS_STAGED_FILES` en custom jobs
- ~~v3.2.0~~: `--dry-run`, `--only-jobs`, `cache:clear`, validación profunda en `conf:check`, auto-detección de `executablePath`

**Breaking changes:**
- PHP mínimo: 7.1 → 7.4
- SecurityChecker eliminado (usar `composer audit` como custom job)
- Formato YAML deprecated (funciona con warning)
- Comando `tool` deprecated (reemplazado por `flow`/`job`)

---

## ~~v3.1.0~~ — Incluida en v3.0.0

Las 5 features de DX planificadas para v3.1.0 se incorporaron a la v3.0.0 antes del release:

- ~~**`--dry-run` en `flow` y `job`**~~: muestra los comandos sin ejecutarlos
- ~~**Validación profunda en `conf:check`**~~: verifica executables, paths y ficheros de config
- ~~**Auto-detección de `executablePath`**~~: busca `vendor/bin/{tool}` antes de caer al PATH
- ~~**`--only-jobs` en `flow`**~~: inverso de `--exclude-jobs`, ejecuta solo los jobs indicados
- ~~**Comando `cache:clear`**~~: borra cachés de PHPStan, PHPMD, PHPCS, Psalm, PHPUnit

---

## v3.2.0 — Extensibilidad y madurez

Prepara la herramienta para proyectos complejos y configuración avanzada.

### Variables de entorno en la configuración

Referencias a `$_ENV` en `githooks.php` para adaptar comportamiento (local vs CI) sin duplicar flows.

### Argumentos nativos ampliados

Reducir dependencia de `otherArguments` incorporando flags comunes de cada tool como claves tipadas con validación en `conf:check`.

### Modo de ejecución `incremental`

Tercer modo junto a `full` y `fast`. Cachea último resultado por archivo, re-analiza solo los que cambiaron desde la última ejecución exitosa.

### Herencia de jobs (`extends`)

Evitar duplicar config cuando dos jobs solo difieren en `paths`:

```php
'phpmd_src' => ['extends' => 'phpmd_base', 'paths' => ['src']],
'phpmd_app' => ['extends' => 'phpmd_base', 'paths' => ['app']],
```

### `conf:init` interactivo

Detectar binarios en `vendor/bin/`, preguntar directorios fuente, generar config adaptada al proyecto.

### Condiciones de ejecución en hooks

Sistema de condiciones que permita decidir si un flow/job se ejecuta según el contexto:
- **Por rama**: ejecutar solo en `main`/`develop`, o excluir ramas con patrón (`feature/*`)
- **Por ficheros staged**: ejecutar solo si hay ficheros de cierto tipo (`.php`, `.js`) en el staging

Caso de uso: checks pesados solo en pre-push a main, checks ligeros en pre-commit de cualquier rama.

```php
'hooks' => [
    'pre-push' => [
        'flows'   => ['fullAnalysis'],
        'only-on' => ['main', 'develop'],
    ],
],
```

### Gestión de caché de herramientas

PHPStan y PHPMD tienen problemas de rendimiento en proyectos grandes por la gestión de su caché interna. Analizar cómo GitHooks puede ayudar:
- Preservar/invalidar cachés entre ejecuciones
- Interacción entre caché de la herramienta y los modos fast/incremental de GitHooks
- Configuración de directorios de caché por job

---

## v4.0 — Limpieza de deprecaciones

Eliminación de todo lo marcado como deprecated en v3.0.

- **Eliminar soporte YAML**: solo formato PHP (`githooks.php`)
- **Eliminar comando `tool`**: solo `flow` y `job`
- **Eliminar formato de configuración v2** (`Options`/`Tools`): solo hooks/flows/jobs
