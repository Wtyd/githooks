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
- [ ] `php7.4 vendor/bin/phpunit --order-by random` — 0 fallos
- [ ] `php7.4 githooks flow qa` — QA completo sin violaciones nuevas
- [ ] Skill `qa-tester` sobre los comandos tocados

## Contexto legacy

Los tests legacy (en `tests/Unit/Tools/`, `tests/Integration/`, `tests/System/Commands/ExecuteToolCommandTest.php`, `tests/System/Release/`) siguen siendo válidos para el sistema v2. Las references `unit-tests.md`, `integration-tests.md`, `system-tests.md`, `release-tests.md` documentan estos patrones. **No añadir tests legacy para funcionalidades nuevas** — usar los patrones v3 de arriba.
