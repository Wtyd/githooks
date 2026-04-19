# Catálogo de Mutators de Infection

Referencia rápida de los mutators más frecuentes en GitHooks y cómo clasificarlos. Organizado por familia.

## Operadores lógicos y de comparación

### `LogicalOr` / `LogicalAnd`

Sustituye `||` por `&&` (o viceversa).

**Patrón típico:** guard defensivo en parsers.

```php
// Original
if (!is_array($data) || !isset($data['files'])) {
    return [];
}
// Mutante
if (!is_array($data) && !isset($data['files'])) {
    return [];
}
```

**Clasificación:** **Real escape** — un test con JSON válido sin la clave esperada lo mata. Con el mutante (AND), si `$data` es array (is_array=true → `!is_array` false), el AND da false aunque falte la clave → no hace return temprano → accede a índice indefinido.

**Test mínimo:**
```php
$this->parser->parse(json_encode(['other' => 1]), 'tool'); // array sin la clave
```

---

### `LogicalAndAllSubExprNegation`, `LogicalAndNegation`, `LogicalAndSingleSubExprNegation`

Variaciones que niegan una o todas las sub-expresiones de un `&&` compuesto.

**Clasificación:** depende de si alguna rama tiene precondiciones imposibles en tests. Si todas son alcanzables, **real escape** — necesita test por combinación de ramas.

---

### `Identical` (`===` → `!==`) y `NotIdentical` (`!==` → `===`)

**Clasificación según contexto:**
- En exit code check `$exitCode !== 0`: **real escape** — test con exit distinto de 0.
- En comparación de strings `$foo === 'literal'`: **real escape** — test con valor exacto.
- En detección de SO (`substr(PHP_OS, 0, 3) === 'WIN'`) en Linux: **real escape** para el mutante principal; **equivalente** para mutantes de substr.

---

### `LessThan`, `LessThanOrEqualTo`, `GreaterThan`, `GreaterThanOrEqualTo`

Cambia `<` ↔ `<=`, `>` ↔ `>=`, etc. Incluye variantes `*Negotiation` que invierten el operador completo.

**Clasificación:** **Real escape** salvo que la frontera no sea alcanzable.

**Test:** valor exacto en el umbral. Ejemplo: para `$priority <= 2 ? 'error' : 'warning'`, incluir test con `priority=2` (frontera) esperando `'error'`.

---

## Retornos y saltos

### `ReturnRemoval`

Elimina la sentencia `return` (cae al siguiente código o devuelve `null` implícitamente).

**Clasificación:** **Real escape** casi siempre. Si el caller no usa el valor devuelto, puede ser equivalente, pero es raro.

---

### `Break_` / `Continue_`

Sustituye `break` ↔ `continue` o los elimina.

**Clasificación:**
- `continue` → `break` en bucle de acumulación (`foreach` que procesa múltiples entradas): **Real escape** — elementos posteriores no se procesan. Test con ≥2 elementos donde el primero no cumple el criterio.
- `break` → `continue` tras marcar una bandera que luego se comprueba: **Equivalente** práctico (solo perf).
- `Break_` eliminado en búsquedas: **Real escape** menor — el bucle completa innecesariamente, pero el resultado es el mismo. Difícil matarlo sin spy.

---

### `Foreach_`

Sustituye el iterable del `foreach` por `[]`.

**Clasificación:** **Real escape** — el cuerpo del bucle no ejecuta. Test que verifique el efecto acumulado tras iterar.

---

## Valores literales

### `TrueValue` / `FalseValue`

Cambia `true` ↔ `false` en literales.

**Clasificación depende del contexto:**
- Parámetro opcional de constructor con default observable: **Real escape** — test que construya sin ese parámetro.
- Parámetro opcional de constructor con todos los callsites pasando valor explícito: **Equivalente**.
- Bandera interna (`$this->loaded = true`): **Real escape** si el cambio de estado afecta llamadas posteriores.

---

### `IncrementInteger` / `DecrementInteger`

Suma/resta 1 a un literal entero.

**Clasificación:**
- En contadores de reporting (allocations, peak, telemetría): **Cosmético** — no afecta éxito/fallo.
- En defaults observables (`$priority ?? 3`): **Real escape** — test con la clave ausente y valor esperado exacto.
- En offsets de `substr`/`array_slice`: **Real escape** — test en la frontera.
- En anchos de marcos ANSI (`$width = 79`): **Cosmético**.

---

### `CastInt` / `CastFloat` / `CastString`

Elimina el cast explícito (`(int)`, `(float)`, `(string)`).

**Clasificación:** **Cobertura débil** — test con string numérica y `assertSame(42, …)` (el entero estricto mata).

---

## Strings y concatenación

### `Concat`, `ConcatOperandRemoval`

Invierte el orden de concatenación o elimina un operando.

**Clasificación según el string resultante:**
- String que se imprime al usuario (mensaje de error, path de archivo, comando shell) y es observable en test: **Real escape** o **cobertura débil** — assert exacto sobre el string emitido.
- String decorativa (marco ANSI, padding, bordes de dashboard): **Cosmético** — descartar.
- String usado como clave de array interno: **Real escape** — el valor se pierde.

---

### `UnwrapStrToUpper`, `UnwrapStrToLower`, `UnwrapTrim`, `UnwrapRtrim`, `UnwrapSubstr`, `UnwrapStrRepeat`

Sustituye la llamada a la función por su argumento directo (p.ej. `strtoupper($x)` → `$x`).

**Clasificación:**
- `UnwrapStrToUpper` en comparación con string en mayúsculas: **Cobertura débil** — test con input en minúsculas.
- `UnwrapTrim` en guard de string: **Cobertura débil** — test con espacios alrededor.
- `UnwrapRtrim` al construir prefijo con trailing slash: **Cobertura débil** — test con cwd acabado en `/`.
- `UnwrapStrRepeat` sobre marcos decorativos: **Cosmético**.

---

### `PregMatchRemoveDollar`, `PregMatchRemoveCaret`

Quita el ancla `$` o `^` del regex pasado a `preg_match`.

**Clasificación:** **Real escape** — test con input que tenga un sufijo/prefijo válido adicional. El original rechaza, el mutante acepta.

**Ejemplo:** para `/^(\d+s)$/` probar `'12s extra'` — el mutante sin `$` matchearía y capturaría `'12s'`.

---

## Array y funciones de array

### `ArrayOneItem`

Recorta el array retornado/construido a un único elemento.

**Clasificación:** **Real escape** si hay lógica que depende de ≥2 elementos. Test con `assertCount(2, …)` o `assertSame([$a, $b], …)`.

---

### `ArrayItemRemoval`

Elimina un item concreto de un array literal.

**Clasificación:** **Real escape** si el item eliminado es parte de un dominio (p.ej. lista de CI vars, lista de extensiones). Test que use cada item individualmente.

**Ejemplo:** `['GITHUB_BASE_REF', 'CI_MERGE_REQUEST_TARGET_BRANCH_NAME', …]` — necesita un test por variable.

---

### `UnwrapArrayValues`, `UnwrapArrayFilter`, `UnwrapArrayMerge`, `UnwrapArrayKeys`

Sustituye la llamada a la función de array por su primer argumento.

**Clasificación:**
- `UnwrapArrayValues` tras `array_filter`: **Real escape** — el array mutante conserva las claves originales; el original reindexaría. Test que verifique `array_keys($result) === [0, 1, …]`.
- `UnwrapArrayFilter` donde se filtran elementos específicos (p.ej. `.`, `..`): **Real escape** — test con dir que contenga esos elementos.

---

## Llamadas

### `MethodCallRemoval`, `FunctionCallRemoval`

Elimina la llamada completa.

**Clasificación según efecto observable:**
- Sobre `exec`, `shell_exec`, `chdir`, `mkdir`, `file_put_contents`, etc.: **Real escape** salvo que se asserta el side effect. Necesita verificar estado post-llamada (fichero creado, cwd, config aplicada).
- Sobre getters puros en el medio de una expresión: normalmente alguna otra aserción lo captura.
- Sobre `sort()`/`ksort()`/`array_multisort()`: **Real escape** — test que dependa del orden resultante.

---

### `Coalesce`

Invierte el orden de los operandos del `??`.

**Clasificación:** **Real escape** si los dos operandos tienen precedencia distinta observable. Test con el operando izquierdo ausente/presente.

**Ejemplo:** `$invocationMode ?? $jobConfig->getExecution()` — test con `$invocationMode='fast'` y `$jobConfig->getExecution()='full'` debe dar `'fast'`.

---

### `Assignment` (`.=` → `=`, `+=` → `=`)

Sustituye asignación compuesta por asignación simple.

**Clasificación:** **Real escape** — test con llamadas repetidas que dependan de la acumulación.

**Ejemplo:** `$regex .= '/'` tras varias iteraciones; mutante pierde la acumulación. Test con pattern con múltiples `**`.

---

## Casos switch

### `SharedCaseRemoval`

En un `switch` con múltiples `case` que comparten cuerpo, elimina uno.

```php
switch ($severity) {
    case 'critical':
    case 'error':      // mutante elimina 'critical', queda solo 'error'
        return 'error';
}
```

**Clasificación:** **Cobertura débil** — test con el case específico eliminado.

---

## Condicionales

### `IfNegation`

Niega la condición de un `if`.

**Clasificación:** **Real escape** casi siempre — cambia qué rama se ejecuta. Test con input que ejercite la rama contraria.

---

### `LogicalNot`

Sustituye `!$x` por `$x` (o viceversa).

**Clasificación:** **Real escape** normalmente. Cuidado con expresiones donde la doble negación es equivalente — raro pero posible.

---

## Otros

### `While_`

Sustituye la condición del `while` por `false` (nunca itera).

**Clasificación:** **Real escape** — el bucle no ejecuta. Test que dependa del efecto acumulado.

---

### `PublicVisibility` / `ProtectedVisibility`

Cambia el modificador de visibilidad. Casi siempre **equivalente** si no hay llamadas externas desde fuera del paquete. PHPStan / PHPMD suelen matarlos estáticamente en proyectos con buen tipado.

---

## Patrón maestro de identificación rápida

Al leer un mutant nuevo:

1. ¿El diff cambia **strings decorativos** (ANSI, bordes)? → **Cosmético**.
2. ¿La clase que contiene el mutant tiene **fichero de test directo**? Si no → **Real escape** (crear test desde cero).
3. ¿El mutator es **defensivo** (`LogicalOr` sobre guard, `IfNegation` sobre early return)? → Buscar test que ejerza exactamente esa rama; si no existe → **Real escape**.
4. ¿Es un cambio en **frontera** (`<=`, `<`, límites enteros)? → Test en el umbral exacto.
5. ¿Es un **default de parámetro opcional** (`TrueValue`, `FalseValue`, `IncrementInteger`)? → Verificar si algún callsite lo usa por defecto; si no → **Equivalente**.
6. Si el mutante requiere **mockear una función global** (`exec`, `file_get_contents`, `posix_isatty`) y no hay refactor para aceptar callable → **No accionable sin refactor**.

## Referencias externas

- Documentación oficial de mutators: https://infection.github.io/guide/mutators.html
- Configuración del proyecto: `infection.json.dist` (raíz del repo)
- Reporte per-mutator: `reports/infection/per-mutator.md` (generado por Infection)
