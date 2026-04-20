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

Infection escribe en `reports/infection/`. **Usa sólo los tres ficheros de texto; ignora el HTML.**

| Fichero | Tamaño típico | Uso |
|---|---|---|
| `infection-summary.log` | <1 KB | **Primer vistazo**: volumen total (Escaped / Killed / Timeouts / MSI) |
| `per-mutator.md` | 10-30 KB | **Segundo vistazo**: desglose por tipo de mutator — identifica patrones repetidos antes de bajar al detalle |
| `infection.log` | 50-300 KB | **Fuente primaria**: diffs completos de cada mutant. Navegable con `Grep` y `Read` con offset |
| ~~`mutation-report.html`~~ | **NUNCA leer** | 5-10 MB de HTML con CSS/JS embebidos. Destinado a navegador humano; inútil y contraproducente para el análisis en contexto |

**Flujo de lectura:**

1. `Read reports/infection/infection-summary.log` → conocer volumen.
2. `Read reports/infection/per-mutator.md` → ver qué tipos de mutator dominan (orienta la priorización).
3. `Grep` sobre `reports/infection/infection.log` para extraer los mutants de un módulo/fichero concreto con su número de línea en el log:
   ```
   Grep pattern:"^\d+\) /abs/path/src/Modulo/" path:"reports/infection/infection.log" output_mode:"content" -n:true
   ```
4. `Read reports/infection/infection.log` con `offset`/`limit` sobre los rangos que te interesen.

**Nunca usar** `Read` sobre `mutation-report.html` — romperá el límite de mensaje y no añade información sobre los `.log`/`.md`. Si el usuario lo adjunta, recordárselo y pedir que aporte el `infection.log` o que re-ejecute Infection acotado (ver abajo).

### 3. Diseñar el análisis: calidad primero

**Regla general: priorizar análisis centralizado.** El orquestador principal tiene más contexto acumulado (puede correlacionar módulos, ver patrones cross-fichero, y re-leer el mismo test desde ángulos distintos). Los subagentes trabajan en silos con presupuesto cognitivo acotado y tienden a sesgo defensivo.

**Criterio de delegación (nuevo):**

| Situación | Estrategia |
|---|---|
| Cualquier volumen, ventana de contexto holgada | **Análisis centralizado**: leer log + código + test iterativamente hasta clasificar con evidencia. Preferido por defecto. |
| Volumen grande y ventana comprometida (ej. >150 mutants densos con código de contexto obligatorio) | **Análisis centralizado por módulo** (no paralelo): procesa un módulo a la vez, consolida y pasa al siguiente. |
| Volumen extremo o restricción dura de contexto | **Delegación con piloto** (ver abajo). Último recurso. |

El volumen no es el criterio; la complejidad y el presupuesto de contexto disponibles sí. Con Opus 1M de contexto, 228 mutants se procesan centralizadamente sin problema.

### 3.b Delegación (sólo si no queda alternativa)

Si se delega, no lanzar todos los lotes a la vez:

**Fase piloto obligatoria.** Lanzar **un único lote representativo** primero (p.ej. un módulo con mutants heterogéneos: Hooks, Jobs o Execution). Revisar el output contra el código y tests reales:
- ¿Clasificó algo como EQUIVALENTE sin citar línea de test que lo cubre?
- ¿Aplicó una pista como veredicto sin verificar?
- ¿Hay mutants agrupados por razón genérica ("rama inalcanzable") que no encaje con el código real?

Si hay ≥2 errores en el piloto, **no lanzar el resto**: reajustar pistas, endurecer el prompt, o caer a análisis centralizado.

**Diseño del prompt de lote** — pasar tareas de verificación, no veredictos:

- ❌ *"CpuDetector tiene ramas Windows/Darwin inalcanzables en Linux CI — clasifica como equivalentes"*
- ✅ *"CpuDetector tiene ramas guardadas por `PHP_OS_FAMILY`. Antes de clasificar como EQUIVALENTE, verifica si existe un stub (`WindowsCpuDetectorStub`, `DarwinCpuDetectorStub`) en `tests/` y si lo usa algún test. Cita el método de test que cubre la rama o marca AMBIGUO."*

- ❌ *"Dashboard concentra cosméticos ANSI — sé estricto agrupándolos"*
- ✅ *"Dashboard tiene mezcla de lógica real (queued/clear/render) y decoración (marcos, padding, timers). Para cada mutant en líneas 188-236, verifica si algún test TTY (`dashboard_handler_*_tty_*`) hace assert sobre el output exacto. Si lo hace → MEDIA/ALTA; si sólo verifica presencia de substring → COSMÉTICO."*

La pista orienta **qué verificar**, no **cómo clasificar**.

**Post-validación obligatoria por lote.** Tras recibir la respuesta del subagente, antes de consolidar:

1. Muestrear 3 clasificaciones al azar por lote (una ALTA, una MEDIA, una EQUIV/COSM).
2. Re-inspeccionar el código + test referenciado.
3. Si alguna clasificación no resiste la inspección, **re-clasificar manualmente** y marcar el lote como no fiable: re-procesar el resto del lote centralizadamente.

Lanza lotes en paralelo **sólo después** de pasar el piloto.

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

## Taxonomía y patrones: dónde viven

La taxonomía detallada (4 categorías) y la tabla de patrones por mutator están en el system prompt del subagente `infection-mutant-classifier` — no se duplican aquí. El catálogo canónico con ejemplos de código sigue en `references/mutator-catalog.md` y el subagente lo consulta bajo demanda para mutators ambiguos.

Recordatorio corto de las 5 categorías (para orquestar y consolidar):
- **ALTA** — Real escape: bug latente, crear/ampliar test.
- **MEDIA** — Cobertura débil: test existe, endurecer assert.
- **EQUIVALENTE** — Mismo output / rama inalcanzable: descartar con evidencia (cita el test que lo cubre, o la rama SO inalcanzable).
- **COSMÉTICO** — Decoración ANSI, contadores de reporting: descartar.
- **AMBIGUO** — No hay evidencia suficiente para decidir tras verificar. El orquestador (no el subagente) re-inspecciona y resuelve. Mejor ambiguo que mal clasificado.

## Flujo de análisis

### Paso 1 — Resumen y perfil (los tres ficheros de texto)

1. **Volumen total** — `Read reports/infection/infection-summary.log` (siempre pequeño, no necesita offset/limit):

   ```
   Total / Killed / Escaped / Timeouts / MSI
   ```

2. **Mutators dominantes** — `Read reports/infection/per-mutator.md`. Identifica patrones: si `LogicalOr` concentra 40 escapes, sabes que hay un patrón de guards defensivos repetido.

3. **Ficheros afectados** — sobre `infection.log`:

   ```
   Grep pattern:"^\d+\) /var/www/html[0-9]*/src/" path:"reports/infection/infection.log" output_mode:"content" -n:true
   ```

   Devuelve `línea_log:índice) path:línea Mutator`. Guardar los rangos de líneas del log por módulo para agentes paralelos.

**Nunca abrir `mutation-report.html`**: es HTML de 5-10 MB con CSS/JS embebidos; rompe el límite de mensaje y no aporta sobre los `.log`/`.md`.

### Paso 2 — Análisis (centralizado por defecto)

**Ruta preferente — centralizado por módulo.** Procesa un módulo a la vez:

1. `Grep` los mutants del módulo sobre `infection.log` (ya lo hiciste en Paso 1).
2. `Read` el log con `offset`/`limit` del módulo.
3. Para cada fichero con mutants:
   - `Read` el código fuente con contexto (±10 líneas por mutante).
   - `Glob` y `Read` el test directo (`tests/Unit/<subruta>/<Class>Test.php`).
   - Clasificar cada mutant con **evidencia citada**: qué test lo cubre (MEDIA), qué línea/método lo cubre por rama (EQUIV), qué hace decorativo el cambio (COSM).
4. Acumular clasificaciones y pasar al siguiente módulo.

Esta es la ruta preferente aunque el log tenga 200+ mutants. Mantener un módulo en contexto a la vez evita silos y permite correlaciones cross-módulo (ej. patrones Windows/Darwin compartidos entre `CpuDetector` y `Platform`).

**Ruta alternativa — delegación.** Sólo si el presupuesto de contexto no alcanza (log muy denso, código fuente voluminoso, muchos tests). En ese caso:

1. **Piloto**: diseñar 1 lote representativo. Invocar `Agent(subagent_type=infection-mutant-classifier)` con prompt de verificación (no de veredicto).
2. **Validar**: ¿cita evidencia (línea de test) para EQUIV/COSM? ¿hay AMBIGUOS razonables o fuerza categoría? ¿aplica pistas como tareas?
3. Si el piloto es **sólido** → lanzar el resto de lotes en paralelo.
4. Si el piloto tiene **errores** → caer a ruta centralizada por módulo.

Criterio de agrupación para lotes (si se delega):
- Ficheros del mismo sub-árbol de `src/` en el mismo lote.
- Evitar lotes >60 mutants (truncado).
- Evitar lotes <15 (overhead).

**Post-validación obligatoria de cada lote delegado** (ver Paso 2 del apartado 3.b): 3 muestras aleatorias re-inspeccionadas antes de consolidar.

El subagente devuelve tablas Markdown. El orquestador:
- Resuelve los **AMBIGUOS** (re-inspección manual).
- Revisa cada **EQUIV/COSM** sin evidencia citada (promover a AMBIGUO).
- Consolida en el informe final del Paso 3.

### Paso 3 — Salida

Producir un informe en Markdown estructurado así:

```markdown
# Informe Infection — <fecha>

## Resumen
- Total / Killed / Escaped / Timeouts
- MSI cubierto
- Contadores por categoría (ALTA / MEDIA / EQUIV / COSM). La suma debe cuadrar con Escaped.

## Prioridad ALTA (bugs latentes reales)
Tabla por fichero:línea / Mutator / Problema / Acción

## Prioridad MEDIA (cobertura débil)
Tabla similar

## No accionable (equivalentes / cosméticos)
Lista resumida agrupada por tipo, con evidencia (test que lo cubre o razón de rama inalcanzable)

## Candidatos a fixes de código (OBLIGATORIO si aplica)
Mutants ALTA cuya mutación sugiere que el código actual puede esconder un bug real (no sólo cobertura débil). Tabla: fichero:línea / sospecha / verificación sugerida. PR aparte del refuerzo de tests.

## Plan de acción priorizado por ROI
1. Tests nuevos (clases sin test directo): cuantificar "mata N mutants".
2. Refuerzos de asserts masivos (patrones repetidos): cuantificar "mata N mutants".
3. Refuerzos puntuales.
4. Supresiones en `infection.json5` (cosméticos densos, ramas inalcanzables).
```

Guardar como `Infection.md` en la raíz del proyecto si el usuario lo pide, o mantener en pantalla si sólo quiere revisar.

**Validación final antes de reportar:**
- La suma de clasificaciones debe igualar el total de escaped. Si faltan mutants, son AMBIGUOS y van explícitamente en una sub-sección.
- Toda EQUIV/COSM debe llevar evidencia (línea de test que lo cubre, o razón concreta de equivalencia/decoración). Sin evidencia → AMBIGUO.
- La sección "Candidatos a fixes de código" no se omite: si no hay candidatos, decirlo explícitamente ("No se detectan bugs latentes — todos los ALTA son huecos de test").

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
- **No delegar por defecto.** La delegación a subagentes es un recurso para cuando la ventana de contexto no da más de sí. Con 1M de contexto, 200+ mutants se procesan centralizadamente sin problema y con mejor calidad. Preferir siempre ruta centralizada.
- **No clasificar sin evidencia.** EQUIVALENTE/COSMÉTICO requiere cita: fichero de test + método que cubre, o razón concreta de decoración/inalcanzabilidad. Sin evidencia → AMBIGUO (que el orquestador resuelve con re-inspección).
- **No convertir pistas en veredictos.** Una pista del orquestador ("X tiene ramas Windows inalcanzables") no es un veredicto — es una tarea de verificación. El clasificador debe buscar el stub o test de esa rama antes de aplicar la pista.
- **Nunca hacer `Read` sobre `mutation-report.html`.** Es HTML con CSS/JS embebido de 5-10 MB pensado para navegador humano; en contexto LLM rompe el límite de mensaje sin aportar nada sobre los tres ficheros de texto. Si el usuario lo menciona o adjunta, recordarle que la fuente correcta es `infection.log` + `infection-summary.log` + `per-mutator.md`.
- **No mezclar fixes de código con tests en el mismo commit.** Los bugs latentes detectados merecen PR propio.

## Checklist de verificación

Antes de reportar:

- [ ] Leído `infection-summary.log` para conocer volumen total
- [ ] **Decisión centralizado/delegado registrada explícitamente** (con razón si se delega)
- [ ] Si se delegó: piloto ejecutado y validado antes de lanzar el resto
- [ ] Si se delegó: post-validación por lote ejecutada (3 muestras aleatorias re-inspeccionadas)
- [ ] Clasificado **cada** mutant escaped; la suma de categorías cuadra con total escaped
- [ ] AMBIGUOS resueltos por el orquestador (re-inspección manual) — no quedar ambiguos en el informe final
- [ ] Toda EQUIV/COSM con evidencia citada (método de test o razón concreta)
- [ ] Cada ALTA tiene una sugerencia concreta de test
- [ ] Mutants agrupados por fichero, no por orden del log
- [ ] Plan de acción priorizado por ROI, no por orden de aparición
- [ ] Sección "Candidatos a fixes de código" presente (o declarada vacía explícitamente)
- [ ] Mencionar qué mutants quedan fuera de alcance y por qué
