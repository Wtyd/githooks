# Análisis de Calidad y Plan de Mejora — Coverage, Infection, PHPMetrics

## Resumen ejecutivo

| Métrica | Valor actual | Objetivo tras el plan |
|---------|-------------|----------------------|
| Coverage líneas | 77.33% | ~91% |
| MSI (Infection) | 91.76% (189 escapados) | ~96% (~82 escapados) |
| Violaciones PHPMetrics | 39 (15 errores, 22 warnings, 2 info) | 33 (9 errores, 22 warnings, 2 info) |

---

# PARTE 1 — DIAGNÓSTICO: PROBLEMAS DETECTADOS

## 1.1 Código sin cobertura (riesgo de regresiones silenciosas)

Estos ficheros tienen cobertura tan baja que cualquier cambio en ellos podría romper funcionalidad sin que los tests lo detecten.

| Fichero | Coverage | Líneas sin cubrir | Riesgo |
|---------|----------|-------------------|--------|
| `src/Utils/ComposerUpdater.php` | **0%** | 21/21 | Copia binarios del .phar según versión PHP. Si falla, el usuario recibe un binario incorrecto o corrupto sin aviso |
| `src/Utils/GitStager.php` | **0%** | 5/5 | Re-staged de ficheros tras auto-fix. Si falla silenciosamente, los fixes de phpcbf/phpcs se pierden del staging area |
| `src/Output/Errors.php` | **40%** | 6/10 | Formatea mensajes de error al usuario. Paths no cubiertos podrían mostrar errores vacíos o malformados |
| `src/Utils/FileUtils.php` | **41.82%** | 32/55 | Utilidades core de git (diff, branches, file comparison). `getBranchDiffFiles()` tiene 3 fallbacks anidados sin testear — si fallan, el modo `fast-branch` deja de funcionar silenciosamente |

**Problema concreto:** `FileUtils::getBranchDiffFiles()` tiene una lógica de 3 intentos (merge-base directo → fetch + merge-base → merge-base sin origin/) donde cada fallback depende de que el anterior falle. Ninguno de los fallbacks está testeado. En un shallow clone de CI, el primer intento falla y la ejecución depende de código sin verificar.

## 1.2 Mutantes escapados (lógica que podría estar rota sin saberlo)

Un mutante escapado significa que se puede cambiar una línea de código y **ningún test lo detecta**. Estos son los ficheros con más mutantes escapados, agrupados por el tipo de problema que revelan:

### Lógica de condiciones y flujo de control sin verificar

| Fichero | Escapados | Problema concreto |
|---------|-----------|-------------------|
| `src/Hooks/HookRunner.php` | **28** | La lógica de pattern matching (branch/file include/exclude, glob-to-regex) tiene 28 mutaciones no detectadas. **Ejemplo real:** si se cambia `\|\|` por `&&` en `matchesBranch()` línea 151, ningún test falla → un patrón de branch podría dejar de matchear y el hook no se ejecutaría |
| `src/Execution/FlowExecutor.php` | **25** | El pool de procesos paralelos, dry-run, y `buildResult()` tienen gaps. **Ejemplo:** si se elimina la llamada a `terminateRunning()` en fail-fast (línea 181), los procesos zombie seguirían ejecutándose sin que ningún test lo detecte |
| `src/Hooks/HookInstaller.php` | **21** | Operaciones de filesystem (mkdir, chmod, file_put_contents) no verifican resultado. **Ejemplo:** si se muta el valor de chmod de 0755 a 0754, el hook instalado podría no ser ejecutable y ningún test lo detecta |
| `src/Execution/ExecutionContext.php` | **16** | La carga lazy con 3 estados (null/false/array) tiene gaps. **Ejemplo:** si se elimina `ensureStagedLoaded()` (línea 73), el modo fast dejaría de funcionar porque no se cargan los ficheros staged |
| `src/Hooks/HookStatusInspector.php` | **11** | Detección del estado de hooks instalados. **Ejemplo:** si se muta `=== '.githooks'` a `!== '.githooks'` (línea 22), el inspector reportaría hooks como no instalados cuando sí lo están |
| `src/Execution/FlowPreparer.php` | **8** | Filtrado por modo de ejecución. **Ejemplo:** si se niega la condición `!$jobConfig->isAccelerable()` (línea 137), jobs que no soportan fast mode se ejecutarían en fast mode igualmente |
| `src/Execution/ThreadBudgetAllocator.php` | **8** | Cálculos de distribución de threads. **Ejemplo:** si se cambia `+=` por `=` en el acumulador de fixed cost (línea 39), el budget se calcularía mal y se lanzarían más procesos de los que el sistema soporta |
| `src/Configuration/HookRef.php` | **8** | Parsing de condiciones from array. **Ejemplo:** si se muta el null coalescing `$raw['flow'] ?? $raw['job'] ?? null` (línea 69), un hookref con job pero sin flow dejaría de funcionar |

### Mutadores con peores tasas de detección

Estos mutadores escapan con más frecuencia, lo que indica **patrones sistémicos** de código sin verificar:

| Mutador | MSI | Escapados/Total | Patrón que revela |
|---------|-----|-----------------|-------------------|
| `CastArray` | **0%** | 1/1 | Type casts no verificados — el código funciona "por casualidad" con el tipo original |
| `IntegerNegation` | **0%** | 1/1 | Valores de retorno numéricos sin assert en tests |
| `Break_` (→ continue) | **50%** | 4/8 | Loops con break/continue donde el test no verifica qué pasa si el loop no se interrumpe |
| `CastBool/CastInt/CastString` | **50-66%** | 3/7 | Conversiones de tipo implícitas que funcionan sin el cast |
| `Continue_` (→ break) | **65%** | 9/26 | Loops que filtran elementos sin verificar que el filtrado realmente ocurre |
| `FunctionCallRemoval` | **69%** | 5/16 | Llamadas a funciones cuyo efecto secundario no se verifica (ej: `chdir()`, `chmod()`) |
| `IncrementInteger` | **75%** | 16/64 | Valores por defecto y constantes numéricas sin verificación exacta |

## 1.3 Complejidad excesiva (errores PHPMetrics)

### 15 Errores de complejidad ciclomática

Clases/métodos cuya complejidad excede los umbrales seguros. A mayor CCN, más ramas de ejecución, más difícil de testear y más probable que contenga bugs ocultos.

**Clases "too complex" (CCN de clase excesivo):**

| Clase | CCN | Líneas | Problema específico |
|-------|-----|--------|---------------------|
| `FlowExecutor` | **45** | 259 | Mezcla 3 responsabilidades: setup de contexto, gestión del pool de procesos, y construcción de resultados. `executeParallel()` tiene loops anidados con estado mutable compartido (`$running`, `$queue`) |
| `ConfigurationParser` | Alto | 353 | Demasiadas responsabilidades: lectura de fichero, parsing v3, resolución de herencia de jobs, merge de overrides locales, detección de formato legacy |
| `JobConfiguration` | Alto | 247 | `fromArray()` con 12 validaciones independientes + `validateArguments()` con switch de 8 ramas por tipo de argumento |

**Métodos "too complex" (CCN de método excesivo):**

| Método | En clase | Problema |
|--------|----------|----------|
| `executeParallel()` | FlowExecutor | CCN=13. Bucle de polling con fill de queue, detección de completados, fail-fast, terminación |
| `prepare()` | FlowPreparer | CCN=12. Iteración de jobs con backward compat, resolución de modo, filtrado, instanciación |
| `fromArray()` | JobConfiguration | 12 validaciones con early returns independientes |
| `fromArray()` | FlowConfiguration | 4 validaciones con nested type checks |
| `fromArray()` | HookConfiguration | Loop anidado (events × refs) con branching en formato de ref |
| `fromArray()` | HookRef | Parsing de múltiples condition keys con normalización de tipos |
| `fromArray()` | OptionsConfiguration | 5 bloques if independientes validando diferentes opciones |
| `prepareCommand()` | CodeSniffer | Construcción de comando shell con muchos flags opcionales |
| `prepareCommand()` | Phpmd | Similar a CodeSniffer |
| `prepareCommand()` | Phpstan | Similar |
| `buildCommand()` | JobAbstract | Loop sobre ARGUMENT_MAP con switch de 6 tipos |
| `buildJobEntry()` | PhpmdJob | Manejo especial de reglas con OR lógico |

### 10 Warnings "Probably bugged"

PHPMetrics estima la probabilidad de bugs basándose en la relación complejidad/volumen. Estas clases tienen >0.3 bugs estimados:

| Clase | Bugs estimados | Por qué |
|-------|----------------|---------|
| `FlowExecutor` | **0.94** | Combinación de alta complejidad (CCN=45) con muchas operaciones. Casi 1 bug estimado |
| `HookRunner` | **0.69** | Alta complejidad en pattern matching con conversión glob-to-regex |
| `FlowPreparer` | **0.51** | Lógica duplicada entre `applyExecutionMode()` y `applyExecutionModeSingleJob()` |
| `ConfigurationFile` | **0.35** | Clase legacy con parsing complejo |
| `ConfigurationGenerator` | ~0.3 | Generación de config con múltiples formatos |
| `ConfigurationMigrator` | ~0.3 | Migración v2→v3 con special cases |
| `ConfigurationParser` | ~0.3 | Parser monolítico |
| `JobConfiguration` | ~0.3 | Validación compleja |
| `JobAbstract` | ~0.3 | Construcción de comandos |
| `MultiProcessesExecution` | ~0.3 | Ejecución paralela legacy |

### 12 Violaciones de principios arquitectónicos (SAP/SDP)

7 paquetes violan Stable Abstractions Principle (SAP) o Stable Dependencies Principle (SDP):

| Paquete | Violación | Significado |
|---------|-----------|-------------|
| `Wtyd\GitHooks` | SAP | Paquete estable sin suficientes abstracciones (interfaces) |
| `Wtyd\GitHooks\Configuration` | SDP | Depende de paquetes menos estables que él |
| `Wtyd\GitHooks\ConfigurationFile` | SAP + SDP | Estable sin abstracciones y dependiendo de inestables |
| `Wtyd\GitHooks\Execution` | SAP + SDP | Idem |
| `Wtyd\GitHooks\Registry` | SAP + SDP | Idem |
| `Wtyd\GitHooks\Tools` | SAP + SDP | Idem |
| `Wtyd\GitHooks\Utils` | SDP | Depende de paquetes inestables |

**Nota:** Estas violaciones son estructurales. Resolverlas requeriría reorganización de paquetes e introducción de interfaces. No se abordan en este plan — son mejoras a largo plazo.

### 2 Informativos

| Clase | Problema | Detalle |
|-------|----------|---------|
| `FlowExecutor` | Too long | 259 líneas (213 lógicas). Umbral recomendado: ~200 |
| `ConfigurationParser` | Too long | 353 líneas. Umbral recomendado: ~200 |

---

# PARTE 2 — BATERÍA DE TESTS PARA SUBSANAR

## Grupo A — Tests para ficheros sin cobertura (Coverage 0%-42%)

### A1. `tests/Unit/Output/ErrorsTest.php` (CREAR)

**Cubre:** `src/Output/Errors.php` (de 40% a ~100%)

| Test | Qué verifica |
|------|-------------|
| `setError()` con tool y error no vacíos | Almacena correctamente |
| `setError()` con tool vacío | NO almacena (guard clause) |
| `getErrors()` | Devuelve errores almacenados |
| `isEmpty()` sin errores → true | Estado inicial |
| `isEmpty()` con errores → false | Tras setError |
| `__toString()` sin errores | Mensaje por defecto "There are no errors" |
| `__toString()` con errores | Formato correcto del mensaje |

### A2. `tests/Unit/Utils/ComposerUpdaterTest.php` (CREAR)

**Cubre:** `src/Utils/ComposerUpdater.php` (de 0% a ~60%)

| Test | Qué verifica |
|------|-------------|
| `pathToBuild()` con PHP >= 8.1 | Devuelve `''` (build principal) |
| `pathToBuild()` con PHP >= 7.4 y < 8.1 | Devuelve `'php7.4'` (build legacy) |
| `pathToBuild()` con PHP < 7.4 | Lanza Exception |

Nota: `phpOldVersions()` no es testeable unitariamente (dependencias hardcoded de filesystem). Candidato a refactor futuro.

### A3. Expandir tests de `FileUtils`

**Cubre:** `src/Utils/FileUtils.php` (de 41.82% a ~65%)

| Test | Qué verifica |
|------|-------------|
| `isSameFile()` — mismo path sin ROOT_PATH | Devuelve true |
| `isSameFile()` — uno con ROOT_PATH, otro sin | Devuelve true si base igual |
| `isSameFile()` — paths diferentes | Devuelve false |
| `directoryContainsFile()` — fichero dentro | Devuelve true |
| `directoryContainsFile()` — fichero fuera | Devuelve false |

### A4. GitStager

Cubierto por tests de integración existentes. Tests unitarios requerirían inyectar un `GitExecutor` — documentar como refactor futuro.

---

## Grupo B — Tests para mutantes escapados en HookRunner (28 escapados)

### B1. `tests/Unit/Hooks/HookRunnerPatternMatchTest.php` (CREAR)

**Target:** ~20 de los 28 mutantes escapados en `src/Hooks/HookRunner.php`

#### globToRegex (~8 mutantes, líneas 220-248)
| Test | Mutante que mata |
|------|-----------------|
| Patrón `**` solo (sin barras) | L244: rama `else` del boundary check |
| Patrón `/**/` (ambos lados con barra) | L238: reemplazo `(?:/.+/|/)` |
| Patrón terminando en `/**` | L239: `$leftEndsSlash` solo |
| Patrón empezando por `**/` | L241: `$rightStartsSlash` solo |
| Múltiples `**` en patrón | Combinación de boundaries |
| Right vacío tras `**` | L235: `isset($right[0])` |

#### matchesBranch (~6 mutantes, líneas 145-165)
| Test | Mutante que mata |
|------|-----------------|
| Branch string vacío | L145: `$branch === ''` |
| Exact match (sin glob) | L151: rama `$branch === $pattern` |
| fnmatch match (con glob) | L151: rama `fnmatch(...)` |
| Include vacío + exclude con valor | L149: default true cuando no hay includes |
| Exclude exact string | Exclusion path |
| Include matchea + exclude NO matchea | Ambas ramas ejercitadas |

#### matchesFiles (~8 mutantes, líneas 174-199)
| Test | Mutante que mata |
|------|-----------------|
| Todos los ficheros excluidos | Loop completo con exclude |
| Include matchea + exclude matchea | Exclude prevalece |
| Ningún include matchea | Short-circuit sin entrar al exclude |
| Array de ficheros vacío | L174: early return |
| Patrón sin `**` con `FNM_PATHNAME` | L208: fnmatch con flag |
| Primer fichero matchea | L186: break en primer match |

#### shouldExecute combinado (~4 mutantes, líneas 112-136)
| Test | Mutante que mata |
|------|-----------------|
| Solo condiciones de branch | Branch-only path |
| Solo condiciones de file | File-only path |
| Branch matchea + files no matchean → false | AND lógico de condiciones |
| Sin condiciones → siempre ejecuta | `hasConditions()` false path |

#### exitCode (~2 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| Todos success → 0 | Loop completo sin failures |
| Uno failed → 1 | Early return en failure |
| Array vacío → 0 | Edge case sin resultados |

---

## Grupo C — Tests para mutantes escapados en FlowExecutor (25 escapados)

### C1. `tests/Unit/Execution/FlowExecutorTest.php` (CREAR)

**Target:** ~20 de los 25 mutantes escapados en `src/Execution/FlowExecutor.php`

#### dry-run (~3 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| `dryRun=true` → all success, time `0ms` | L56-57: early return path |
| Múltiples jobs en dry-run | Todos en resultados |
| `onJobDryRun` llamado por job | Output handler callback |

#### Ejecución secuencial (~5 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| Job único success | Resultado correcto |
| Job failure sin fail-fast | Failure reportado |
| Fail-fast: segundo job NO ejecutado | L179-180: break condition |
| `reportSkipped` reporta restantes | L187-188: skip reporting |
| Peak threads tracking | `peakEstimatedThreads` accumulator |

#### Thread budget (~4 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| `maxProcesses > 1` + múltiples jobs | `applyThreadLimit` called |
| `maxProcesses == 1` → secuencial | No thread budget |
| `threadAllocations` en peak | Peak calculation |

#### buildResult (~6 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| `exitCode == 0` → success | L282: `=== 0` check |
| `exitCode != 0` + `isFixApplied()` → success | L287: fix detection |
| `exitCode != 0` + no fix → failure | Default failure path |
| `ignoreErrorsOnExit` + failure → success | Override path |
| `exitCode == null` → tratado como 1 | L247: `?? 1` fallback |

#### formatTime (~3 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| < 1 segundo → `Nms` | L306: `< 1` boundary |
| 1-60 segundos → `N.NNs` | Middle range |
| > 60 segundos → `Nm Ns` | L245: `-` vs `+` mutation |
| Exactamente 1 segundo | Boundary value |

#### Paralelo (~4 mutantes)
| Test | Mutante que mata |
|------|-----------------|
| Dos jobs passing | Pool result collection |
| 3 jobs + maxProcesses=2 | Queue fill limit |
| Fail-fast termina running | `terminateRunning()` path |

---

## Grupo D — Tests para mutantes escapados en HookInstaller, ExecutionContext, ThreadBudgetAllocator, HookRef

### D1. `tests/Unit/Hooks/HookInstallerMutationTest.php` (CREAR) — ~15 de 21 mutantes

| Test | Mutante que mata |
|------|-----------------|
| `install()` crea directorio si no existe | L32: mkdir conditional |
| Eventos inválidos → no producen ficheros | L40: continue en loop |
| `installLegacy()` sin `.git/hooks` → vacío | L86: directory check |
| `clean()` sobre directorio inexistente | L102: glob safety |
| `clean()` elimina ficheros y directorio | L103: rmdir after unlink |
| `cleanLegacy()` con eventos vacío → all hooks | Default event list |
| `buildScript()` con comando custom | L134: script template |
| `buildScript()` con string vacío | L132: empty check |
| Permisos chmod 0755 | L32,L44: permission bits |
| `configureHooksPath()` ejecuta git config | L140: exec call |

### D2. Expandir `ExecutionContextTest` — ~10 de 16 mutantes

| Test | Mutante que mata |
|------|-----------------|
| `fileUtils` null → `getBranchDiffFilesLazy()` null | L146: null guard |
| `mainBranch` null → lazy devuelve null | L149: null guard |
| Lazy tras fallo → null sin reintentar | L137: sentinel `false` |
| Lazy tras éxito → resultado cacheado | L141: is_array check |
| `filterFilesForMode` modo desconocido → null | L98: mode dispatch |
| `ensureStagedLoaded()` carga solo una vez | Idempotency check |
| `fileIsInPaths` con fileUtils null → false | L174: null guard |
| `filterFileList` paths vacíos → vacío | Empty input |
| `FAST_BRANCH` + branchDiffFiles vacío | Empty filtered list |

### D3. Expandir `ThreadBudgetAllocatorTest` — ~5 de 8 mutantes

| Test | Mutante que mata |
|------|-----------------|
| Budget = 1 → todos 1 thread | L22: `< 1` boundary |
| Budget < fixed costs → threadable min 1 | L47: remaining budget calc |
| Todos uncontrollable | Fixed cost overflow |
| Budget == fixed costs exacto | L50: `>` vs `>=` boundary |
| Job threadable con min > 1 | L55: max(min, threadsPerJob) |
| `calculateMaxParallel` todos exceden budget | L91: break vs continue |

### D4. `tests/Unit/Configuration/HookRefTest.php` (CREAR) — ~6 de 8 mutantes

| Test | Mutante que mata |
|------|-----------------|
| `fromString()` → ref con condiciones vacías | Basic construction |
| `fromArray()` sin `flow`/`job` → error | L69: null coalescing chain |
| `fromArray()` valor no-string → error | L71: type check |
| `only-on` como string → normalizado a array | L78: array wrapping |
| `exclude-on`/`only-files`/`exclude-files` string → array | L83,88,93: same pattern |
| `execution` inválido → error | L98: validation |
| Clave desconocida → warning | L105-110: unknown key loop |
| `hasConditions()` true/false | Condition aggregation |

---

# PARTE 3 — REFACTORS: POR QUÉ Y RIESGOS DE NO HACERLO

## Refactor 1: Extraer PatternMatcher de HookRunner

### Estado actual

`HookRunner` (266 líneas, 8 métodos) mezcla dos responsabilidades:
1. **Orquestación:** Resolver eventos de hook a flows/jobs, evaluar condiciones, ejecutar
2. **Pattern matching:** Conversión glob-to-regex, matching de branches, matching de ficheros

Los 4 métodos de pattern matching (`fileMatchesPattern`, `globToRegex`, `matchesBranch`, `matchesFiles`) son funciones puras sin estado — no necesitan acceso a ninguna propiedad de HookRunner.

### Por qué refactorizar

- **PHPMetrics reporta "probably bugged" (0.69 bugs estimados)** — la complejidad combinada de orquestación + matching excede los umbrales de fiabilidad
- **28 mutantes escapados** — la mayoría (>20) en la lógica de matching. Estos métodos son privados y solo testeables indirectamente vía `run()`, lo que dificulta cubrir todas las ramas
- Patrón `globToRegex()` tiene 28 líneas de manipulación de strings con 6 ramas condicionales para manejar `**` — es el tipo de código que más se beneficia de tests unitarios directos

### Riesgos de NO refactorizar

- **Riesgo moderado:** La lógica de glob-to-regex es la más frágil del fichero. Hay 8 mutantes escapados solo en ese método. Un bug en la conversión haría que patrones como `src/**/*.php` no matchearan correctamente, y los hooks se ejecutarían (o no) de forma incorrecta. Al ser métodos privados, los tests tienen que montar todo el contexto de HookRunner para ejercitar un edge case de regex
- **Sin refactor los tests de la Parte 2 (Grupo B) siguen siendo posibles**, pero serán más complicados de escribir y mantener porque cada test necesita un setup completo de HookRunner

### Qué se haría

1. **Crear** `src/Hooks/PatternMatcher.php` — clase stateless con los 4 métodos públicos
2. **Modificar** `src/Hooks/HookRunner.php` — `shouldExecute()` delega a PatternMatcher
3. **Crear** `tests/Unit/Hooks/PatternMatcherTest.php` — tests directos de cada método
4. HookRunner CCN baja de ~20 a ~8

### Ficheros afectados
- `src/Hooks/PatternMatcher.php` (nuevo)
- `src/Hooks/HookRunner.php` (modificar)
- `tests/Unit/Hooks/PatternMatcherTest.php` (nuevo)

---

## Refactor 2: Extraer ProcessPool de FlowExecutor

### Estado actual

`FlowExecutor` (317 líneas, 11 métodos, CCN=45) es la clase más compleja del proyecto. `executeParallel()` implementa un pool de procesos in-line:
- Mantiene arrays mutables `$running` y `$queue`
- Bucle de polling con `usleep(10000)` que verifica completados
- Lógica de fill (no exceder maxProcesses) mezclada con fail-fast
- Terminación de procesos running cuando falla uno

Todo esto convive con la lógica de negocio: setup de contexto, thread budget allocation, construcción de resultados con detección de fixes, restaging de ficheros.

### Por qué refactorizar

- **PHPMetrics: 3 violaciones** — "too complex class" (CCN=45), "too complex method" (CCN=13 en executeParallel), "too long" (259 líneas)
- **PHPMetrics estima 0.94 bugs** — la probabilidad más alta de todo el proyecto
- **25 mutantes escapados** — la gestión del pool (polling, fill, terminate) tiene gaps porque está imbricada con la lógica de negocio
- `executeParallel()` tiene 58 líneas con 2 bucles anidados y estado mutable compartido — el patrón clásico de "código correcto pero inmantenible"

### Riesgos de NO refactorizar

- **Riesgo alto a medio plazo:** Cualquier cambio futuro en la ejecución paralela (ej: priorización de jobs, timeouts por job, reporting de progreso) requiere modificar `executeParallel()`, que ya está al límite de complejidad. Un cambio mal hecho podría provocar procesos zombie (si `terminateRunning` se rompe) o consumo descontrolado de CPU (si el polling falla)
- **Riesgo de bugs concurrentes:** El polling con `usleep` + arrays mutables es un patrón propenso a race conditions. Hoy funciona porque los tests de fail-fast cubren el happy path, pero los edge cases (qué pasa si un proceso termina exactamente durante el fill del queue) no están cubiertos
- **Sin refactor, los tests de la Parte 2 (Grupo C) son posibles pero frágiles** — tienen que mockear el proceso completo para ejercitar un edge case del pool

### Qué se haría

1. **Crear** `src/Execution/ProcessPool.php` — encapsula `$running`, `$queue`, polling y terminación. Métodos: `submit()`, `poll()`, `drain()`, `terminateAll()`
2. **Modificar** `src/Execution/FlowExecutor.php` — `executeParallel()` pasa a ser un bucle simple: submit jobs → check fail-fast → collect results
3. **Crear** `tests/Unit/Execution/ProcessPoolTest.php`
4. FlowExecutor CCN baja de 45 a ~15

### Ficheros afectados
- `src/Execution/ProcessPool.php` (nuevo)
- `src/Execution/FlowExecutor.php` (modificar)
- `tests/Unit/Execution/ProcessPoolTest.php` (nuevo)

---

## Refactor 3: Unificar lógica duplicada en FlowPreparer

### Estado actual

`FlowPreparer` (251 líneas, 6 métodos) tiene dos métodos que hacen casi lo mismo:
- `applyExecutionMode()` — usado cuando se ejecuta un flow completo
- `applyExecutionModeSingleJob()` — usado cuando se ejecuta un job individual

Ambos siguen la misma secuencia: resolver modo → check accelerability → check context → filtrar por modo → handle fallback. La diferencia es que el primero tiene acceso a `FlowConfiguration` para resolver el modo y a `ConfigurationResult` para warnings, y el segundo no.

El 90% del código es idéntico. Las 2 copias divergen sutilmente en cómo manejan el fallback de `fast-branch`, lo que es exactamente el tipo de divergencia que produce bugs.

### Por qué refactorizar

- **PHPMetrics: "too complex method"** (CCN=12 en `prepare()`)
- **8 mutantes escapados** — 4 de ellos en `applyExecutionModeSingleJob()`, que es la copia menos testeada de la lógica
- **Duplicación = divergencia silenciosa:** Si se corrige un bug en `applyExecutionMode()` pero no en `applyExecutionModeSingleJob()`, el comando `githooks job <name>` se comportaría diferente a ejecutar el mismo job dentro de un flow

### Riesgos de NO refactorizar

- **Riesgo moderado:** La duplicación ya está causando gaps de testing — los 4 mutantes escapados en `applyExecutionModeSingleJob` muestran que la copia es la "hermana olvidada". Cada feature nueva en el filtrado de modos (como `fast-branch` fallback) tiene que implementarse y testearse dos veces
- **Sin refactor los tests son posibles** pero hay que duplicar los tests para ambos paths, lo que es propenso a que uno de los dos sets quede desactualizado

### Qué se haría

1. **Crear método unificado:** `filterJobForMode(JobConfiguration, string $mode, ?ExecutionContext, OptionsConfiguration, ?ConfigurationResult = null): ?JobConfiguration`
2. `applyExecutionMode()` → resuelve modo + llama a `filterJobForMode`
3. `applyExecutionModeSingleJob()` → resuelve modo inline + llama a `filterJobForMode`
4. **Extraer:** `resolveEffectiveInvocation(?string, ?ExecutionContext): ?string` para la lógica de backward compat
5. FlowPreparer CCN baja de ~12 a ~7

### Ficheros afectados
- `src/Execution/FlowPreparer.php` (modificar)
- Tests existentes de FlowPreparer (expandir)

---

## Resumen de refactors

| Refactor | Violaciones que resuelve | Riesgo si NO se hace | Esfuerzo |
|----------|-------------------------|----------------------|----------|
| PatternMatcher | 2 PHPMetrics (too complex, bugged) | Moderado: bugs en matching silenciosos, tests difíciles | ~250 líneas |
| ProcessPool | 3 PHPMetrics (too complex class+method, too long) | Alto a medio plazo: procesos zombie, inmantenibilidad | ~300 líneas |
| FlowPreparer | 1 PHPMetrics (too complex method) | Moderado: divergencia silenciosa entre flow y single-job | ~100 líneas |

---

## Orden de ejecución recomendado

```
1. Tests Grupo A (coverage gaps)          ─┐
2. Tests Grupo B (HookRunner mutantes)     ├─ Independientes
3. Tests Grupo C (FlowExecutor mutantes)   │  entre sí
4. Tests Grupo D (Installer/Context/etc)  ─┘
         │
         ▼
5. Refactor 1 (PatternMatcher)  ← requiere Grupo B verde
6. Refactor 2 (ProcessPool)     ← requiere Grupo C verde  
7. Refactor 3 (FlowPreparer)    ← requiere Grupo D verde
```
