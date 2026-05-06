# Roadmap GitHooks

Solo ideas pendientes de implementar. Lo ya cerrado vive en `docs/changelog.md`.

---

## v3.3.x — Bugs pendientes

### BUG-15 · `--fast` / `--fast-branch` con set vacío no skipea non-accelerable

Con `--fast` (sin staged) o `--fast-branch` (sin diff contra base), los jobs accelerable skipean correctamente pero los non-accelerable (phpunit, paratest, phpcpd, script, custom, composer-*) **siguen ejecutándose** con sus `paths` originales. Resultado: el flow lanza la suite entera de phpunit aunque el modo señaló "no hay cambios relevantes". Contradice el contrato del modo y rompe la paridad con la 2.x.

**Reproducción**: `flow qa --fast` con working tree limpio sobre una config con `phpstan_src` (accelerable, paths declarados) y `phpunit_tests` (non-accelerable). phpstan skipea, phpunit corre la suite.

**Alcance**: solo el caso de **set efectivo vacío**. Cuando el set NO es vacío pero no matchea los `paths` del job, la regla declarativa correcta es `only-files` per-job entry (ver FEAT-1 en v3.4) — separa los conceptos `paths` (input para la tool) y reglas de admisión.

**Implementación**: en [`FlowPreparer::filterJobForMode()`](src/Execution/FlowPreparer.php#L244), detectar la rama "context con set vacío" antes del check de accelerable. Test parametrizado sobre la decision table `(modo × set × accelerable × paths declarados)`.

**Coste**: bajo. ~30 LOC + test parametrizado.

---

## v3.3 — Pendientes

### 7. Pruebas Extra

Verificar que fast-branch y los budgets funcionan en GitHub Actions. Crear una rama, hacer cambios con commits y pushes para verificar que se aplica correctamente. Además verificar en servidores Windows.

### 8. Refuerzo de cobertura a partir de informes Infection y coverage

El usuario genera manualmente los informes de Infection (mutation testing) y de coverage (líneas/branches), y los pasa al asistente. A partir de los informes:

- **Identificar mutants escaped** y módulos con menor MSI; clasificarlos (real / cobertura débil / equivalente / cosmético) usando la skill `mutation-analyzer`.
- **Identificar gaps de coverage**: ficheros y líneas críticas no cubiertos, especialmente tras los cambios masivos de v3.3 (admission, memory, time-budget, formatters, kebab-case parser).
- **Plan priorizado** de tests nuevos, agrupado por módulo y con coste estimado.
- **Ejecución incremental**: tests añadidos en commits separados con tipo `test(<modulo>)`, regenerar informes, iterar hasta el umbral acordado.

**Umbral objetivo**: alinear con tier 1 ya cerrado del proyecto (Infection MSI ~97 %, coverage ≥ 95 % en módulos modificados). Los módulos prioritarios saldrán de los propios informes — no se prefijan a priori.

**Coste estimado**: variable según el resultado de los informes. Tarea iterativa.

### 9. Acelerar la suite de tests del propio proyecto

Los tests de GitHooks empiezan a ser lentos a medida que la suite crece (v3.3 ha añadido muchos tests de execution/output/admission). Dos intervenciones complementarias:

- **Integrar `paratest`** como ejecutor alternativo de la suite. Ya existe el job nativo `paratest` desde v3.x, pero hay que cablearlo para los tests del propio proyecto (un nuevo job `paratest_self` o variante del flow `ci-tests`).
- **Integrar `johnkary/phpunit-speedtrap`** como listener de PHPUnit. Reporta los N tests más lentos al final de la suite, lo que permite identificar candidatos a refactor. Configuración via `phpunit.xml.dist` con el extension/listener registrado.

**Decisiones a cerrar al implementar**:
- ¿Paratest sustituye a phpunit en CI o convive como flow alternativo (`ci-tests-fast` vs `ci-tests`)?
- Umbral de speedtrap (`slowThreshold` en ms) y `reportLength` (cuántos tests slow listar).
- Si los tests con `@group git` o `@group release` son seguros con paratest (paralelización vs side-effects en filesystem real).

**Coste estimado**: bajo. Configuración + ajustes en `phpunit.xml.dist` + posible sub-flow nuevo. Las optimizaciones de tests individuales (refactor de tests slow detectados por speedtrap) son trabajo posterior que sale del informe del listener.

---

## v3.4 (aplazado)

### FEAT-1 · `only-files` / `exclude-files` declarables en `flows.<X>.jobs[entry]`

**Caso de uso real** (monorepo grande con dos sub-apps): el repo es la suma de AppA y AppB con dependencias comunes. Cada sub-app tiene su propia suite (phpunit-A.xml, phpunit-B.xml). Hoy en GitLab CI hay 2 jobs separados, en paralelo, cada uno con su andamiaje (`composer install`, image pull, etc.) que tarda ~2 min, y cada uno corriendo paratest a 4 cores. El runner se satura con los 8 cores simultáneos de los dos jobs.

Lo que se quiere: un solo job de GitLab que invoque un único flow de GitHooks con tests A y tests B serializados (`processes: 1`) y `cores: 6` cada uno. A veces corre A, a veces B, a veces los dos según el diff. Beneficios cuantificables:

| Métrica | Hoy (2 jobs paralelos) | Con FEAT-1 (1 job, flow serializado) |
|---|---|---|
| Andamiaje/desmontaje por pipeline | 4 min (2×2) | 2 min |
| Cores pico simultáneos | hasta 8 | 6 (predecible) |
| Cores ociosos cuando solo aplica una suite | 4 (la otra ejecutándose en vacío) | 0 (skip declarativo) |
| Trazabilidad del skip en MR / dashboard | `passed` enmascarado | `skipped: true` con razón |

Lo que ya funciona hoy en 3.3: serializar (`processes: 1`) y reservar cores predecibles (`cores: 6` en paratest). Lo que falta: la admisión declarativa de A vs B vs ambos según el diff. La única alternativa actual es envolver phpunit en un `type: custom` con `git diff … grep -qE …; exit 0` al inicio del script — funciona pero el job sale como `passed` (no `skipped` real), rompe en runners Windows sin POSIX, y N reglas duplicadas son N scripts shell que mantener.

**Propuesta**: extender `flows.<X>.jobs` para aceptar entradas como string (caso actual) o como objeto:

```php
'flows' => [
    'tests' => [
        'jobs' => [
            ['job' => 'tests_a', 'only-files' => ['src/A/**/*.php', 'composer.json', 'composer.lock']],
            ['job' => 'tests_b', 'only-files' => ['src/B/**/*.php', 'composer.json', 'composer.lock']],
            'lint_full',  // sin reglas, corre siempre
        ],
    ],
],
```

**Semántica**:

- Decisión binaria de admisión (skip vs run), **no filtrado de input**. Phpunit non-accelerable admitido sigue corriendo la suite entera.
- **Aplica a todos los tipos** (accelerable, non-accelerable, custom). La regla "¿este job es admisible en esta invocación?" es ortogonal al tipo.
- **`paths` mantiene su semántica actual** (input para la tool en accelerable, ignorado en non-accelerable). NO se mezcla con el rol de regla de admisión.
- **Modo full**: las reglas son no-op. Si no, declarar `only-files` rompería `flow qa` manual.
- **`exclude-files` prevalece sobre `only-files`** (consistente con HookRef).
- **Glob nativo** (`**`, `*`, `?`) — misma sintaxis que en HookRef y `--exclude-pattern`.
- **Composición con HookRef**: doble filtrado natural (AND lógico). El HookRef filtra la admisión al hook; las reglas del flow entry filtran la admisión al job. Niveles ortogonales, sin reglas de prevalencia.
- **Trazabilidad**: el job aparece como `skipped: true` con `skipReason` en JSON/SARIF/JUnit (no `exit 0` enmascarado).

**Reuso**: la lógica de matching glob ya existe en [`HookRef`](src/Configuration/HookRef.php) y en `--exclude-pattern`. La integración va en [`FlowPreparer::filterJobForMode()`](src/Execution/FlowPreparer.php#L244) antes del chequeo de accelerable. Validación nueva en `conf:check`: glob bien formado, no duplicar entradas en `only-files`/`exclude-files`, mismo job no puede aparecer dos veces en la misma `jobs[]` con reglas distintas.

**Out of scope**: no reciclar `paths` para admisión (mantener separación de conceptos), no introducir prevalencia hook vs flow (composición natural por construcción), no tocar `--files` / `--files-from` / `--exclude-pattern` CLI (son ortogonales).

**Relación con BUG-15**: BUG-15 cubre "set efectivo vacío" (no hay cambios). FEAT-1 cubre "set no vacío pero no afecta a este job". Conceptos distintos, fixes complementarios. BUG-15 va antes (es bug del modo); FEAT-1 después (mejora declarativa).

**Ver también**: FEAT-2 (`on` per flow) y FEAT-3 (`needs` per entry). Las tres se componen como atributos de la misma flow entry: FEAT-2 selecciona el modo según la rama, FEAT-1 filtra la admisión por ficheros, FEAT-3 ordena la admisión por dependencias entre jobs.

### FEAT-2 · `on => [branch => attributes]` declarable per flow

**Caso de uso real**: el `.gitlab-ci.yml` del usuario tiene reglas duplicadas para asignar `GITHOOKS_FLAGS=--fast-branch` solo en task branches mientras las ramas principales (`master`/`beta`/`main`) corren en modo `full`. La lógica vive en el caller (YAML del CI), replicada en cada plantilla de reglas (`qa_validation_rules`, `tests_php_rules`, etc.). Si mañana cambias de CI o tienes hooks locales que invocan el mismo flow, hay que reescribirla.

Lo que se quiere: que el config de GitHooks decida qué modo aplicar según la rama, y el caller pase a una sola línea `script: githooks flows X` sin variables.

**Propuesta**: una key `on` per flow que mapea patrones de rama a atributos de ejecución:

```php
'flows' => [
    'ci-validation' => [
        'on' => [
            'master' => ['execution-mode' => 'full'],
            'beta'   => ['execution-mode' => 'full'],
            'main'   => ['execution-mode' => 'full'],
            '*'      => ['execution-mode' => 'fast-branch'],
        ],
        'jobs' => [/* ... */],
    ],
],
```

**Por qué esta sintaxis y no `execution-mode => [modo => [ramas]]`** (la del .md original):

Porque generaliza. Hoy soporta `execution-mode`. Mañana, sin cambiar la estructura raíz, puede llevar `time-budget`, `fail-fast`, etc.:

```php
'on' => [
    'master' => ['execution-mode' => 'full', 'time-budget' => ['fail-after' => 1200]],
    '*'      => ['execution-mode' => 'fast-branch', 'time-budget' => ['fail-after' => 300]],
],
```

Y es coherente con `HookRef.only-on` (mismo eje rama/glob, distinto efecto: el HookRef admite, el `on` selecciona).

**Decisiones del diseño**:

| Decisión | Valor |
|---|---|
| Sintaxis | `on => [branch_pattern => attributes_block]` |
| Atributos soportados v3.4 | `execution-mode` (string: `full` \| `fast` \| `fast-branch`) |
| Modo `files` | Fuera del scope (lista dinámica no expresable en config estático) |
| Cascada de matching | literal exacto > glob específico (`release/*`) > `*` catch-all |
| Detección de rama | (1) `--branch=X` CLI > (2) `GITHOOKS_BRANCH` env > (3) CI vars conocidas (`CI_COMMIT_REF_NAME`, `GITHUB_REF_NAME`, `BUILDKITE_BRANCH`…) > (4) `git rev-parse --abbrev-ref HEAD` > (5) si devuelve `HEAD` literal: error con mensaje pedagógico |
| CLI flag override | Sí — `--mode=full` siempre gana (CON-005) |
| Ámbito | Per flow (no global en v3.4) |
| Coexistencia con `HookRef.only-on` | Niveles distintos: HookRef admite el flow al hook; flow.on selecciona modo |

**Caso compuesto con FEAT-1**

Las dos features se componen para cerrar el caso CI completo:

```php
'flows' => [
    'ci-validation' => [
        'on' => [
            'master' => ['execution-mode' => 'full'],
            '*'      => ['execution-mode' => 'fast-branch'],
        ],
        'options' => ['processes' => 1],
        'jobs' => [
            ['job' => 'tests_a', 'only-files' => ['src/AppA/**', 'composer.lock']],
            ['job' => 'tests_b', 'only-files' => ['src/AppB/**', 'composer.lock']],
        ],
    ],
],
```

CI:
```yaml
script: vendor/bin/githooks flows ci-validation
```

En master: `execution-mode → full`, las `only-files` quedan no-op, ambos `tests_*` corren la suite completa. En task branch: `fast-branch`, FEAT-1 filtra (A si toca AppA, B si toca AppB, ambos si tocan ambos o composer.lock).

**Reuso**: el matcher de glob ya existe en `HookRef`. El CLI flag `--mode=` ya existe. Detección de CI vars es nueva pero limitada (~6 vars conocidas).

**Validación nueva en `conf:check`**:
- Glob de rama válido.
- `execution-mode` con valor permitido (no `files`).
- Atributos desconocidos bajo `on.<branch>` con did-you-mean (reusa `KeySuggestion`).
- No duplicar el mismo patrón de rama.
- Si no hay `*` catch-all, warning (no error: el usuario puede querer fallar explícito cuando la rama no encaja).

**Out of scope v3.4**:
- Atributos distintos a `execution-mode` (esperar demanda confirmada antes de extender).
- `on` global en `flows.options.on` (per flow llega para v3.4; global ensancha sin caso).
- Modo `files` per rama (la lista dinámica no encaja en config estático).

**UX y mitigaciones**:
- El dev que ejecuta `flows X` localmente desde una task branch corre `fast-branch` sin pedirlo. La cabecera `Settings:` ya muestra `mode = fast-branch (flows.<X>.on)` con su origen, pero la primera vez sorprende. Mitigar con docs claras y un ejemplo de `--mode=full` como escape hatch local.

**Score**: 6.5/10 (con la sintaxis B). Por debajo de FEAT-1 en urgencia, pero defendible: caso real concreto en CI, encapsulamiento elegante, base extensible.

### FEAT-3 · `needs` declarable per job entry para dependencias dentro de un flow

**Casos de uso reales**:

1. **Proyecto híbrido PHP+Vue**. Tres jobs frontend: `yarn-install`, `eslint`, `prettier`. `eslint` y `prettier` necesitan `node_modules/`; sin él, `eslint: command not found`. En paralelo con `--processes >= 4` y otros jobs PHP (phpstan, phpcs, phpmd) hoy no hay forma de garantizar el orden sin partir el flow.

2. **Pre-commit con `phpcbf` antes de `phpcs`**. `phpcbf` corrige automáticamente y restagea (3.3 ya lo hace), `phpcs` verifica solo lo no corregible. Si `phpcs` corre antes que `phpcbf` con `--processes > 1`, reporta errores que `phpcbf` habría corregido — falsos positivos.

**Lo que falla hoy**:

```php
'flows' => [
    'qa' => [
        'options' => ['processes' => 4],
        'jobs' => [
            'yarn-install',     // 30s, debe ir primero
            'eslint',           // 5s, depende de node_modules
            'prettier',         // 5s, depende de node_modules
            'phpstan',          // 60s, independiente
            'phpcs',            // 15s, independiente
        ],
    ],
],
```

`processes: 4` admite eslint sin esperar a yarn → falla. El orden no es declarativo.

**Por qué meta-flow no captura bien el patrón**:

El meta-flow `[yarn-install, [eslint, prettier, phpstan, phpcs]]` funciona pero **desperdicia paralelismo**: phpstan (60s) y los demás esperan a que yarn termine (30s) cuando podrían correr en paralelo desde t=0. La pérdida de wall time es proporcional a `min(duración del job dependencia, duración de jobs independientes)`. En CI con runners por minuto/hora son segundos × N pipelines/día = horas perdidas.

**Comparación numérica del caso 1**:

| Estrategia | Wall time | Comentario |
|---|---|---|
| Paralelo sin needs (hoy) | — | falla por `eslint: command not found` |
| Meta-flow `[yarn, [resto]]` | 30 + 60 = 90s | yarn bloquea phpstan/phpcs/phpmd 30s |
| Con `needs` | 60s | yarn corre paralelo a phpstan; eslint/prettier esperan solo a yarn |

Diferencia: 30s ahorrados por pipeline, multiplicados por N runs.

**Propuesta**: extender la sintaxis de flow entry de FEAT-1 con un atributo `needs`:

```php
'flows' => [
    'qa' => [
        'options' => ['processes' => 4],
        'jobs' => [
            'yarn-install',
            ['job' => 'eslint',   'needs' => ['yarn-install']],
            ['job' => 'prettier', 'needs' => ['yarn-install']],
            'phpstan',
            'phpcs',
        ],
    ],
],
```

**Decisiones del diseño**:

| Decisión | Valor |
|---|---|
| Ubicación | Atributo de flow entry, igual que `only-files`/`exclude-files` (FEAT-1) |
| Valor | Lista de nombres de jobs en el mismo flow |
| Cross-flow | No en v3.4 — `needs` no cruza el límite de un flow (eso ya lo dan los meta-flows) |
| Skip por dependencia skipeada | Se propaga por defecto (`skipReason: "needs <X> was skipped"`); `optional: true` para no propagar (idea v3.5+ si aparece demanda) |
| Fail por dependencia fallida | Siempre se propaga: dependiente skipea con `skipReason: "needs <X> failed"` |
| Detección de ciclos | `conf:check` con DFS, error explícito apuntando al ciclo |
| `processes: 1` | Compatible: el orden secuencial respeta el orden topológico |
| `fail-fast` | Refinable con `needs`: solo aborta los descendientes, no todo el flow |
| Output (dashboard TTY paralelo) | Cuarto estado: ⏸ "waiting (deps)" además de queued/running/done |
| JSON v2 | Nuevo campo `waitedOn: ['<job>', ...]` cuando aplica; `skipReason` con la causa concreta |

**Composición con FEAT-1**:

```php
['job' => 'eslint', 'needs' => ['yarn-install'], 'only-files' => ['**/*.{js,vue,ts}']],
```

Si no hay JS modificado, `eslint` skipea por `only-files`. `yarn-install` corre igual (otra entry sin `only-files`). Si yarn-install fallase, eslint skipea con razón "needs yarn-install failed" — no se evalúa `only-files`. Orden de evaluación: `only-files`/`exclude-files` primero (admisión por inputs), `needs` segundo (admisión por dependencias).

**Composición con FEAT-2**: el modo (`full` / `fast` / `fast-branch`) no afecta a `needs`. La dependencia es estructural, independiente del modo.

**Reuso**: la lógica de admisión 2D (cores + memory) ya existe en `FifoAdmission`/`GreedyAdmission`. Añadir `needs` significa pre-filtrar la cola: un job es admisible solo si sus dependencias están completas. Topological sort en `conf:check` para detección de ciclos. El dashboard TTY paralelo ya es estado-máquina; añadir un estado más es viable.

**Validación nueva en `conf:check`**:
- `needs` apunta a jobs declarados en el mismo flow.
- No hay ciclos (DFS sobre el DAG; error con la lista del ciclo).
- No duplicar entradas en `needs`.
- Atributo `needs` solo válido en entries-objeto, no en strings.

**Out of scope v3.4**:
- `needs` cross-flow (eso es meta-flow).
- `needs` con condiciones tipo GitLab (`when: 'always'` / `when: 'on_failure'`) — empezar simple.
- Paralelismo "matrix" tipo GHA.
- `optional: true` per dependencia — esperar demanda.

**Score**: 6/10. Justo por debajo de FEAT-2. Los dos casos reales son recurrentes en proyectos PHP serios; meta-flow funciona pero desperdicia paralelismo justo en los proyectos donde más interesa.

### FEAT-5 · Run history + comando `profile` para análisis temporal

**Caso de uso**: hoy, "phpstan_src está tardando más esta semana, ¿desde cuándo?" no tiene respuesta. Cada `--stats` es un snapshot aislado; las tendencias se pierden. Cuando se introduzca un cambio que afecta a la performance (refactor de qa, actualización de phpstan, regla nueva en la config), el regression hunt es a ojo.

**Propuesta**: persistir el JSON v2 de cada run en `.githooks/history/<timestamp>-<flow>.json` (rotación a N últimos runs, configurable). Dos comandos nuevos:

```bash
githooks history qa                          # lista últimos N runs con totalTime, passed/failed
githooks profile qa --job=phpstan_src        # gráfico ASCII de duración del job
githooks profile qa --metric=peak-memory     # idem para memoria
```

Output `profile`:
```
phpstan_src · last 30 runs · time
  4.2s ▁▃▆▆█▆▃▁▂▃▆█▇▆▅▅▆▆▆▇█▇▆▆▅▅▆▆▆▇
  ──────────────────────────────────
  min: 1.8s · p50: 4.0s · p95: 5.6s · max: 6.1s · trend: ↑ +18% vs prev 30
```

**Decisiones tentativas**:

| Decisión | Valor |
|---|---|
| Persistencia | `.githooks/history/<timestamp>-<flow>.json` (un JSON v2 completo por run) |
| Rotación | N últimos runs, default 100, configurable via `flows.options.history-size` |
| Activación | Opt-in via `--save-history` o `history-size > 0` en config |
| Métricas soportadas | `time` (total + per-job), `peak-memory` (per-job), `peak-cores` (per-job), `passed/failed/skipped` |
| Filtros | `--job=X`, `--since=YYYY-MM-DD`, `--last=N` |
| Gitignore | El path `.githooks/history/` debe ir auto al gitignore en `conf:init` (history es local, no debe viajar al repo) |
| Output formats | text (ASCII sparkline + estadísticas), `--format=json` para consumers |
| Composición con `time-budget` | El `profile` puede sugerir umbrales calibrados: "p95 = 5.6s; sugerir warn-after=7" |

**Reuso**: el JSON v2 ya es estable y rico (effectiveOptions, timeBudget, memoryBudget, deprecations, stats). Solo se añade persistencia al final del run y comandos de query sobre el directorio.

**Sinergia futura**: con FEAT-9 (threshold regression budgets) podría auto-detectar regresiones: "el p95 de phpstan_src ha pasado de 4.2s a 6.1s en los últimos 30 runs vs los 30 anteriores → fallar el flow".

**Out of scope v3.4**:
- Persistencia centralizada (server-side dashboard tipo Codecov para coverage). Local files-only en v3.4.
- Comparativa entre proyectos.
- Filtros avanzados tipo `--branch=` (requeriría persistir más metadata por run).

**Score**: 7/10. Coste bajo (JSON ya está; añadir escritura al directorio + comandos de query con sparkline ASCII), valor alto, encaja con la línea data-driven de v3.3 (time-budget, memory-budget, --stats).

### FEAT-6 · Comando `affected` para análisis de impacto

**Caso de uso**: monorepo grande, MR con 3 ficheros modificados. ¿Qué flows/jobs se afectan según los `paths` declarados? Hoy hay que leer mentalmente la config + paths + entender accelerable. En proyectos con N flows × M jobs, no se escala. En CI orquestado (donde una etapa decide qué etapas siguientes lanzar), tener una respuesta declarativa simplifica.

**Propuesta**: comando nuevo `affected` que toma un set de ficheros (de un diff o explícitos) y reporta qué flows/jobs serían admitidos:

```bash
githooks affected --since=origin/main
githooks affected --files=src/Foo.php,composer.lock
githooks affected --since=origin/main --format=json   # para consumers (CI orquestado)
```

Output text:
```
Affected by 3 changed files (vs origin/main):
  src/AppA/User.php, src/AppA/Order.php, composer.lock

  Flow `qa`:
    ✓ phpstan_src      (matches src/AppA/**)
    ✓ phpcs_src        (matches src/AppA/**)
    ✗ phpstan_modules  (no match)
    ✓ phpunit_unit     (matches composer.lock)

  Flow `tests`:
    ✓ tests_a          (matches src/AppA/**, composer.lock)
    ✗ tests_b          (no match)

Summary: 4 jobs would run, 2 would skip.
```

Output JSON:
```json
{
  "input": {"source": "git-diff", "base": "origin/main", "files": [...]},
  "flows": [
    {"name": "qa", "affected": ["phpstan_src", "phpcs_src", "phpunit_unit"], "skipped": ["phpstan_modules"]},
    {"name": "tests", "affected": ["tests_a"], "skipped": ["tests_b"]}
  ]
}
```

**Decisiones tentativas**:

| Decisión | Valor |
|---|---|
| Modos de input | `--since=<ref>` (git diff), `--files=<csv>` o `--files-from=<path>` (explícito) |
| Modos de evaluación | Equivalente a `--fast` (con `--since=HEAD`) o `--fast-branch` (con `--since=origin/main`). Reusa lógica de `FlowPreparer::filterJobForMode()` |
| Composición con FEAT-1 | Si entra `only-files`/`exclude-files` per entry, `affected` los respeta |
| Composición con FEAT-3 | Si entra `needs`, un job afectado pero cuya dependencia no aplica también se reporta como skipped (con `skipReason: "needs <X> not affected"`) |
| Output | text por defecto, `--format=json` para consumers |
| Side effects | Ninguno. NO ejecuta los jobs. Solo calcula el plan |

**Reuso**: la lógica de matching ya existe en `FlowPreparer::filterJobForMode()`. El comando es básicamente un dry-run del filtrado sin invocar los procesos.

**Casos de uso reales**:

- **CI orquestado**: una etapa "plan" llama `githooks affected --format=json --since=$BASE_SHA`, parsea el resultado, y decide qué jobs Kubernetes/Docker lanzar.
- **Code review humano**: "¿qué tests me toca correr antes del PR?". Una mirada rápida.
- **Onboarding**: dev nuevo entiende qué afecta qué sin leer toda la config.

**Out of scope v3.4**:
- Análisis transitivo de dependencias entre paths (si `src/Foo.php` requiere `src/Bar.php`, solo se considera lo declarado en `paths`, no el grafo de uses PHP).
- Detección automática de qué tests cubren qué clase (eso es coverage analysis, fuera de scope).

**Score**: 6.5/10. Coste muy bajo (reusa lógica de filterJobForMode, sin ejecución), valor real en monorepos y CI orquestado. Encaja con la línea de "data-driven" de v3.3.

### Validación de commit messages como tipo nativo (`commit-msg`)

Tipo de job nativo que valida el subject del commit con reglas declarativas (`min-length`, `max-length`, `pattern`, `forbid-trailing-period`, `subject-case`, `forbid-empty`, `merge-allowed`) y preset `conventional-commits`. Se cablea al hook git `commit-msg`. Movido el 2026-04-28: a nivel funcional v3.3 ya cumple el objetivo del usuario (multi-flow + multi-reporte + budgets); commit-msg pasa a "nice-to-have" sin urgencia. **Spec de diseño ya redactada** en `spec/spec-design-commit-message-validation.md` (~620 líneas, 17 AC, 34 REQ): cuando se reabra, la implementación arranca directa de ahí.

### Wizard de instalación

Evolucionar `conf:init` a un asistente completo (descarga de tools vía Composer/PHAR, configuración paso a paso, explicaciones contextuales). Es el ítem más grande del lote y el de menor diferencial inmediato — los usuarios actuales ya tienen las tools instaladas.

---

## Pendiente de análisis

### FEAT-4 · Ordenar la tabla de `--stats` por nombre / tipo / orden de ejecución

**Caso de uso**: en proyectos con muchos jobs nominalmente similares (`phpstan_app`, `phpstan_src`, `phpstan_modules`, `phpcs_app`, `phpcs_src`, …), la tabla de `--stats` sale hoy en orden de finalización (no determinista en `processes > 1`). Para responder "¿corrió phpstan_modules?", el ojo tiene que escanear la tabla entera. Si los grupos `phpstan_*` / `phpcs_*` salen contiguos, la lectura es inmediata.

**Propuesta**: flag `--stats-sort=<exec|name|type>` con default `exec` (comportamiento actual). Cuando el sort sea distinto de `exec`, añadir una columna `#` con el orden de ejecución para no perder esa información:

```
+----+-----------------+--------+-------+------------+-------------+
| #  | Job             | Status | Time  | Peak Cores | Peak Memory |
+----+-----------------+--------+-------+------------+-------------+
| 3  | phpcs_app       | OK     | 65ms  | 1          | 40 MB       |
| 5  | phpcs_modules   | OK     | 72ms  | 1          | 41 MB       |
| 6  | phpcs_src       | OK     | 82ms  | 1          | 42 MB       |
| 1  | phpstan_app     | OK     | 60ms  | 2          | 70 MB       |
| 4  | phpstan_modules | OK     | 70ms  | 2          | 75 MB       |
| 2  | phpstan_src     | OK     | 80ms  | 2          | 78 MB       |
+----+-----------------+--------+-------+------------+-------------+
| -  | TOTAL (flow)    | 6/6 ✔  | 0.42s | 2/2        | 78 MB       |
+----+-----------------+--------+-------+------------+-------------+
```

**Decisiones tentativas**:

| Decisión | Valor |
|---|---|
| Flag CLI | `--stats-sort=<exec\|name\|type>` |
| Default | `exec` (orden de finalización, igual que hoy) |
| Config equivalente | `stats-sort` en `flow.options` / `flows.options` |
| Columna `#` | Solo cuando el sort sea distinto de `exec` (con `exec` la fila ya marca el orden) |
| Definición de "tipo" | Literal del campo `type` del job (`phpstan`, `phpcs`, `custom`, `paratest`, …) |
| Aplicación a JSON v2 | NO — el JSON sigue en orden canónico de ejecución. Añadir `executionOrder: N` per job para que consumers reordenen client-side |
| Aplicación a JUnit/SARIF/CodeClimate | NO — solo afecta al formato texto humano |

**Por qué "Pendiente de análisis" en lugar de v3.4**:

- Coste muy bajo (~50 LOC + flag + tests) pero **caso 80/20 tibio**: el sort gana valor real a partir de ~10 jobs en el flow. La mayoría de proyectos tienen 5-8.
- En proyectos bien nombrados, sort `name` ya agrupa por prefijo de facto = sort `type` en la práctica. La diferencia entre `name` y `type` es marginal salvo nomenclaturas inconsistentes.
- Nice-to-have para una v3.5 sin tirón de planning. Esperar a confirmar tablas grandes reales antes de implementar.

**Score**: 5/10. Polish UX, no urgente.

### FEAT-7 · Watch mode (`--watch` / `flow watch`)

**Caso de uso**: dev iterando sobre `src/Foo.php`. Hoy `githooks job phpstan_src --files=src/Foo.php` cada vez carga PHP, instancia el flow, rehace bootstrap — overhead de ~200-300 ms por invocación aunque el job tarde 50 ms. En TDD eso se acumula.

**Propuesta**: `githooks flow qa --watch` deja un proceso vivo que observa el filesystem y re-ejecuta el flow filtrando por los ficheros que cambiaron. Como `vitest watch`, `nodemon` o `phpunit-watcher`. Los jobs accelerable se filtran automáticamente (intersección de cambios y `paths` declarados); los non-accelerable corren completos cada vez que se afectan según FEAT-1 (cuando entre).

**Decisiones tentativas**:

| Decisión | Valor |
|---|---|
| Flag | `--watch` en `flow` y `job` |
| Backend FS watcher | Pluggable: detecta `inotify-tools` (Linux), `fswatch` (macOS), `Watchman` si están disponibles. Fallback: polling cada N ms (degradado pero universal) |
| Debounce | 200-500 ms agrupando ráfagas de cambios (editor "save all", `git checkout`) |
| Salida | TTY interactivo: header con "watching N paths…", run output como ahora, separador entre runs, `q` o `Ctrl+C` para salir |
| Activación | CLI only — no tiene sentido en config |
| Composición con `--format=json` | Permitido pero NDJSON por run (un objeto JSON por línea, separados por `\n`); cada run es un payload completo |
| Bootstrap warm | El proceso PHP se mantiene vivo; entre runs solo se re-instancian flow + jobs. Ahorro real vs invocaciones repetidas |

**Por qué "Pendiente de análisis" en lugar de v3.4**:

- **Coste medio-alto**: PHP no tiene FS watcher nativo. Habría que envolver `inotify-tools` / `fswatch` / `Watchman` (cada uno con su sintaxis distinta) o caer a polling. La parte GitHooks (recibir lista de paths cambiados → invocar flow filtrado) es trivial; la parte del watcher es donde está el trabajo real.
- **Puede haber alternativas externas**: `entr`, `watchexec`, GitHub `gh` con wrappers shell. Antes de invertir en una solución nativa, valorar si una recomendación de "úsalo con `entr`" cubre el 80%.
- **Diferenciador competitivo**: ningún competidor PHP (GrumPHP, CaptainHook) tiene watch. Si entra, es un argumento de marketing.

**Score**: 6/10. ROI alto en developer experience pero coste medio-alto que justifica esperar.

### FEAT-12 · Distribución como binario standalone (PHPacker)

**Caso de uso**: equipos sin PHP instalado (proyectos JS/Vue puros, CI runners minimalistas, devs curiosos que vienen de lefthook/pre-commit) que querrían probar GitHooks sin levantar el stack PHP. Hoy, `composer require wtyd/githooks` es bloqueante: si no tienes PHP/Composer en el entorno, no entras.

**Premisa explícita — estrictamente aditivo**:

- El `.phar` sigue siendo la distribución canónica. Para usuarios con PHP instalado: pesa 7.5 MB, comportamiento validado, build matrix existente con dos tiers (`builds/` para 8.1+, `builds/php7.4/` para 7.4-8.0).
- Los binarios standalone son un **tercer artefacto adicional** para usuarios sin PHP. Se distribuyen en GitHub Releases como assets aparte de los `.phar`.
- Si un binario falla en un caso límite (extensión PHP no embebida, comportamiento divergente), el `.phar` sigue siendo el camino canónico — el binario no es sustituto, es opción.

**Backend**: [PHPacker](https://phpacker.dev), integrado en Laravel Zero ([docs](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary)). Empaqueta PHP 8.4 + el `.phar` en un binario único por plataforma.

**Plataformas soportadas por PHPacker**: macOS arm64/x64, Linux arm64/x64, Windows x64 (cinco binarios por release).

**Build pipeline tentativo**:

```bash
php8.1 tools/composer update --no-dev      # ya hacemos
php8.1 githooks app:pre-build php          # ya hacemos
php8.1 githooks app:build                  # genera builds/githooks (.phar 8.1+)
phpacker build --src=builds/githooks --php=8.4 all   # NUEVO: 5 binarios
```

CI workflow extendido: matrix `[linux-x64, linux-arm64, macos-x64, macos-arm64, windows-x64]` paralela tras la fase de `.phar`. Cada job ejecuta `phpacker build` para su plataforma y sube el artefacto a la release.

**Caveats por verificar antes de comprometer (spike de 1-2 días)**:

1. **Extensiones críticas embebidas**: GitHooks usa `mbstring`, `tokenizer`, `dom`/`simplexml` (SARIF/JUnit), `pcntl`/`posix` (memory sampling con `posix_isatty`, `pcntl_signal`). Confirmar que PHPacker las incluye o son configurables.
2. **Tamaño real del binario**: estimación 40-80 MB. ×5 plataformas = 250-400 MB por release en GitHub Releases. Aceptable pero no trivial.
3. **`shell_exec` para invocar QA tools del proyecto cliente** debe funcionar idéntico al `.phar` (es API PHP estándar, sin runtime quirks esperables).
4. **Cross-compilation**: PHPacker descarga binarios PHP precompilados, así que cada plataforma se construye desde su propio runner CI (no requerimos cross-compiler).
5. **Tests `@group release`**: hoy validan el `.phar`. Habría que replicar el harness para validar los binarios (al menos los smoke tests: `flow qa --format=json`, `conf:check`, `system:info`).

**Plan tentativo (en orden)**:

1. **Spike** (1-2 días): instalar PHPacker localmente, generar binario Linux x64 sobre el `.phar` actual, probarlo contra `/var/www/html3` con 3-4 comandos canónicos. Registrar tamaño, anomalías, extensiones que fallan.
2. **Si el spike pasa sin caveats serios**: añadir matriz CI para los 5 binarios + tests release adaptados + sección en docs de instalación. Subir a "v3.4" o una v3.x menor según prioridad.
3. **Si el spike encuentra caveats serios**: documentar y mantener en "Ideas exploratorias" con datos concretos.

**Decisiones tentativas**:

| Decisión | Valor |
|---|---|
| Distribución `.phar` | Canónica, sin cambios. Dos tiers (`builds/`, `builds/php7.4/`). |
| Distribución binario | Adicional, opt-in. 5 binarios por release. |
| Versión PHP embebida | 8.4 (lo que produce PHPacker). Independiente de los tiers `.phar`. |
| Audience documentado | "Para usuarios sin PHP instalado. Si tienes PHP, prefiere el `.phar`." |
| Tests | Smoke tests por plataforma en CI release; no replicar la suite completa contra binarios. |
| Versión semántica | El binario sigue la misma versión que el `.phar` (1:1). |
| Composición con `--no-color`, TTY detection, etc. | Validar en spike. PHP 8.4 embebido debería responder igual al `.phar`. |

**Out of scope**:

- Rewrite del proyecto en Go/Rust. Coste enorme (~6-12 meses), tira el equity de 30K líneas, compite con lefthook/pre-commit en su terreno.
- Soporte de plataformas exóticas (BSD, Alpine musl) más allá de las cinco que cubre PHPacker out-of-the-box.
- "Static-php-cli manual" — PHPacker ya lo abstrae.

**Score**: 5.5/10. Coste real bajo (1 semana incluido CI tests) gracias a PHPacker; estrategia aditiva (no se tira nada); abre potencial de adopción non-PHP. La nota baja viene de la incertidumbre sobre demanda real (mientras "no nos conoce ni el tato", la inversión en distribución multi-plataforma puede no encontrar audiencia hasta que la base de usuarios crezca por otras vías).

**Por qué "Pendiente de análisis" en lugar de v3.4**:

- Necesita spike previo para confirmar viabilidad sin caveats serios.
- v3.4 ya tiene carga (FEAT-1/2/3/5/6 + commit-msg + Wizard).
- No resuelve dolor de usuarios actuales, abre mercado nuevo. Mejor esperar al cierre de v3.4 y meter la inversión cuando haya capacidad.

### Problema en el contenedor con los hooks

```bash
php7.4 githooks status

GitHooks Status

  hooks path: not configured (run 'githooks hook' to install)

+------------+--------+---------+
| Event      | Status | Targets |
+------------+--------+---------+
| pre-commit | synced | qa      |
+------------+--------+---------+

  Legend: synced = installed & configured, missing = configured but not installed, orphan = installed but not configured
```

Esto es porque al destruirse el contenedor la variable `core.hooksPath` se resetea.

### Receta de config compartida vía paquete Composer

Movido aquí desde v3.3 el 2026-04-28. Motivo: el caso de uso del ROADMAP (empresa con N microservicios que comparten config QA vía dependencia Composer) **no está confirmado por ningún consumidor real**. El propio caso del autor del proyecto resulta no encajar — comparten un framework por **fork**, no por dependencia, y el patrón de `array_replace_recursive` sobre un paquete vendoreado no aplica al modelo fork.

Reabrir cuando aparezca un consumidor real en el modelo "framework como dependencia Composer + N proyectos consumidores" que pida soporte oficial. Hasta entonces, el patrón ya funciona hoy en PHP plano (`require` + `array_replace_recursive`) sin necesidad de página de docs ni tests dedicados; quien lo necesite lo descubre en 5 minutos.

Diseño previo (v1.0, 2026-04-28) preservado en el historial git por si se reabre. Texto original contemplaba: docs only en v3.3 (página `docs/how-to/shared-config.md`, mención en `getting-started.md`, plantilla del paquete consumible y ejemplo del consumidor); comando `conf:init --from=acme/qa-shared` aplazado a v3.4.

### Line-wrap en `conf:check` para comandos largos en lugar de truncar con `…`

Hoy `conf:check` trunca a 80 caracteres con `…` los comandos del job table para mantener la tabla legible en terminales estrechos. El trade-off es que si el usuario quiere revisar los argumentos reales de un job (p.ej. si phpcpd está excluyendo el directorio correcto), tiene que ejecutar `githooks job X --dry-run` en otra pestaña.

Alternativas:

1. **Line-wrap en la celda de la tabla**: dividir el comando en varias líneas cuando supere el ancho disponible, sin truncar. Cero pérdida de información, pero complica el renderizado de la tabla (Symfony Table soporta multilínea por celda via `\n`; hay que medir ancho útil y partir por espacios respetando flags agrupados tipo `--flag=value`).
2. **Flag opcional**: `--full` / `--expand` en `conf:check` que desactive el truncado (bypasea el `truncateCommand`, imprime una línea por job sin tabla cuando superan X caracteres). Comportamiento por defecto queda igual.
3. **Ambas**: wrap por defecto + flag `--compact` que mantiene el comportamiento actual para scripts que parsean la tabla.

Decisión pendiente: qué balance entre legibilidad por defecto y complejidad de renderizado.

---

## Ideas exploratorias

Funcionalidades con ROI percibido bajo, casos de uso de nicho, o que solapan con alternativas razonables. Apuntadas para no perderlas; no se trabajan sin un caso real concreto que las eleve a "Pendiente de análisis".

### FEAT-8 · NDJSON streaming del JSON v2

Hoy `--format=json` es one-shot al final del run. Para un consumidor (CI dashboard live) que quiere ver progreso por job, NDJSON (un objeto JSON por línea, formato `application/x-ndjson`) sería emisión incremental: cada job termina → línea JSON nueva con su result; al final una línea con el resumen del flow.

**Por qué exploratorio**: el dashboard TTY ya cubre el caso human-eyes; los CI dashboards normalmente parsean al final del run. La demanda real para NDJSON live es nicho, y mientras tanto SSE / websockets / polling sobre `--report-json=PATH` cubren el 80%.

**Score**: 4/10.

### FEAT-9 · Threshold-based regression budgets

Configurar umbrales tipo "phpstan no debe reportar más de N errores", "psalm no debe pasar de M issues". Si el run actual los cruza, falla. Útil en proyectos con backlog técnico que no quieren regresar.

**Por qué exploratorio**: las herramientas modernas ya lo cubren nativamente — phpstan tiene `baseline`, psalm tiene `errorBaseline`, php-cs-fixer tiene `dry-run --diff`. GitHooks duplicaría sin aportar diferencial. El valor real estaría en agregar varias herramientas en una sola política, pero ese caso es raro.

**Score**: 4/10. Reabrir si aparece sinergia con FEAT-5 (history): "el p95 de errores de phpstan sube ↑ 40 % vs últimos 30 runs → fallar".

### FEAT-10 · `replay <run-id>` — re-ejecutar un plan capturado

Dado un run-id en `.githooks/history/` (FEAT-5), re-ejecutar exactamente el mismo plan: mismos jobs, mismos input files, mismas options efectivas. Útil para reproducir CI flakys en local sin tener que reconstruir el contexto.

**Por qué exploratorio**: depende de FEAT-5 (history). Aporta valor real en proyectos con tests flaky, pero la solución más usada hoy es checkout del commit + reset config + run manual — funciona aunque sea menos cómodo. Score depende de cuánta gente persigue flakys cross-environment.

**Score**: 5/10 si entra FEAT-5 antes; sin FEAT-5, no aplica.

### FEAT-11 · `doctor` command — diagnóstico de tools y entorno

Comando que verifica que todas las tools declaradas en config existen, responden a `--version`, y sus configs (`.phpstan.neon`, `phpcs.xml`, etc.) son parseables. Detecta entornos rotos antes de ejecutar.

**Por qué exploratorio**: solapa parcial con `conf:check` (que ya valida ejecutables y paths). El delta sería verificar que el binario realmente arranca + que su config es válida (parseo del fichero externo). El primero es trivial pero raramente roto; el segundo abre una caja de Pandora (cada tool tiene su parser).

**Score**: 4/10. Reabrir si aparecen reportes de "instalé GitHooks pero nada funciona y no entiendo por qué".
