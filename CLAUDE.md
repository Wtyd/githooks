# CLAUDE.md

## Idioma

Responde siempre en castellano (español de España).

## Qué es este proyecto

**GitHooks** (`wtyd/githooks`) es una herramienta CLI en PHP distribuida como `.phar`, construida con Laravel Zero. Gestiona git hooks y ofrece una interfaz unificada para ejecutar herramientas de QA (phpstan, phpmd, phpcs, phpunit, psalm, etc.) contra un código fuente. Se distribuye como binario standalone para no interferir con las dependencias Composer del proyecto destino.

## Proceso de trabajo

### Antes de empezar cualquier tarea

1. **Verificar rama:** Asegúrate de estar en una rama de tarea con formato `gh-{ID}` (ej: `gh-42`). Si estás en `master`, pregunta al usuario antes de continuar.
2. **Leer antes de escribir:** No propongas cambios en código que no hayas leído.

### Tareas grandes (nueva feature, nueva tool, refactor)

1. **Analizar** — Leer los ficheros involucrados, identificar qué tipo de tarea es y qué skills aplican.
2. **Resolver dudas** — Si hay cualquier ambigüedad sobre el alcance, los casos de uso o el comportamiento esperado, preguntar al usuario **antes** de entrar en modo plan. No asumir.
3. **Planificar** — Entrar en modo plan (`/plan`). El plan debe incluir un apartado **"Qué y por qué"** que explique qué se va a hacer y la justificación de cada decisión relevante. Listar ficheros a crear/modificar, explicar enfoque y trade-offs. No escribir código.
4. **Validar** — Esperar confirmación del usuario antes de ejecutar.
5. **Ejecutar** — Implementar paso a paso, siguiendo la skill correspondiente.
6. **Verificar** — Ejecutar los checks automáticamente (ver sección "Verificación").
7. **Reportar** — Resumen de ficheros creados/modificados y estado de los checks.

### Tareas pequeñas (bug fix, cambio menor)

Analizar → Ejecutar → Verificar. Sin modo plan ni validación previa.

### Verificación (siempre, al terminar)

El orden de verificación depende del contexto:

| Contexto | Orden |
|---|---|
| **Feature nueva** | Código → QA sobre `src/` → Tests → QA completo |
| **Añadir tests** | Tests hasta que pasen → QA completo al final |
| **Bug fix** | Fix → Test que reproduzca → QA completo |
| **Refactor** | QA + Tests antes (baseline) → Refactor → QA + Tests después |

```bash
# Tests (usar php7.4 por defecto)
php7.4 vendor/bin/phpunit --order-by random

# QA tools (v3)
php7.4 githooks flow qa
```

Si algún check falla, seguir la skill `qa-workflow` para gestionar las violaciones (corregir, suprimir o documentar según criterio).

**Si se usó una skill, verificar CADA punto de su checklist antes de reportar.** Leer los ficheros implicados y confirmar que los cambios están presentes — no asumir que está hecho.

### Commits

Formato **Conventional Commits**: `tipo(ámbito): descripción`

```
feat(phpunit): add support for phpunit as QA tool
fix(ConfigurationFile): handle empty Options tag gracefully
test(ComposerUpdater): add unit tests for version path resolution
refactor(FastExecution): simplify file filtering logic
```

**Nunca commitear automáticamente.** Proponer commit cuando haya una unidad de código autosuficiente — funcionalidad que aunque no esté acabada, esté operativa y no rompa nada.

### Señales de alarma (cuándo parar)

Dos umbrales para evitar bucles improductivos:

- **3 ediciones sobre el mismo fichero en la misma tarea** → parar, re-leer el requisito original del usuario y confirmar que el enfoque sigue siendo correcto antes de seguir editando.
- **2 fallos consecutivos del mismo tipo de acción** (mismo test falla, mismo comando rompe, misma edición genera el mismo error) → parar, diagnosticar la causa raíz. Si no está clara, resumir lo intentado y preguntar al usuario en vez de reintentar.

## Entorno de ejecución

El contenedor tiene PHP 7.0 a 8.4. Se invocan como `php7.0`, `php7.1`, ..., `php8.4`. La versión por defecto (`php`) es 8.4.

**Regla:** usar siempre `php7.4` para desarrollo por defecto. Las dependencias compatibles siguen los tiers del build:

| Composer update con | Ejecutable con | Tier |
|---|---|---|
| `php7.4 tools/composer update` | PHP 7.4, 8.0 | `builds/php7.4/` |
| `php8.1 tools/composer update` | PHP 8.1, 8.2, 8.3, 8.4, 8.5 | `builds/` |

Para probar con otra versión, primero actualizar dependencias con el `composer update` correspondiente al tier.

## Comandos de referencia

```bash
# Tests
php7.4 vendor/bin/phpunit --order-by random      # Suite completa
php7.4 vendor/bin/phpunit tests/Unit              # Solo unitarios
php7.4 vendor/bin/phpunit --group git             # Tests de git (excluidos por defecto)
php7.4 vendor/bin/phpunit --group release         # Tests de release (requieren .phar)

# QA (v3 — usa qa/githooks.php con formato hooks/flows/jobs)
php7.4 githooks flow qa --format=json       # Flow completo de QA
php7.4 githooks job phpstan_src --format=json  # Job individual

# Build
php7.4 githooks app:pre-build php
php7.4 githooks app:build
```

Grupos de test excluidos por defecto: `@group release`, `@group git`, `@group windows`.

### Invocación por Claude — siempre `--format=json`

**Regla obligatoria**: cuando Claude ejecuta `githooks flow`, `githooks job`, `githooks conf:check`, etc. como parte de una tarea (no para probar el output humano), usar **siempre** `--format=json` y parsear la respuesta.

Razones:
- **stdout limpio y parseable** — sin ANSI, sin barras de progreso, sin mensajes `⏩` mezclados.
- **stderr silencioso sin TTY** — el progreso solo aparece cuando hay un terminal interactivo o con `-v`. Ejecutado desde Claude, CI o un pipe, stderr está vacío por defecto. No hace falta `2>/dev/null`.
- **Output determinista** — estructura fija `{version, flow, success, totalTime, executionMode, passed, failed, skipped, jobs[{name, type, success, exitCode, output, command, paths, skipped, skipReason}]}`.
- **Extracción directa** — filtrar jobs fallidos (`jq '.jobs[] | select(.success == false)'`) en lugar de parsear bloques coloreados.

Ejemplo idiomático:

```bash
php7.4 githooks flow qa --format=json | python3 -c "
import json, sys
d = json.load(sys.stdin)
print(f'{d[\"passed\"]}/{d[\"passed\"]+d[\"failed\"]} passed, {d[\"skipped\"]} skipped')
for j in d['jobs']:
    if not j['success'] and not j.get('skipped'):
        print(f'  KO {j[\"name\"]} ({j[\"type\"]}): exitCode={j[\"exitCode\"]}')"
```

**Forzar progreso en CI o pipelines largos**: añadir `--show-progress` — el handler emite `OK/KO jobname [n/m]` en stderr aunque no haya TTY. stdout sigue siendo JSON limpio.

**Excepción**: la skill `qa-tester` ejecuta los comandos sin `--format=json` porque su cometido es precisamente validar el output humano (colores, progreso, dashboard TTY).

**Pasar args al tool subyacente**: `githooks job` acepta el separador POSIX `--`; todo lo que va después se concatena al comando generado. Usar esto en lugar de invocar `vendor/bin/<tool>` directamente para iterar sobre un subconjunto:

```bash
# Correcto — respeta config del proyecto (--log-junit, --colors)
php7.4 githooks job "Phpunit" --format=json -- --filter=MyTest
php7.4 githooks job "Phpunit" --format=json -- --group=slow --stop-on-failure

# Evitar — rompe la config
php7.4 vendor/bin/phpunit --filter=MyTest
```

`githooks flow` **no** soporta `--` (no tiene sentido aplicar args específicos a todos los jobs del flow).

## Arquitectura (orientación)

### Flujo de ejecución de tools

```
CLI (ExecuteToolCommand) → ConfigurationFile → ToolsPreparer → ProcessExecutionFactory → Tool::prepareCommand()
```

1. El comando recibe nombre de tool y modo (full/fast)
2. `ConfigurationFile` parsea `githooks.php` (prioridad) o `githooks.yml`
3. `ToolsPreparer` → `ToolsFactory` instancia las tools
4. `ProcessExecutionFactory` crea ejecución single o paralela
5. Cada tool construye su comando shell via `prepareCommand()`

### Estructura de directorios

| Directorio | Contenido |
|---|---|
| `app/Commands/` | Comandos artisan (capa CLI) |
| `src/Tools/Tool/` | Clases de QA tools + sus `*Fake.php` |
| `src/ConfigurationFile/` | Parseo y validación de config |
| `src/LoadTools/` | Estrategias de ejecución (Full/Fast) |
| `src/Tools/Process/` | Ejecución de procesos (single/parallel) |
| `tests/Unit/`, `Integration/`, `System/` | Tests por nivel |

### Formato de configuración

```php
// githooks.php
return [
    'Options' => ['execution' => 'full', 'processes' => 8],
    'Tools'   => ['phpstan', 'phpmd', 'phpcs'],
    'phpstan' => ['config' => 'qa/phpstan.neon'],
];
```

## Restricciones técnicas

- **PHP >=7.4** como mínimo. El `.phar` se compila en 2 binarios: `builds/php7.4/` (7.4, 8.0), `builds/` (8.1+). Si tocas `Build.php` o `ComposerUpdater.php`, consulta la skill `ci-pipeline`. Los hooks de hookify verifican automáticamente la compatibilidad de syntax.
- **Sin dependencias runtime en `require`.** Todas las dependencias están en `require-dev` por diseño — el `.phar` las embebe en compilación. Si necesitas añadir una dependencia, va en `require-dev`. Nunca en `require` salvo que se hable explícitamente con el usuario.
- **Dirección de dependencias:** `src/` nunca importa de `app/` (hook de hookify lo bloquea).
- **`declare(strict_types=1)`** obligatorio en `src/` (hook de hookify lo verifica).
- **`ToolsFactory`** — factory que instancia las tools desde la configuración.
- **`src/Tools/Process/`** excluido de PHPStan — wraps de Symfony Process.
- **PHPStan nivel 8**, PSR-12, PHPMD completo — ejecutados via `php7.4 githooks tool all full`.

## Skills disponibles

| Tarea | Skill |
|---|---|
| Añadir una nueva QA tool | `.claude/skills/add-tool/` |
| Crear/modificar un comando artisan | `.claude/skills/add-command/` |
| Modificar CI/CD o build del .phar | `.claude/skills/ci-pipeline/` |
| Crear tests para una feature | `.claude/skills/php-test-creator/` |
| QA antes de commit (phpmd, phpstan, tests) | `.claude/skills/qa-workflow/` |
| Testing funcional del CLI | `.claude/skills/qa-tester/` |
| Documentación externa (MkDocs) | `.claude/skills/docs/` |
| Analizar informe de Infection (mutants escaped) | `.claude/skills/mutation-analyzer/` |

Consulta la skill correspondiente antes de empezar una tarea que encaje en estas categorías.
