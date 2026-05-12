# Tabla de factores — `Job::isEmptyInputTolerated()`

## Componente

`PhpstanJob::isEmptyInputTolerated(int $exitCode, string $output): bool`
`PhpcsJob::isEmptyInputTolerated(int $exitCode, string $output): bool`
(default `false` en `JobAbstract` para el resto)

## Invariante

Devuelve `true` **si y solo si** `(exitCode, output)` corresponde al patrón
"el tool ha descartado todo el input al aplicar sus exclusiones internas"
(empty input legítimo, no fallo real).

Ese patrón se inspecciona post-hoc tras ejecutar la tool, sin parsear su
config — gemelo de `JobAbstract::isFixApplied()`.

## Factores

| Factor | Clases de equivalencia |
|---|---|
| **tool** | phpstan (override), phpcs (override defensivo), phpcbf (hereda phpcs), `otros` (default `false`) |
| **exitCode** | `0`, `1`, `2`, `3`, `16`, `cualquier_otro` |
| **output** | contiene marker exacto / contiene marker como substring / no contiene marker / output vacío / marker en stderr (concatenado) |
| **case sensitivity del marker** | match case-exact / case distinto |

## Valores AVL (frontera)

| Tool | exitCode AVL | output AVL |
|---|---|---|
| phpstan | `0` (success), `1` (trigger), `2` (otro fallo) | `""` vacío, `"No files found to analyse"` exacto, `"no files found to analyse"` lowercase, marker como substring |
| phpcs | `0` silent, `1`, `2`, `3`, `16` (trigger en 4.x), `4` (fuera del set defensivo) | `""`, ambos markers conocidos (`All specified files were excluded`, `No files were checked`), case distinto |

## Decision table — phpstan

| exit | output contiene marker | resultado |
|---|---|---|
| 0   | (cualquiera)              | `false` (success exit nunca se reinterpreta) |
| 1   | sí, marker exacto         | `true` ← **clase patógena (BUG-1)** |
| 1   | sí, como substring        | `true` |
| 1   | sí, case-mismatch         | `false` (marker es case-sensitive) |
| 1   | no contiene               | `false` (fallo real con violations) |
| 1   | output vacío              | `false` |
| 2   | sí                        | `false` (solo exit 1 dispara) |

## Decision table — phpcs (defensiva multi-versión)

| exit | output contiene algún marker | resultado |
|---|---|---|
| 0  | (cualquiera) | `false` |
| 1  | sí | `true` (defensiva para versiones que devuelvan 1 en empty input) |
| 2  | sí | `true` (defensiva) |
| 3  | sí | `true` (defensiva) |
| 16 | sí, `All specified files were excluded` | `true` ← **clase patógena (PHPCS 4.0.1)** |
| 16 | sí, `No files were checked` (variante) | `true` |
| 16 | no contiene marker | `false` (otro tipo de fallo con exit 16) |
| 16 | empty output | `false` |
| 4  | sí | `false` (fuera del set defensivo) |

## Clases patógenas identificadas

1. **`phpstan` exit 1 + `No files found to analyse`** — el bug del cliente PROD-4492.
2. **`phpcs` (PHPCSStandards 4.0.1) exit 16 + marker** — empíricamente verificado con fixture real en `tests/fixtures/tool-outputs/phpcs-4.0.1/D_all_ignored`.
3. **Falso positivo en phpstan**: error real con exit 1 + violations sin marker (NO debe reinterpretarse) — cubierto por escenario `phpstan_real_error`.

## Cobertura existente

- `PhpstanJobTest::test_empty_input_tolerance_matches_phpstan_exit_signature` — `@dataProvider` con 8 filas sintéticas que cubren la tabla.
- `PhpcsJobTest::test_empty_input_tolerance_matches_phpcs_exit_signature` — `@dataProvider` con 11 filas sintéticas.
- `FlowExecutorEmptyInputTest` — cableado integración con jobs sintéticos.
- `EmptyInputToleranceReleaseTest` (`@group release`) — end-to-end con `.phar` real.

## Cobertura añadida por `ToolOutputFixturesRegressionTest`

Itera sobre **salidas reales capturadas** de las tools (phpstan 2.2.x-dev,
phpcs Squiz 3.13.6, phpcs PHPCSStandards 4.0.1) y verifica que la heurística
las reconoce correctamente. **Test de regresión**: si una versión futura de
phpstan/phpcs cambia el wording del marker o el exit code, este test falla y
nos avisa antes de que el bug del cliente vuelva.

## Anti-patrón evitado

- **Valor inocuo común**: NO usar `exitCode = 1` con `output = "No files found"` en TODAS las filas. La tabla cubre `exit ∈ {0, 1, 2, 3, 4, 16}` y `output ∈ {marker exacto, substring, vacío, otro contenido, case distinto}` cruzados.
- **Test "un escenario, un fix"**: no me limito a probar el caso del cliente (PROD-4492). La decision table cubre clases adversariales (`exit 1` con violations reales NO reinterpretar; `exit 16` sin marker NO reinterpretar).
