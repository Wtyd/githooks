# Tabla de factores — `flow --repeat` (src/Flaky, FlowRunner::runRepeated)

Componentes con varios factores interactuando: `JunitReport` (parser), `FlapAggregator`
(clasificador), `FlowRunner::runRepeated()` (bifurcación y guards) y `JobAbstract::getJunitPath()`
(resolución de la ruta). El CLAUDE.md exige esta tabla antes de tocarlos.

Invariante global: **un test nunca cuenta como "pasó" en una pasada cuyo junit no se pudo leer**.
Clase patógena: una pasada que crashea sin escribir junit. Sin el borrado previo (anti-stale) se
leería el junit de la pasada anterior y el test saldría verde.

---

## A. Clasificador (`FlapAggregator`) — por (job, test-id) cruzando N pasadas

Factores por pasada `k`:

- **F1 — estado del fichero junit del job**: `ok` | `corrupt` (no parsea, truncado, vacío, raíz
  inesperada) | `missing` (no existe tras la pasada) | `stale` (no se pudo borrar antes de la pasada).
- **F2 — presencia del test-id** (si F1=ok): `present` | `absent-from-file`.
- **F3 — estado del testcase** (si present): `passed` | `failure` | `error` | `skipped` | `warning`.

Normalización:

| F1 | F2 | F3 | Cuenta como |
|---|---|---|---|
| corrupt / missing / stale | — | — | **anomalía de pasada** del job: sale del denominador de todos sus tests |
| ok | absent-from-file | — | `x` (ausencia neutral) |
| ok | present | passed | `g` (GREEN) |
| ok | present | failure, error | `n` (NONGREEN) |
| ok | present | skipped, warning | `s` (NEUTRAL) |

`exec = g + n`, `T = --flap-threshold` (entero ≥ 1, default 1).

| # | Condición | Clase | Exit |
|---|---|---|---|
| 1 | g>0 ∧ n=0 ∧ s=0 ∧ x=0 | `stable-pass` | 0 |
| 2 | n>0 ∧ g=0 ∧ s=0 ∧ x=0 | `stable-fail` | 1 |
| 3 | g>0 ∧ n>0 ∧ min(g,n) ≥ T | `flaky` | 1 |
| 4 | g>0 ∧ n>0 ∧ min(g,n) < T | `suspect` | 0 |
| 5 | g>0 ∧ n=0 ∧ (s>0 ∨ x>0) | `stable-pass-with-skips` | 0 |
| 6 | n>0 ∧ g=0 ∧ (s>0 ∨ x>0) | `stable-fail-with-skips` | 1 |
| 7 | exec=0 ∧ s>0 | `always-skipped` | 0 |
| ~~8~~ | ~~exec=0 ∧ s=0 ∧ x>0~~ | **inalcanzable** | — |
| 9 | anomalías de pasada (ortogonal) | lista `anomalies[]` + aviso | 0 salvo fila 9b |
| 9b | job con junit sin **ninguna** pasada legible | `inconclusive` | 1 |
| 10 | test-id repetido en una pasada | se fusiona (gana el peor: n > g > s) + `collisions[]` | — |

Precedencia: g>0 ∧ n>0 decide flaky/suspect aunque haya s o x (filas 3/4 antes que 5/6).

**Fila 8 eliminada al implementar.** La card la describía como "id que aparece y desaparece", pero un
test solo existe como fila si alguna pasada legible lo reportó, y entonces aporta g, n o s. Por tanto
`g=n=s=0 ∧ x>0` no puede darse. El caso real (id presente en unas pasadas y ausente en otras) ya lo
recogen las filas 5, 6 y 7 con `x>0`, y el informe expone `absent` por test.

AVL del umbral: T=2 con min(g,n) ∈ {1 → suspect, 2 → flaky, 3 → flaky}. Con T=1 la fila 4 es inalcanzable.

Identidad: `classname` (o `class`, o `file`) + `::` + `name` verbatim. El sufijo de dataset
(`with data set #0` / `with data set "label"`) ya viene en `name`.

## B. Bifurcación del comando

| `--repeat` | `--dry-run` | `--format` | jobs con junit en el plan | rutas junit | Resultado |
|---|---|---|---|---|---|
| ausente / 1 | * | * | * | * | `run()` intacto |
| 0, negativo, no entero | * | * | * | * | error, exit 1 |
| ≥ 2 | sí | * | * | * | `run()` (repeat ignorado) |
| ≥ 2 | no | junit, sarif, codeclimate, claude-code | * | * | error, exit 1 |
| ≥ 2 | no | text, json, vacío | ninguno | — | error, exit 1 |
| ≥ 2 | no | text, json, vacío | ≥ 1 | dos jobs con la misma ruta | error, exit 1 |
| ≥ 2 | no | text, json, vacío | ≥ 1 | distintas | `runRepeated()` |

`--flap-threshold`: 0, negativo o no entero → error, exit 1. Con `--repeat` < 2 → aviso "ignored".

**Unicidad de rutas en el plan, no en `conf:check`.** `extends` copia todas las claves del padre:
`phpunit-git extends phpunit` hereda su `log-junit`. Dos jobs pueden compartir ruta legítimamente
mientras no se repitan en el mismo flow, así que la comprobación vive en `runRepeated()`.

## C. Ruta junit por job (`JobAbstract::getJunitPath()`)

| `junit` | type con `log-junit` en ARGUMENT_MAP | `log-junit` | Ruta | `conf:check` |
|---|---|---|---|---|
| string no vacía | * | ausente | `junit` | — |
| string no vacía | sí | igual | `junit` | — |
| string no vacía | sí | distinto | `junit` | warning |
| ausente | sí (phpunit, paratest) | string no vacía | `log-junit` | — |
| ausente | sí | ausente | null | — |
| ausente | no (custom, pest, phpstan…) | — | null | — |
| no string / vacía | * | * | null (salvo derivación) | warning |
