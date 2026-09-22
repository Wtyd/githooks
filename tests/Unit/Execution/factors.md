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

## 4. `buildProcessPool()` — gating de admisión 2D (mutante 612)

Factor A: ¿algún job declara `memoryReserve !== null`? (→ `hasReservation`).
Factor B: ¿`options.memoryBudget !== null` y no deshabilitado?

| `hasReservation` | `memoryBudget` | `memoryBudgetMb` pasado al pool | modo admisión |
|---|---|---|---|
| F | * | null | 1D |
| T | null | null | 1D |
| T | no-null | `budget.binPackingReference` | **2D** |

Clase patógena (mutante 612, TrueValue `hasReservation=true`→`false`): con reservas + budget,
el pool debería entrar en 2D; el mutante lo deja en 1D (ignora memoria). **Cubierto** por
`FlowExecutorBuildPoolMemoryTest` (expone `buildProcessPool` vía subclase anónima y asserta
`getMemoryBudget()` en ambas direcciones de la tabla).

## 5. Reparto del thread budget — `resolveThreadBudget()` y sus tres loops

Componentes: `applyExplicitCoresOverrides` (FlowExecutor:370), `allocateParallelBudget`
(:388), `fillSequentialAllocations` (:417). INVARIANTE: `1 ≤ threadAllocations[job] ≤
coresBudget` y **todo** job del plan recibe asignación (los loops con `continue` sobre jobs
ya asignados deben seguir procesando los posteriores).

| Factor | Clases | AVL |
|---|---|---|
| `cores` override | ausente, `=1` (frontera inferior), `> budget` | 1, budget, budget+1 |
| posición del job con override | primero, último | orden [con, sin] |
| modo | paralelo (`processes>1` y ≥2 jobs), secuencial (resto) | — |

Clases patógenas (Infection 2026-08-12):
- **clamp==1** (IncrementInteger en `max(1, …)` :375): sin la fila `cores: 1`, el suelo
  podría subir a 2 en silencio.
- **continue→break** (:394 paralelo, :421 secuencial): con un solo job (o el job con
  override al final), `break` y `continue` son indistinguibles — hace falta el orden
  [override, sin-override] y assertar la asignación del **segundo**.

Cubierto por `FlowExecutorThreadAllocationTest` (observable: `buildCommand()` tras dry-run —
`resolveThreadBudget` corre antes del branch dry-run y `applyThreadLimit` reescribe el flag
nativo del tool).

## 6. `ProcessTerminator::terminate()` — kill del árbol de procesos (BUG-35)

`Process::fromShellCommandLine()` hace que `getPid()` sea el `sh -c` intermedio y
`Process::stop()` solo alcanza a ese PID: el analizador real y sus workers quedaban
reparentados a PID 1. `ProcessPool::terminateAll()` delega ahora en `ProcessTerminator`,
que enumera cada árbol con un `ProcessTree` (Linux `/proc`, macOS `ps`, Null) y señaliza
de hoja a raíz. Tests: `tests/Unit/Execution/Process/ProcessTerminatorTest.php`
(decision table con dobles) y `ProcessPoolTest::terminateAll_kills_the_descendants_*`
(árbol real).

**Invariantes**

- I1 — tras `terminate()`, ningún PID enumerado (raíz + descendientes) sigue vivo no-zombi.
- I2 — **enumerar todos los árboles antes de la primera señal**: matar un padre reparenta
  a sus hijos a PID 1 y se pierde la referencia.
- I3 — orden de señalización hoja → raíz (BFS inverso por árbol; la raíz la última).
- I4 — `SIGKILL` solo a los supervivientes tras `graceMs`; nunca antes de esperar.
- I5 — nunca señalizar `getmypid()` ni PID ≤ 1.
- I6 — sin árbol disponible o sin `posix_kill` → comportamiento previo (`stop(0)`) + aviso
  en stderr; nunca un fatal.
- I7 — `Process::stop(0)` se invoca siempre por proceso (Symfony reapea el wrapper),
  también en el camino degradado.

| Factor | Clases de equivalencia | AVL |
|---|---|---|
| F1 `tree->isAvailable()` | T, F | — |
| F2 `posix_kill` disponible (`canSignal`) | T, F | — |
| F3 PID de la raíz (`Process::getPid()`) | `null` (aún sin fork), `> 1` | — |
| F4 nº de descendientes | 0, 1, ≥2 (varios niveles) | 0 / 1 / 2 |
| F5 respuesta a `SIGTERM` de cada víctima | muere, la ignora (→ KILL), ya es zombi | — |
| F6 `graceMs` | 0, > 0 | 0 |
| F7 nº de raíces | 1, 2 (árboles con PIDs solapados) | 2 |
| F8 PID propio / ≤ 1 entre los descendientes | ausente, presente | — |

**Decision table** (`ProcessTerminatorTest`)

| # | F1 | F2 | F3 | F4 | F5 | F6 | F7 | F8 | Esperado |
|---|---|---|---|---|---|---|---|---|---|
| 1 | F | * | * | * | * | * | 1 | * | aviso con la reason del árbol; 0 señales; `stop(0)` |
| 2 | T | F | * | * | * | * | 1 | * | aviso `ext-posix not loaded`; 0 señales; `stop(0)` |
| 3 | T | T | null | * | * | * | 1 | * | sin `descendants()`, 0 señales; `stop(0)` |
| 4 | T | T | pid | 0 | muere | 5000 | 1 | – | TERM a la raíz; sin KILL; `stop(0)` |
| 5 | T | T | pid | 2 (3 niveles) | muere | 5000 | 1 | – | `descendants()` antes de la 1ª señal; TERM `[nieto, hijo, raíz]`; sin KILL |
| 6 | T | T | pid | 2 | el hijo ignora TERM | 5000 | 1 | – | KILL **solo** al hijo, tras agotar el grace |
| 7 | T | T | pid | 2 | el nieto ya es zombi | 5000 | 1 | – | TERM a todos (inocuo); sin KILL; no se espera por el zombi |
| 8 | T | T | pid | 1 | ignora | 0 | 1 | – | KILL inmediato tras TERM (frontera `graceMs = 0`) |
| 9 | T | T | pid | 2 | muere | 5000 | 2 solapados | – | cada PID una sola vez; ambas enumeraciones antes de la 1ª señal |
| 10 | T | T | pid | 2 | muere | 5000 | 1 | propio + PID 1 | ninguno de los dos recibe señal |

**Clase patógena**: fila 5 (el bug: hoy solo `stop(0)` a la raíz → el nieto sobrevive) y
fila 6 (TERM ignorado sin KILL → superviviente). La fila 9 protege I2.

**`ProcessTree::alive()` (Linux)** — estado en `/proc/<pid>/stat`, tercer campo tras el
**último** `)` (el `comm` puede llevar espacios y paréntesis): `S`/`R`/`D` → vivo;
`Z`/`X` → muerto; fichero ausente → muerto. Trampa medida: `posix_kill($pid, 0)` devuelve
`true` para un zombi, por eso no se usa como test de vida.

**`ProcessTreeWalk::descendants()`** — profundidad (`MAX_TREE_DEPTH` = 16: la cadena
100→118 devuelve 101..116), PID repetido en el fichero `children`, diamante, ciclo (un hijo
lista a un ancestro), raíz ≤ 0 → `[]`. Compartido por `LinuxProcessTree`, `MacOsProcessTree`,
`LinuxRssSampler` y `MacOsRssSampler` — un solo BFS.

---

## 7. `FlowPreparer` — precedencia del modo de ejecución (Infection 2026-09-21)

Dos `??` encadenados resuelven el modo de un job suelto: `prepareSingleJob()` para el modo
que se reporta en el plan (línea 232) y `applyExecutionModeSingleJob()` para el filtrado
real de ficheros (línea 310). Ambos deben leerse igual: **CLI > config del job > FULL**.

| `--execution` (CLI) | `execution:` del job | modo efectivo |
|---|---|---|
| — | — | full |
| — | fast | fast |
| — | full | full |
| fast | — | fast |
| **fast** | **full** | **fast** |
| **full** | **fast** | **full** |

**Clase patógena**: las dos últimas filas, las únicas donde ambos orígenes están presentes
**y discrepan**. Con un solo lado poblado, intercambiar los operandos del `??` es invisible
— por eso el test que ya existía (`plan_preserves_explicit_invocation_mode`, con un job sin
`execution`) dejaba vivos los dos mutantes Coalesce. El modo se observa en dos sitios
distintos y hay que assertar los dos: `FlowPlan::getExecutionMode()` (lo que sale en el
envelope) y el comando construido (si filtró a los ficheros modificados o no).

## 8. `JobRunner` — override de budgets desde la CLI (Infection 2026-09-21)

Guard `warn !== null || fail !== null` seguido de un `foreach` que aplica el override a cada
job del plan. El override **reemplaza** el par completo: un solo flag limpia el otro.

| `--warn-after` | `--fail-after` | warn efectivo | fail efectivo |
|---|---|---|---|
| — | — | el de la config | el de la config |
| 5 | — | 5 | null |
| — | 10 | null | 10 |
| **5** | **10** | 5 | 10 |

**Clase patógena**: el job bajo test tiene que traer `warn-after`/`fail-after` **en su
config**. Sin esa línea base, "el override no corrió" y "el override corrió" dejan ambos el
job a `null`, y sobreviven los tres mutantes de la zona (el guard, el `foreach` y la
llamada). La fila con los dos flags a la vez es la que mata la negación del `||`.

## 9. `AdmissionContext::getBlockingNeeds()` — buckets terminales

Tres guards consecutivos (`completedJobs`, `failedJobs`, `skippedJobs`), cada uno con su
`continue`. Un need en cualquiera de los tres está resuelto y no bloquea.

| bucket del 1er need | 2º need | `getBlockingNeeds()` |
|---|---|---|
| completed | pendiente | `[2º]` |
| failed | pendiente | `[2º]` |
| skipped | pendiente | `[2º]` |

**Clase patógena**: hacen falta **dos** needs con el primero en el bucket bajo test — con
uno solo, `continue` y `break` devuelven lo mismo. Y hace falta **una fila por bucket**: un
test que solo cubra `completed` no distingue el `continue` de los guards de debajo.

## 10. `MacOsProcessTree` — parseo de `ps` (Infection 2026-09-21)

Dos parsers línea a línea: `parseChildren()` sobre `ps -o pid=,ppid= -ax` y `parseStates()`
sobre `ps -o pid=,stat= -p …`. Sólo cuenta una línea con la forma exacta
`^\s*<pid>\s+<campo>`; las demás se saltan sin abortar el recorrido.

| Factor | Clases de equivalencia | Valores |
|---|---|---|
| forma de la línea | válida / basura **antes** de los dígitos (viola `^`) / basura **después** (viola `$`, sólo `parseChildren`) / cabecera / vacía | `"  200 100"` / `"xx 300 100"` / `"300 100 extra"` / `"PID PPID"` / `""` |
| posición de la inválida | primera / **intermedia** / última | — |
| cardinal del resultado | 0 / 1 / **≥2** | — |
| estado del proceso | vivo (`S+`, `R`) / zombi (`Z`) / sin entrada | — |

**Clase patógena**: línea inválida **intermedia** (distingue `continue` de `break`) con un
resultado de **≥2** elementos (distingue `ArrayOneItem` en `parseStates()` y en `alive()`).
Los fixtures anteriores tenían un único PID vivo, así que las tres mutaciones sobrevivían
con el mismo listado.

Dos trampas al construir el fixture:

- Para el ancla de `parseStates()` el estado tiene que ser **vivo**: con `"cmd 300 Z"` el
  filtro de zombis descarta el PID igual que si no se hubiera parseado, y el mutante
  sobrevive. Con `"cmd 300 S+"` la diferencia sí se ve.
- Un zombi **antes** que un proceso vivo distingue "estado `Z`" de "PID ausente", que es lo
  que el truncado a un elemento convierte en indistinguible.
