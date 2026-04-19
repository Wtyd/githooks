---
name: mutation-analyzer
description: >
  Analiza informes de Infection (mutation testing) para el proyecto GitHooks.
  Clasifica los mutants escaped, distingue bugs latentes reales de mutants
  equivalentes/cosméticos, y produce un plan de refuerzo de tests priorizado.
  Usa esta skill cuando el usuario pida "analizar Infection", "revisar mutants
  escaped", "interpretar mutation testing", "qué mutants han sobrevivido",
  "MSI bajo", o cuando ejecute Infection y haya mutants escaped que revisar.
---

# Mutation Analyzer para GitHooks

Esta skill interpreta el output de [Infection](https://infection.github.io/) y convierte una lista bruta de mutants escaped en un plan accionable para endurecer los tests.

Infection se ejecuta manualmente de forma esporádica (no en CI por coste). Cuando deja mutants escaped, esta skill sistematiza su análisis para que el trabajo resultante sea coherente y priorizado.

## Flujo de decisión

### 1. ¿Qué analizar?

| Señal | Acción |
|---|---|
| Usuario invoca tras `vendor/bin/infection` con mutants escaped | Análisis completo del log |
| MSI bajo (<90 %) y pregunta qué hacer | Análisis completo del log |
| Usuario apunta un fichero concreto | Análisis focalizado de ese fichero |
| Usuario pide sólo el catálogo de mutators | Referencia directa a `references/mutator-catalog.md` |

### 2. Localizar los artefactos

Infection escribe en `reports/infection/`:

```
infection.log            ← log principal con los diffs de cada mutant
infection-summary.log    ← resumen numérico (Total/Killed/Escaped/Timeouts…)
per-mutator.md           ← desglose por tipo de mutator
mutation-report.html     ← reporte HTML navegable (grande; NO leerlo directo)
```

Empezar por `infection-summary.log` para conocer el volumen, después el `.log`. **No leer el HTML**: son MB de ruido.

### 3. Diseñar el análisis según volumen

| Escaped | Estrategia |
|---|---|
| 0–30 | Análisis directo: leer el log, clasificar cada mutant en el mismo turno |
| 30–80 | Agrupar por fichero; analizar un fichero cada vez |
| >80 | Delegar a **agentes paralelos por módulo** (`Agent(subagent_type=general-purpose)`) con el rango de líneas del log que le corresponde a cada uno |

Cuando delegues, cada agente necesita:
- Ruta del log y rango de líneas de su sección
- Lista de ficheros con número de mutants
- La taxonomía (ver abajo) para clasificar
- Formato de salida (tabla por fichero)
- Instrucción de límite de palabras (≤600 por agente)

## Formato del log de Infection

Cada mutant escapado sigue este patrón:

```
1) /abs/path/To/File.php:42    [M] MutatorName [ID] hash

@@ @@
         $context line
-        $original line
+        $mutant line
         $context line
```

Estructura:
- `1)` → índice dentro de la sección
- `[M] MutatorName` → tipo de mutación (ver catálogo)
- `[ID] hash` → identificador único (útil si quieres re-ejecutar sólo ese)
- Diff unified con ± indicando la transformación

Las secciones del log son:

```
Escaped mutants:    (línea ~4, el grueso del trabajo)
Timed Out mutants:  (mutants que no terminaron — trátalos aparte si los hay)
```

Un `Grep` con patrón `^\d+\) /abs/path/src/Modulo/` filtra los mutants de un módulo concreto con su línea.

## Taxonomía de clasificación

Cada mutant escaped cae en una de estas cuatro categorías. La clasificación determina la acción.

### 1. Real escape (bug latente)

El mutante cambia comportamiento observable y el código real tiene un bug que no se detecta porque ningún test lo ejerce. **Acción: crear o ampliar test.**

Señales típicas:
- Clase sin fichero de test directo (busca con `grep -rL "new ClassName" tests/`).
- Side effect (`exec`, `chdir`, `file_put_contents`) cuya llamada puede eliminarse sin que falle ningún test.
- Guard defensivo (`!is_array($x) || !isset($x['key'])`) con OR mutable a AND.
- Operador relacional `<=`/`>=` en frontera sin test en el umbral.

### 2. Cobertura débil

Existe test que ejecuta el código, pero los asserts son demasiado laxos para discriminar el cambio. **Acción: endurecer el assert, no añadir test nuevo.**

Señales típicas:
- `is_executable()` donde cabe `fileperms & 0777`.
- `assertCount(1)` en vez de `assertSame([$exact], …)`.
- `assertMatchesRegularExpression` con patrón permisivo donde cabe `assertSame` de regex exacta.
- `assertTrue($x->isSuccess())` sin mirar el estado interno.

### 3. Equivalente

El mutante produce la misma salida observable que el original, o el camino mutado nunca se ejecuta bajo precondiciones reales. **Acción: ninguna — documentar y descartar del baseline.**

Señales típicas:
- Default de parámetro opcional que todos los callsites pasan explícitamente.
- `exec(..., $exitCode)` con mutación del valor inicial de `$exitCode` (la llamada lo sobrescribe por referencia).
- Asignación redundante (`$x = false` en L45 sobrescrita en L47).
- Detección de SO en líneas que dan el mismo `false` en el entorno de test (p.ej. `substr(PHP_OS, 0, 3) === 'WIN'` en Linux — todos los mutantes sobre `substr` dan `false`).

### 4. Cosmético / no accionable sin refactor

El mutante afecta código estético (strings ANSI, padding, ancho de marco) o requeriría infraestructura desproporcionada para mockear. **Acción: ninguna.**

Señales típicas:
- Mutantes en `str_repeat`, `Concat`, `IncrementInteger` sobre anchos de marco decorativo.
- `exec`/`file_get_contents` no virtualizados (testearlos requiere refactor para aceptar callable inyectable).
- Mutantes sobre contadores de reporting (thread allocations, peak usage) que no afectan éxito/fallo.

## Patrones por mutator (resumen)

| Mutator | Clasificación típica | Por qué |
|---|---|---|
| `LogicalOr` en guard `\|\| !isset(...)` | **Real escape** | Validación defensiva no cubierta con entrada malformada |
| `LessThanOrEqualTo` / `GreaterThan` | **Real escape** | Falta test en frontera |
| `ConcatOperandRemoval` en strings de error/display | **Cobertura débil** o **real** según observabilidad |
| `ConcatOperandRemoval` en strings ANSI decorativas | **Cosmético** |
| `Concat` / `UnwrapStrRepeat` en marcos ANSI | **Cosmético** |
| `IncrementInteger` / `DecrementInteger` en contadores de reporting | **Cosmético** |
| `IncrementInteger` / `DecrementInteger` en defaults observables (`priority ?? 3`) | **Real escape** |
| `MethodCallRemoval` / `FunctionCallRemoval` sobre `exec`/`chdir`/`mkdir` | **Real escape** (falta assert de side-effect) |
| `TrueValue` / `FalseValue` en parámetro opcional con todos los callsites explícitos | **Equivalente** |
| `TrueValue` / `FalseValue` en bandera interna observable | **Real escape** |
| `ArrayOneItem` / `ArrayItemRemoval` | **Real escape** si hay lógica sobre múltiples elementos |
| `Break_` ↔ `Continue_` tras flag ya establecido | **Equivalente** (solo afecta perf) |
| `Continue_` → `Break_` en bucles de acumulación | **Real escape** (omite elementos posteriores) |
| `Identical` (`===` ↔ `!==`) en detección de SO (Linux) | **Equivalente** para mutaciones de substr; **real** para el `===` final |
| `PregMatchRemoveDollar` / regex anchor removal | **Real escape** — test con input con sufijo inválido |
| `CastInt` | **Cobertura débil** — test con string numérica y assertSame a int |
| `SharedCaseRemoval` en switch con casos que devuelven lo mismo | **Equivalente** de un lado, **cobertura débil** del otro (test con el case específico) |

El catálogo completo con ejemplos de código está en `references/mutator-catalog.md`.

## Flujo de análisis

### Paso 1 — Resumen del log

```bash
cat reports/infection/infection-summary.log
grep -oE '/src/[A-Z][^ ]+\.php:[0-9]+' reports/infection/infection.log | sort -u | head -40
```

Obtener:
- Volumen por categoría (Escaped / Timed Out / Errored).
- Lista única de ficheros afectados.

### Paso 2 — Agrupar por fichero

Un mismo fichero suele concentrar mutants relacionados. Procesar fichero-a-fichero produce diagnósticos coherentes (p.ej. "los 5 parsers tienen el mismo patrón `LogicalOr`").

Para cada fichero:
1. Leer el código fuente alrededor de cada línea del mutant.
2. Leer el test directo de la clase (si existe).
3. Clasificar cada mutant con la taxonomía.

### Paso 3 — Salida

Producir un informe en Markdown estructurado así:

```markdown
# Informe Infection — <fecha>

## Resumen
- Total / Killed / Escaped / Timeouts
- MSI cubierto

## Prioridad ALTA (bugs latentes reales)
Tabla por fichero:línea / Mutator / Problema / Acción

## Prioridad MEDIA (cobertura débil)
Tabla similar

## No accionable (equivalentes / cosméticos)
Lista resumida agrupada por tipo

## Plan de acción
1. Tests nuevos a crear (con clases sin test directo)
2. Refuerzos de asserts (asserts existentes a endurecer)
3. Fixes de código (bugs confirmados — unidad aparte)
```

Guardar como `Infection.md` en la raíz del proyecto si el usuario lo pide, o mantener en pantalla si sólo quiere revisar.

### Paso 4 — Puente con `php-test-creator`

Cuando pases a implementar:
1. Para **clases sin test directo** → crear fichero nuevo siguiendo `php-test-creator` (paso 0 del flujo de decisión de esa skill).
2. Para **cobertura débil** → aplicar los principios de asserts fuertes y cobertura por operador de `php-test-creator`.
3. Para **bugs de código** detectados → commit aparte, no mezclar con los de tests.

Orden de implementación sugerido (máximo ROI):
1. Tests nuevos de clases sin cobertura directa (cada uno mata 6–12 mutants de golpe).
2. Refuerzos masivos sobre patrones repetidos (p.ej. los 12 `LogicalOr` de parsers con un test por parser).
3. Refuerzos puntuales en el resto.

## Ejecución de Infection

Configurado en `infection.json.dist` (si existe) o vía opciones CLI:

```bash
php7.4 vendor/bin/infection --threads=4 --min-msi=85 --min-covered-msi=85 \
    --logger-html=reports/infection/mutation-report.html \
    --log-verbosity=default 2> reports/infection/infection-summary.log
```

El log principal (`reports/infection/infection.log`) se genera automáticamente si el `.dist` lo tiene configurado como `text` logger. Verifica la configuración antes de ejecutar.

**Si el log que vas a analizar es viejo**, re-ejecutar Infection antes: los mutants pueden haber sido matados por commits posteriores.

## Anti-patrones a evitar

- **No clasificar "real" por defecto.** Muchos mutants son equivalentes genuinos; forzar test para todos infla la suite sin beneficio.
- **No perseguir mutantes cosméticos.** Strings ANSI, paddings y anchos de marco no necesitan test — documentar y descartar.
- **No leer `mutation-report.html`** para analizar. Es un informe para navegar en navegador, no para tu contexto. Usar el `.log` de texto.
- **No mezclar fixes de código con tests en el mismo commit.** Los bugs latentes detectados merecen PR propio.

## Checklist de verificación

Antes de reportar:

- [ ] Leído `infection-summary.log` para conocer volumen total
- [ ] Clasificado **cada** mutant escaped (no dejar sin categorizar)
- [ ] Cada "real escape" tiene una sugerencia concreta de test
- [ ] Mutants agrupados por fichero, no por orden del log
- [ ] "No accionable" justificado (por qué es equivalente/cosmético)
- [ ] Plan de acción priorizado por ROI, no por orden de aparición
- [ ] Mencionar qué mutants quedan fuera de alcance y por qué
