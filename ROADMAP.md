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

## v3.2.0 — Extensibilidad

Prepara la herramienta para proyectos complejos y configuración avanzada.

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

### Herencia de jobs (`extends`)

Evitar duplicar config cuando dos jobs solo difieren en `paths`:

```php
'phpmd_src' => ['extends' => 'phpmd_base', 'paths' => ['src']],
'phpmd_app' => ['extends' => 'phpmd_base', 'paths' => ['app']],
```

### `conf:init` interactivo

Detectar binarios en `vendor/bin/`, preguntar directorios fuente, generar config adaptada al proyecto.

---

### Features descartadas

Las siguientes features se evaluaron y se descartaron del roadmap:

**Modo de ejecución `incremental`** — Descartado. Las tools que importan (PHPStan, PHPMD, PHPCS, Psalm) ya gestionan su propia caché por fichero y la invalidan automáticamente al detectar cambios. Un modo incremental en GitHooks duplicaría ese trabajo sin beneficio real. Las tools sin caché propia (parallel-lint, phpcpd) son lo bastante rápidas como para no necesitarlo.

**Gestión de caché de herramientas** — Descartado. Cubierto por el comando `cache:clear` para borrar cachés, y por la configuración nativa de cada tool (`tmpDir`, `--cache`, `cache-file`) que ya se expone via ARGUMENT_MAP. GitHooks no necesita una capa adicional de gestión.

**Argumentos nativos ampliados** — Descartado. Los argumentos principales de cada tool ya están en sus ARGUMENT_MAP. Los argumentos secundarios (e.g. `--autoload-file` de PHPStan, `--coverage-html` de PHPUnit) son específicos de configuraciones avanzadas que se gestionan mejor desde la propia config de la tool (`phpstan.neon`, `phpunit.xml`). `otherArguments` cubre correctamente los casos restantes sin necesidad de mapeo tipado.

**Variables de entorno en la configuración** — Descartado. Como `githooks.php` es PHP puro, el usuario ya puede usar `getenv()`, `$_ENV` o cualquier lógica condicional directamente en el return array. No hace falta un sistema adicional.

---

## v4.0 — Limpieza de deprecaciones

Eliminación de todo lo marcado como deprecated en v3.0.

- **Eliminar soporte YAML**: solo formato PHP (`githooks.php`)
- **Eliminar comando `tool`**: solo `flow` y `job`
- **Eliminar formato de configuración v2** (`Options`/`Tools`): solo hooks/flows/jobs
