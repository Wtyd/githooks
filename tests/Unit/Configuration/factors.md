# Tabla de factores — `tests/Unit/Configuration/`

Componentes de parseo/validación de config con factores interactuando. Cada
entrada documenta la decision table que el `@dataProvider` correspondiente
materializa, para que quien toque el componente herede la tabla en lugar de
reinventarla.

## `HookConfiguration::fromArray()` — guard de referencia hook → target

**Fichero de test:** `HookConfigurationTest.php`
**Test:** `hook_target_reference_error_iff_target_in_neither_pool` (`@dataProvider hookTargetReferenceCases`)

**Código:** `src/Configuration/HookConfiguration.php:82-88`

```php
if (
    !in_array($target, $availableFlowNames, true)
    && !in_array($target, $availableJobNames, true)
) {
    $result->addError("Hook '$event' references '$target' which is not a defined flow or job.");
}
```

**Invariante:** para cada `HookRef`, se añade el error
`"Hook '<event>' references '<target>' which is not a defined flow or job."`
**si y solo si** `target ∉ availableFlowNames AND target ∉ availableJobNames`.

| Factor           | Clases de equivalencia |
|------------------|------------------------|
| `target ∈ flows` | presente / ausente     |
| `target ∈ jobs`  | presente / ausente     |

Decision table (2 factores → 4 filas, completa):

| target∈flows | target∈jobs | error? |
|--------------|-------------|--------|
| T            | T           | no     |
| T            | F           | no     |
| F            | T           | no     |
| F            | F           | **sí** ← clase patógena |

**Clase patógena (F,F):** es exactamente la que disparó el fallo de la pipeline
de release `26880755671` — un test redefinía sus flows con `setV3Flows(['shards'])`,
dejando el hook por defecto `pre-commit => qa` apuntando a un flow `qa` ya
inexistente. `flow shards` salía con código 1 antes de ejecutar nada.

**Mutantes que matan cada fila:**

- `LogicalAnd→Or` (`&& → ||`): lo distinguen **(T,F)** y **(F,T)** — con `||`
  esos casos producirían un error falso. Filas con `assertFalse(hasErrors())`.
- `FunctionCallRemoval` del 1er `in_array(flows)`: lo mata **(T,F)**.
- `FunctionCallRemoval` del 2º `in_array(jobs)`: lo mata **(F,T)**.
- `Concat` / `ConcatOperandRemoval` en el mensaje + interpolación `$event`/`$target`:
  los mata la fila **(F,F)** con `assertErrorEquals` (mensaje completo exacto).

(T,T) no mata `&&→||` por sí sola (ambos dan `false`), pero completa la tabla y
fija el contrato "presente en ambos pools ⇒ válido" contra regresiones futuras.
