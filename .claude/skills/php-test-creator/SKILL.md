---
name: php-test-creator
description: >
  Crea tests PHPUnit para el proyecto GitHooks (wtyd/githooks) siguiendo sus convenciones y patrones.
  Usa esta skill siempre que el usuario pida crear tests, añadir tests, testear una feature, verificar
  una funcionalidad, o cuando se implemente una nueva feature que necesite cobertura de tests.
  También cuando se mencione: "añadir tests", "crear tests", "test unitario", "test de integración",
  "test de sistema", "necesito tests para esto", "falta cobertura".
---

# PHP Test Creator para GitHooks

Esta skill genera tests para el proyecto GitHooks siguiendo sus convenciones.
El proyecto tiene 4 niveles de testing, cada uno con un propósito claro.

## Flujo de decisión

### Paso 0 — ¿La clase ya tiene un test directo?

Antes de añadir tests, comprobar si la clase tiene **un fichero de test propio**. **Siempre case-insensitive** — los filesystems Linux permiten que `PhpstanTest.php` y `PhpStanTest.php` coexistan pero PHPUnit los carga ambos y choca con `Cannot declare class`.

```bash
find tests/ -iname "${ClassName}Test.php" -o -iname "${ClassName}MutationTest.php"
grep -rl "new ${ClassName}" tests/
```

Si **no existe**, crear el fichero desde cero siguiendo los patrones v3. La ausencia de test directo es la causa más frecuente de mutaciones escaped y cobertura engañosa (código ejecutado indirectamente pero sin asserts que discriminen su comportamiento).

Si aparece un fichero con el nombre pero **con distinta capitalización** (`PhpStanTest.php` existente vs `PhpstanTest.php` que querías crear), **endurece el existente** en lugar de crear un nuevo fichero — así evitas la colisión del classmap.

### Paso 1 — Determinar el tipo de test

| Ubicación del código tocado | Tipo de test |
|---|---|
| `src/Configuration/`, `src/Jobs/`, `src/Execution/`, `src/Hooks/` | **Unit test v3** (`references/unit-tests-v3.md`) |
| `src/Tools/Tool/` | **Unit test legacy** + Fake + infra (`references/unit-tests.md`) |
| `app/Commands/` nuevo o con flags nuevos | **System test** + testing funcional (`qa-tester`) |
| Feature visible desde el `.phar` | **Release test** happy path (`references/release-tests.md`) |

**Regla de oro:** toda opción CLI nueva necesita un test que verifique que el valor se propaga hasta el servicio (no solo que el comando no crashea).

### Paso 2 — Diseñar los casos antes de escribir

Aplicar las cuatro listas de comprobación siguientes al código que se va a testear. No escribir el fichero de tests hasta haber enumerado los casos.

## Diseño de tests por factores (estrategia)

**Esto va primero. Antes de elegir asserts, antes de pensar en mocks, antes de escribir el método de test.**

Los tests "un escenario, un test" dejan clases de entrada adversarias sin cubrir. La cobertura de líneas y la MSI de Infection pueden ser altísimas mientras un invariante crítico se viola en una ruta jamás testeada con valores patógenos. Caso real en este repo: el invariante `coresByJob[job] ≤ coresBudget` se rompió por **tres rutas** distintas en commits sucesivos (BUG-1, BUG-2, BUG-3) porque ningún test usaba `cost > budget` — todos usaban `coresByJob[*] = 1`.

La técnica es de los años 70. Aplicarla en este orden:

### 1. Identificar factores

Un **factor** es una variable de entrada o estado cuyo cambio puede producir un output observable distinto. NO es un dato de prueba; es una dimensión del input space.

Pregúntate: para cada bifurcación del código (`if`, `??`, `?:`, ternario, guard, `match`/`switch`), ¿qué variable está siendo comparada y contra qué? Cada lado de la comparación puede ser un factor. Cada flag CLI que cambia un branch es un factor. Cada interacción `A vs B` (`cost vs budget`, `peak vs threshold`, `cwd vs path-pattern`) es un factor.

### 2. Enumerar clases de equivalencia por factor

Para cada factor, partir el dominio en clases tales que cualquier valor de la misma clase produce el mismo comportamiento. Las clases típicas:

- **Comparaciones** (`a vs b`): `<`, `==`, `>` (3 clases mínimo).
- **Cardinales**: `0`, `1`, `>1` (porque `foreach` con 1 elemento no detecta `continue→break`).
- **Nulables**: `null`, `valor presente`.
- **Flags / enums**: cada valor del enum es su propia clase.
- **Strings**: `""`, `"contenido normal"`, `"con caracteres especiales/whitespace/CRLF/BOM"` cuando el código parsea texto.

### 3. Análisis de Valores Límite (AVL) por clase

Para cada clase, los valores que más mutantes matan están en la **frontera** entre clases:

- Comparación `cost ≤ budget`: probar `cost = budget - 1`, `cost = budget`, `cost = budget + 1`.
- `count > 0`: probar `0`, `1`.
- `length >= MIN`: probar `MIN - 1`, `MIN`, `MIN + 1`.

Una clase no testeada con su frontera es una invitación a que un mutante `<` → `<=` escape.

### 4. Decision table o pairwise

**Cuando los factores son ≤ 3**, escribe la decision table completa. Cada fila es una combinación; cada columna es un factor; la última columna es el output esperado. El `@dataProvider` materializa la tabla.

Ejemplo real (`AdmissionContext::fits()`):

| `cost ≤ coresFree` | `memoryFree === null` | `mem ≤ memoryFree` | `fits()` |
|---|---|---|---|
| F | * | * | F |
| T | T | * | T |
| T | F | F | F |
| T | F | T | T |

Cuatro filas. Tres asserts cada una. 12 asserts cubren el invariante para siempre.

**Cuando los factores son > 3**, el producto cartesiano explota. Aplicar **pairwise (all-pairs)**: cubrir todas las **parejas** de valores en lugar de todas las combinaciones. Reduce 648 → ~20-25 casos garantizando que cada par aparece junto en ≥ 1 test.

Generador recomendado (offline, sin instalar nada nuevo): tabla manual usando el algoritmo IPOG. Si la matriz es grande, [PICT de Microsoft](https://github.com/microsoft/pict) genera pairwise desde un fichero de modelo. Documentar el modelo en `tests/Unit/<Component>/factors.md` para que el siguiente que toque el componente herede la tabla.

### 5. Materializar como `@dataProvider`

Cada fila de la tabla → entrada del provider. Nombrar claves del provider con la clase que prueban (`'cost > budget'`, `'cost == budget'`), no con el escenario (`'phpstan with processes 2'`). Así el test dice qué clase cubre.

```php
/**
 * @test
 * @dataProvider admissionFitsCases
 */
function admission_fits_respects_decision_table($coresFree, $cost, $memFree, $memReserve, bool $expected)
{
    $ctx = new AdmissionContext($coresFree, $memFree, ['j' => $cost], ['j' => $memReserve]);
    $job = $this->makeJob('j');
    $this->assertSame($expected, $ctx->fits($job));
}

public function admissionFitsCases(): array
{
    return [
        // coresFree, cost, memFree, memReserve, expected
        'cost > coresFree (cores axis blocks)'              => [2, 4, null, 0, false],
        'cost == coresFree (boundary, fits)'                => [2, 2, null, 0, true],
        'cost < coresFree (cores axis fits, 1D)'            => [4, 2, null, 0, true],
        'memory unavailable, cost ok (1D mode)'             => [4, 1, null, 999, true],
        'memory available, mem > memFree (memory blocks)'   => [4, 1, 100, 200, false],
        'memory available, mem == memFree (boundary, fits)' => [4, 1, 200, 200, true],
        'memory available, mem < memFree (fits)'            => [4, 1, 200, 100, true],
    ];
}
```

### 6. Plantilla obligatoria a rellenar antes de tocar el código

```
COMPONENTE: <Class::method>
INVARIANTE(S): <expresión booleana que el método garantiza>

| Factor | Clases de equivalencia | Valores AVL |
|---|---|---|
| <factor 1> | <c1>, <c2>, <c3> | <v1>, <v2>, <v3> |
| <factor 2> | ... | ... |

DECISION TABLE / PAIRWISE:
| <factor 1> | <factor 2> | ... | <output> |
|---|---|---|---|
...

CLASE PATÓGENA IDENTIFICADA: <la fila que rompe el invariante si no hay clamp/guard>
COBERTURA EN TESTS EXISTENTES: <qué filas ya están testeadas, cuáles no>
```

### Anti-patrón: "valor inocuo común"

`coresByJob[*] = 1` en 24 tests del proyecto. Cuando un parámetro toma el **mismo valor "fácil"** en todas las filas del provider, ese parámetro **no está siendo testeado** — está fijado. Su clase patógena no aparece. La presencia del parámetro es decorativa.

Señales de alarma:

- El parámetro aparece en la firma pero todos los tests le pasan el mismo valor.
- Los providers nombran sus filas por escenario (`'three jobs config'`) en lugar de por clase (`'cost > budget'`).
- Cobertura de líneas alta + ningún test con `>`, `>=`, `==` en la frontera del parámetro.

### Tests de paso-a-través / equivalencia: fixture maximal

Aplica cuando un test afirma que un objeto **atraviesa una frontera intacto**: equivalencias (`flow X` ≡ `flows X`), "el plan refleja la config", "el envelope refleja el job", copy-constructors posicionales (`new FlowPlan(...)` reconstruido en varios call sites), merges que reenvían campos. Son bugs de **cableado**, no de lógica: un atributo se cae al recomponer un objeto portador campo-a-campo. El nivel unitario de las piezas puras es **ciego** a ellos — sólo se ven donde se observa la pérdida (el payload final).

Es el anti-patrón "valor inocuo común" llevado al **fixture**: un fixture sin atributos opcionales pasa en verde aunque la propagación esté rota.

Reglas:

1. **Fixture maximal, no mínimo.** Un único fixture "kitchen-sink" que active a la vez **todos** los atributos opcionales a valores no-default (todos los `needs` / `only-files` / `exclude-files` / `on` / … y, si aporta, su interacción — p.ej. un job cuyo `needs` apunta a otro que la admisión salta). No hace falta separar por atributo a este nivel; eso es trabajo del nivel unitario.
2. **Compara el payload completo normalizado**, no los campos que se te ocurran a mano. `assertSame($normaliza($a), $normaliza($b))` quitando sólo lo volátil en runtime (`time`, `duration`, `memoryPeak`, `memoryReserved`, `totalTime`). Así un campo perdido aparece como diff **sin que el test lo prevea — incluido un atributo que aún no existe** cuando se escribe el test. Comparar 3 campos elegidos a mano es lo que dejó pasar el bug.
3. **Mantén también un camino mínimo** (sin attrs) como smoke del caso común; pero la caza de pérdidas la hace el maximal + comparación total contra la referencia.
4. **Verifica que el guard muerde**: rompe temporalmente un punto del cableado y confirma que el test se pone rojo (si el fix está sin commitear, NO uses `git checkout` para restaurar — revierte a HEAD y te borra el fix; deshaz la rotura a mano).

Complemento estructural (reduce la dependencia de la disciplina de tests): minimiza las reconstrucciones posicionales de objetos portadores. Cada `new FlowPlan(...)` repetido con ~10 args es una ocasión de omitir el último; prefiere withers (`$plan->withOptions($o)->withExpandedFlows($f)`) que copian todo lo demás, de modo que un campo nuevo no haya que acordarse de reenviarlo en cada call site.

Caso de referencia: **BUG flows-entry-attrs (rc-3.4.0)**. `flows` perdía needs/admisión porque `prepareMultiple` aplanaba los jobs a strings y el grafo se caía en dos reconstrucciones de `FlowPlan`. El test `single_flow_degenerate_matches_flow_command_output` existía y comparaba `flow` ≡ `flows`, pero su fixture no llevaba ningún entry-attr y sólo comparaba 3 campos → verde en falso. Ahora usa fixture maximal + payload completo.

### Cuándo aplicar este capítulo

Obligatorio cuando el componente bajo test:

- Tiene varios factores interactuando (≥ 2 ramas con condiciones que cruzan variables distintas).
- Es un **scheduler / admission / queue** (concurrencia).
- Es un **parser / validador / resolver** con guards compuestos (`!is_array || !isset || ...`).
- Es un **matcher** de patrones contra contexto (paths, branches, flags).
- Es un **merger de options** con orígenes múltiples (CLI, config, defaults, per-flow, per-job).

Para getters triviales y data classes pasivas, basta con el flujo "Paso 2" estándar.

### Componentes en este repo con tabla de factores documentada

A medida que se rellenen, listar aquí. La tabla viene en `tests/Unit/<Path>/factors.md`:

- **`tests/Unit/Execution/factors.md`** — admission, scheduler, thread budget allocator. (Pendiente — añadir cuando se toque cada componente.)
- **`tests/Unit/Configuration/factors.md`** — parsers de config, validadores. (Pendiente.)
- **`tests/Unit/Execution/factors-input-files.md`** — resolver `--files`/`--files-from`/`--exclude-pattern`/`--fast`. (Pendiente.)
- **`tests/Unit/Hooks/factors-conditions.md`** — matcher de `only-on`/`exclude-on`/`only-files`/`exclude-files`. (Pendiente.)

Cualquier bug crítico (CRIT/ALTA) que se cierre tocando uno de estos componentes debe rellenar el `factors.md` correspondiente en el mismo commit. El siguiente que toque el componente hereda la tabla y la extiende, no la reinventa.

## Principios de asserts fuertes

Un test que ejecuta código sin asserts discriminativos es casi equivalente a no tenerlo: el código queda cubierto en líneas pero no en comportamiento.

### Reglas

- **`assertSame` por defecto** (estricto) en lugar de `assertEquals` (lax). Solo usar `assertEquals` si se compara un objeto por valor y es intencional.
- **Valores exactos** sobre predicados derivados. Preferir `assertSame(0755, fileperms($p) & 0777)` a `assertTrue(is_executable($p))`.
- **Contenido completo** en arrays/strings cuando el tamaño lo permita. `assertSame(['a', 'b'], $result)` antes que `assertCount(2, $result)`.
- **Tipo + valor** en resultados numéricos: `assertSame(42, $n)` (también verifica que es `int`, no `"42"`).
- **Identidad de referencias** (regex exacta, no `assertMatchesRegularExpression` con un patrón permisivo).

### Anti-patrones a evitar

```php
// NO — el mutante que cambia la condición a `|| true` pasa
$this->assertTrue($result->isSuccess());

// SÍ — verifica el valor de la propiedad que generó el éxito
$this->assertSame(0, $result->getExitCode());
$this->assertSame([], $result->getErrors());
```

```php
// NO — `assertCount(1)` se mata con ArrayOneItem pero no con ArrayTwoItems
$this->assertCount(1, $result);

// SÍ — fuerza contenido exacto
$this->assertSame([$expected], $result);
```

```php
// NO — permite muchos regex válidos
$this->assertMatchesRegularExpression('/src/', $regex);

// SÍ — mata mutantes de concatenación/assignment
$this->assertSame('#^src/.*/File\.php$#', $regex);
```

## Cuando tienes un informe de Infection

Si el punto de partida es un `Infection.md` con mutants escaped, usa esta tabla para mapear cada mutante a la acción de test correcta. Si un mutante no aparece aquí, aplica los principios de asserts fuertes y la lista por operador de la siguiente sección.

| Mutator | Síntoma cuando escapa | Acción de test |
|---|---|---|
| `Continue_→break` | Loop línea-a-línea sobre array con un solo elemento inválido — `break` y `continue` producen el mismo resultado | **Provider de 2 elementos: primero inválido, segundo válido.** Asserts: `assertCount(1, $result)` **y** `assertSame('valor-del-segundo', $result[0]->getField())` (identidad, no solo count). Ver sección "Dos elementos mínimo" |
| `Coalesce` / `CoalesceSwapFirstArg` | `$x ?? $default` invertido → devuelve siempre el default, o viceversa | Dos tests: uno con clave presente (assertSame valor explícito) y otro sin clave (assertSame default exacto). Nunca uno solo |
| `ReturnRemoval` tras `addError` | Factoría devuelve objeto en estado corrupto en vez de `null` | `assertNull($instance)` en los tests de error, no solo `$result->hasErrors()` |
| `ConcatOperandRemoval` / `Concat` en mensajes | Parte del warning/error desaparece | `assertSame('texto exacto completo', $mensaje)` o helper `assertWarningEquals` — nunca `assertStringContainsString` sobre un substring genérico |
| `CastArray` en `(array) $scalar` | `foreach ((array) $value)` sin cast silenciosamente vacío si $value es escalar | Test con **valor escalar** en ese argumento (no solo arrays) y assert del resultado |
| `LogicalAnd→Or` / `LogicalOr→And` en guards | Una rama se activa cuando no debería | Un test por combinación significativa: (A∧B), (¬A∧B), (A∧¬B) — no solo el happy path |
| `NotIdentical→Identical` sobre `$x !== ''` | Bloque se ejecuta con string vacío, añadiendo espacio trailing | Test sin el valor opcional con `assertStringEndsWith` sobre el último token real (mata también `Concat` trailing) y otro con el valor |
| `NewObject`→`null` | Método devuelve `null` donde debería haber una instancia | `assertInstanceOf(ExpectedClass::class, $result)` **y** `assertSame(valorEsperado, $result->getField())` |
| `SharedCaseRemoval` (switch) | Un `case` se vuelve inalcanzable | Test que fuerza ese exact case. Si la entrada que lo dispara no existe en producción, usar `\ReflectionMethod` para invocar el método privado con el valor directamente |
| `UnwrapArrayMerge` | `array_merge($a, $b)` colapsa a un lado | Poblar **ambos** operandos con contenido distinto y assert `assertSame([...a-items, ...b-items], $merged)` |
| `FunctionCallRemoval` (`exec`, `file_get_contents`) | La llamada desaparece y se cae al fallback silenciosamente | **Refactorizar** para inyectar colaborador (ver sección "Colaboradores inyectables") y assertar **valor exacto** de la primera llamada |

### Flujo de trabajo con un informe Infection

1. Copia la clasificación ALTA/MEDIA a un scratchpad.
2. **Agrupa por fichero**, no por mutante: un mismo fichero suele tener varios mutants relacionados.
3. Aplica la tabla Mutator→Acción para cada línea.
4. Ejecuta los nuevos tests tras cada fichero. Re-ejecuta Infection solo al final (es caro).
5. Para cada mutante ALTA que siga vivo tras endurecer el test, lee `reports/infection/mutation-report.html` para ver el diff concreto.

## Dos elementos mínimo

Cualquier `foreach` que contenga un `continue` o un guard que filtre elementos necesita un test con **al menos 2 elementos** donde el primero se salte y el segundo sea válido. Un provider con un solo elemento inválido que asserta `[]` no distingue `continue` de `break` — ambos producen `[]`.

**Patrón:**

```php
/** @test */
function it_keeps_processing_after_skipping_an_invalid_entry()
{
    $input = [invalid_entry, valid_entry_with_distinct_field];

    $result = $this->subject->parse($input);

    $this->assertCount(1, $result);
    $this->assertSame('valor-solo-en-el-valido', $result[0]->getField());
}
```

Aplica igual a los providers `dataProvider` que usen un solo item: son útiles para testear "input malformado produce vacío" pero NO matan `continue→break`.

## Warnings/errores como texto exacto

Cuando una clase produce warnings o errores, usa el trait compartido `Tests\Support\AssertWarningsTrait` para asserts de igualdad:

```php
use Tests\Support\AssertWarningsTrait;

class MyTest extends TestCase
{
    use AssertWarningsTrait;

    /** @test */
    function it_warns_with_exact_message_when_input_is_invalid()
    {
        $result = new ValidationResult();
        $subject->validate($input, $result);

        $this->assertWarningEquals(
            "Job 'name': key 'foo' expects an array or string.",
            $result
        );
    }
}
```

Los asserts `assertStringContainsString('array or string', ...)` dejan escapar mutants `ConcatOperandRemoval` que eliminen la mitad del mensaje. El trait compara contra la lista completa de `getWarnings()`/`getErrors()` con diff legible en el fallo.

## Colaboradores inyectables para APIs globales

El código que llama a `exec()`, `shell_exec()`, `file_get_contents()`, `getenv()`, `posix_isatty()` o similares es **imposible de testear por comportamiento** sin refactor. Cobertura de líneas al 100 % puede coexistir con 0 mutantes matados en esas llamadas.

**Patrón recomendado — método protegido override:**

```php
// src/Utils/CpuDetector.php
class CpuDetector
{
    protected function detectUnix(): int
    {
        $output = []; $exitCode = 0;
        $this->execCommand('nproc 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }
        // fallback...
    }

    protected function execCommand(string $cmd, array &$output, int &$exitCode): void
    {
        exec($cmd, $output, $exitCode);
    }
}

// tests/Doubles/UnixCpuDetectorStub.php (PHP 7.4 compatible)
class UnixCpuDetectorStub extends CpuDetector
{
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    protected function execCommand(string $cmd, array &$output, int &$exitCode): void
    {
        $output = $this->responses[$cmd]['output'] ?? [];
        $exitCode = $this->responses[$cmd]['exit'] ?? 127;
    }
}

// test
$stub = new UnixCpuDetectorStub([
    'nproc 2>/dev/null' => ['output' => ['12'], 'exit' => 0],
]);
$this->assertSame(12, $stub->detect()); // kills FunctionCallRemoval + Identical mutants
```

Aplica el mismo patrón a `file_get_contents` → `readFile($path)`, `getenv` → `readEnv($name)`, `posix_isatty` → `isInteractive()`.

## Spy real vs Mockery::spy con capturas profundas

Para asserts de **identidad** sobre arrays anidados que vienen como argumento de un método (ej. `printer->summary($passed, $total, $failed, $skipped)`), `Mockery::spy()` puede no registrar correctamente la llamada cuando el método tiene varios parámetros con defaults. En esos casos, crea una subclase double del colaborador que capture los argumentos en propiedades públicas:

```php
class SummaryCapturingPrinter extends Printer
{
    public int $summaryCallCount = 0;
    public array $lastSkipped = [];

    public function __construct() { /* skip parent */ }

    public function summary(int $p, int $t, array $f, array $s = []): void
    {
        $this->summaryCallCount++;
        $this->lastSkipped = $s;
        // ...
    }

    // stub the rest with empty bodies
}
```

Esto permite `assertSame($expectedSkippedArray, $spy->lastSkipped)` — comparación estricta que mata mutants `ArrayItem`, `ArrayItemRemoval`, `FalseValue` en los argumentos agregados.

### Spy para una interface de eventos con varios métodos

Cuando el colaborador es una **interface con 5+ métodos de evento** (p.ej. `OutputHandler` con `onFlowStart`, `onJobStart`, `onJobOutput`, `onJobSuccess`, `onJobError`, `onJobSkipped`, `onJobDryRun`, `flush`), crea un **Spy que implemente la interface y exponga listas públicas** de cada evento capturado. Permite aseverar orden, argumentos exactos y ausencia de llamadas, todo con `assertSame` sobre arrays.

```php
// tests/Doubles/OutputHandlerSpy.php
class OutputHandlerSpy implements OutputHandler
{
    /** @var string[] */     public array $startedJobs = [];
    /** @var array<int, array{job:string,chunk:string,isStderr:bool}> */ public array $outputs = [];
    public array $skippedJobs = [];
    public int $flushCount = 0;

    public function onJobStart(string $jobName): void { $this->startedJobs[] = $jobName; }
    public function onJobOutput(string $j, string $c, bool $err): void { $this->outputs[] = [...]; }
    public function onJobSkipped(string $j, string $reason): void { $this->skippedJobs[] = [...]; }
    public function flush(): void { $this->flushCount++; }
    // ... resto de métodos stub vacíos
}
```

**Ventajas sobre `createMock(Interface::class)` con `expects()`:**

- Tests independientes: no configuras expectativas antes, observas después.
- Orden y contenido juntos: `assertSame(['a', 'b'], $spy->startedJobs)` mata mutantes de orden + identidad en una línea.
- Helpers derivados fáciles: métodos como `jobNamesWithStderrOutput()` que filtran las listas capturadas.
- Se reutiliza entre muchos tests: un único Spy mata decenas de mutantes sobre `MethodCallRemoval`, `Identical` (channel flags), `ArrayItem` (reasons), `TrueValue`.

**Anti-patrón:** `Mockery::spy(OutputHandler::class)` con `shouldHaveReceived()->onJobOutput(...)`. En interfaces con muchos métodos y argumentos complejos los matchers de Mockery fallan silenciosamente y los tests no detectan el mutante.

## Fakes simples vs Mockery para configuración avanzada

Hay un umbral claro entre lo que debería ir en un Fake (`tests/Doubles/*Fake.php`) y lo que debería ser un mock de Mockery configurado in-test:

**Fake apropiado** — replacement estable y casi stateless, o estado trivial:
- Devolver siempre el mismo valor de una interfaz (`FileUtilsFake::getModifiedFiles()` → array configurable por setter una vez).
- Un único contador público de invocaciones para asertar retry/cache (`$branchDiffCallCount`).
- Implementación alternativa concreta, no una simulación (p.ej. `GitStagerFake` que no hace `git add` real).

**Cuando el Fake necesita cualquiera de lo siguiente — bórralo y usa Mockery:**
- Devoluciones distintas por llamada (primera vez X, segunda vez Y) — `Mockery::mock()->shouldReceive('foo')->andReturn($x, $y)`.
- Matching de argumentos complejo.
- Expectativas de número de llamadas (`->times(2)` / `->once()`).
- Múltiples estados que el test necesita orquestar.

Señal de alarma: si te encuentras añadiendo un segundo contador, un segundo setter condicional, o un mecanismo de fases al Fake, el test pide un mock, no un Fake. El Fake debe quedar en "setters simples + getters". Toda la configuración de comportamiento variable pertenece al test (Mockery) para que quede explícita.

## Asserts: una característica por test

Cada test debe tener un nombre que describe **una característica concreta** (p.ej. `it_keeps_parsing_after_skipping_an_invalid_entry`). Los asserts de ese test deben verificar **esa característica y solo esa**.

**Bueno** — el test sobre `continue→break` necesita dos asserts mínimos:
```php
$this->assertCount(1, $result);                       // no se abortó el loop
$this->assertSame('value-del-segundo', $result[0]);   // se procesó el segundo, no el primero
```

**Malo** — engordar con asserts que pertenecen a otros tests:
```php
$this->assertCount(1, $result);
$this->assertSame('value-del-segundo', $result[0]);
$this->assertSame('SomeRule', $result[0]->getRuleId());      // ← corresponde al test de rule-id
$this->assertSame(42, $result[0]->getLine());                // ← corresponde al test de line-casting
$this->assertSame('error', $result[0]->getSeverity());       // ← corresponde al test de severity
```

Un test con 10 asserts que falla no dice **qué** se rompió. Un test con 2 asserts que falla localiza la regresión al instante. Si una característica requiere más de 3-4 asserts, probablemente son varias características disfrazadas de una.

**Excepción**: un assert "identidad" mínimo (el surviving es el segundo, no el primero silenciosamente procesado) sí es parte de la característica de `continue→break` — sin él, `assertCount(1)` no distingue. Ese es el límite, no el mínimo común múltiplo de tests vecinos.

## Triage de mutants: real, equivalente, imposible, descartable

Cuando un mutante escapa, clasifícalo **antes** de escribir un test:

| Tipo | Cómo identificarlo | Acción |
|---|---|---|
| **Real (matable)** | Hay input público que distingue el comportamiento mutado del original | Escribir el test siguiendo los patrones de las secciones anteriores |
| **Equivalente** | La mutación no cambia el comportamiento observable: otro path devuelve el mismo valor, el side-effect es idéntico, o la constante mutada no afecta al resultado | **No escribir test.** Suprimir en `infection.json` con comentario explicando por qué |
| **Requiere estado no-construible por API pública** | Para matarlo haría falta instanciar el objeto en un estado que ningún factory/constructor expone (p.ej. guard compuesto con combinación de fields imposible) | **Detener.** Dos opciones: (a) refactorizar el código para que el estado sea alcanzable, si el guard defensivo oculta un bug real; (b) aceptar que el mutante sobrevive y suprimir en `infection.json` porque el guard es redundante |
| **Descartable (cosmético/plataforma/perf)** | Formato ANSI, rama Windows/Darwin en CI Linux, `break→continue` tras flag ya encontrada, padding decimal | **Batch-excluir** en `infection.json` al final del ciclo, cuando la clasificación real/equivalente esté clara para todo el proyecto |

**Regla de oro:** si necesitas `ReflectionProperty`, monkey-patching, o cualquier truco de test para alcanzar el mutante, para y replantea. No es un mutante real matable — es señal de que el código o el mutante están mal.

**Cuándo decidir exclusiones**: al final del ciclo Tier 1 + Tier 2 + Tier 3, con todo el campo a la vista. Excluir antes puede ocultar real escapes que otro test habría cazado.

## Cobertura exhaustiva — lista por operador

Leer el código del método a testear y marcar cada uno de estos operadores. Cada marca requiere su test.

| Construcción en el código | Tests requeridos |
|---|---|
| `<`, `<=`, `>`, `>=` | Test en la **frontera exacta** (valor igual al umbral) + uno a cada lado |
| `===`, `!==` | Test con el valor exacto + un caso que lo rompa |
| `??` | Test con operando izquierdo **ausente/null** y otro con valor explícito |
| `&&` compuesto | Un test por rama significativa (al menos A∧B, ¬A∧B, A∧¬B) |
| `\|\|` compuesto | Idem, asegurando que ninguna sub-expresión sea mutable a `true` sin test |
| `foreach` | Test con `0`, `1` y `2+` elementos (muchas `ArrayOneItem` escapan por solo probar 1) |
| `continue` / `break` | Test que verifique que los elementos **posteriores** al skip/break reciben el tratamiento correcto |
| `return` temprano | Test que **active** esa rama de return (no solo la principal) |

## Validación defensiva de entrada (parsers, config, deserializers)

Cuando el código valida su entrada con guards del tipo `!is_array($x) || !isset($x['key'])`, hay que cubrir cada cláusula por separado, no solo el happy path.

Para cada parser de entrada externa (JSON, XML, YAML, array de config):

- [ ] Happy path con entrada bien formada
- [ ] Entrada vacía (`[]`, `{}`, `""`)
- [ ] Cada clave opcional ausente individualmente
- [ ] Cada clave con **tipo incorrecto** (string donde se espera int, array donde se espera string)
- [ ] Nivel anidado: elemento del array sin las claves esperadas

Una única entrada malformada bien elegida mata múltiples mutaciones `LogicalOr` de guards defensivos a la vez.

## Side effects observables

El código que llama a `exec`, `shell_exec`, `chdir`, `mkdir`, `file_put_contents`, o cualquier API global, es testeable solo si el efecto es observable desde el test.

### Opciones (en orden de preferencia)

1. **Verificar estado resultante**: tras invocar, comprobar que el fichero existe con el contenido esperado, que `getcwd()` es el esperado, que la config de git tiene el valor correcto.
2. **Inyectar el colaborador**: aceptar un callable (`$exec`, `$fs`) en el constructor para poder espiarlo en test y usar la implementación real en producción.
3. **Extraer a interface**: si hay varios side effects, crear una interfaz (`ProcessRunner`, `Filesystem`) y un Fake para tests.

Nunca aceptar "el test pasa porque no crashea": eso permite que se elimine la llamada sin que el test se entere.

### Convenciones transversales

- **`/** @test */`** en cada método (nunca prefijo `test`).
- **snake_case con lenguaje natural**: `it_does_something_when_condition()`.
- **`@dataProvider`** para cobertura paramétrica (preferible a N métodos casi idénticos).
- **Namespace** espeja la estructura de directorios.
- **Una clase de test por clase testeada**.
- **No usar `setUp()`** si no es necesario (fixtures específicas van en el método del test).
- **`@group slow`** para tests que usen `sleep`, `usleep` o procesos con latencia deliberada. Permite excluirlos del ciclo rápido: `php7.4 vendor/bin/phpunit --exclude-group slow`. No se excluyen por defecto — en CI corren igual.

## Opciones CLI — sección crítica

**Cada opción del signature de un comando debe tener un test que verifique que modifica el comportamiento.**

No basta con "el comando se ejecuta con --flag y no crashea": hay que verificar el efecto observable del valor pasado.

```php
/** @test */
function fail_fast_option_stops_execution_on_first_failure()
{
    // Configurar 3 jobs donde el 2o falla
    // Ejecutar con --fail-fast
    // Verificar que el 3o NO se ejecutó (assertCount sobre jobs ejecutados)
}

/** @test */
function processes_option_overrides_config_value()
{
    // Config tiene processes=1
    // Ejecutar con --processes=4
    // Verificar que FlowPlan tiene processes=4 (assertSame(4, $plan->getProcesses()))
}

/** @test */
function exclude_jobs_option_removes_jobs_from_plan()
{
    // Config tiene jobs [a, b, c]
    // Ejecutar con --exclude-jobs=b
    // Verificar que el plan contiene exactamente [a, c]
}
```

## Patrones de test por tipo

Los ejemplos completos con imports y estructura están en las referencias:

- **Unit tests v3** → `references/unit-tests-v3.md` (Configuration, Jobs, Execution, Hooks)
- **Unit tests legacy** → `references/unit-tests.md` (Tools, Fakes, infra)
- **Integration tests** → `references/integration-tests.md`
- **System tests** → `references/system-tests.md`
- **Release tests** → `references/release-tests.md`

Leer la referencia antes de escribir el primer test del tipo correspondiente.

## Checklist de verificación (usar al final)

### Diseño por factores (CRÍTICO si el componente lo requiere — ver "Cuándo aplicar")
- [ ] Tabla de factores rellenada **antes** de escribir el primer test (no a posteriori)
- [ ] Cada factor tiene clases de equivalencia explícitas
- [ ] Valores AVL en cada frontera de comparación (`<`, `<=`, `==`, `>=`, `>`)
- [ ] Decision table completa (factores ≤ 3) o pairwise (factores > 3)
- [ ] **Clase patógena del invariante** identificada y cubierta — la fila que rompe el contrato si falta el clamp/guard
- [ ] `@dataProvider` materializa la tabla; claves nombradas por **clase**, no por escenario
- [ ] Sin "valor inocuo común" en parámetros del provider (mismo valor en todas las filas = parámetro no testeado)
- [ ] `factors.md` actualizado en el directorio del componente

### Estructura
- [ ] Tests en directorio correcto (`tests/Unit/`, `tests/System/Commands/`, etc.)
- [ ] Clase extiende la base class correcta
- [ ] Namespace coincide con la ruta del fichero

### Convenciones
- [ ] `/** @test */` en todos los métodos
- [ ] snake_case descriptivo
- [ ] `@dataProvider` donde hay variantes

### Calidad de asserts
- [ ] `assertSame` usado por defecto (no `assertEquals` salvo justificado)
- [ ] Cada assert verifica un valor **exacto**, no un predicado derivado
- [ ] Arrays/strings comparados por contenido completo cuando el tamaño lo permite
- [ ] Sin `assertTrue($x->isSuccess())` sin verificar el estado interno
- [ ] Cada test asserta **una característica y solo esa** (sin asserts prestados de otros tests)

### Dobles de test
- [ ] Fakes solo tienen setters simples + contador único si lo exige retry/cache; cualquier comportamiento más complejo → Mockery mock in-test
- [ ] Sin reflection ni monkey-patching para alcanzar estado no-construible por API pública

### Cobertura lógica
- [ ] Cada `<`/`<=`/`>`/`>=` del código tiene test **en la frontera**
- [ ] Cada `?? default` tiene test con operando izquierdo ausente **y** otro con valor explícito
- [ ] Cada `||`/`&&` compuesto tiene test por rama significativa
- [ ] Cada `continue`/`break` tiene test con **2 elementos** (primero se salta, segundo se procesa)
- [ ] Cada guard defensivo (`!is_array`, `!isset`) tiene test con la entrada inválida correspondiente
- [ ] Cada `(array) $value` tiene test con **valor escalar** (no solo arrays)

### Warnings/errores
- [ ] Tests que verifican un warning/error usan `assertWarningEquals`/`assertErrorEquals` (trait `AssertWarningsTrait`), **no** `assertStringContainsString` sobre un substring
- [ ] Factorías que hacen `addError` y `return null` tienen tanto el assert del error como `assertNull($instance)`

### Side effects
- [ ] Toda llamada a `exec`/`shell_exec`/`chdir`/`file_put_contents` tiene un assert sobre el estado resultante
- [ ] Si no es observable → el código se ha refactorizado para aceptar colaborador inyectable (método protegido + subclase stub)

### Cobertura de opciones CLI (CRÍTICA)
- [ ] Cada opción del signature tiene un test que verifica que el valor **llega al servicio**
- [ ] Ninguna opción del signature sin cobertura de test
- [ ] Test con config inexistente → no produce stack trace
- [ ] Test con config inválida → errores descriptivos
- [ ] Test con formato inválido (ej. `--format=csv`) → comportamiento definido

### Para Jobs v3
- [ ] `buildCommand()` con todos los argumentos
- [ ] `buildCommand()` con argumentos opcionales ausentes (sin dobles espacios ni flags vacíos)
- [ ] `getThreadCapability()` devuelve la capability correcta (o null)
- [ ] `applyThreadLimit()` modifica el argumento correcto

### Ejecución final (OBLIGATORIO)
- [ ] `php7.4 githooks job "Phpunit" --format=json -- --order-by=random` — 0 fallos
- [ ] `php7.4 githooks flow qa --format=json` — QA completo sin violaciones nuevas
- [ ] Skill `qa-tester` sobre los comandos tocados

**Iterar sobre un test concreto** durante desarrollo (en lugar de correr la suite entera):

```bash
php7.4 githooks job "Phpunit" --format=json -- --filter=MyNewFeatureTest
php7.4 githooks job "Phpunit" --format=json -- --filter='MyClassTest::method_x'
```

El separador `--` pasa los args literales a phpunit. Parsear la respuesta JSON para ver qué falló sin leer bloques ANSI.

## Contexto legacy

Los tests legacy (en `tests/Unit/Tools/`, `tests/Integration/`, `tests/System/Commands/ExecuteToolCommandTest.php`, `tests/System/Release/`) siguen siendo válidos para el sistema v2. Las references `unit-tests.md`, `integration-tests.md`, `system-tests.md`, `release-tests.md` documentan estos patrones. **No añadir tests legacy para funcionalidades nuevas** — usar los patrones v3 de arriba.
