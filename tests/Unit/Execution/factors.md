# Tabla de factores — scheduler / admission (src/Execution)

Componentes con varios factores interactuando: `AdmissionContext`, `ProcessPool`,
`FlowExecutor::executeParallel`, `FlowExecutor::buildProcessPool`. El CLAUDE.md exige
esta tabla antes de tocar cualquiera de ellos. Cada sección lista factores, clases de
equivalencia, AVL y la clase patógena del invariante.

Invariante global del scheduler: **lo admitido nunca excede el budget (cores/memoria) y
la admisión nunca se bloquea para siempre**. Nota de implementación clave:
`ProcessPool::__construct` clampa `maxProcesses` y `coresBudget` a `max(1, …)`
(`ProcessPool.php:84-85`) — por eso `processes=0` NO deadlockea (verificado 2026-06-13).

---

## 1. `AdmissionContext::fits()` — admisión 1D/2D

Ya cubierto por `tests/Unit/Execution/Admission/AdmissionContextTest.php` (decision table).
Resumen de referencia:

| `cost ≤ coresFree` | `memoryFree === null` (1D) | `mem ≤ memoryFree` | `fits()` |
|---|---|---|---|
| F | * | * | F |
| T | T (1D) | * | T |
| T | F (2D) | F | F |
| T | F (2D) | T | T |

AVL cores: `cost = coresFree-1 / coresFree / coresFree+1`. AVL memoria idéntica.
Clase patógena: `cost > coresFree` con job no clampado (BUG-1/2/3 históricos: `cost=1` en
todos los tests ocultaba la frontera).

## 2. `notifyResult()` + needs → ¿el dependiente ejecuta o se drena?

Factor A: estado terminal del need (vía `notifyResult($name, $success, $skipped)`).
Factor B: el dependiente declara `needs: [A]`.

| Estado de A | `isJobReady(B)` | `drainBlockedByFailedDeps` | Resultado de B |
|---|---|---|---|
| completed (success=T, skipped=F) | T | no drena | **ejecuta** |
| failed (success=F, skipped=F) | F | drena | skipped "needs A failed" |
| skipped (skipped=T) | F | drena | skipped "needs A was skipped" |

Clases de equivalencia del 3er arg de `notifyResult`: `skipped=false` (completado/fallido,
distinguidos por success) vs `skipped=true`. **Clase patógena (mutante 784, FalseValue):** si
un job completado se notifica con `skipped=true`, sus dependientes nunca lo ven en
`completedJobs` → se drenan como skipped en vez de ejecutar. Cubierto por el **camino feliz en
paralelo** (A success → B ejecuta), que los tests previos de needs (sequential / fallo) no
ejercían. Ver `FlowExecutorParallelNeedsTest`.

## 3. Batch fail-fast en `executeParallel` (bug `10917ac`)

`pollCompleted()` devuelve un lote y los saca de `running`. Factores:

| Factor | Clases | AVL |
|---|---|---|
| nº jobs completados en el mismo poll | 1, **2+** | 2 |
| posición del que falla en el lote | primero, no-último, último | — |
| nº de fallos en el lote | 1, 2+ | 2 |

Clase patógena (resuelta): **≥2 en el lote, el que falla no es el último** → con el `break`
antiguo, los posteriores del lote se perdían. Cubierto por
`FlowExecutorFailFastTest::parallel_fail_fast_keeps_results_of_jobs_completing_in_the_same_poll`
(+ `..._two_failures_in_same_poll_drain_queue_once` para el guard one-shot del drain).

## 4. `buildProcessPool()` — gating de admisión 2D (mutante 612, pendiente)

Factor A: ¿algún job declara `memoryReserve !== null`? (→ `hasReservation`).
Factor B: ¿`options.memoryBudget !== null` y no deshabilitado?

| `hasReservation` | `memoryBudget` | `memoryBudgetMb` pasado al pool | modo admisión |
|---|---|---|---|
| F | * | null | 1D |
| T | null | null | 1D |
| T | no-null | `budget.binPackingReference` | **2D** |

Clase patógena (mutante 612, TrueValue `hasReservation=true`→`false`): con reservas + budget,
el pool debería entrar en 2D; el mutante lo deja en 1D (ignora memoria). Hueco: `fits()` 2D
está testeado, pero que `buildProcessPool` **conecte** el budget no. Pendiente de harness
(buildProcessPool es protected; requiere exponerlo o un test de comportamiento observable).
