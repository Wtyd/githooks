# Roadmap GitHooks

## Resumen por versión

| Versión  | Tema                  | Ítems principales                                                                                                                           |
| -------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **v3.0** | Release               | Versión final publicada. Arquitectura hooks/flows/jobs, modos fast/fast-branch, conf:init interactivo, output JSON/JUnit, thread budgeting. |
| **v3.1** | Adopción ✔            | Documentación externa, override local + Docker, argumentos extra por CLI para jobs, comparación + migraciones                               |
| **v3.2** | Herramientas y Output | PHP CS Fixer nativo, Rector nativo, rediseño output (streaming texto + progress bar formatos), output CI nativo, revisión JSON para IA, Wizard de instalación, tests Windows |
| **v3.3** | Madurez               | Validación commit messages, monitor de rendimiento, flag `--files`, receta config compartida Composer, prohibir espacios en nombres de job, comando `flows` multi-flow, estandarizar claves a kebab-case |

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

Objetivo: cubrir el gap de herramientas nativas y mejorar la integración con CI e IA.

### 1. PHP CS Fixer como tipo nativo

Argumentos abstraídos: config, rules, dry-run, diff, allow-risky. Auto-staging automático (igual que phpcbf) cuando no se ejecuta en modo `--dry-run`. Modo `accelerable: true` por defecto. Es la herramienta de estilo más usada en proyectos Symfony/Laravel modernos y ejecutarla vía custom pierde la abstracción de argumentos que sí tienen phpcs, phpstan o psalm.

### 2. Rector como tipo nativo

Argumentos abstraídos: config, dry-run, clear-cache. Rector es cada vez más estándar en el ecosistema PHP para refactorización automática y modernización de código. Mismo razonamiento que PHP CS Fixer.

### 3. Rediseño del sistema de output

El sistema de output actual bufferiza toda la salida de los procesos y solo muestra "OK"/"KO" al final. Esto tiene dos problemas: en jobs largos (phpmd puede tardar 800s) parece que la herramienta está congelada, y se pierde la visibilidad en tiempo real que tenía v2.

La regla que unifica el diseño: **el formato determina el comportamiento del output.**

**Formato texto (default, humano mirando terminal):**

| Escenario | Comportamiento |
|---|---|
| Single job (`job X`) | Streaming en vivo vía `process->wait($callback)`. El usuario ve la salida real de la herramienta como en v2. |
| Flow secuencial (processes=1) | Cada job streameado con cabecera separadora entre jobs. Como `make` o `docker-compose`. |
| Flow paralelo (processes>1) | Línea de estado por job ("Running X...") que se actualiza al completar con OK/KO + tiempo. Output completo solo en error. No se puede streamear output paralelo sin interleaving. |

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

### 3b. Truncado de comandos largos en tablas

Cuando un job tiene muchos argumentos (ej: phpcpd con 15+ `--exclude`), el comando generado puede superar los 500 caracteres y desbordar la tabla de `conf:check`. Regla: en contextos tabulares (múltiples jobs) truncar a 80 caracteres con `...`; en contextos de job individual (`job X --dry-run`) mostrar el comando completo.

### 4. Output CI nativo

Detectar automáticamente el entorno de ejecución (GitHub Actions, GitLab CI, etc.) y formatear la salida con anotaciones nativas del entorno. En GitHub Actions, los errores formateados como `::error file=src/User.php,line=14::...` aparecen como anotaciones inline directamente en el PR. En GitLab CI, las secciones colapsables mejoran la legibilidad del log. No cambia la funcionalidad: las mismas herramientas, los mismos errores, pero visualmente integrados en la plataforma de CI.

### 5. Revisión del formato JSON para consumo por IA

El JSON actual (`--format=json`) tiene campos básicos pero le falta información clave para que una IA o herramienta externa pueda consumirlo eficazmente. Campos a revisar/añadir:

- **Tipo de job** (`type`: phpstan, phpcs...) — actualmente solo está el nombre, la IA no sabe qué herramienta falló.
- **Paths** analizados por cada job.
- **Modo de ejecución** (full/fast/fast-branch).
- **Exit code** por job.
- **Ficheros analizados** y **ficheros con errores**.
- **Jobs skipped** y su razón.
- Revisar que las **keys** sean consistentes y descriptivas.

El objetivo es que el JSON sea un contrato estable que herramientas externas (IA, dashboards, scripts) puedan consumir sin parsear texto plano.

### 6. Wizard de instalación

Evolucionar `conf:init` de un generador de config rápido a un asistente de onboarding completo. Actualmente `conf:init` solo detecta herramientas ya instaladas en `vendor/bin/` y genera un fichero con defaults genéricos. El wizard debe:

- **Ofrecer descargar herramientas** que el usuario quiera pero no tenga instaladas.
- **Dos métodos de instalación**: Composer (`composer require --dev`) o PHAR (descarga en un directorio dado, por defecto `tools/`).
- **Guiar sobre buenas prácticas Composer**: herramientas QA en `require-dev`, o `composer.json` separado para evitar conflictos de dependencias.
- **Configurar cada herramienta paso a paso**: preguntar level de phpstan, standard de phpcs, rules de phpmd, etc. en lugar de poner defaults genéricos.
- **Explicar al usuario** qué hace cada opción con contexto y tips.

### 7. Tests Windows

Batería de tests que verifique que la funcionalidad core es correcta en Windows. Actualmente todas las pruebas se ejecutan en Linux. Áreas a cubrir: paths con `\`, `DIRECTORY_SEPARATOR`, detección de CPUs (`wmic`, `NUMBER_OF_PROCESSORS`), ejecución de procesos, y resolución de rutas de ejecutables.

---

## v3.3 — Madurez

Objetivo: pulir funcionalidades y diferenciar frente a la competencia.

### 1. Validación de commit messages como tipo nativo

Un tipo de job específico que permita validar el mensaje de commit con regex, longitud mínima/máxima y opcionalmente conventional commits como formato predefinido. Hoy se puede hacer con un job `script` en el hook `commit-msg`, pero un tipo nativo abstraería la configuración y sería coherente con la filosofía de GitHooks.

### 2. Monitor de rendimiento

Evolucionar el `--monitor` existente a un reporte de tiempos por job y por flow. Que el equipo pueda ver que phpstan tarda 12 segundos, phpcs tarda 2 y phpunit tarda 45, y así decidir si sacar phpunit del pre-commit al pre-push, o si ajustar el thread budget. Ninguna de las herramientas competidoras ofrece esto. Diferenciador real.

### 3. Flag `--files` para ejecución contra lista explícita

Ejecución de flow o job en modo `--fast` aceptando un argumento `--files` con un array de ficheros contra los que se lanza. El caso de uso principal es CI/CD en ramas de tarea: se le pasan por parámetro los ficheros modificados en el último commit. Otra opción es un flag `--fast-ci` donde GitHooks detecta automáticamente los ficheros del último commit vía comandos git.

### 4. Receta de config compartida vía paquete Composer

Documentar el patrón para empresas con muchos repos que quieran mantener reglas QA centralizadas. No requiere funcionalidad nueva: como la config es PHP, un `require` de un paquete Composer + merge de arrays ya funciona. Solo falta una receta oficial en la documentación que lo explique como patrón recomendado.

### 5. Prohibir espacios en nombres de job

Los nombres de job con espacios (`Phpstan Src`) obligan a entrecomillar en CLI: `githooks job "Phpstan Src"`. Añadir validación que rechace espacios en nombres de job y proponga alternativas (`phpstan_src`, `phpstan-src`). Rediseñar `conf:check` al mismo tiempo para usar el nuevo formato sin tablas (comando en su propia línea, jobs agrupados por flow).

### 7. Comando `flows` — ejecución combinada de múltiples flows

Nuevo comando que ejecute varios flows como si fuera uno solo, mergeando sus jobs bajo un único plan de ejecución:

```bash
githooks flows qa schedule         # mergea jobs de qa + schedule en un solo plan
githooks flows qa luz gas --fast   # tres flows combinados en modo fast
```

**Qué resuelve.** Hoy la alternativa es `githooks flow qa && githooks flow schedule`, que los ejecuta en serie, con planes independientes, sin thread budget compartido ni paralelismo cruzado. Con `flows`, todos los jobs entran en un único `FlowPlan`: un solo thread budget, un solo resultado, un solo exit code. Caso de uso típico: CI que lanza todo junto, o un usuario que quiere QA + batería pesada de un golpe.

**Mecánica de merge de jobs.** Unión ordenada: primero los jobs del primer flow, luego los del segundo, etc. Si un job aparece en más de un flow, se incluye solo la primera ocurrencia (dedup por nombre). Esto es seguro porque las definiciones de los jobs son globales (sección `jobs`) — no hay variación por flow.

**Resolución de opciones discrepantes.** Este es el punto crítico. Las opciones en la arquitectura actual se resuelven así:

```
CLI flags → flow.options → flows.options (global) → defaults
```

Cuando hay múltiples flows, el nivel `flow.options` puede ser contradictorio. Ejemplo con la config actual:

| Opción | `qa` | `schedule` | Conflicto |
|---|---|---|---|
| `processes` | 10 (global) | 1 (propio) | Sí: ¿10 o 1? |
| `fail-fast` | false (global) | true (propio) | Sí: ¿parar o seguir? |
| `executable-prefix` | (ninguno) | (ninguno) | No en este caso, pero posible |
| `execution` | null | null | No en este caso, pero posible |

**Regla propuesta:** las opciones del flow combinado se resuelven ignorando las opciones per-flow. El pipeline queda:

```
CLI flags → flows.options (global) → defaults
```

Cuando alguno de los flows tiene options propias que difieren de las globales y el usuario no ha pasado CLI flags, se emite un **warning informativo** que explica qué opciones se aplican y su valor. El objetivo es que el usuario no se sorprenda:

```
⚠ Flows 'qa' y 'schedule' tienen opciones distintas. Se aplican las opciones globales:
    processes: 10, fail-fast: false
  Usa --processes y --fail-fast para sobrescribir.
```

El warning solo aparece cuando hay discrepancia real (al menos un flow tiene options propias que difieren de las globales). Si todos heredan globales o si el usuario pasó CLI flags, no se muestra nada.

**Justificación:** las options per-flow están diseñadas para el contexto de ese flow individual. `schedule` tiene `processes=1` porque sus jobs son pesados y secuenciales por naturaleza (composer update, coverage, infection). `qa` hereda `processes=10` porque sus jobs son ligeros y paralelizables. Intentar mergear estos valores (¿media? ¿máximo? ¿mínimo?) produce un resultado que no tiene sentido para ninguno de los dos. Usar los **defaults globales** como base es predecible y consistente: el usuario ya los eligió pensando en "todo junto", que es exactamente lo que `flows` hace. Y los CLI flags siempre ganan:

```bash
githooks flows qa schedule --processes=4 --fail-fast
```

**Opciones a nivel de job se preservan intactas.** Las claves `execution`, `failFast`, `ignoreErrorsOnExit` y `executable-prefix` de cada job individual no se ven afectadas por el merge de flows — son intrínsecas al job, no al flow.

**Casos borde:**

1. **Ningún flow tiene options propias** — trivial: se usan globales, como ya ocurre con un flow individual.
2. **Todos los flows referenciados tienen las mismas options** — se podría usar esa config convergente en lugar de globales. Decisión de diseño: ¿vale la complejidad del check de convergencia? Probablemente no — mantener la regla simple.
3. **Flow inexistente** — error y abortar, igual que `githooks flow noexiste`.
4. **Un solo flow** — degrada a `githooks flow X`. Podría ser un alias o redirigir internamente.
5. **Jobs duplicados entre flows** — primera ocurrencia gana, las siguientes se ignoran silenciosamente. No warning: el usuario sabe que pidió flows con overlap.
6. **Flags `--exclude-jobs` / `--only-jobs`** — aplican sobre la lista mergeada, no por flow.

**Impacto en la arquitectura:**

- Nuevo `FlowsCommand` en `app/Commands/`.
- `FlowPreparer` necesita un método `prepareMultiple(FlowConfiguration[] $flows, ...)` que itere los flows, recolecte jobs con dedup, y construya un `FlowPlan` con las opciones globales.
- `FlowExecutor` y `FlowPlan` no cambian: ya trabajan con una lista de jobs + options.
- `ConfigurationResult` no cambia: ya expone flows y jobs por separado.

### 8. Estandarizar claves de configuración a kebab-case

Las claves de job usan camelCase (`executablePath`, `otherArguments`, `ignoreErrorsOnExit`, `failFast`) porque vienen de v2. Las claves de options usan kebab-case (`fail-fast`, `main-branch`, `executable-prefix`) porque se crearon en v3. Estandarizar todo a kebab-case con período de deprecation:

1. Aceptar ambos formatos (`executablePath` y `executable-path`) con warning de deprecation en v3.x.
2. `conf:migrate` convierte automáticamente camelCase a kebab-case.
3. Eliminar soporte camelCase en v4.0.

Kebab-case es la convención dominante en ficheros de configuración (YAML, CLI flags, Symfony, Laravel).

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