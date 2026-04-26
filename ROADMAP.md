# Roadmap GitHooks

## Resumen por versión

| Versión  | Tema                  | Ítems principales                                                                                                                           |
| -------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **v3.0** | Release               | Versión final publicada. Arquitectura hooks/flows/jobs, modos fast/fast-branch, conf:init interactivo, output JSON/JUnit, thread budgeting. |
| **v3.1** | Adopción ✔            | Documentación externa, override local + Docker, argumentos extra por CLI para jobs, comparación + migraciones                               |
| **v3.2** | Herramientas y Output ✔ | PHP CS Fixer nativo, Rector nativo, rediseño output (streaming + dashboard paralelo), output CI nativo, formatos Code Climate y SARIF, revisión JSON para IA, tests Windows |
| **Fase 0** | Consolidación QA pre-3.3.0 | Reestructuración CI (flows + herencia + kebab-case + contrato SARIF), cobertura y verificaciones post-3.2, colisión `-v`, silenciar progreso stderr en CI |
| **v3.3** | Madurez               | Comando `flows` multi-flow, multi-reporte (estilo PHPUnit/Psalm) ✔, flag `--files`/`--files-from` ✔, monitor de rendimiento + time threshold, memory budget por job (diseño abierto), kebab-case (deprecation paso 1), validación commit messages (nativo), receta config compartida Composer (docs) |

---

## v3.1 — Adopción ✔

Objetivo: reducir la fricción para nuevos usuarios y equipos. **Release Candidate disponible.**

### 1. Documentación externa ✔

Site estático con MkDocs Material desplegado en GitHub Pages. Incluye: guía de inicio rápido, referencia completa de configuración (hooks, flows, jobs, options), referencia CLI, documentación de cada tool, guías how-to (paralelismo, hooks condicionales, CI/CD, frontend, herencia, formatos de output, Docker + override local), guías de migración (v2→v3, GrumPHP, CaptainHook) y página de comparación.

### 2. Override local por desarrollador + soporte Docker ✔

Implementado. `githooks.local.php` se busca junto a `githooks.php` y se mergea con `array_replace_recursive`. La opción `executable-prefix` (a nivel global, flow o job) permite anteponer comandos como `docker exec -i app` a todos los ejecutables. Los jobs pueden hacer opt-out con `executable-prefix: ''` o `null`. Documentado en la guía "Docker & Local Override".

### 3. Argumentos extra por CLI para jobs ✔

Implementado solo para el comando `job`: `githooks job phpunit_all -- --filter=testFoo`. Se descartó para `flow` porque argumentos específicos de una herramienta pueden ser incompatibles con otros jobs del mismo flow.

---

## v3.2 — Herramientas y Output

Objetivo: cubrir el gap de herramientas nativas y mejorar la integración con CI e IA. **Release Candidate en preparación.**

### 1. PHP CS Fixer como tipo nativo ✔

Argumentos abstraídos: config, rules, dry-run, diff, allow-risky. Auto-staging automático (igual que phpcbf) cuando no se ejecuta en modo `--dry-run`. Modo `accelerable: true` por defecto. Es la herramienta de estilo más usada en proyectos Symfony/Laravel modernos y ejecutarla vía custom pierde la abstracción de argumentos que sí tienen phpcs, phpstan o psalm.

### 2. Rector como tipo nativo ✔

Argumentos abstraídos: config, dry-run, clear-cache. Rector es cada vez más estándar en el ecosistema PHP para refactorización automática y modernización de código. Mismo razonamiento que PHP CS Fixer.

### 3. Rediseño del sistema de output ✔

El sistema de output actual bufferiza toda la salida de los procesos y solo muestra "OK"/"KO" al final. Esto tiene dos problemas: en jobs largos (phpmd puede tardar 800s) parece que la herramienta está congelada, y se pierde la visibilidad en tiempo real que tenía v2.

La regla que unifica el diseño: **el formato determina el comportamiento del output.**

**Formato texto (default, humano mirando terminal):**

| Escenario | Comportamiento |
|---|---|
| Single job (`job X`) | Streaming en vivo vía `process->wait($callback)`. El usuario ve la salida real de la herramienta como en v2. |
| Flow secuencial (processes=1) | Cada job streameado con cabecera separadora entre jobs. Como `make` o `docker-compose`. |
| Flow paralelo (processes>1) | Dashboard interactivo con tres estados: ⏺ en cola, ⏳ ejecutando [timer], ✓/✗ terminado. Se actualiza en vivo con ANSI cursor. Output completo solo en error. Al final queda la foto limpia de resultados. En CI (no TTY) fallback a output append-only. |

**Formato estructurado (json, junit — máquina, IA, CI):**

| Escenario | Comportamiento |
|---|---|
| Todos | Barra de progreso en **stderr** (no corrompe stdout). Output estructurado completo en **stdout** al final. La IA hace `githooks flow qa --format=json 2>/dev/null` y obtiene JSON limpio. |

La separación stderr/stdout es el patrón Unix estándar: progreso para humanos en stderr, datos para máquinas en stdout.

**Ejemplo: antes (v3.1) vs después (v3.2) — single job y flow secuencial:**

```
# ANTES (v3.1): githooks job "Phpstan Src" — bufferizado, solo OK/KO al final
  Phpstan Src - OK. Time: 656ms
Results: 1/1 passed in 0.66s ✔️

# DESPUÉS (v3.2): githooks job "Phpstan Src" — streaming en vivo
  --- Phpstan Src ---

 [OK] No errors                         ← output real de phpstan en tiempo real

  Phpstan Src - OK. Time: 656ms
Results: 1/1 passed in 0.66s ✔️
```

```
# ANTES (v3.1): githooks flow qa --processes=1 — solo estados al final
  Phpcbf - OK. Time: 1.93s
  Phpstan Src - OK. Time: 715ms
  Parallel-lint - OK. Time: 196ms
  ...
Results: 8/8 passed in 12.18s ✔️

# DESPUÉS (v3.2): githooks flow qa --processes=1 — cabecera + streaming por job
  --- Phpcbf ---
  No fixable errors were found
  Time: 1.91 secs; Memory: 16MB
  Phpcbf - OK. Time: 1.93s
  --- Phpstan Src ---
   [OK] No errors                       ← output real de cada herramienta visible
  Phpstan Src - OK. Time: 715ms
  --- Parallel-lint ---
  Checked 144 files in 0.2 seconds
  No syntax error found
  Parallel-lint - OK. Time: 196ms
  ...
Results: 8/8 passed in 12.18s ✔️
```

**Ejemplo: flow paralelo (processes=4, 7 jobs) — dashboard con estados:**

```
# ANTES (v3.1): nada hasta que terminan
  Phpcpd - OK. Time: 175ms
  Phpstan Src - OK. Time: 832ms
  ...

# DESPUÉS (v3.2): feedback inmediato con 3 estados
  Phpcpd - OK. Time: 175ms
  ⏳ Phpstan Src [0.9s]            ← ejecutando (timer en vivo)
  ⏳ Parallel-lint [0.9s]
  ⏳ Phpmd Src [0.9s]
  ⏳ Phpcs [0.1s]                  ← entró al liberarse un slot
  ⏺ Phpunit                       ← en cola, esperando slot
  ⏺ Composer Audit

# Cuando todos terminan, queda la foto limpia:
  Phpcpd - OK. Time: 175ms
  Phpstan Src - OK. Time: 832ms
  Parallel-lint - OK. Time: 1.24s
  Phpmd Src - OK. Time: 1.65s
  Phpcs - OK. Time: 2.34s
  Phpunit - OK. Time: 4.23s
  Composer Audit - OK. Time: 1.47s
Results: 7/7 passed in 4.23s ✔️
```

En CI (no TTY), fallback a output append-only sin ANSI cursor movement.

**Ejemplo: formato JSON — progreso en stderr, datos en stdout:**

```bash
githooks flow qa --format=json 2>/dev/null   # stdout = JSON limpio
githooks flow qa --format=json               # stderr muestra progreso:
#   OK Phpcpd (175ms)  [1/8]
#   OK Phpstan Src (852ms)  [2/8]
#   OK Parallel-lint (1.27s)  [3/8]
#   ...
#   Done. 8/8 completed.
```

**Cambios en la arquitectura:**

- `FlowExecutor`: dos modos de ejecución. Streaming (`process->run($callback)`) para texto secuencial. Buffered para paralelo y formatos estructurados.
- `OutputHandler`: tres nuevos métodos: `onFlowStart(int $totalJobs)`, `onJobStart(string $jobName)`, `onJobOutput(string $jobName, string $chunk, bool $isStderr)`.
- `StreamingTextOutputHandler`: imprime cabeceras y output en vivo (texto secuencial).
- `ProgressOutputHandler`: progreso en stderr para json/junit, silencio en stdout.
- `ResultFormatter` (json/junit) sin cambios — sigue recibiendo `FlowResult` completo al final.

### 3b. Truncado de comandos largos en tablas ✔

Cuando un job tiene muchos argumentos (ej: phpcpd con 15+ `--exclude`), el comando generado puede superar los 500 caracteres y desbordar la tabla de `conf:check`. Regla: en contextos tabulares (múltiples jobs) truncar a 80 caracteres con `...`; en contextos de job individual (`job X --dry-run`) mostrar el comando completo.

### 4. Output CI nativo y formatos Code Climate / SARIF ✔

Dos niveles de integración CI:

**Nivel 1 — Anotaciones automáticas (ya implementado):** Detección automática de entorno (GitHub Actions, GitLab CI) con anotaciones nativas. En GitHub Actions, `::error file=src/User.php,line=14::...` genera anotaciones inline en el diff del PR. En GitLab CI, secciones colapsables en el log. Se desactiva con `--no-ci`.

**Nivel 2 — Formatos de reporte para CI (Code Climate + SARIF):** Nuevos formatos de output que las plataformas CI consumen como artefactos para mostrar resultados inline en PRs/MRs:

- `--format=codeclimate` → JSON en formato [Code Climate](https://docs.gitlab.com/ci/testing/code_quality/). GitLab CI lo consume como artefacto `codequality` y muestra violaciones inline en el MR. Ejemplo de uso en `.gitlab-ci.yml`:

  ```yaml
  code_quality:
    script:
      - githooks flow qa --format=codeclimate --output=gl-code-quality-report.json
    artifacts:
      reports:
        codequality: gl-code-quality-report.json
  ```

- `--format=sarif` → JSON en formato [SARIF](https://sarifweb.azurewebsites.net/). GitHub lo consume via Code Scanning (upload-sarif action) y muestra alertas inline en el PR. Ejemplo en GitHub Actions:

  ```yaml
  - run: githooks flow qa --format=sarif --output=results.sarif
  - uses: github/codeql-action/upload-sarif@v3
    with:
      sarif_file: results.sarif
  ```

Ambos formatos requieren que GitHooks parsee el output estructurado de cada herramienta (phpstan `--error-format=json`, phpcs `--report=json`, phpmd `--json`, psalm `--output-format=json`) para extraer file, línea y mensaje. Herramientas que no producen output con localización (phpunit, phpcpd) se excluyen de estos formatos — sus resultados siguen disponibles en `--format=json`.

### 5. Revisión del formato JSON para consumo por IA ✔

El JSON actual (`--format=json`) tiene campos básicos pero le falta información clave para que una IA o herramienta externa pueda consumirlo eficazmente. Campos a revisar/añadir:

- **Tipo de job** (`type`: phpstan, phpcs...) — actualmente solo está el nombre, la IA no sabe qué herramienta falló.
- **Paths** analizados por cada job.
- **Modo de ejecución** (full/fast/fast-branch).
- **Exit code** por job.
- **Ficheros analizados** y **ficheros con errores**.
- **Jobs skipped** y su razón.
- Revisar que las **keys** sean consistentes y descriptivas.

El objetivo es que el JSON sea un contrato estable que herramientas externas (IA, dashboards, scripts) puedan consumir sin parsear texto plano.

### 6. Tests Windows ✔

Batería de tests que verifique que la funcionalidad core es correcta en Windows. Actualmente todas las pruebas se ejecutan en Linux. Áreas a cubrir: paths con `\`, `DIRECTORY_SEPARATOR`, detección de CPUs (`wmic`, `NUMBER_OF_PROCESSORS`), ejecución de procesos, y resolución de rutas de ejecutables.

---

## Fase 0 — Consolidación QA pre-3.3.0

Objetivo: cerrar deuda identificada durante 3.2.0 y reestructurar la infraestructura QA del repo para explotar las capacidades que ofrece la propia herramienta (dogfooding). Tras esta fase, v3.3.0 arranca sobre una base limpia.

### Orden y dependencias

```
      ┌──── 0.4 (colisión -v) ────┐
      │     decide el flag final  │
      ▼                           ▼
  0.1 (CI + renames + SARIF)   0.6 (silenciar stderr en CI)
      │
      ▼
  0.5 (smoke build 7.4 en release.yml)

  0.2 (dashboard TTY) ─┐
                       │── independientes, en paralelo
  0.3 (contador n/m) ──┘
```

### 0.1 Reestructurar CI aprovechando flows + herencia + renombrado + contrato SARIF ✔

El CI actual tiene dos workflows que se solapan (`code-analysis.yml` ejecuta `flow qa` — que ya incluye Phpunit — y `main-tests.yml` vuelve a ejecutar Phpunit en PHP 7.4). Se explota la propia herramienta (GitHooks) como fuente única de verdad: lo que corre el CI queda definido en `qa/githooks.php`, el workflow es un thin wrapper.

**Subtareas:**

1. **Renombrar jobs de `qa/githooks.php` a kebab-case** (anticipa parte del ítem 6 de v3.3). El resto (`qa/githooks.dist.php`, `.yml`) se ajusta por coherencia con la convención recomendada en docs, no por obligación técnica.

   | Antes | Después |
   |---|---|
   | `Phpstan Src` | `phpstan-src` |
   | `Phpmd Src` | `phpmd-src` |
   | `Phpcpd`, `Phpcs`, `Phpcbf`, `Phpunit` | `phpcpd`, `phpcs`, `phpcbf`, `phpunit` |
   | `Composer Audit/Update/Downgrade` | `composer-audit/update/downgrade` |
   | `Coverage`, `Infection`, `PhpMetrics` | `coverage`, `infection`, `phpmetrics` |
   | `psalm_src` | `psalm-src` |

2. **Añadir `phpunit-git` y `phpunit-windows`** via `extends: phpunit`, sobrescribiendo sólo el argumento `group`. `phpunit.xml` ya excluye por defecto los grupos `release`, `git` y `windows`, así que el `phpunit` base es automáticamente la "suite principal".

3. **Crear flow `ci-tests`** con los tres jobs de phpunit. Fuente única para la batería de tests de CI.

4. **Fusionar `code-analysis.yml` + `main-tests.yml` → `ci.yml`** con matriz única `{os, php, exclude}`:

   ```yaml
   matrix:
     include:
       - { os: ubuntu-latest,  php: '7.4', mode: 'full-qa' }      # flow qa (incluye phpunit)
       - { os: ubuntu-latest,  php: '8.1', mode: 'tests-only' }   # flow ci-tests --exclude-jobs=phpunit-windows
       - { os: ubuntu-latest,  php: '8.5', mode: 'tests-only' }
       - { os: windows-latest, php: '7.4', mode: 'windows-only' } # job phpunit-windows
       - { os: windows-latest, php: '8.5', mode: 'windows-only' }
   ```

   Nota: la matriz unificada **no acelera el setup** (cada entrada sigue siendo un runner independiente con su propio checkout/composer install), pero elimina la duplicación de Phpunit en 7.4 (ahorro ~1 runner-minute) y deja una fuente de verdad única.

5. **Workflow `sarif-contract.yml` on-demand** con triggers `workflow_dispatch` únicamente (opcionalmente añadir `schedule` trimestral en el futuro como canario de drift). Dos jobs internos:
   - `verify`: ejecuta el flow contra fixture con violaciones controladas, valida el SARIF contra schema 2.1.0 oficial descargado en vivo.
   - `refresh`: produce el SARIF como artefacto descargable para regenerar el golden file cuando el formato cambie legítimamente.

6. **Fixture de violaciones controladas** en `tests/fixtures/sarif-broken-code/` (ficheros con errores intencionales: variables sin usar, complexity alta, etc.) + config dedicada `qa/githooks.sarif-check.php` que apunta sólo a ese directorio. El QA real del proyecto no se contamina.

7. **Test unitario `SarifResultFormatterSchemaTest`** con dos aserciones nuevas (complementan los 12 unit tests que ya existen en `SarifResultFormatterTest`):
   - Validación contra schema SARIF 2.1.0 oficial (fixture local en `tests/fixtures/sarif-schema-2.1.0.json`).
   - Golden file de regresión con violaciones reales.

**Por qué no va al workflow `ci.yml` el upload-sarif permanente:** en este repo el QA pasa siempre, el SARIF va vacío. Añadir el step con `security-events: write` y dependencia en `codeql-action` sin valor real es ceremonia. El ejemplo para consumidores va en `docs/` (skill `docs` en otra tarea).

### 0.2 Tests automatizados del dashboard TTY paralelo ✔

El flujo `flow qa --processes=N` en terminal interactivo (dashboard con ⏺/⏳/✓/✗ y timers en vivo) se valida hoy manualmente con la skill `qa-tester`. Hay que definir si se automatiza vía `expect`/pty, un harness PHP que alimente un stream fake-TTY, o se acepta como verificación manual documentada. La parte no-TTY (append-only) ya está cubierta por los release tests.

### 0.3 Release test del contador `[n/m]` con flow mixto skip/run ✔

El contador del `ProgressOutputHandler` tiene cobertura unitaria, pero no hay test `@group release` que combine jobs ejecutados y jobs saltados (fast-mode sin staged files, fail-fast, hooks condicionales) en el mismo flow para confirmar que `[1/3]`, `[2/3]`, `[3/3]` se emiten correctamente aunque algunos sean skip.

### 0.4 Resolver colisión de `-v` (progreso vs `--verbose` Symfony) ✔

El `--verbose` de Symfony Console se está usando para forzar progreso en `flow`/`job`. Si un comando futuro usa `$this->info()` o `$this->line()` condicionado a `OutputInterface::isVerbose()`, el mismo flag tendrá dos significados. Auditar los comandos actuales y decidir si el override de progreso debe moverse a un flag dedicado (`--progress` / `--show-progress`) antes de que surja el conflicto.

Se ejecuta antes de 0.1 porque condiciona el diseño del nuevo `ci.yml`: si el override se mueve a otro flag, el workflow adopta el flag final desde el primer momento y no hay que rehacerlo.

### 0.5 Smoke test de `builds/php7.4/` en CI release ✔

El rebuild del tier PHP 7.4 falla localmente por incompatibilidades de dependencias dev. El CI lo maneja porque instala el tier correcto con `php7.4 tools/composer update` antes del `app:build`, pero no hay un smoke test explícito que descargue el artefacto y confirme que el `.phar` de `builds/php7.4/` arranca en PHP 7.4 y 8.0. Añadir un job mínimo al pipeline de release.

### 0.6 Silenciar progreso en stderr con `--format=` estructurado en CI ✔ *(superada por 0.4)*

El enunciado original era: en CI con `--format=json` el progreso sale por stderr y, como muchos runners mezclan stdout+stderr en el log, el usuario tiene que añadir `2>/dev/null` a mano. La propuesta barajaba (a) auto-silenciar cuando hay CI detectado + formato estructurado o (b) un flag `--no-progress`.

Tras la 0.4, el mecanismo pasa a ser **opt-in** via `--show-progress`: sin TTY (que es el caso en CI) y sin flag, stderr está silencioso por defecto. El caso que la 0.6 pretendía resolver ya no ocurre — el usuario que lance `githooks flow qa --format=json` en CI obtiene stdout limpio y stderr vacío sin hacer nada.

Queda como no-resuelto un caso marginal: usuario en terminal TTY que quiere `--format=json` sin progreso en stderr. Sigue disponible la ruta Unix estándar (`2>/dev/null`). Si algún día aparece demanda real de un `--no-progress` explícito, `FormatsOutput::resolveProgressHandler` es el único punto a tocar.

---

## v3.3 — Madurez

Objetivo: pulir el flujo CI (un único `flows` por runner con multi-reporte y `--files`), añadir los primeros diferenciadores reales frente a la competencia (monitor + time threshold, memory budget por job para que la fusión de jobs CI en monolitos no reviente por OOM) y empezar la deprecation de claves camelCase. Wizard y "prohibir espacios" salen del scope.

### 1. Comando `flows` — ejecución combinada de múltiples flows

Nuevo comando que ejecuta varios flows como uno solo, mergeando sus jobs bajo un único `FlowPlan`:

```bash
githooks flows qa ci-tests --processes=4
githooks flows qa schedule --fast
```

**Qué resuelve y por qué entra en 3.3.** El caso de uso central no es "el usuario que quiere ejecutar QA + schedule en local" sino **simplificar el CI**. Hoy `ci.yml` arranca dos pasos (`flow qa` + `flow ci-tests`) que son dos `composer install`, dos arranques de PHP, y dos thread budgets independientes. Con `flows ci-tests qa` un único runner ejecuta todo en un solo plan: thread budget compartido (phpunit + phpstan paralelizando entre sí), un único exit code, un único reporte. Es la pieza que cierra el storytelling **"un único `flows` reemplaza todos los pasos QA del runner CI"**.

**Mecánica de merge de jobs.** Unión ordenada: primero los jobs del primer flow, luego los del segundo, etc. Si un job aparece en más de un flow, se incluye solo la primera ocurrencia (dedup por nombre). Esto es seguro porque las definiciones de los jobs son globales (sección `jobs`) — no hay variación por flow.

**Resolución de opciones discrepantes.** Las opciones se resuelven hoy así:

```
CLI flags → flow.options → flows.options (global) → defaults
```

Cuando hay múltiples flows, el nivel `flow.options` puede ser contradictorio. Ejemplo con la config actual:

| Opción | `qa` | `schedule` | Conflicto |
|---|---|---|---|
| `processes` | 10 (global) | 1 (propio) | Sí: ¿10 o 1? |
| `fail-fast` | false (global) | true (propio) | Sí: ¿parar o seguir? |

**Regla propuesta:** las opciones del flow combinado **ignoran** las options per-flow. Pipeline:

```
CLI flags → flows.options (global) → defaults
```

Cuando algún flow tiene options propias que difieren de las globales y el usuario no ha pasado CLI flags, warning informativo:

```
⚠ Flows 'qa' y 'schedule' tienen opciones distintas. Se aplican las opciones globales:
    processes: 10, fail-fast: false
  Usa --processes y --fail-fast para sobrescribir.
```

**Justificación:** las options per-flow están diseñadas para el contexto de ese flow individual. `schedule` tiene `processes=1` porque sus jobs son pesados y secuenciales (composer update, coverage, infection). `qa` hereda `processes=10` porque sus jobs son ligeros. Intentar mergear (¿media? ¿máximo?) produce un resultado que no tiene sentido para ninguno. Defaults globales como base es predecible. Y los CLI flags siempre ganan:

```bash
githooks flows qa ci-tests --processes=4 --fail-fast
```

**Opciones a nivel de job se preservan intactas.** Las claves `execution`, `failFast`, `ignoreErrorsOnExit` y `executable-prefix` de cada job individual no se ven afectadas — son intrínsecas al job, no al flow.

**Soporte de multi-reporte (ítem 2).** `flows` debe soportar los flags `--report-*` y la sección `reports` en `flows.options` desde el primer momento — el caso CI que justifica este comando depende de poder emitir SARIF/JUnit del run combinado.

**Soporte de `--files` / `--files-from` (ítem 3) y `--fast-branch`.** Aplican a la unión de jobs, no por flow.

**Casos borde:**

1. **Ningún flow tiene options propias** — trivial: globales.
2. **Todos los flows convergen en options** — no se chequea, mantenemos la regla simple.
3. **Flow inexistente** — error y abortar.
4. **Un solo flow** — degrada a `githooks flow X`.
5. **Jobs duplicados entre flows** — primera ocurrencia gana, sin warning.
6. **`--exclude-jobs` / `--only-jobs`** — aplican sobre la lista mergeada, no por flow.

**Documentación**: añadir guía oficial "Recipe: un único `flows` para CI" en `docs/how-to/ci-cd.md` posicionándolo como el patrón recomendado, no como caso secundario.

**Impacto en la arquitectura:**

- Nuevo `FlowsCommand` en `app/Commands/`.
- `FlowPreparer` necesita un método `prepareMultiple(FlowConfiguration[] $flows, ...)` que itere los flows, recolecte jobs con dedup, y construya un `FlowPlan` con las opciones globales.
- `FlowExecutor` y `FlowPlan` no cambian: ya trabajan con una lista de jobs + options.
- `ConfigurationResult` no cambia: ya expone flows y jobs por separado.

### 2. Multi-reporte en una sola ejecución (estilo PHPUnit/Psalm) ✔

Hoy `--format=FORMAT` acepta un único valor. Si un pipeline necesita SARIF para Code Scanning **y** JSON v2 para un bot **y** JUnit para el dashboard, hay que correr `flow qa` tres veces. Reanalizar todo tres veces es coste real en CI (phpstan + phpunit con coverage no son baratos).

**Estudio rápido de qué hace cada herramienta del ecosistema:**

| Herramienta | API CLI | API config |
|---|---|---|
| **PHPUnit** | `--log-junit X --coverage-html Y --coverage-xml Z --testdox-html W` (un flag por tipo) | `<logging>` con `<log type="..." target="..."/>` repetible en `phpunit.xml` |
| **Psalm** | `--report=results.json --report=results.xml --report=results.sarif` (mismo flag repetible, formato inferido por extensión) | Sección `<report_format>` en `psalm.xml` |
| **PHPStan** | `--error-format=junit` (uno solo) | — |
| **golangci-lint** | `--out-format=text,sarif:report.sarif,checkstyle:cs.xml` (un flag, lista CSV `formato:path`) | `output.formats` en `.golangci.yml` |

**Decisión:** PHPUnit-style con prefijo `--report-X`. Es descubrible (`--help` lista cada formato), no rompe `--format`/`--output`, y convive con la config declarativa.

**API CLI:**

```bash
# Caso simple (igual que hoy, no cambia)
githooks flow qa --format=json --output=reports/qa.json

# Multi-report: un flag por formato
githooks flow qa \
  --report-sarif=reports/qa.sarif \
  --report-junit=reports/junit.xml \
  --report-codeclimate=reports/gl.json
# Stdout = texto humano (default), porque no hay --format=

# Mezcla: stdout JSON + ficheros adicionales
githooks flow qa --format=json --report-sarif=reports/qa.sarif
# Stdout = JSON, fichero SARIF aparte
```

Naming: prefijo `--report-` siempre. Reserva el namespace para futuros formatos sin colisión.

**API config (declarativa a nivel flow):**

```php
'qa' => [
    'jobs' => [...],
    'options' => [
        'reports' => [
            'sarif'       => 'reports/qa.sarif',
            'junit'       => 'reports/junit.xml',
            'codeclimate' => 'reports/gl-code-quality.json',
        ],
    ],
],
```

**Reglas:**
- CLI gana sobre config (`--report-sarif=otro.sarif` sobrescribe `reports.sarif`).
- `--format=X --output=Y` sigue funcionando exactamente igual que hoy (equivalente a `--format=X --report-X=Y` con stdout vacío).
- Si `--format=` no se pasa, stdout es texto humano (dashboard/streaming según contexto).
- `conf:check` valida que los paths de `reports` son escribibles y avisa si la carpeta no existe.

**Implementación:** los formatos ya están normalizados (`ResultFormatter`). Se introduce una colección `ReportTargets` que itera y escribe N veces el `FlowResult` ya formateado. Coste mínimo, máximo valor.

### 3. Flag `--files` y `--files-from` ✔

Ejecución de flow o job contra una lista explícita de ficheros, sobreescribiendo `--fast` y `--fast-branch`.

**Casos de uso:**

1. **Validación local puntual** — "quiero correr QA solo sobre estos 3 ficheros que he tocado":
   ```bash
   githooks flow qa --files=src/User.php,src/Order.php
   githooks job phpstan-src --files=src/User.php
   ```
2. **CI alternativos donde `fast-branch` no funciona bien** — Travis, Circle, Bitbucket Pipelines: checkout shallow, detached HEAD que no resuelve `origin/main`. Hoy `--fast-branch-fallback=full` funciona pero es todo o nada. Con `--files` el CI calcula la lista por su cuenta y se la pasa a GitHooks:
   ```bash
   CHANGED_FILES=$(git diff --name-only HEAD~1 | tr '\n' ',')
   githooks flow qa --files=$CHANGED_FILES
   ```
3. **Integraciones externas (IDE on-save, hook custom)**:
   ```bash
   githooks job phpstan-src --files=$CURRENT_FILE
   ```

**Diseño:**

```bash
--files=path1,path2,path3       # CSV
--files-from=changed.txt        # Una ruta por línea — útil cuando CSV peta el límite del shell (200+ ficheros)
```

**Reglas:**
- `--files` y `--files-from` sobreescriben `--fast` y `--fast-branch` (warning si se mezclan).
- Para cada job se filtran las rutas que matcheen sus globs/extensiones (igual que `--fast`).
- Jobs no-accelerable (`phpunit`, `phpcpd`) se ejecutan con sus paths originales — mismo comportamiento que `--fast-branch` actualmente.
- Rutas inexistentes → warning + skip de ese fichero. Si **todas** son inválidas, el job se skipea con razón explícita.

### 4. Monitor de rendimiento + time threshold

Evolución del `--monitor` actual a un sistema de medición + acción con dos niveles **independientes**:

- **Job-threshold** (`warn-after`/`fail-after` por job): vigila que un job particular no exceda su tiempo. Detecta regresiones locales.
- **Flow-threshold** (`time-budget` en sub-grupo a nivel flow): vigila la **suma** de duraciones de los jobs ejecutados. Detecta drift acumulado que ningún job individual percibe.

Los dos niveles son sistemas paralelos que no se ven: un job solo declara threshold en el job; un flow solo declara budget en `flow.options` o `flows.options`. No hay cascada heredable.

Diferenciador: ni GrumPHP ni CaptainHook ofrecen este sistema. Es feature visible en cada `flow qa` que se ejecute, sin setup adicional.

#### Configuración

```php
return [
    'flows' => [
        'options' => [
            'processes' => 10,
            'fail-fast' => false,
            // Default global. Aplica a cualquier flow que no lo redefina.
            'time-budget' => [
                'warn-after' => 120,    // suma de duraciones, en segundos
                'fail-after' => 300,
            ],
        ],
        'qa' => [
            // qa hereda el time-budget global
            'jobs' => ['phpcs', 'phpstan-src', 'phpunit', ...],
        ],
        'pre-commit-light' => [
            'options' => [
                // Override granular: este flow tiene presupuesto distinto
                'time-budget' => [
                    'warn-after' => 5,
                    'fail-after' => 15,
                ],
            ],
            'jobs' => ['phpcbf', 'parallel-lint'],
        ],
    ],

    'jobs' => [
        // Job-threshold solo donde tiene sentido vigilar regresión local
        'phpunit' => [
            'type' => 'phpunit',
            'warn-after' => 60,
            'fail-after' => 180,
        ],
        'phpcs' => [
            'type' => 'phpcs',
            'warn-after' => 5,    // si tarda >5s algo va mal con la config
        ],
        'phpstan-src' => [
            'type' => 'phpstan',
            // sin threshold — sin alarma para este job
        ],
    ],
];
```

`time-budget` puede declarar solo `warn-after`, solo `fail-after`, o ambos. Lo mismo a nivel job.

#### CLI override

```bash
# Sobrescribir el time-budget del flow (suma)
githooks flow qa --warn-after=120 --fail-after=600

# Desactivar todo el sistema (job + flow), útil en profiling/CI especial
githooks flow qa --no-thresholds
```

Las flags CLI `--warn-after`/`--fail-after` aplican siempre al **flow-level** (sustituyen `time-budget`). No hay flag para sobrescribir job-level — eso es decisión de proyecto, va en config. Si pasas solo `--warn-after`, el `fail-after` de config sigue activo.

`--no-thresholds` desactiva ambos niveles. Si se mezcla con `--warn-after` o `--fail-after`, gana `--no-thresholds` con warning informativo.

#### Comportamiento — matriz de casos

**Por job:**

| Estado job | warn-after | fail-after | Resultado | Exit job |
|---|---|---|---|---|
| OK | no cruza | no cruza | ✓ OK | 0 |
| OK | cruzado | no cruza | ⚠ OK con warning | 0 |
| OK | cruzado | cruzado | ✗ KO por threshold | 1 |
| KO real (tool exit≠0) | — | — | ✗ KO real (gana causa real; threshold informativo) | 1 |
| Skipped | — | — | ⏭ no cuenta para la suma del flow | — |

**Por flow** (suma de duraciones de jobs ejecutados; excluye skipped):

| Suma | warn-after | fail-after | Resultado flow | Exit |
|---|---|---|---|---|
| < warn | — | — | ✓ OK | 0 (si todos jobs OK) |
| warn ≤ S < fail | cruzado | no cruza | ⚠ Flow warning | 0 (si jobs OK) |
| ≥ fail | cruzado | cruzado | ✗ Flow KO | **1** aunque todos los jobs hayan pasado |

**Combinaciones:**

| Job-state | Flow-state | Exit final | Resumen |
|---|---|---|---|
| Todos OK | OK | 0 | Todo verde |
| Algún job ⚠ | OK | 0 | ⚠ en jobs concretos |
| Todos OK | ⚠ | 0 | ⚠ solo en línea final |
| Algún job ⚠ | ⚠ | 0 | ⚠ en jobs y en flow |
| Algún job ✗ por threshold | OK | 1 | ✗ del job, exit 1 |
| Algún job ✗ por threshold | ⚠ | 1 | ✗ del job (gana), ⚠ del flow informativo |
| Algún job ✗ por threshold | ✗ | 1 | ✗ del job + ✗ del flow |
| Todos OK | ✗ | **1** | Todos los jobs ✓ pero flow rompe budget — caso conceptual clave |
| Algún job ✗ real | cualquiera | 1 | KO real gana; threshold informativo |

#### Ejemplos de output (texto)

**Job warn-after cruzado, flow OK:**

```
  --- Phpunit ---
  ...........  18 / 18 (100%)
  OK (18 tests, 42 assertions)
  Phpunit - OK ⚠. Time: 65.2s (warn-after: 60s)

Results: 8/8 passed in 125.4s ⚠ (1 warning)
```

**Job fail-after cruzado:**

```
  Phpunit - KO ✗. Time: 195.3s (fail-after: 180s)

Results: 7/8 passed in 256.1s ✗
✗ Job 'phpunit' exceeded time threshold (took 195.3s, limit 180s)
```

**Flow fail-after por suma con todos los jobs OK individualmente (caso clave):**

```
  ...
  Phpunit - OK. Time: 285.4s

Results: 8/8 passed in 320.1s ✗
✗ Flow time-budget exceeded: total job time 320.1s, limit 300s
```

**Job ✗ + flow ⚠:**

```
  Phpcs - KO ✗. Time: 8.2s (fail-after: 5s)
  ...
Results: 7/8 passed in 125.4s ✗
✗ Job 'phpcs' exceeded time threshold (took 8.2s, limit 5s)
⚠ Flow time-budget warning: total job time 125.4s exceeded warn-after (120s)
```

**KO real + threshold cruzado:**

```
  Phpcs - KO ✗. Time: 8.2s (also: fail-after 5s)
Results: 7/8 passed in 125.4s ✗
✗ Job 'phpcs' failed (tool exit code 2)
   ↳ also exceeded time threshold (took 8.2s, limit 5s)
```

#### Output JSON v2 — patrón null explícito

Los campos `timeBudget` (root) y `threshold` (por job) **siempre aparecen** con valor `null` cuando no hay configuración. Cuando hay, son objetos completos. Esto evita null-checks de existencia del campo:

```json
{
  "version": 2,
  "flow": "qa",
  "success": false,
  "totalTime": 125.4,
  "executionMode": "full",
  "passed": 7,
  "failed": 1,
  "skipped": 0,
  "timeBudget": {
    "warnAfter": 120,
    "failAfter": 300,
    "totalJobDuration": 125.4,
    "warned": true,
    "failed": false
  },
  "jobs": [
    {
      "name": "phpcs",
      "type": "phpcs",
      "success": false,
      "exitCode": 0,
      "duration": 8.2,
      "threshold": {
        "warnAfter": null,
        "failAfter": 5,
        "warned": false,
        "failed": true,
        "reason": "exceeded fail-after"
      }
    },
    {
      "name": "phpunit",
      "type": "phpunit",
      "success": true,
      "exitCode": 0,
      "duration": 95.4,
      "threshold": {
        "warnAfter": 60,
        "failAfter": 180,
        "warned": true,
        "failed": false,
        "reason": "exceeded warn-after"
      }
    },
    {
      "name": "phpstan-src",
      "type": "phpstan",
      "success": true,
      "exitCode": 0,
      "duration": 12.4,
      "threshold": null
    }
  ]
}
```

**Reglas del JSON:**

| Caso | `timeBudget` | `threshold` por job |
|---|---|---|
| Sin time-budget configurado a nivel flow | `null` | — |
| Con time-budget pero suma no cruza | objeto con `warned: false, failed: false` | — |
| Job sin warn-after ni fail-after | — | `null` |
| Job con threshold configurado | — | objeto. Sub-campos `warnAfter`/`failAfter` son `null` si solo se configuró uno de los dos |
| `reason` siempre presente cuando `warned` o `failed` son true | — | `null` en el resto de casos |

Justificación del patrón: contrato estable, el consumidor escribe `if (job.threshold) { … }` y el campo siempre existe. Patrón usado por GraphQL, JSON:API, OpenAPI con `nullable: true`. El sentinel `0 = infinito` se descarta — mezcla valor numérico con semántica especial y reserva un valor del dominio.

Mismo principio en SARIF/Code Climate: el threshold va como propiedad opcional dentro de `properties`, con `null` si no aplica.

#### Validación en `conf:check`

| Caso | Comportamiento |
|---|---|
| `warn-after` o `fail-after` no positivo (`-1`, `0`, `'foo'`) | Error: "must be a positive integer" |
| `warn-after >= fail-after` (a nivel job o flow) | Error: "warn-after must be less than fail-after" |
| `time-budget` con clave desconocida | Warning: "unknown key in time-budget: 'X'" |
| Suma de `fail-after` de jobs > `time-budget.fail-after` del flow | Sin warning. Es legítimo (no todos los jobs cruzan a la vez). |

Ejemplo:

```
$ githooks conf:check
✗ Configuration errors:
  • Job 'phpunit': 'warn-after' (60) must be less than 'fail-after' (45)

⚠ Configuration warnings:
  • Option 'time-budget': unknown key 'warn-affter' (did you mean 'warn-after'?)
```

#### Casos borde resueltos

| Caso | Decisión |
|---|---|
| `--dry-run` | Skip total. No se mide tiempo, no se evalúa threshold. |
| CLI `--warn-after=X` solo (sin `--fail-after`) | Override solo de `time-budget.warn-after`. El `fail-after` de config sigue activo. |
| CLI `--no-thresholds` + `--warn-after` simultáneos | `--no-thresholds` gana. Warning: "ignoring --warn-after due to --no-thresholds". |
| Job con error real (exit≠0) que también cruza `fail-after` | KO real es la causa principal; threshold se anota como información secundaria. Exit 1. |
| `fail-fast=true` y job rompe `fail-after` | Mismo comportamiento que cualquier KO con fail-fast: aborta el flow, los siguientes jobs no corren. La suma se calcula con lo ejecutado. |
| Flow con todos los jobs skipped → suma = 0 | OK. `time-budget` no cruza (0 < cualquier threshold positivo). |
| Multi-flow (`flows qa schedule`) con `time-budget` distinto | Patrón ya acordado: globales ganan + warning informativo. |
| Job ya marcado KO con duración enorme (timeout interno del tool) | La duración cuenta para la suma. Si el flow tenía `fail-after` cercano puede empujarlo a ✗ también. Coherente: el tiempo se consumió igual. |
| `time-budget` solo con `warn-after` | Válido. Solo dispara warning. |
| `time-budget` solo con `fail-after` | Válido. Sin transición intermedia, salta directo a ✗. |
| Granularidad < 1s | Se mide en milisegundos internamente; threshold es entero en segundos, comparación con float. |

#### Implementación

- `JobConfiguration::fromArray` parsea `warn-after`/`fail-after` como integers positivos. Mismo patrón de validación que el resto.
- `OptionsConfiguration::fromArray` parsea sub-grupo `time-budget` (nuevo `TimeBudget` value object).
- `JobResult` ya lleva `duration`. Añadir `thresholdState` (none|warned|failed) y `thresholdReason`.
- `FlowExecutor` calcula la suma post-hoc y aplica estado al `FlowResult`.
- `TextOutputHandler`/`StreamingTextOutputHandler` añaden el sufijo coloreado en la línea del job y la línea final del flow.
- `JsonResultFormatter` emite los campos `timeBudget` y `threshold` siguiendo las reglas del patrón null explícito.
- Tests: job-warn, job-fail, flow-warn-by-sum, flow-fail-by-sum, todos-OK-flow-fail (caso clave), KO real + threshold (la causa real gana), multi-flow con time-budget divergente.

Coste: ~250 LOC + tests.

### 5. Memory budget por job (`memory: <MB>`)

**Estado**: incluido en v3.3 como complemento del comando `flows` (ítem 1). Diseño con varias decisiones abiertas — ver "Puntos de discusión" al final.

Complementa el thread budget actual (`processes` + `cores`) con un eje paralelo de memoria. Resuelve el caso identificado en la discusión del ítem 1 (multi-flow): cuando se fusionan varios `flow-src-N` en un único runner, la suma de **picos de memoria** en paralelo es la limitación real, no la CPU. Sin un presupuesto de RAM declarativo, el allocator apila phpstan-src-1 + phpstan-src-2 + phpstan-src-3 a la vez, satura los 6-7 GB típicos de un runner GHA y revienta por OOM con stacktrace ilegible.

#### Motivación

El allocator actual reparte solo cores. En proyectos pequeños es suficiente. En monolitos donde phpstan se parte en N subsets para no agotar la RAM, fusionar esos subsets vía `flows ... --processes=8` exige que el allocator entienda **dos recursos a la vez**: si phpstan-src-1 declara `memory: 2048` y el runner tiene 6 GB, no se pueden lanzar 4 subsets juntos aunque sobren cores.

Diferenciador: ni GrumPHP, ni CaptainHook, ni lefthook, ni pre-commit (Yelp), ni golangci-lint exponen un presupuesto declarativo de memoria por job. El equivalente más cercano es el `--memory-limit` de phpstan, pero es per-tool y no participa en scheduling cross-job.

**Caso de uso de cabecera:** monolito PHP con `src/` partido en 4-6 subsets de phpstan + phpmd, hoy distribuidos en 4-6 jobs CI separados (cada uno con su `composer install` de 3 min). Con `flows` (ítem 1) + `cores` (existente) + `memory` (este ítem), todo el QA cabe en un único runner sin sobre-suscribir CPU ni RAM. El ahorro estimado es 12-50 runner-min por PR según tamaño de proyecto.

#### Configuración tentativa

```php
return [
    'flows' => [
        'options' => [
            'processes'    => 8,
            'memory-limit' => 6144,   // MB; techo del runner. Auto-detect si se omite.
        ],
    ],

    'jobs' => [
        'phpstan-src-1' => [
            'type'   => 'phpstan',
            'paths'  => ['src/Domain'],
            'cores'  => 2,
            'memory' => 2048,         // MB de pico esperado
        ],
        'phpstan-src-2' => [
            'type'   => 'phpstan',
            'paths'  => ['src/Infrastructure'],
            'cores'  => 2,
            'memory' => 2048,
        ],
        'parallel-lint' => [
            'type'  => 'parallel-lint',
            'paths' => ['src'],
            // sin 'memory' declarado — null
        ],
    ],
];
```

#### CLI override

```bash
# Override del techo del runner (útil en CI con runners de tamaño variable)
githooks flow qa --memory-limit=4096

# Desactivar el gate de memoria (vuelve al comportamiento pre-3.3)
githooks flow qa --no-memory-budget
```

No se contempla flag `--memory=X` per-job: igual que `cores`, es decisión declarativa de proyecto. Ver punto de discusión 6.

#### Comportamiento — matriz tentativa

Bin-packing 2D estricto (propuesta inicial): un job solo arranca cuando hay simultáneamente cores libres **y** memoria libre suficiente.

| Pool | Cores libres | Memoria libre | ¿Job arranca? |
|---|---|---|---|
| Job pide 2 cores + 2048 MB | ≥ 2 | ≥ 2048 | Sí |
| Job pide 2 cores + 2048 MB | ≥ 2 | < 2048 | No (espera memoria) |
| Job pide 2 cores + 2048 MB | < 2 | ≥ 2048 | No (espera cores) |
| Job sin `memory` declarado | suficientes | depende default | Ver discusión 2 |

**Auto-detección del techo del runner:**

| Plataforma | Mecanismo | Fallback si falla |
|---|---|---|
| Linux | `/proc/meminfo` → `MemTotal` | 4096 MB + warning |
| macOS | `sysctl hw.memsize` | 4096 MB + warning |
| Windows | `wmic ComputerSystem get TotalPhysicalMemory` o PowerShell equivalente | 4096 MB + warning |

**Sin enforcement**: el motor no aplica `ulimit -v` ni cgroups. Si un job declara `memory: 2048` y consume 4096 en realidad, el OOM lo lanza el sistema operativo como hoy. El presupuesto es **scheduling**, no jaula. Ver discusión 1.

#### JSON output v2 — patrón null explícito

```json
{
  "version": 2,
  "flow": "qa",
  "success": true,
  "memoryBudget": {
    "limit": 6144,
    "limitSource": "auto-detected",
    "peakReserved": 4096
  },
  "jobs": [
    {
      "name": "phpstan-src-1",
      "type": "phpstan",
      "memory": 2048,
      "success": true
    },
    {
      "name": "parallel-lint",
      "type": "parallel-lint",
      "memory": null,
      "success": true
    }
  ]
}
```

Reglas:

| Caso | `memoryBudget` (root) | `memory` (per-job) |
|---|---|---|
| Sin `memory-limit` configurado y ningún job declara `memory` | `null` | `null` en todos |
| Con `memory-limit` (configurado o auto) | objeto con `limit`, `limitSource`, `peakReserved` | `null` o número según job |
| `--no-memory-budget` | `null` | se ignoran los `memory` declarados |

`limitSource`: `"configured"` | `"auto-detected"` | `"cli-override"`.

`peakReserved`: pico de memoria reservada simultáneamente durante la ejecución (suma de `memory` de jobs corriendo en paralelo en el momento más cargado).

#### Validación en `conf:check`

| Caso | Comportamiento |
|---|---|
| `memory` no entero positivo | Error: "'memory' must be a positive integer (MB)" |
| `memory > memory-limit` | Error: "job 'X' memory (Y) exceeds runner memory-limit (Z)" — config irresoluble |
| `memory-limit` no declarado y al menos un job declara `memory` | Info: "memory-limit not configured; using auto-detected value (W MB)" |
| Auto-detección falla | Warning + fallback informado |
| Suma de `memory` de jobs > `memory-limit` | Sin warning. Es legítimo: el allocator serializa. |

#### Casos borde tentativos

| Caso | Decisión propuesta |
|---|---|
| Ningún job declara `memory`, ni hay `memory-limit` | Comportamiento idéntico a hoy. No se aplica gate. |
| Job con `memory > memory-limit` | Error en `conf:check`. El job nunca podría arrancar. |
| `--dry-run` | Skip total. No se ejecuta nada, no se evalúa. |
| `--no-memory-budget` con `memory` en jobs | Warning: "ignoring 'memory' in jobs due to --no-memory-budget". |
| Self-hosted runner con 64 GB | Auto-detect lo respeta y multiplica el paralelismo posible. |
| Solo algunos jobs declaran `memory` | Los que declaran respetan gate; los que no se rigen por el default (ver discusión 2). |
| Job termina antes que sus pares en paralelo | Su memoria reservada se libera de inmediato; el siguiente en cola arranca si entra. |
| Multi-flow (`flows qa src --memory-limit=X`) | El gate aplica al pool unificado, igual que `processes` y `cores`. |
| `fail-fast=true` y job ✗ con memoria reservada | La memoria se libera al cancelar; no afecta a la lógica del gate. |

#### Esquema de implementación

- `JobConfiguration::fromArray` parsea `memory` como int positivo opcional.
- `OptionsConfiguration::fromArray` parsea `memory-limit`.
- Definir el flag `--memory-limit` y `--no-memory-budget` en `flow`, `flows` y `job` (mismas tres entradas que `--processes`).
- Nuevo `MemoryDetector` con implementaciones por plataforma (Linux/macOS/Windows) y fallback informado.
- El allocator del thread budget (`ThreadBudgetAllocator` o equivalente) se extiende a admisión bidimensional: `cores ≥ jobCores AND memory ≥ jobMemory`.
- `FlowResult` añade `memoryBudget` con `limit`, `limitSource`, `peakReserved`.
- `JsonResultFormatter` emite `memoryBudget` y `memory` (per-job) siguiendo el patrón null explícito.
- `system:info` añade fila "Detected memory: X MB (source: auto-detected | configured)".
- Tests: jobs con/sin `memory`, gate por memoria, auto-detect en Linux/Windows/macOS, fallback, `--no-memory-budget`, conf:check (errores y warnings), interacción con `cores` (bin-packing 2D), multi-flow con pools unificados.

Coste estimado: ~300 LOC + tests. Más que `time-budget` porque toca el allocator, no solo la observación post-hoc.

#### Puntos de discusión abiertos

1. **Scheduling vs enforcement.** Propuesta: solo scheduling (declarativo, sin `ulimit`/cgroups). Enforcement añade dependencia plataforma + complejidad de debug (procesos matados sin gracia, OOM con stacktrace ilegible). *¿Confirmamos solo scheduling, o se valora un modo opt-in `enforce: true` para Linux con cgroups v2?*

2. **Default para jobs sin `memory` declarado.** Tres alternativas:
   - `null` → allocator **conservador**: no apila dos `null` juntos (los serializa de a uno con jobs declarados). Mejor por defecto, peor rendimiento.
   - `0` → trata el job como ligero, libre para apilarse. Riesgo: olvidar declarar y reventar.
   - Heurística por `type` (phpstan: 2048, phpunit: 1024, parallel-lint: 256, custom: 0...). Mágica, frágil al evolucionar tools.

   Propuesta: `null` conservador. Forzar al usuario a declarar cuando le importe el rendimiento; mientras no lo haga, no apilar ciegamente.

3. **Allocator estricto vs greedy.** Con bin-packing 2D, el orden de admisión importa.
   - **Estricto**: respeta orden de declaración FIFO, espera ambos recursos. Predecible. Puede infrautilizar CPU si el primer job de la cola necesita mucha memoria.
   - **Greedy**: prioriza jobs grandes primero, llena huecos con ligeros. Mejor utilización efectiva, complejidad mayor, harder to test.

   Propuesta: estricto en v3.3 (la versión simple ya es diferencial); greedy queda como evolución v3.x si aparece demanda.

4. **`memory-budget` a nivel flow** (paralelo a `time-budget` con `warn-after`/`fail-after`). Detectaría drift acumulado de memoria pico por flow. Pero la "suma de picos paralelos" es difícil de medir post-hoc sin cgroups, y el caso real (proteger el runner) ya lo cubre el gate de scheduling.

   Propuesta: **no** entra en v3.3. Solo gate scheduling. Simetría con `time-budget` queda candidata para v3.4 si aparece demanda.

5. **Soporte Windows.** Auto-detect vía `wmic`/PowerShell con fallback + warning, o exigir `memory-limit` declarado en config para entornos Windows.

   Propuesta: detect con fallback (mejor UX), aceptando que algunas configs raras vayan al fallback. Test específico en CI Windows.

6. **CLI override per-job.** Propuesta: solo `--memory-limit` global y `--no-memory-budget`. No hay flag `--memory=X` para un job concreto — sigue la misma lógica que `cores`. *¿Algún equipo necesita override por job desde CLI para experimentación (ej: ajustar phpstan en un PR concreto)? Si no aparece demanda real, mantener cerrado.*

7. **Interacción con `cores`.** Si un job declara `cores: 4` pero no `memory`, ¿se le asigna `memory` proporcional al ratio core:memory del runner?

   Propuesta: **no**. Son ejes independientes; mezclarlos implícitamente reduce la legibilidad de la config. Si el usuario quiere relacionarlos, lo declara.

8. **¿`memory` heredable desde `flows.options`?** Análogo a cómo `time-budget` se hereda flow→job en el ítem 4. Permitiría declarar un `memory: 2048` por defecto a nivel flow y omitirlo en cada job. Riesgo: oculta el coste real de cada job en su declaración.

   Propuesta: **no** en v3.3. Mantener `memory` como propiedad intrínseca del job (igual que `cores`). Reabrir si la config repetida se vuelve dolor real.

### 6. Estandarizar claves de configuración a kebab-case (deprecation paso 1)

Las claves de job usan camelCase (`executablePath`, `otherArguments`, `ignoreErrorsOnExit`, `failFast`) porque vienen de v2. Las claves de options usan kebab-case (`fail-fast`, `main-branch`, `executable-prefix`, `error-severity`, `log-junit`...) porque se crearon en v3. Hoy ambos conviven en el mismo fichero — ver `qa/githooks.php` líneas 55-58.

**Plan multi-versión:**

1. **v3.3 (paso 1, único objetivo de este release)**: aceptar ambos formatos. Si llega `executablePath`, parsear igual y emitir warning `Deprecated: 'executablePath' is renamed to 'executable-path'. Will be removed in v4.0.` Tabla de mapping camelCase → kebab-case interna. **No tocar `conf:migrate` aún**: dar un ciclo entero para que la gente vea el warning antes de migrar.
2. **v3.x posterior**: `conf:migrate` reescribe camelCase a kebab-case automáticamente.
3. **v4.0**: eliminar soporte camelCase.

**Justificación de empezar ya**: cuanto antes empiece a salir el warning, más tiempo tienen los proyectos consumidores para adaptarse antes del breaking change de v4.0.

**Coste de implementación**: bajo. Un mapping `[camelCase => kebabCase]` aplicado en `JobConfiguration::fromArray()` y `OptionsConfiguration::fromArray()` antes de la validación, más warnings de deprecation. ~50 LOC + tests.

### 7. Validación de commit messages como tipo nativo

Tipo de job nativo `commit-msg` que ejecuta validaciones declarativas sobre el mensaje del commit, leyéndolo de `.git/COMMIT_EDITMSG` (lo que git pasa al hook `commit-msg`).

**Configuración:**

```php
'jobs' => [
    'commit-format' => [
        'type' => 'commit-msg',
        'rules' => [
            'min-length' => 10,
            'max-length' => 100,
            'pattern' => '/^(feat|fix|test|docs|refactor|chore|ci)(\([a-z-]+\))?: .+/',
            'pattern-message' => 'Use Conventional Commits: tipo(scope): descripción',
            'forbid-trailing-period' => true,
            'subject-case' => 'lowercase',  // 'lowercase' | 'sentence' | null
        ],
    ],
    // O modo "preset":
    'commit-conventional' => [
        'type' => 'commit-msg',
        'preset' => 'conventional-commits',   // pattern + reglas estándar
    ],
],

'hooks' => [
    'commit-msg' => ['commit-format'],
],
```

**Reglas mínimas viables:**

| Regla | Significado |
|---|---|
| `min-length` / `max-length` | Longitud del subject (primera línea) |
| `pattern` + `pattern-message` | Regex contra el subject; mensaje custom de error |
| `forbid-trailing-period` | El subject no termina en `.` |
| `subject-case` | `lowercase` / `sentence` / `null` |
| `forbid-empty` | (default true) Rechazar mensaje vacío |
| `merge-allowed` | (default true) Saltar validación si el commit es un merge |

**Presets**: `conventional-commits` de partida (pattern + tipos estándar + footer `BREAKING CHANGE` permitido). `gitmoji`, `jira-ticket` quedan para futuro si hay demanda.

**Comportamiento:**

- Pasa: exit 0, sin output.
- Falla: exit 1 con mensaje claro de qué regla rompió y un ejemplo válido.
- En `--format=json` se incluye el mensaje original y la regla que falló.

**Diferenciador**: hoy se puede hacer con un job `custom` ejecutando un script. El tipo nativo lo hace declarativo, funciona en Windows sin pelearse con bash, se documenta una vez, y refuerza el storytelling "GitHooks gestiona todo el ciclo de git hooks, no solo QA".

**Prioridad**: nice-to-have. Último item en entrar; si el ciclo se alarga, candidato a mover a 3.4.

### 8. Receta de config compartida vía paquete Composer

Caso de uso: una empresa con N microservicios PHP que quiere que todos corran exactamente la misma configuración de phpstan, phpcs, phpmd. Hoy cada repo tiene su propio `qa/githooks.php`, `qa/phpstan.neon`, `qa/phpmd-ruleset.xml`. Si cambias una regla, hay que abrir N PRs.

**Solución sin código nuevo.** Como `githooks.php` es PHP plano, ya se puede hacer:

```bash
composer require --dev acme/qa-shared
```

El paquete exporta una config base:

```php
// vendor/acme/qa-shared/githooks-base.php
return [
    'jobs' => [
        'phpstan-src' => [
            'type' => 'phpstan',
            'config' => 'vendor/acme/qa-shared/phpstan.neon',
            'paths' => ['src'],
        ],
        'phpcs' => [...],
        'phpmd-src' => [...],
    ],
];
```

Y el `githooks.php` del consumidor merge-ea sobre esa base:

```php
<?php

$base = require __DIR__ . '/vendor/acme/qa-shared/githooks-base.php';

return array_replace_recursive($base, [
    'hooks' => [
        'pre-commit' => ['qa'],
    ],
    'flows' => [
        'qa' => ['jobs' => ['phpstan-src', 'phpcs', 'phpmd-src']],
    ],
    // Override puntual: este micro tiene tests legacy y baja el level
    'jobs' => [
        'phpstan-src' => [
            'config' => 'qa/phpstan-relaxed.neon',
        ],
    ],
]);
```

**Qué entra en 3.3 (docs only):**

1. **Página `docs/how-to/shared-config.md`** con el patrón explicado, plantilla del paquete consumible y ejemplo del consumidor.
2. **Mención en `getting-started.md`** como pattern recomendado para empresas.

**Qué queda fuera (candidato a 3.4)**: comando `conf:init --from=acme/qa-shared` que descargue el paquete y genere el wrapper boilerplate. ~50 LOC. Solo se aborda si la doc genera demanda.

**Estado**: el usuario quiere estudiar más a fondo el caso de uso antes de cerrar el diseño definitivo.

---

## v3.4 (aplazado)

Items que estuvieron en v3.3 y se han movido por scope:

- **Wizard de instalación**: evolucionar `conf:init` a un asistente completo (descarga de tools vía Composer/PHAR, configuración paso a paso, explicaciones contextuales). Es el ítem más grande del lote y el de menor diferencial inmediato — los usuarios actuales ya tienen las tools instaladas.
- **Prohibir espacios en nombres de job**: descartado. La convención kebab-case ya está documentada y es lo que recomendamos. Bloquear `Phpstan Src` con un error es más ruido que valor — los proyectos legacy con nombres con espacios siguen funcionando.

---

## Pendiente de análisis

### ~~Análisis competitivo~~ ✔

Completado como parte de v3.1. Se creó la página de comparación (`docs/comparison.md`) con tabla de features y guías de migración desde GrumPHP (`docs/migration/from-grumphp.md`) y CaptainHook (`docs/migration/from-captainhook.md`).

### Problema en el contenedor con los hooks
```bash 
php7.4 githooksstatus

GitHooks Status

  hooks path: not configured (run 'githooks hook' to install)

+------------+--------+---------+
| Event      | Status | Targets |
+------------+--------+---------+
| pre-commit | synced | qa      |
+------------+--------+---------+

  Legend: synced = installed & configured, missing = configured but not installed, orphan = installed but not configured
```
Esto es porque al destruirse el contenedor la variable core.path se resetea.

### ~~Silenciar el progreso en stderr cuando hay formato estructurado en CI~~

Movido a **Fase 0, ítem 0.6**.

### Line-wrap en `conf:check` para comandos largos en lugar de truncar con `…`

Hoy `conf:check` trunca a 80 caracteres con `…` los comandos del job table para mantener la tabla legible en terminales estrechos. El trade-off es que si el usuario quiere revisar los argumentos reales de un job (p.ej. si phpcpd está excluyendo el directorio correcto), tiene que ejecutar `githooks job X --dry-run` en otra pestaña.

Alternativas:

1. **Line-wrap en la celda de la tabla**: dividir el comando en varias líneas cuando supere el ancho disponible, sin truncar. Cero pérdida de información, pero complica el renderizado de la tabla (Symfony Table soporta multilínea por celda via `\n`; hay que medir ancho útil y partir por espacios respetando flags agrupados tipo `--flag=value`).
2. **Flag opcional**: `--full` / `--expand` en `conf:check` que desactive el truncado (bypasea el `truncateCommand`, imprime una línea por job sin tabla cuando superan X caracteres). Comportamiento por defecto queda igual.
3. **Ambas**: wrap por defecto + flag `--compact` que mantiene el comportamiento actual para scripts que parsean la tabla.

Decisión pendiente: qué balance entre legibilidad por defecto y complejidad de renderizado.

