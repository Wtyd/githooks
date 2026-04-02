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

Guía para hacer testing funcional exhaustivo del CLI de GitHooks v3.

## Principios de testing

1. **Probar como un QA tester hostil**: no verificar que los ficheros existen, EJECUTAR los comandos y comprobar el resultado real.
2. **Probar edge cases**: inputs vacíos, tipos incorrectos, valores límite, combinaciones imposibles.
3. **Probar en entorno real**: usar `/var/www/html3` como proyecto de prueba — es un proyecto Composer real con tools instaladas, ficheros con errores a propósito y configs variadas.
4. **No asumir que funciona**: si el Changelog dice que una feature existe, PROBARLA.
5. **El CWD importa**: la auto-detección de `executablePath` busca `vendor/bin/` relativo al directorio actual. Ejecutar desde `/var/www/html3` (tiene `vendor/bin/`) vs otro directorio da resultados diferentes.

## Entorno de pruebas: `/var/www/html3`

Proyecto Composer preparado para testing funcional. No hace falta montar nada.

### Estructura

```
/var/www/html3/
├── composer.json           # Proyecto "prueba/prueba" con tools QA en require-dev
├── vendor/bin/             # Binarios reales: phpstan, phpcs, phpcbf, phpmd, parallel-lint
├── src/
│   ├── CleanFile.php       # Código limpio — pasa todas las tools
│   ├── FileWithErrors.php  # Variable no usada, propiedad undefined — falla phpstan, phpmd
│   ├── SyntaxError.php     # Syntax error PHP — falla parallel-lint
│   ├── DuplicateA.php      # Código duplicado con DuplicateB — falla phpcpd
│   └── DuplicateB.php      # (mismo código que DuplicateA)
├── tests/
│   ├── PassingTest.php     # assertTrue(true) — pasa phpunit
│   └── FailingTest.php     # assertTrue(false) — falla phpunit
├── githooks.php            # Config v2 completa (phpstan, lint, phpcs, phpcbf, phpmd, script)
├── failfast-githooks.php   # Config v2 con failFast en parallel-lint
├── pertool-githooks.php    # Config v2 con fast mode por tool
├── custom/githooks.php     # Config v2 en subdirectorio
├── phpunit.xml             # Config PHPUnit (testsuite "default" → tests/)
└── psalm.xml               # Config Psalm (errorLevel 8, src/)
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

### Patrón de ejecución

Todos los tests usan este patrón (ejecutar DESDE `/var/www/html3`):

```bash
cd /var/www/html3
php7.4 /var/www/html1/githooks <comando> [--config=<config>]
```

Sin `--config`, busca `githooks.php` en el CWD (la config v2 del proyecto).

### Configs de test adicionales

Cuando necesites una config v3 o una config rota, créalas en `/var/www/html3/` con nombre descriptivo. Ejemplo de config v3 base:

```bash
cat > /var/www/html3/githooks-v3.php << 'PHPEOF'
<?php
return [
    'flows' => [
        'options' => ['processes' => 2, 'fail-fast' => false],
        'qa' => [
            'jobs' => ['parallel_lint', 'phpcs_src', 'phpmd_src', 'phpstan_src'],
        ],
        'lint' => [
            'options' => ['fail-fast' => true],
            'jobs' => ['parallel_lint', 'phpcs_src'],
        ],
    ],
    'hooks' => [
        'pre-commit' => ['qa'],
    ],
    'jobs' => [
        'parallel_lint' => [
            'type' => 'parallel-lint',
            'paths' => ['src'],
            'exclude' => ['vendor'],
        ],
        'phpcs_src' => [
            'type' => 'phpcs',
            'standard' => 'PSR12',
            'paths' => ['src'],
        ],
        'phpmd_src' => [
            'type' => 'phpmd',
            'paths' => ['src'],
            'rules' => 'unusedcode',
        ],
        'phpstan_src' => [
            'type' => 'phpstan',
            'level' => 0,
            'paths' => ['src'],
        ],
    ],
];
PHPEOF
```

Para configs rotas (executable inexistente, paths inválidos, etc.), crear como `githooks-broken.php`.

## Áreas de testing

### 1. Comandos básicos — Happy path

| Comando | Qué probar | Comando exacto |
|---|---|---|
| `flow <name>` | Flow existente | `php7.4 /var/www/html1/githooks flow qa --config=githooks-v3.php` |
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
| JSON dry-run | `flow qa --dry-run --format=json` | Incluye campo `command` en cada job. `time: "0ms"`, `output: ""` |
| JSON sin dry-run | `flow qa --format=json` | NO incluye campo `command` |

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
| `--fast` | `flow qa --fast` | `$GITHOOKS_STAGED_FILES` se pasa a custom jobs |
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
| Múltiples jobs | `cache:clear phpcs_src phpmd_src` | Borra ambas |
| Job inexistente | `cache:clear inventado` | Warning + "No jobs to clear" |
| Directorio (phpstan) | Crear dir `{sys_get_temp_dir}/phpstan/`, ejecutar | Borra recursivamente |
| Config legacy | `cache:clear` (usa githooks.php v2) | Error: "requires v3 format" |
| Sin --config | `cache:clear --config=githooks-v3.php` en CWD | Encuentra config |

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

### 11. Migración y compatibilidad legacy

| Test | Comando | Resultado esperado |
|---|---|---|
| Migrar config v2 válida | Copiar githooks.php a tmp, `conf:migrate --config=tmp` | Genera formato v3, backup |
| Migrar config ya v3 | `conf:migrate --config=githooks-v3.php` | Detecta que ya es v3 |
| `tool all full` con config v2 | `tool all full` (usa githooks.php) | Ejecuta con deprecation warning |
| `tool all full` con config v3 | `tool all full --config=githooks-v3.php` | Error o redirección a `flow` |
| `conf:check` con config legacy | `conf:check` (usa githooks.php v2) | Tablas + warning de migración |

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
