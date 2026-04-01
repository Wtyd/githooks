---
name: qa-tester
description: >
  Testing funcional y de integración de GitHooks como QA tester. Prueba todos los comandos CLI
  (flow, job, hook, conf:check, conf:migrate, status, system:info, tool), edge cases de configuración,
  formatos de salida, flags, y compatibilidad legacy. Genera configuraciones de test en /tmp.
  Usa esta skill cuando el usuario quiera probar, verificar, o validar funcionalidades del CLI,
  buscar bugs, hacer testing de aceptación, o cuando mencione "probar", "testear funcionalidad",
  "verificar comando", "QA", "edge cases", "testing funcional".
---

# QA Testing funcional de GitHooks

Guía para hacer testing funcional exhaustivo del CLI de GitHooks v3.

## Principios de testing

1. **Probar como un QA tester hostil**: no verificar que los ficheros existen, EJECUTAR los comandos y comprobar el resultado real.
2. **Probar edge cases**: inputs vacíos, tipos incorrectos, valores límite, combinaciones imposibles.
3. **Probar en entorno real**: usar el binario de desarrollo directamente, crear configuraciones temporales en `/tmp/githooks-test/`.
4. **No asumir que funciona**: si el Changelog dice que una feature existe, PROBARLA.

## Setup del entorno de test

```bash
# Crear directorio de pruebas
mkdir -p /tmp/githooks-test
cd /tmp/githooks-test
git init
mkdir -p src app qa

# Alias para ejecutar githooks desde cualquier directorio
# Los tests siempre usan: php7.4 /var/www/html1/githooks <comando> --config=/tmp/githooks-test/<config>
```

## Áreas de testing

### 1. Comandos básicos — Happy path

| Comando | Qué probar |
|---|---|
| `flow <name>` | Flow existente, inexistente, sin argumento |
| `job <name>` | Job existente, inexistente, sin argumento |
| `conf:check` | Con config válida v3, legacy, vacía, rota |
| `conf:migrate` | Desde v2 a v3, backup, formato correcto |
| `status` | Con y sin hooks instalados |
| `system:info` | Detecta CPUs, muestra processes |
| `hook` / `hook:clean` | Instalar, limpiar, reinstalar |
| `hook:run` | Con y sin hooks configurados |
| `tool` (legacy) | Deprecation warning visible |

### 2. Edge cases de configuración

Para cada test, crear un fichero PHP en `/tmp/githooks-test/` con la config de prueba.

| Caso | Config | Resultado esperado |
|---|---|---|
| Config vacía | `return [];` | Error: jobs missing |
| Sin sección flows | Solo jobs | ¿Funciona sin flows? |
| Flow referencia job inexistente | `'jobs' => ['noexiste']` | Warning, job skipped |
| Job con type inválido | `'type' => 'inventado'` | Error |
| Job con argumentos mal tipados | `'level' => 'string'`, `'paths' => 'string'` | Error o warning |
| Hook con evento git inválido | `'hooks' => ['inventado' => []]` | Error |
| Flow con nombre de hook git | `'flows' => ['pre-commit' => [...]]` | Error |
| Options con processes negativo | `'processes' => -5` | Error |
| Options con fail-fast no booleano | `'fail-fast' => 'quiza'` | Error |
| Custom job sin script | `'type' => 'custom'` (sin script) | Error |
| Job con paths array vacío | `'paths' => []` | ¿Qué pasa? |
| Job duplicado en flow | `'jobs' => ['a', 'a']` | ¿Se ejecuta 2 veces? |

### 3. Formatos de salida

| Test | Comando | Resultado esperado |
|---|---|---|
| JSON válido | `flow qa --format=json` | JSON parseable, estructura {flow, jobs, success} |
| JUnit válido | `flow qa --format=junit` | XML válido con testsuites/testcase |
| Formato inválido | `flow qa --format=csv` | Error o fallback |
| JSON en job individual | `job X --format=json` | JSON parseable |
| Texto por defecto | `flow qa` | Output con colores, Results: X/Y passed |

### 4. Flags especiales

| Flag | Comando | Qué verificar |
|---|---|---|
| `--exclude-jobs` | `flow qa --exclude-jobs=Phpunit` | El job NO se ejecuta |
| `--fail-fast` | `flow qa --fail-fast` | Se detiene en primer fallo |
| `--fast` | `flow qa --fast` | GITHOOKS_STAGED_FILES se pasa a custom jobs |
| `--monitor` | `flow qa --monitor` | Muestra thread report |
| `--processes` | `flow qa --processes=4` | Cambia paralelismo |
| `-c` / `--config` | Cualquier comando con -c | ¿Funciona el shortcut? |

### 5. Hooks

| Test | Comando | Resultado esperado |
|---|---|---|
| Instalar hooks | `hook` | Crea .githooks/ + configura core.hooksPath |
| Estado tras instalar | `status` | Shows synced |
| Limpiar hooks | `hook:clean` | Borra .githooks/ + desactiva core.hooksPath |
| Estado tras limpiar | `status` | Shows missing |
| hook:run sin config | `hook:run pre-commit` (sin hooks) | ¿Error o noop? |
| hook:run evento inválido | `hook:run inventado` | Error |

### 6. Migración

| Test | Comando | Resultado esperado |
|---|---|---|
| Migrar config v2 válida | `conf:migrate` | Genera formato v3, backup del original |
| Migrar config ya v3 | `conf:migrate` | ¿Detecta que ya es v3? |
| Migrar config vacía | `conf:migrate` | Error descriptivo |

### 7. Compatibilidad legacy

| Test | Comando | Resultado esperado |
|---|---|---|
| `tool all full` con config v3 | Con githooks.php v3 | ¿Error? ¿Funciona? |
| `tool phpstan full` con config v3 | Con githooks.php v3 | ¿Error descriptivo? |
| `conf:check` con config legacy | Con Options/Tools format | Muestra legacy + warning |

## Formato de reporte

Al terminar, generar una tabla resumen con:

| # | Área | Test | Resultado | Severidad | Descripción del bug |
|---|---|---|---|---|---|
| 1 | Flow | --exclude-jobs | BUG | Alta | Se define en signature pero no se implementa |
| 2 | CLI | -c shortcut | BUG | Media | No funciona en ningún comando |

Severidades:
- **Crítica**: crashea, pierde datos, rompe la ejecución normal
- **Alta**: funcionalidad documentada que no funciona
- **Media**: edge case mal manejado pero con workaround
- **Baja**: cosmético, mensaje confuso, comportamiento no ideal
