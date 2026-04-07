# Catálogo de Tests Funcionales — GitHooks

Catálogo reproducible de tests funcionales para GitHooks v2.8 y v3.0.

## Cómo usar

1. **Elegir rama** según la versión a probar:
   ```bash
   git checkout 2.x      # Tests v2.8
   git checkout master    # Tests v3.0
   ```

2. **Ir al commit** del test:
   ```bash
   git checkout <SHA>
   ```

3. **Ejecutar el comando** desde `/var/www/html3`:
   ```bash
   cd /var/www/html3
   <comando del test>
   ```

4. **Verificar** la salida y el exit code (`echo $?`).

## Binarios

| Versión | Binario | Notas |
|---|---|---|
| 2.8 | `php7.4 vendor/bin/githooks` | Instalado via Composer en html3 |
| 3.0 | `php7.4 /var/www/html1/githooks` | Binario en desarrollo |

## Ramas y commits

### Rama `2.x` (v2.8)

| SHA | Descripción |
|---|---|
| `d09e7e3` | Base: source files + composer |
| `435cad2` | + githooks.php (config principal v2) |
| `aa8b476` | + failfast-githooks.php |
| `5090094` | + pertool-githooks.php |
| `f439254` | + custom/githooks.php |
| `d6b846b` | + configs rotas (empty, no-tools, bad-exec, conflict) |

### Rama `master` (v3.0)

| SHA | Descripción |
|---|---|
| `d09e7e3` | Base: source files + composer |
| `d2dadd9` | + githooks-v3.php (config principal v3) |
| `1802458` | + githooks.php + githooks-v2-migrate.php (legacy) |
| `5ab9f60` | + githooks-missing-tools.php (phpunit/psalm) |
| `1ab3ace` | + edge cases (empty, bad-type, bad-path, etc.) |

---

## Tests v2.8

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php7.4 vendor/bin/githooks`

### Ejecución básica de tools

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-001 | tool | Ejecutar todas las tools en modo full | `GH tool all full` | Ejecuta phpstan, parallel-lint, phpcs, phpcbf, phpmd, my-script. Fallan parallel-lint (SyntaxError), phpcs (formatting), phpmd (unused var), phpstan (undefined prop) | 1 | `435cad2` | githooks.php |
| V28-002 | tool | Ejecutar todas las tools en modo fast | `GH tool all fast` | Mismo que V28-001 pero solo analiza ficheros modificados (staged/unstaged). Sin cambios staged puede dar 0 errores | 0/1 | `435cad2` | githooks.php |
| V28-003 | tool | Tool individual: phpcs | `GH tool phpcs` | Falla: FileWithErrors.php tiene errores PSR12 (indentación, espacios) | 1 | `435cad2` | githooks.php |
| V28-004 | tool | Tool individual: parallel-lint | `GH tool parallel-lint` | Falla: SyntaxError.php tiene `$a = ;` | 1 | `435cad2` | githooks.php |
| V28-005 | tool | Tool individual: phpstan | `GH tool phpstan` | Falla: FileWithErrors.php accede a `$x->undefinedProperty` | 1 | `435cad2` | githooks.php |
| V28-006 | tool | Tool individual: phpmd | `GH tool phpmd` | Falla: FileWithErrors.php tiene `$unused` sin usar | 1 | `435cad2` | githooks.php |
| V28-007 | tool | Tool individual en modo fast | `GH tool phpcs fast` | Modo fast: solo analiza ficheros modificados. Sin cambios puede pasar | 0/1 | `435cad2` | githooks.php |
| V28-008 | tool | Tool inexistente | `GH tool inventado` | Error: tool "inventado" no encontrada o no configurada | 1 | `435cad2` | githooks.php |

### Flags CLI del comando tool

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-009 | flags | --ignoreErrorsOnExit=true en all | `GH tool all full --ignoreErrorsOnExit=true` | Ejecuta todas las tools, reporta errores pero exit code 0 | 0 | `435cad2` | githooks.php |
| V28-010 | flags | --ignoreErrorsOnExit=false en all | `GH tool all full --ignoreErrorsOnExit=false` | Ejecuta todas, falla con exit 1 (como por defecto) | 1 | `435cad2` | githooks.php |
| V28-011 | flags | --failFast=true en all | `GH tool all full --failFast=true` | Se detiene tras la primera tool que falle. Tools restantes "skipped by failFast" | 1 | `435cad2` | githooks.php |
| V28-012 | flags | --processes=2 en all | `GH tool all full --processes=2` | Ejecuta 2 tools en paralelo. Resultado similar a V28-001 | 1 | `435cad2` | githooks.php |
| V28-013 | flags | --paths override a fichero limpio | `GH tool phpstan --paths=src/CleanFile.php` | Solo analiza CleanFile.php que no tiene errores → pasa | 0 | `435cad2` | githooks.php |
| V28-014 | flags | --otherArguments extra | `GH tool phpstan --otherArguments="--no-progress"` | Pasa --no-progress a phpstan. Funciona igual pero sin barra de progreso | 1 | `435cad2` | githooks.php |
| V28-015 | flags | --config con ruta a subdirectorio | `GH tool all full --config=custom/githooks.php` | Usa config de custom/. Ejecuta solo phpcs y parallel-lint | 1 | `f439254` | custom/githooks.php |

### Comportamiento failFast

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-016 | failFast | failFast en config — tools restantes skipped | `GH tool all full -c failfast-githooks.php` | parallel-lint falla (failFast=true en config), phpcs y my-script se saltan con "skipped by failFast" | 1 | `aa8b476` | failfast-githooks.php |
| V28-017 | failFast | failFast + ignoreErrors conflict | `GH tool all full -c githooks-conflict.php` | Warning: failFast e ignoreErrorsOnExit ambos true. failFast prevalece | 1 | `d6b846b` | githooks-conflict.php |

### Ejecución per-tool

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-018 | per-tool | phpcs en fast, parallel-lint en full | `GH tool all full -c pertool-githooks.php` | phpcs usa modo fast (override per-tool), parallel-lint usa full (del global) | 1 | `5090094` | pertool-githooks.php |

### conf:check

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-019 | conf:check | Config válida por defecto | `GH conf:check` | Muestra tabla Options y tabla Tools con todas las tools configuradas. Sin errores | 0 | `435cad2` | githooks.php |
| V28-020 | conf:check | Config válida ruta custom | `GH conf:check -c custom/githooks.php` | Muestra config de custom/. 2 tools (phpcs, parallel-lint) | 0 | `f439254` | custom/githooks.php |
| V28-021 | conf:check | Config con tool sin binario | `GH conf:check -c githooks-bad-exec.php` | Muestra warning/error: executable '/usr/bin/nonexistent-phpstan' no encontrado | 0/1 | `d6b846b` | githooks-bad-exec.php |
| V28-022 | conf:check | Config vacía | `GH conf:check -c githooks-empty.php` | Error o warning: no hay Tools configuradas | 0/1 | `d6b846b` | githooks-empty.php |

### Hooks

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-023 | hook | Instalar pre-commit hook | `GH hook` | Crea script en .git/hooks/pre-commit | 0 | `435cad2` | githooks.php |
| V28-024 | hook | Verificar hook instalado | `ls -la .git/hooks/pre-commit` | Fichero existe y es ejecutable | 0 | `435cad2` | - |
| V28-025 | hook | Limpiar hook | `GH hook:clean` | Borra .git/hooks/pre-commit | 0 | `435cad2` | - |
| V28-026 | hook | Instalar hook no-default | `GH hook post-commit` | Crea .git/hooks/post-commit | 0 | `435cad2` | githooks.php |

### conf:init

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-027 | conf:init | Crear config por defecto | Renombrar githooks.php, ejecutar `GH conf:init` | Genera githooks.php desde template. Detecta tools en vendor/bin | 0 | `435cad2` | - |

### Error handling

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-028 | error | Config vacía | `GH tool all full -c githooks-empty.php` | Error: no hay Tools configuradas | 1 | `d6b846b` | githooks-empty.php |
| V28-029 | error | Config sin Tools | `GH tool all full -c githooks-no-tools.php` | Error: sección Tools no encontrada | 1 | `d6b846b` | githooks-no-tools.php |
| V28-030 | error | Config sin Options | `GH tool all full -c githooks-no-options.php` | Funciona con defaults (execution=full, processes=1) | 0/1 | `d6b846b` | githooks-no-options.php |
| V28-031 | error | Tool con executable inexistente | `GH tool all full -c githooks-bad-exec.php` | Error de ejecución: phpstan no encontrado en ruta | 1 | `d6b846b` | githooks-bad-exec.php |
| V28-032 | error | Config file inexistente | `GH tool all full -c noexiste.php` | Error: fichero de configuración no encontrado | 1 | `d6b846b` | - |

### phpcbf y script

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V28-033 | phpcbf | phpcbf corrige errores de estilo | `GH tool phpcbf` | Intenta corregir errores PSR12 en FileWithErrors.php. Reporta ficheros corregidos | 0/1 | `435cad2` | githooks.php |
| V28-034 | script | Script con nombre custom (my-script) | `GH tool my-script` | Ejecuta `echo Script tool works!`. Output contiene "Script tool works!" | 0 | `435cad2` | githooks.php |
| V28-035 | script | Script tool en tool all | `GH tool all full` | my-script aparece en la lista de tools ejecutadas con su output | 0/1 | `435cad2` | githooks.php |

---

## Tests v3.0

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php7.4 /var/www/html1/githooks`

### Flow — happy path

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-001 | flow | Flow qa completo (4 jobs) | `GH flow qa --config=githooks-v3.php` | Ejecuta parallel_lint, phpcs_src, phpmd_src, phpstan_src. Varios fallan. Muestra "Results: X/4 passed" | 1 | `d2dadd9` | githooks-v3.php |
| V30-002 | flow | Flow lint con fail-fast en config | `GH flow lint --config=githooks-v3.php` | Ejecuta parallel_lint, phpcs_src. fail-fast=true en config del flow. Si parallel_lint falla, phpcs_src se salta | 1 | `d2dadd9` | githooks-v3.php |
| V30-003 | flow | Flow inexistente | `GH flow inventado --config=githooks-v3.php` | Error: flow "inventado" no encontrado. Muestra lista de flows disponibles (qa, lint) | 1 | `d2dadd9` | githooks-v3.php |

### Job — happy path

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-004 | job | Job individual: parallel_lint | `GH job parallel_lint --config=githooks-v3.php` | Falla: SyntaxError.php tiene error de sintaxis | 1 | `d2dadd9` | githooks-v3.php |
| V30-005 | job | Job individual: phpstan_src | `GH job phpstan_src --config=githooks-v3.php` | Falla: FileWithErrors.php accede a undefinedProperty | 1 | `d2dadd9` | githooks-v3.php |
| V30-006 | job | Job inexistente | `GH job inventado --config=githooks-v3.php` | Error: job "inventado" no encontrado. Muestra lista de jobs disponibles | 1 | `d2dadd9` | githooks-v3.php |

### Formatos de salida

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-007 | format | JSON válido en flow | `GH flow qa --config=githooks-v3.php --format=json` | JSON parseable con: flow, success, totalTime, passed, failed, jobs[{name, success, time, output}] | 1 | `d2dadd9` | githooks-v3.php |
| V30-008 | format | JUnit XML válido en flow | `GH flow qa --config=githooks-v3.php --format=junit` | XML con `<testsuites><testsuite name tests failures time><testcase/></testsuite></testsuites>` | 1 | `d2dadd9` | githooks-v3.php |
| V30-009 | format | Formato inválido → fallback | `GH flow qa --config=githooks-v3.php --format=csv` | Warning "Unknown format" + fallback a texto normal | 1 | `d2dadd9` | githooks-v3.php |
| V30-010 | format | JSON en job individual | `GH job phpstan_src --config=githooks-v3.php --format=json` | JSON con misma estructura que flow pero un solo job | 1 | `d2dadd9` | githooks-v3.php |
| V30-011 | format | Texto por defecto | `GH flow qa --config=githooks-v3.php` | Output con colores/unicode, línea "Results: X/Y passed in Zs" | 1 | `d2dadd9` | githooks-v3.php |
| V30-012 | format | JSON dry-run incluye campo command | `GH flow qa --config=githooks-v3.php --dry-run --format=json` | JSON con campo `command` en cada job. time="0ms", output="" | 0 | `d2dadd9` | githooks-v3.php |
| V30-013 | format | JSON sin dry-run NO incluye command | `GH flow qa --config=githooks-v3.php --format=json` | JSON SIN campo `command` en los jobs (o con campo vacío) | 1 | `d2dadd9` | githooks-v3.php |

### Flags individuales

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-014 | flags | --exclude-jobs un job | `GH flow qa --exclude-jobs=phpmd_src --config=githooks-v3.php` | Ejecuta 3 jobs (sin phpmd_src). "Results: X/3 passed" | 1 | `d2dadd9` | githooks-v3.php |
| V30-015 | flags | --only-jobs un job | `GH flow qa --only-jobs=parallel_lint --config=githooks-v3.php` | Solo ejecuta parallel_lint. "Results: X/1 passed" | 1 | `d2dadd9` | githooks-v3.php |
| V30-016 | flags | --only-jobs CSV (2 jobs) | `GH flow qa --only-jobs=parallel_lint,phpcs_src --config=githooks-v3.php` | Ejecuta solo parallel_lint y phpcs_src. "Results: X/2 passed" | 1 | `d2dadd9` | githooks-v3.php |
| V30-017 | flags | --only-jobs + --exclude-jobs → error | `GH flow qa --only-jobs=parallel_lint --exclude-jobs=phpmd_src --config=githooks-v3.php` | Error: "cannot be used together" o similar | 1 | `d2dadd9` | githooks-v3.php |
| V30-018 | flags | --only-jobs vacío → ejecuta todos | `GH flow qa --only-jobs= --config=githooks-v3.php` | Ejecuta los 4 jobs (no filtra nada) | 1 | `d2dadd9` | githooks-v3.php |
| V30-019 | flags | --only-jobs inexistente → 0 jobs | `GH flow qa --only-jobs=inventado --config=githooks-v3.php` | 0 jobs ejecutados. Exit 0 (nada falló) | 0 | `d2dadd9` | githooks-v3.php |
| V30-020 | flags | --only-jobs no pertenece al flow | `GH flow lint --only-jobs=phpmd_src --config=githooks-v3.php` | 0 jobs (phpmd_src no está en flow lint). Exit 0 | 0 | `d2dadd9` | githooks-v3.php |
| V30-021 | flags | --dry-run en flow | `GH flow qa --dry-run --config=githooks-v3.php` | Muestra nombre + comando de cada job. No ejecuta. Time=0ms | 0 | `d2dadd9` | githooks-v3.php |
| V30-022 | flags | --dry-run en job | `GH job phpcs_src --dry-run --config=githooks-v3.php` | Muestra comando de phpcs. No ejecuta | 0 | `d2dadd9` | githooks-v3.php |
| V30-023 | flags | --fail-fast en flow | `GH flow qa --fail-fast --config=githooks-v3.php` | Se detiene en primer job que falle. Restantes "skipped by fail-fast" | 1 | `d2dadd9` | githooks-v3.php |
| V30-024 | flags | --fast en flow (dry-run) | `GH flow qa --fast --dry-run --config=githooks-fast.php` | Con fichero staged: paths de jobs acelerables sustituidos por ficheros filtrados. Sin staged: todos los jobs skipped | 0 | `9d5795a` | githooks-fast.php |
| V30-025 | flags | --monitor en flow | `GH flow qa --monitor --config=githooks-v3.php` | Muestra al final: "Thread monitor: peak ~N threads (budget: M)" | 1 | `d2dadd9` | githooks-v3.php |
| V30-026 | flags | --processes=1 (secuencial) | `GH flow qa --processes=1 --config=githooks-v3.php` | Ejecuta jobs secuencialmente (1 a la vez) | 1 | `d2dadd9` | githooks-v3.php |

### Combinaciones de flags

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-027 | combo | --dry-run + --only-jobs | `GH flow qa --dry-run --only-jobs=parallel_lint --config=githooks-v3.php` | Solo muestra el job parallel_lint (dry-run). No ejecuta | 0 | `d2dadd9` | githooks-v3.php |
| V30-028 | combo | --dry-run + --format=json | `GH flow qa --dry-run --format=json --config=githooks-v3.php` | JSON con campo `command`, time=0, output="" | 0 | `d2dadd9` | githooks-v3.php |
| V30-029 | combo | --dry-run + --format=junit | `GH flow qa --dry-run --format=junit --config=githooks-v3.php` | XML JUnit válido con time=0 para todos los testcases | 0 | `d2dadd9` | githooks-v3.php |
| V30-030 | combo | --dry-run + --monitor | `GH flow qa --dry-run --monitor --config=githooks-v3.php` | --monitor se ignora silenciosamente en dry-run (no hay threads que monitorizar, peak=0 y budget=0) | 0 | `d2dadd9` | githooks-v3.php |
| V30-031 | combo | --only-jobs + --fail-fast | `GH flow qa --only-jobs=phpcs_src,phpstan_src --fail-fast --config=githooks-v3.php` | Ejecuta solo 2 jobs. Si phpcs falla primero, phpstan se salta | 1 | `d2dadd9` | githooks-v3.php |
| V30-032 | combo | --exclude-jobs + --format=json | `GH flow qa --exclude-jobs=phpmd_src --format=json --config=githooks-v3.php` | JSON con 3 jobs (sin phpmd_src) | 1 | `d2dadd9` | githooks-v3.php |

### Fast mode (--fast)

Requiere `githooks-fast.php` en html3 (config con jobs estándar + custom con paths/accelerable + phpstan con accelerable=false + phpunit no acelerado + custom legacy sin paths).

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-061 | fast | Sin staged files → todos skip | `GH flow qa --fast --dry-run --config=githooks-fast.php` | Todos los jobs skipped: "no staged files match its paths." | 0 | `9d5795a` | githooks-fast.php |
| V30-062 | fast | Con staged file → paths filtrados (dry-run) | `GH flow qa --fast --dry-run --config=githooks-fast.php` | Cada job muestra el fichero staged, no el directorio (ej: `analyse src/CleanFile.php`) | 0 | `9d5795a` | githooks-fast.php |
| V30-063 | fast | Ejecución real con fichero limpio staged | `GH flow qa --fast --config=githooks-fast.php` | parallel_lint, phpcs, phpstan pasan. phpmd falla por vendor/symfony (no relacionado) | 1 | `9d5795a` | githooks-fast.php |
| V30-064 | fast | job --fast --dry-run | `GH job phpstan_src --fast --dry-run --config=githooks-fast.php` | Muestra `analyse src/CleanFile.php` | 0 | `9d5795a` | githooks-fast.php |
| V30-065 | fast | Custom job con executablePath+paths+accelerable | `GH flow custom_flow --fast --dry-run --config=githooks-fast.php` | custom_lint: `/bin/echo src/CleanFile.php --checked`. custom_legacy: `echo legacy-mode-ok` (sin cambio) | 0 | `9d5795a` | githooks-fast.php |
| V30-066 | fast | accelerable=false override ignora --fast | `GH flow override --fast --dry-run --config=githooks-fast.php` | phpstan_no_accel: `analyse src` (directorio completo, sin filtrar) | 0 | `9d5795a` | githooks-fast.php |
| V30-067 | fast | --fast + --format=json | `GH flow qa --fast --format=json --config=githooks-fast.php` | JSON válido. parallel_lint reporta "Checked 1 files" | 1 | `9d5795a` | githooks-fast.php |
| V30-068 | fast | --fast + --fail-fast | `GH flow qa --fast --fail-fast --config=githooks-fast.php` | Se detiene en primer fallo con paths filtrados | 1 | `9d5795a` | githooks-fast.php |
| V30-069 | fast | --fast + --exclude-jobs | `GH flow qa --fast --exclude-jobs=phpmd_src --dry-run --config=githooks-fast.php` | 3 jobs (sin phpmd_src), todos con fichero filtrado | 0 | `9d5795a` | githooks-fast.php |
| V30-070 | fast | Fichero eliminado no se pasa a tools | `GH flow qa --fast --dry-run --config=githooks-fast.php` | Con `git rm --cached` de un fichero: no aparece en ningún comando, todos skip si es el único staged | 0 | `9d5795a` | githooks-fast.php |
| V30-071 | fast | Mixed flow (acelerado + no acelerado) | `GH flow mixed --fast --dry-run --config=githooks-fast.php` | phpstan: fichero filtrado. phpunit: `-c phpunit.xml` (sin filtrar, no acelerado) | 0 | `9d5795a` | githooks-fast.php |
| V30-072 | fast | Sin --fast → paths completos (regresión) | `GH flow qa --dry-run --config=githooks-fast.php` | Todos los jobs usan `src` (directorio completo) | 0 | `9d5795a` | githooks-fast.php |
| V30-073 | fast | Staged file fuera de paths del job no se pasa | Stagear fichero en `database/` o `config/`, job con `paths: ['src']`. `GH flow qa --fast --dry-run --config=githooks-fast.php` | El job no recibe el fichero de otro directorio. Si no hay ficheros en `src/` staged, el job se salta | 0 | `9d5795a` | githooks-fast.php |

### conf:check

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-033 | conf:check | Config v3 válida | `GH conf:check --config=githooks-v3.php` | Tabla Jobs con Status ✔ para todos. Tabla Flows, Hooks | 0 | `d2dadd9` | githooks-v3.php |
| V30-034 | conf:check | Config legacy v2 → warning | `GH conf:check --config=githooks.php` | Tablas Options/Tools + warning de migración a v3 | 0 | `1802458` | githooks.php |
| V30-035 | conf:check | Tool sin binario (phpunit) | `GH conf:check --config=githooks-missing-tools.php` | Status: "executable 'phpunit' not found" para phpunit_tests y psalm_src | 0 | `5ab9f60` | githooks-missing-tools.php |
| V30-036 | conf:check | Path inexistente | `GH conf:check --config=githooks-bad-path.php` | Status: "path 'directorio_inexistente' not found" | 0 | `1ab3ace` | githooks-bad-path.php |

### conf:migrate

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-037 | conf:migrate | Migrar v2 a v3 | `GH conf:migrate --config=githooks-v2-migrate.php` | Genera formato v3 (hooks/flows/jobs). Crea backup .v2.bak | 0 | `1802458` | githooks-v2-migrate.php |
| V30-038 | conf:migrate | Migrar config ya v3 | `GH conf:migrate --config=githooks-v3.php` | Detecta que ya es v3. No migra | 1 | `1802458` | githooks-v3.php |

### cache:clear

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-039 | cache | Sin cachés → not found | `GH cache:clear --config=githooks-v3.php` | Reporta cada path como "(not found)" | 0 | `d2dadd9` | githooks-v3.php |
| V30-040 | cache | Con caché creada → deleted | `touch .phpcs.cache && GH cache:clear --config=githooks-v3.php` | Borra .phpcs.cache, reporta "deleted" | 0 | `d2dadd9` | githooks-v3.php |
| V30-041 | cache | Job específico | `GH cache:clear phpcs_src --config=githooks-v3.php` | Solo intenta borrar caché de phpcs_src | 0 | `d2dadd9` | githooks-v3.php |
| V30-042 | cache | Job inexistente | `GH cache:clear inventado --config=githooks-v3.php` | Warning: job no encontrado | 1 | `d2dadd9` | githooks-v3.php |

### Hooks

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-043 | hook | Instalar hooks v3 (core.hooksPath) | `GH hook --config=githooks-v3.php` | Crea .githooks/ + configura core.hooksPath | 0 | `d2dadd9` | githooks-v3.php |
| V30-044 | hook | Status tras instalar → synced | `GH status --config=githooks-v3.php` | pre-commit: synced (targets: qa) | 0 | `d2dadd9` | githooks-v3.php |
| V30-045 | hook | Limpiar hooks | `GH hook:clean` | Borra .githooks/ + desactiva core.hooksPath | 0 | `d2dadd9` | githooks-v3.php |
| V30-046 | hook | Status tras limpiar → missing | `GH status --config=githooks-v3.php` | pre-commit: missing | 0 | `d2dadd9` | githooks-v3.php |

### Status y system:info

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-047 | system | system:info muestra CPUs y processes | `GH system:info --config=githooks-v3.php` | Muestra CPUs detectadas, processes configurados, budget info | 0 | `d2dadd9` | githooks-v3.php |
| V30-048 | status | Status sin hooks instalados | `GH status --config=githooks-v3.php` | pre-commit: missing (no hay .githooks/) | 0 | `d2dadd9` | githooks-v3.php |

### Compatibilidad legacy

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-049 | legacy | tool all full con config v2 → deprecation | `GH tool all full --config=githooks.php` | Deprecation warning: "use flow/job instead". Ejecuta las tools igualmente | 1 | `1802458` | githooks.php |
| V30-050 | legacy | tool con config v3 | `GH tool all full --config=githooks-v3.php` | Error o redirección: config v3 no compatible con comando tool | 1 | `1802458` | githooks-v3.php |

### Edge cases de configuración

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-051 | edge | Config vacía (return []) | `GH flow qa --config=githooks-empty.php` | Error: no hay flows/jobs definidos | 1 | `1ab3ace` | githooks-empty.php |
| V30-052 | edge | Flow referencia job inexistente | `GH flow qa --config=githooks-bad-job-ref.php` | Warning: job "noexiste_job" no definido. Ejecuta phpcs_src | 0/1 | `1ab3ace` | githooks-bad-job-ref.php |
| V30-053 | edge | Job con type inválido | `GH flow qa --config=githooks-bad-type.php` | Error: type "inventado" no es una tool soportada | 1 | `1ab3ace` | githooks-bad-type.php |
| V30-054 | edge | Custom job sin script | `GH flow qa --config=githooks-custom-no-script.php` | Error: campo script requerido para type custom | 1 | `1ab3ace` | githooks-custom-no-script.php |
| V30-055 | edge | Job con paths vacío | `GH flow qa --config=githooks-empty-paths.php` | No analiza nada o error. No crashea | 0/1 | `1ab3ace` | githooks-empty-paths.php |

### Tools no instaladas

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-056 | missing | Job phpunit sin binario | `GH job phpunit_tests --config=githooks-missing-tools.php` | Error descriptivo: phpunit no encontrado. No crash | 1 | `5ab9f60` | githooks-missing-tools.php |
| V30-057 | missing | Job psalm sin binario | `GH job psalm_src --config=githooks-missing-tools.php` | Error descriptivo: psalm no encontrado. No crash | 1 | `5ab9f60` | githooks-missing-tools.php |
| V30-058 | missing | dry-run con tool sin binario | `GH job phpunit_tests --dry-run --config=githooks-missing-tools.php` | Muestra comando (no ejecuta). No falla | 0 | `5ab9f60` | githooks-missing-tools.php |

### Auto-detección de executablePath

| ID | Área | Test | Comando | Salida esperada | Exit | SHA | Config |
|---|---|---|---|---|---|---|---|
| V30-059 | exec | Desde html3 sin executablePath → vendor/bin | `GH job phpcs_src --dry-run --config=githooks-v3.php` | El comando mostrado usa vendor/bin/phpcs (auto-detectado) | 0 | `d2dadd9` | githooks-v3.php |
| V30-060 | exec | Con executablePath explícito → respeta | `GH job phpcs_src --dry-run --config=githooks-explicit-exec.php` | El comando usa vendor/bin/phpcs (el valor explícito, no auto-detectado) | 0 | `1ab3ace` | githooks-explicit-exec.php |

---

## Resumen

| Versión | Tests | Áreas cubiertas |
|---|---|---|
| v2.8 | 35 | tool, flags, failFast, per-tool, conf:check, hook, conf:init, error handling, phpcbf, script |
| v3.0 | 73 | flow, job, format, flags, combos, fast mode, conf:check, conf:migrate, cache, hooks, status, system:info, legacy, edge cases, missing tools, exec detection |
| **Total** | **108** | |
