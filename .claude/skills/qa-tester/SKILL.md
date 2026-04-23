---
name: qa-tester
description: >
  Testing funcional y de integración de GitHooks como QA tester. Prueba todos los comandos CLI
  (flow, job, hook, conf:check, conf:migrate, cache:clear, status, system:info, tool), edge cases
  de configuración, formatos de salida, flags, combinaciones y compatibilidad legacy.
  Usa esta skill cuando el usuario quiera probar, verificar, o validar funcionalidades del CLI,
  buscar bugs, hacer testing de aceptación, o cuando mencione "probar", "testear funcionalidad",
  "verificar comando", "QA", "edge cases", "testing funcional".
---

# QA Testing funcional de GitHooks

Guía para hacer testing funcional exhaustivo del CLI de GitHooks.

## Principios de testing

1. **Probar como un QA tester hostil**: no verificar que los ficheros existen, EJECUTAR los comandos y comprobar el resultado real.
2. **Probar edge cases**: inputs vacíos, tipos incorrectos, valores límite, combinaciones imposibles.
3. **Probar en entorno real**: usar `/var/www/html3` como proyecto de prueba — es un proyecto Composer real con tools instaladas, ficheros con errores a propósito y configs variadas.
4. **No asumir que funciona**: si el Changelog dice que una feature existe, PROBARLA.
5. **El CWD importa**: la auto-detección de `executablePath` busca `vendor/bin/` relativo al directorio actual. Ejecutar desde `/var/www/html3` (tiene `vendor/bin/`) vs otro directorio da resultados diferentes.
6. **Leer la implementación antes de crear configs de test**: ante cualquier output inesperado (warnings, errores), buscar en `src/` el mensaje exacto con Grep para entender de dónde viene antes de catalogar como BUG.

## Paso 0: Preguntar al usuario

Antes de empezar, preguntar:

1. **Versión a probar**: `2.x` o `3.x` (ej: `3.0.0`)
2. **Versión de PHP**: `7.4`, `8.0`, `8.1`, `8.2`, `8.3`, `8.4`, `8.5`...
3. **Build**: ¿Usar la build actual o construir una nueva?

Con estos datos se determina:

| Dato | Valor |
|---|---|
| Rama RC en html1 | `rc-{versión}` (ej: `rc-3.0.0`) |
| Rama en html3 | `master` para 3.x, `2.x` para 2.x |
| Versión Composer | `dev-rc-{versión}` (ej: `dev-rc-3.0.0`) |
| Binario | `vendor/bin/githooks` (instalado por Composer) |
| Abreviatura en tests | `GH` = `phpX.Y vendor/bin/githooks` |
| Post-update necesario | Sí si PHP < 8.1, No si PHP >= 8.1 |

### Gestión de la build

Los binarios se encuentran en:
- `builds/githooks` — para PHP 8.1+
- `builds/php7.4/githooks` — para PHP 7.4, 8.0

**Verificar si la build existe y está actualizada:**

```bash
ls -la builds/githooks builds/php7.4/githooks   # ¿existen?
git log --oneline -1 -- builds/                  # ¿cuándo se buildeó?
git log --oneline -1                             # ¿último commit del código?
```

Si la build no existe o está desactualizada respecto al código, preguntar al usuario:

> Los builds están desactualizados (código más reciente que la build). ¿Quieres que construya una build nueva antes de probar?

**Si el usuario quiere build nueva**, construir con la versión de PHP del tier correspondiente:

| PHP para tests | PHP para build | Comando |
|---|---|---|
| 7.4, 8.0 | `php7.4` | `php7.4 githooks app:pre-build php && php7.4 githooks app:build` |
| 8.1, 8.2, 8.3, 8.4, 8.5 | `php8.1` | `php8.1 githooks app:pre-build php && php8.1 githooks app:build` |

**Importante**: el build elimina las dev dependencies de html1. Tras el build, restaurarlas:

```bash
git checkout -- composer.json && php{X.Y} tools/composer update
```

**Si el usuario quiere usar la build actual**, verificar que los binarios existen. Si no existen, avisar y preguntar de nuevo.

## Paso 1: Preparar el entorno de pruebas

```bash
cd /var/www/html3

# 1. Checkout rama correcta
git checkout {master|2.x}
git checkout -b {version}-prueba    # ej: 3.0.0-prueba

# 2. Preparar composer.json desde el example
cp composer.example.json composer.json
```

### Editar composer.json

Reemplazar la versión de githooks:

```bash
# Cambiar "*" por la versión RC
php{X.Y} -r '
$json = json_decode(file_get_contents("composer.json"), true);
$json["require-dev"]["wtyd/githooks"] = "dev-rc-{versión}";
file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
```

**Si PHP < 8.1**, añadir el evento `post-update-cmd` para instalar el binario correcto:

```bash
php{X.Y} -r '
$json = json_decode(file_get_contents("composer.json"), true);
$json["scripts"] = [
    "post-update-cmd" => ["Wtyd\\GitHooks\\Utils\\ComposerUpdater::phpOldVersions"]
];
file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
```

**Recordatorio — Cuándo es necesario post-update-cmd:**

| Composer update con | Ejecutable con | Tier | Post-update |
|---|---|---|---|
| php7.4 | PHP 7.4, 8.0 | `builds/php7.4/` | **Sí** |
| php8.1+ | PHP 8.1, 8.2, 8.3, 8.4, 8.5 | `builds/` | No |

Sin el post-update-cmd en PHP <8.1, `vendor/bin/githooks` apuntará al build de 8.1+ que no es compatible.

### Instalar dependencias

```bash
php{X.Y} composer.phar update
```

Esto instala GitHooks desde Packagist (rama RC) con el binario correcto en `vendor/bin/githooks`.

### Verificar instalación

```bash
php{X.Y} vendor/bin/githooks --version
```

Debe mostrar la versión correspondiente sin errores PHP.

## Paso 2: Ejecutar los tests

Consultar `TESTS.md` en la rama actual de html3. Contiene el catálogo completo de tests con comandos exactos, salidas esperadas, exit codes y SHAs de commit para cada test.

### Patrón de ejecución

Todos los tests se ejecutan DESDE `/var/www/html3`:

```bash
cd /var/www/html3
php{X.Y} vendor/bin/githooks <comando> [--config=<config>]
```

Sin `--config`, busca `githooks.php` en el CWD.

### Ir al commit correcto (si el test lo indica)

Algunos tests requieren un SHA específico. Antes de ejecutar:

```bash
git checkout <SHA>
```

Tras el test, volver a la rama de prueba:

```bash
git checkout {version}-prueba
```

## Entorno de pruebas: `/var/www/html3`

### Estructura

```
/var/www/html3/
├── composer.example.json   # Template — copiar a composer.json y editar
├── composer.phar           # Composer standalone (no depende del sistema)
├── src/
│   ├── CleanFile.php       # Código limpio — pasa todas las tools
│   ├── FileWithErrors.php  # Variable no usada, propiedad undefined — falla phpstan, phpmd
│   ├── SyntaxError.php     # Syntax error PHP — falla parallel-lint
│   ├── DuplicateA.php      # Código duplicado con DuplicateB — falla phpcpd
│   └── DuplicateB.php      # (mismo código que DuplicateA)
├── tests/
│   ├── PassingTest.php     # assertTrue(true) — pasa phpunit
│   └── FailingTest.php     # assertTrue(false) — falla phpunit
├── phpunit.xml             # Config PHPUnit (testsuite "default" → tests/)
├── psalm.xml               # Config Psalm (errorLevel 8, src/)
├── TESTS.md                # Catálogo completo de tests con SHAs
└── [configs por rama]      # githooks.php, githooks-v3.php, etc.
```

### Tools disponibles vs no disponibles

| Tool | Binario en vendor/bin/ | Notas |
|---|---|---|
| phpstan | Sí | Falla con `FileWithErrors.php` |
| phpcs / phpcbf | Sí | Falla con `FileWithErrors.php` (PSR12) |
| phpmd | Sí | Falla con `FileWithErrors.php` (unusedcode) |
| parallel-lint | Sí | Falla con `SyntaxError.php` |
| phpunit | **No** | `phpunit.xml` existe pero no hay binario — probar que el error es descriptivo |
| psalm | **No** | `psalm.xml` existe pero no hay binario — probar que el error es descriptivo |
| phpcpd | **No** | `DuplicateA/B.php` preparados para cuando se instale |

### Errores esperados por fichero

| Fichero | parallel-lint | phpcs | phpmd | phpstan |
|---|---|---|---|---|
| `CleanFile.php` | OK | OK | OK | OK |
| `FileWithErrors.php` | OK | KO (PSR12) | KO (unusedcode) | KO (undefined property) |
| `SyntaxError.php` | KO (syntax) | KO | KO | KO |
| `DuplicateA/B.php` | OK | OK | OK | OK |

### Configs de test adicionales

Cuando necesites una config v3 o una config rota que no exista ya, créalas en `/var/www/html3/` con nombre descriptivo.

**IMPORTANTE**: antes de crear una config, leer la implementación en `src/` para entender el formato exacto. Las condiciones de ejecución condicional (`only-on`, `exclude-on`, `only-files`, `exclude-files`) van en la sección `hooks` como parte del HookRef, NO en `options` del flow. Ejemplo:

```php
'hooks' => [
    'pre-commit' => [
        ['flow' => 'qa', 'only-on' => ['main', 'release/*']],
        ['job' => 'audit', 'only-files' => ['src/**/*.php']],
    ],
],
```

## Áreas de testing

### 1. Comandos básicos — Happy path

| Comando | Qué probar | Comando exacto |
|---|---|---|
| `flow <name>` | Flow existente | `GH flow qa --config=githooks-v3.php` |
| `flow <name>` | Flow inexistente | `... flow inventado --config=githooks-v3.php` → error + lista de flows |
| `job <name>` | Job existente | `... job parallel_lint --config=githooks-v3.php` |
| `job <name>` | Job inexistente | `... job inventado --config=githooks-v3.php` → error + lista de jobs |
| `conf:check` | Config válida v3 | `... conf:check --config=githooks-v3.php` → tabla con Status |
| `conf:check` | Config legacy | `... conf:check` (usa githooks.php v2) → tablas + warning migración |
| `conf:migrate` | Desde v2 a v3 | Copiar githooks.php a tmp, migrar, verificar backup + formato nuevo |
| `status` | Con y sin hooks | Instalar hooks, verificar synced; limpiar, verificar missing |
| `system:info` | Info del sistema | `... system:info` → CPUs, processes |
| `cache:clear` | Sin cachés | `... cache:clear --config=githooks-v3.php` → reporta "not found" |
| `cache:clear` | Con cachés | Crear `.phpcs.cache`, ejecutar, verificar borrado |
| `cache:clear` | Por flow | `... cache:clear qa --config=githooks-v3.php` → borra cachés de todos los jobs del flow |
| `tool` (legacy) | Deprecation warning | `... tool all full` → warning de deprecación |

### 2. Edge cases de configuración

Crear ficheros PHP en `/var/www/html3/` con la config de prueba.

| Caso | Config | Resultado esperado |
|---|---|---|
| Config vacía | `return [];` | Error o warning: jobs missing |
| Sin sección flows | Solo `jobs` y `hooks` | Funciona si hooks referencia jobs directamente |
| Flow referencia job inexistente | `'jobs' => ['noexiste']` | Warning, job skipped |
| Job con type inválido | `'type' => 'inventado'` | Error en parseo |
| Hook con evento git inválido | `'hooks' => ['inventado' => []]` | Error |
| Flow con nombre de hook git | `'flows' => ['pre-commit' => [...]]` | Error: nombre reservado |
| Options con processes negativo | `'processes' => -5` | Error o warning |
| Options con fail-fast no booleano | `'fail-fast' => 'quiza'` | Error o warning |
| Custom job sin script | `'type' => 'custom'` (sin `script`) | Error: script required |
| Job con paths array vacío | `'paths' => []` | No analiza nada, no crashea |
| Job duplicado en flow | `'jobs' => ['a', 'a']` | Se ejecuta 2 veces (verificar) |

### 3. Formatos de salida

| Test | Comando | Verificar |
|---|---|---|
| JSON válido | `flow qa --format=json` | Parseable. Estructura: `{flow, success, totalTime, passed, failed, jobs[{name, success, time, output, fixApplied}]}` |
| JUnit válido | `flow qa --format=junit` | XML válido: `<testsuites><testsuite name tests failures time><testcase name time/></testsuite></testsuites>` |
| Formato inválido | `flow qa --format=csv` | Warning "Unknown format" + fallback a texto |
| JSON en job individual | `job X --format=json` | Misma estructura JSON |
| Texto por defecto | `flow qa` | Output con colores, `Results: X/Y passed in Xs` |
| JSON dry-run | `flow qa --dry-run --format=json` | Incluye `command` en cada job. `time: "0ms"`, `output: ""` |
| JSON sin dry-run | `flow qa --format=json` | Incluye `command` con el comando real ejecutado |
| JSON con `--fast` y skipped | `flow qa --fast --format=json` (sin staged) | stdout es JSON parseable. Mensajes `⏩ was skipped` salen por stderr, no contaminan el payload |
| JSON `executionMode` | `flow qa --format=json` vs `--fast` vs `--fast-branch` | Campo `executionMode` refleja el modo real (`full`/`fast`/`fast-branch`) |

### 4. Flags especiales

| Flag | Comando | Qué verificar |
|---|---|---|
| `--exclude-jobs` | `flow qa --exclude-jobs=phpmd_src` | El job NO aparece en results |
| `--only-jobs` | `flow qa --only-jobs=parallel_lint` | Solo ese job se ejecuta |
| `--only-jobs` CSV | `flow qa --only-jobs=parallel_lint,phpcs_src` | Solo esos 2 |
| `--only-jobs` + `--exclude-jobs` | Ambos a la vez | Error: "cannot be used together" |
| `--only-jobs` vacío | `flow qa --only-jobs=` | Ejecuta todos (no filtra) |
| `--only-jobs` inexistente | `flow qa --only-jobs=inventado` | 0 jobs, exit 0 |
| `--only-jobs` no en flow | `flow lint --only-jobs=phpmd_src` | 0 jobs (phpmd_src no está en lint) |
| `--dry-run` en flow | `flow qa --dry-run` | Muestra nombre + comando, no ejecuta. Time=0ms |
| `--dry-run` en job | `job phpcs_src --dry-run` | Muestra comando, no ejecuta |
| `--fail-fast` | `flow qa --fail-fast` | Se detiene en primer fallo, restantes "skipped by fail-fast" |
| `--fast` | `flow qa --fast` | Jobs acelerables analizan solo staged files |
| `--fast-branch` | `flow qa --fast-branch` | Jobs acelerables analizan diff de rama vs principal |
| `--monitor` | `flow qa --monitor` | Muestra "Thread monitor: peak ~N threads (budget: M)" |
| `--processes` | `flow qa --processes=4` | Cambia paralelismo |
| `--config` | Cualquier comando con --config=path | Usa config indicada |

### 5. Combinaciones de flags

Los bugs más interesantes salen de combinar flags. Probar explícitamente:

| Combinación | Qué verificar |
|---|---|
| `--dry-run` + `--only-jobs` | Solo muestra los jobs filtrados |
| `--dry-run` + `--format=json` | JSON con campo `command`, sin ejecución |
| `--dry-run` + `--format=junit` | XML válido con time=0 |
| `--dry-run` + `--monitor` | No muestra monitor (budget=0 en dry-run) |
| `--dry-run` + `--fail-fast` | No afecta (nada falla en dry-run) |
| `--only-jobs` + `--fail-fast` | Solo ejecuta los jobs indicados, se detiene si falla |
| `--exclude-jobs` + `--format=json` | JSON sin el job excluido |

### 6. Comando cache:clear

| Test | Comando | Qué verificar |
|---|---|---|
| Sin cachés | `cache:clear --config=githooks-v3.php` | Reporta cada path como "(not found)" |
| Con cachés fichero | Crear `.phpcs.cache`, `.phpmd.cache`, ejecutar | Borra, reporta "deleted" |
| Job específico | `cache:clear phpcs_src --config=githooks-v3.php` | Solo borra caché de phpcs |
| Flow name | `cache:clear qa --config=githooks-v3.php` | Borra cachés de todos los jobs del flow |
| Múltiples nombres | `cache:clear phpcs_src phpmd_src` | Borra ambas |
| Nombre inexistente | `cache:clear inventado` | Warning + exit 1 |
| Mix válido + inexistente | `cache:clear phpcs_src inventado` | Borra phpcs + warning + exit 1 |
| Directorio (phpstan) | Crear dir `{sys_get_temp_dir}/phpstan/`, ejecutar | Borra recursivamente |
| Config legacy | `cache:clear` (usa githooks.php v2) | Error: "requires v3 format" |

### 7. Validación profunda en conf:check

| Test | Qué verificar |
|---|---|
| Config válida (todo existe) | Tabla Jobs con columna Status: todos `✔` |
| Executable inexistente | Status: "executable 'X' not found" |
| Path inexistente | Status: "path 'X' not found" |
| Config file inexistente | Status: "config file 'X' not found" |
| Rules como fichero existente | `✔` |
| Rules como nombres CSV (`cleancode,codesize`) | `✔` (no intenta validar como fichero) |
| Standard como nombre simbólico (`PSR12`) | `✔` (no es un fichero, no debe dar warning) |
| Tool no instalada (phpunit, psalm) | Status: "executable 'X' not found" — error descriptivo, no crash |

### 8. Auto-detección de executablePath

| Test | CWD | Config | Resultado esperado |
|---|---|---|---|
| Con vendor/bin/ | `/var/www/html3` | Job sin `executablePath` | Usa `vendor/bin/phpstan` |
| Sin vendor/bin/ | `/tmp` | Job sin `executablePath` | Cae a nombre simple (`phpstan`) |
| executablePath explícito | Cualquiera | `'executablePath' => '/custom/path'` | Respeta el valor, no auto-detecta |
| Custom job | Cualquiera | `'type' => 'custom', 'script' => '...'` | No aplica auto-detección |
| Script job | Cualquiera | `'type' => 'script'` | No aplica auto-detección |

### 9. Tools no instaladas

El proyecto html3 no tiene phpunit ni psalm instalados. Verificar que el comportamiento es correcto:

| Test | Config | Resultado esperado |
|---|---|---|
| Job phpunit sin binario | `'type' => 'phpunit', 'config' => 'phpunit.xml'` | Error descriptivo, no crash |
| Job psalm sin binario | `'type' => 'psalm', 'config' => 'psalm.xml'` | Error descriptivo, no crash |
| conf:check con tool sin binario | Config con phpunit/psalm | Status: "executable not found" |
| dry-run con tool sin binario | `--dry-run` con phpunit | Muestra comando (no ejecuta, no falla) |

### 10. Hooks

| Test | Comando | Resultado esperado |
|---|---|---|
| Instalar hooks | `hook --config=githooks-v3.php` | Crea `.githooks/` + configura `core.hooksPath` |
| Estado tras instalar | `status --config=githooks-v3.php` | Shows synced |
| Limpiar hooks | `hook:clean` | Borra `.githooks/` + desactiva `core.hooksPath` |
| Estado tras limpiar | `status --config=githooks-v3.php` | Shows missing |
| hook:run sin hooks | `hook:run pre-commit` (config sin sección hooks) | Warning "No hooks section" |
| hook:run evento inexistente | `hook:run inventado` | Warning "No flows or jobs configured" |
| Commit real con hook OK | Instalar hook, stage CleanFile.php, commit | Hook dispara, pasa, commit se crea |
| Commit real con hook KO | Instalar hook, stage SyntaxError.php, commit | Hook dispara, falla, commit bloqueado |

**Teardown hooks**: `git config --unset core.hooksPath; rm -rf .githooks/`

### 11. Ejecución condicional (solo hooks)

Las condiciones van en la sección `hooks` como parte del HookRef. Se prueban via `hook:run <evento>`.

| Test | Config hooks | Resultado esperado |
|---|---|---|
| `only-on` rama coincide | `['flow' => 'qa', 'only-on' => ['3.x-*']]` en pre-commit | Ejecuta (rama 3.x-prueba coincide) |
| `only-on` rama NO coincide | `['flow' => 'qa', 'only-on' => ['main', 'develop']]` en pre-push | Skip |
| `exclude-on` rama coincide | `['flow' => 'qa', 'exclude-on' => ['3.x-*']]` en post-merge | Skip |
| `only-files` con match | `['flow' => 'qa', 'only-files' => ['src/**/*.php']]` + staged src/*.php | Ejecuta |
| `only-files` sin match | `['flow' => 'qa', 'only-files' => ['tests/**/*.php']]` + staged src/*.php | Skip |
| `exclude-files` excluye | `['only-files' => ['src/**'], 'exclude-files' => ['src/Clean*']]` + staged CleanFile.php | Skip |
| `exclude-on` prevails | `['only-on' => ['3.x-*'], 'exclude-on' => ['3.x-prueba']]` | Skip (exclude gana) |

### 12. Features v3.2 sin cobertura en release tests

Estas features están en el changelog de [3.2.0] pero no tienen test `@group release`. Probarlas a mano tras cada build nuevo del `.phar` hasta que se añadan al pipeline:

| # | Feature | Comando / setup | Qué verificar |
|---|---|---|---|
| 1 | `cores: N` propaga `--parallel` en phpcs | `flow qa --dry-run --format=json` con `phpcs_src` declarando `cores: 2` | El `jobs[].command` del JSON contiene `--parallel=2` |
| 2 | `cores: N` propaga `--processes` en paratest | `flow qa --dry-run --format=json` con `paratest_all` (`type: paratest`, `cores: 4`) | El `command` contiene `vendor/bin/paratest ... --processes=4` |
| 3 | `conf:check` warn de conflicto `cores` vs flag nativo | Config con `phpcs` + `parallel: 8` + `cores: 2` | Output incluye `'cores' overrides 'parallel'` |
| 4 | `--output=PATH` escribe fichero (los 4 formatos estructurados) | Para cada `FORMAT ∈ {json, junit, codeclimate, sarif}`: `flow qa --format=FORMAT --output=/tmp/qa.out` | Exit 0, fichero `/tmp/qa.out` contiene el payload esperado (JSON parseable, XML válido, …) |
| 5 | JUnit emite `<skipped>` para jobs skippados | `flow qa --fast --format=junit` con un job accelerable que no matchea staged files | Output contiene `<skipped message="..."/>` dentro del `<testcase>` correspondiente |
| 6 | GitLab CI annotations | `GITLAB_CI=true flow qa` (desde un test con error) | Output incluye markers `section_start:` / `section_end:` entre jobs |
| 7 | `conf:check` trunca commands a 80 chars | Config con un comando generado largo | Tabla de Jobs muestra `…` al final del comando; `githooks job X --dry-run` muestra el comando completo |
| 8 | Live streaming sequential (`flow --processes=1`) | `flow qa --processes=1` con varios jobs | Cada job emite su output en tiempo real con separadores `--- job_name ---` (identificador literal del job) entre ellos |
| 9 | Dashboard TTY paralelo | `flow qa --processes=4` en un terminal interactivo | Aparece dashboard con estados ⏺/⏳/✓/✗ y timers en vivo; al acabar, colapsa al resumen |
| 10 | `--fast --format=<estructurado>` no contamina stdout | `flow qa --fast --format=json` (sin staged) 1>stdout 2>stderr | `stdout` es JSON parseable; mensajes `⏩ was skipped` solo en stderr |
| 11 | `executionMode` real en payload JSON | `flow qa --format=json` / `--fast` / `--fast-branch` | Campo `executionMode` refleja el modo del CLI, no siempre `full` |
| 12 | Code Climate `location.path` relativo | `flow qa --format=codeclimate` con errores en `src/...` | `location.path` es relativo al CWD (`src/errors/...`), no absoluto |

Cada item vale para los dos tiers (`builds/php7.4/` y `builds/`). Priorizar los 1-4 (nuevos de `gh-49-cores` + flags de output más utilizados); 5-9 son útiles pero preexistentes.

### 13. Migración y compatibilidad legacy

| Test | Comando | Resultado esperado |
|---|---|---|
| Migrar config v2 válida | Copiar githooks.php a tmp, `conf:migrate --config=tmp` | Genera formato v3, backup |
| Migrar config ya v3 | `conf:migrate --config=githooks-v3.php` | Detecta que ya es v3 |
| `tool all full` con config v2 | `tool all full` (usa githooks.php) | Ejecuta con deprecation warning |
| `tool all full` con config v3 | `tool all full --config=githooks-v3.php` | Error o redirección a `flow` |
| `conf:check` con config legacy | `conf:check` (usa githooks.php v2) | Tablas + warning de migración |

## Paso 3: Limpiar

```bash
cd /var/www/html3

# 1. Deshacer hooks de git
git config --unset core.hooksPath 2>/dev/null
rm -rf .githooks/ 2>/dev/null

# 2. Limpiar staged + working tree (por si algún test dejó restos)
git restore --staged . 2>/dev/null
git checkout -- . 2>/dev/null
git clean -fd 2>/dev/null

# 3. Volver a la rama base y borrar la de prueba
git checkout {master|2.x}
git branch -D {version}-prueba

# 4. Verificar que html3 queda completamente limpio
git status
```

**El paso 4 es obligatorio.** No generar el reporte hasta confirmar que `git status` muestra working tree limpio (sin staged, sin modificados, sin untracked). Si queda algo sucio, limpiarlo antes de continuar.

**Protección**: nunca borrar `master` ni `2.x`.

### Teardown durante los tests

Cuando un test modifica ficheros (ej: `echo >> file && git add file` para probar `only-files`), el teardown inmediato tras ese test debe deshacer **tanto el stage como el working tree**:

```bash
git restore --staged <file> && git checkout -- <file>
```

`git checkout -- <file>` solo deshace cambios en working tree, **no limpia el staging area**. Si hiciste `git add`, necesitas `git restore --staged` primero.

## Formato de reporte

Al terminar, generar una tabla resumen. Usar la columna Resultado para distinguir entre estados:

| # | Área | Test | Resultado | Severidad | Notas |
|---|---|---|---|---|---|
| 1 | flow | --only-jobs un job | OK | | Solo ejecuta el indicado |
| 2 | conf:check | standard PSR12 | BUG CORREGIDO | Media | Falso positivo: validaba nombre simbólico como fichero |
| 3 | flow | --exclude-jobs | BUG | Alta | Se define en signature pero no filtra |

**Valores de Resultado:**
- **OK**: funciona como se espera
- **BUG**: no funciona, pendiente de corregir
- **BUG CORREGIDO**: encontrado y corregido durante el testing
- **SKIP**: no se pudo probar (falta dependencia, entorno, etc.)

**Severidades (solo para BUG/BUG CORREGIDO):**
- **Critica**: crashea, pierde datos, rompe la ejecución normal
- **Alta**: funcionalidad documentada que no funciona
- **Media**: edge case mal manejado pero con workaround
- **Baja**: cosmético, mensaje confuso, comportamiento no ideal
