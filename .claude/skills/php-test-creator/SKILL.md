---
name: php-test-creator
description: >
  Crea tests PHPUnit para el proyecto GitHooks (wtyd/githooks) siguiendo sus convenciones y patrones.
  Usa esta skill siempre que el usuario pida crear tests, añadir tests, testear una feature, verificar
  una funcionalidad, o cuando se implemente una nueva feature que necesite cobertura de tests.
  También cuando se mencione: "añadir tests", "crear tests", "test unitario", "test de integración",
  "test de sistema", "test de release", "necesito tests para esto", "falta cobertura", o cualquier
  variación en castellano o inglés relacionada con testing en este proyecto.
allowed-tools: Read, Edit, Write, Glob, Grep, Bash(php7.1 vendor/bin/phpunit *), Bash(php7.1 githooks tool *), Bash(git add *), Bash(git commit *), Bash(git status), Bash(git diff *), Bash(git log *), Agent
---

# PHP Test Creator para GitHooks

Esta skill genera tests para el proyecto GitHooks siguiendo estrictamente sus convenciones.
El proyecto tiene 4 niveles de testing, cada uno con un propósito claro y un patrón propio.

## Flujo de decisión

Ante una nueva feature o bug fix, sigue este orden:

### 1. Determinar qué tests son necesarios

Pregúntate:

| Pregunta | Si la respuesta es sí → |
|---|---|
| ¿Se ha creado/modificado una clase en `src/`? | **Unit test** |
| ¿Se ha añadido/modificado una Tool? | **Unit test** + actualizar `ConfigurationFileBuilder` + crear `*Fake.php` |
| ¿Afecta al comportamiento de un comando artisan? | **System test** |
| ¿Afecta a cómo interactúan varios componentes con el container? | **Integration test** |
| ¿Es una feature visible para el usuario final del `.phar`? | **Release test** (al menos happy path) |

**Regla de oro:** toda feature nueva necesita al menos un unit test Y un release test (happy path).
El release test existe para verificar que la build del `.phar` contiene lo que promete — es un smoke test
del artefacto de distribución, no del código fuente.

### 2. Crear los tests

Para cada tipo de test, lee la referencia correspondiente antes de escribir código:

- **Unit tests** → lee `references/unit-tests.md`
- **Integration tests** → lee `references/integration-tests.md`
- **System tests** → lee `references/system-tests.md`
- **Release tests** → lee `references/release-tests.md`

### 3. Actualizar infraestructura de testing (solo si es una Tool nueva)

Si la feature implica una nueva Tool en `src/Tools/Tool/`:

1. Crear `src/Tools/Tool/MyToolFake.php` que extienda la clase real y use `TestToolTrait`
2. Registrar la tool en `SUPPORTED_TOOLS` de `ToolAbstract`
3. Añadir la configuración por defecto en `ConfigurationFileBuilder`
4. Añadir el binding Fake en `ConsoleTestCase::bindFakeTools()`
5. Añadir la tool en los `dataProvider` de tests existentes (integration y system)
6. Añadir soporte de errores en `PhpFileBuilder` si la tool analiza código

## Convenciones transversales a todos los tests

Estas convenciones aplican a TODOS los tipos de test:

- **Anotación `/** @test */`** en cada método de test (nunca prefijo `test` en el nombre)
- **snake_case con lenguaje natural** para nombres de test: `function it_does_something_when_condition()`
- **`@dataProvider`** para cobertura paramétrica cuando hay múltiples variantes
- **Namespace** sigue la estructura de directorios: `Tests\Unit\Tools\Tool\MyToolTest`
- **Una clase de test por clase testeada** (salvo integration tests que pueden agrupar por feature)
- **No usar `setUp()` si no es necesario** — preferir configuración inline cuando es simple

## Checklist de verificación

**IMPORTANTE:** No decir que los tests están listos hasta haber verificado CADA punto. Leer cada fichero mencionado y confirmar que la nueva tool/feature está cubierta.

### Estructura
- [ ] Los tests están en el directorio correcto (`tests/Unit/`, `tests/Integration/`, `tests/System/Commands/`, `tests/System/Release/`)
- [ ] La clase de test extiende la base class correcta
- [ ] El namespace coincide con la ruta del fichero

### Convenciones
- [ ] Todos los métodos de test tienen `/** @test */`
- [ ] Los nombres de test usan snake_case descriptivo
- [ ] Se usan `@dataProvider` donde hay variantes paramétricas
- [ ] No se ha añadido `test` como prefijo del nombre del método

### Para Tools nuevas — Infraestructura (LEER CADA FICHERO)
- [ ] `src/Tools/Tool/MyToolFake.php` existe con `use TestToolTrait`
- [ ] `src/Tools/Tool/ToolAbstract.php` — tool en `SUPPORTED_TOOLS`
- [ ] `tests/Utils/ConfigurationFileBuilder.php`:
  - [ ] Import de la clase Tool
  - [ ] Tool en `$this->tools`
  - [ ] Configuración en `$this->configurationTools` con `IGNORE_ERRORS_ON_EXIT => false`
- [ ] `tests/Utils/TestCase/ConsoleTestCase.php`:
  - [ ] Import de Tool + ToolFake
  - [ ] `$this->app->bind(Tool::class, ToolFake::class)` en `bindFakeTools()`
- [ ] `tests/Utils/PhpFileBuilder.php` (si analiza código):
  - [ ] Constante `MY_TOOL`
  - [ ] Método `addMyToolError()`
  - [ ] Case en `buildWithErrors()`

### Para Tools nuevas — DataProviders (ABRIR Y VERIFICAR CADA UNO)
- [ ] `tests/Integration/IgnoreErrorsOnExitFlagTest.php` → `allToolsProvider()`
- [ ] `tests/System/Commands/ExecuteToolCommandTest.php`:
  - [ ] `allToolsOKDataProvider()` — tool + comando generado por prepareCommand
  - [ ] `allToolsKODataProvider()` — tool + comando generado
  - [ ] `allToolsAtSameTimeDataProvider()` — tool en Tools array + Command dict
  - [ ] `it_runs_all_configured_tools_at_same_time()` — assertions incluyen la tool
  - [ ] `onlyConfiguredToolsAtSameTimeDataProvider()` — tool en "Not runned tools"
  - [ ] `exit1DataProvider()` — caso con la tool fallando
- [ ] `tests/System/Release/ExecuteToolTest.php`:
  - [ ] `allToolsProvider()` — entrada para la tool (si analiza código)
  - [ ] 3 tests de `tool all` (full, fast, multi-process) — setTools + assertions
  - [ ] Test individual happy path

### Cobertura mínima por nivel
- [ ] **Unit test:** checkTool(), todos los argumentos, executablePath por defecto, argumentos inesperados, prepareCommand con variantes
- [ ] **Integration test:** ignoreErrorsOnExit true/false, tanto individual (`tool mytool`) como `tool all`
- [ ] **System test:** ejecución OK y KO con config real en filesystem, comando completo verificado
- [ ] **Release test:** al menos happy path individual + incluida en tests de `tool all`

### Ejecución final (OBLIGATORIO)
- [ ] `php7.1 vendor/bin/phpunit tests/Unit/...` pasa
- [ ] `php7.1 vendor/bin/phpunit tests/Integration/...` pasa (si hay integration tests)
- [ ] `php7.1 vendor/bin/phpunit tests/System/...` pasa (si hay system tests, excluyendo release)
- [ ] `php7.1 vendor/bin/phpunit --order-by random` — suite completa sin fallos
- [ ] `php7.1 githooks tool all full` — QA completo sin violaciones nuevas

## Permisos necesarios

### Lectura de ficheros
- `src/` — Clases bajo test (para entender la API pública y comportamiento)
- `tests/Unit/`, `tests/Integration/`, `tests/System/` — Tests existentes como referencia de patrones
- `tests/Utils/` — `ConfigurationFileBuilder.php`, `TestCase/`, `Traits/` — Utilidades de test
- `tests/Mock/` — Mocks existentes
- `src/Utils/FileUtilsFake.php` — Fake de filesystem para tests unitarios
- `src/Tools/Process/ProcessFake.php` — Fake de proceso para tests de ejecución
- `src/Tools/Process/ExecutionFakeTrait.php` — Trait para fakes de ejecución
- `phpunit.xml` — Configuración de PHPUnit (grupos, suites)

### Escritura de ficheros
- `tests/Unit/**/*Test.php` — Tests unitarios nuevos o modificados
- `tests/Integration/**/*Test.php` — Tests de integración
- `tests/System/**/*Test.php` — Tests de sistema
- `tests/Utils/ConfigurationFileBuilder.php` — Añadir configuraciones de nuevas tools

### Comandos Bash
- `php7.1 vendor/bin/phpunit --order-by random` — Suite completa
- `php7.1 vendor/bin/phpunit tests/Unit/{path}` — Tests específicos
- `php7.1 vendor/bin/phpunit --filter {testName}` — Test individual
- `php7.1 githooks tool all full` — QA completo (verificación final)
- `git add` / `git commit` — Commits (solo si el usuario lo pide)

### Agentes
- **Explore** — Para investigar patrones de test existentes y cobertura actual
