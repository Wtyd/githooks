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

Antes de añadir tests, comprobar si la clase tiene **un fichero de test propio**:

```bash
find tests/ -name "$(basename ClassName).php" -o -name "${ClassName}Test.php"
grep -rL "new ${ClassName}" tests/
```

Si **no existe**, crear el fichero desde cero siguiendo los patrones v3. La ausencia de test directo es la causa más frecuente de mutaciones escaped y cobertura engañosa (código ejecutado indirectamente pero sin asserts que discriminen su comportamiento).

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
- [ ] Cada `?? default` tiene test con operando izquierdo ausente
- [ ] Cada `||`/`&&` compuesto tiene test por rama significativa
- [ ] Cada `continue`/`break` tiene test que verifica el estado **después** del skip
- [ ] Cada guard defensivo (`!is_array`, `!isset`) tiene test con la entrada inválida correspondiente

### Side effects
- [ ] Toda llamada a `exec`/`shell_exec`/`chdir`/`file_put_contents` tiene un assert sobre el estado resultante
- [ ] Si no es observable → el código se ha refactorizado para aceptar colaborador inyectable

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
