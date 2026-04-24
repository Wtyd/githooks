# Catálogo de Tests Funcionales — GitHooks

Catálogo reproducible de tests funcionales para GitHooks v2.8 y v3.0.

## Protocolo de ejecución

### 1. Crear rama de prueba

**NUNCA ejecutar tests destructivos directamente en `master` o `2.x`.**

```bash
cd /var/www/html3
git checkout master                  # o 2.x según la versión
git checkout -b 3.x-prueba          # crear rama desechable
```

### 2. Ir al commit del test (si aplica)

```bash
git checkout <SHA>
```

### 3. Ejecutar el comando

Desde `/var/www/html3`:
```bash
cd /var/www/html3
php7.4 /var/www/html1/githooks <comando> [--config=<config>]
```

Sustituir `php7.4` por la versión de PHP bajo la que se esté probando.

### 4. Verificar salida y exit code

```bash
echo $?
```

### 5. Ejecutar Teardown

Cada test que modifica estado tiene una columna **Teardown** con los comandos necesarios para limpiar. **Ejecutar siempre** antes del siguiente test.

### 6. Limpiar al terminar

```bash
cd /var/www/html3
git checkout master                  # volver a rama principal
git branch -D 3.x-prueba            # borrar rama de prueba
```

**Protección**: nunca borrar `master` ni `2.x`. El comando `git branch -D` solo acepta la rama de prueba.

## Binarios

| Versión | Binario                          | Notas                            |
| ------- | -------------------------------- | -------------------------------- |
| 2.8     | `php7.4 vendor/bin/githooks`     | Instalado via Composer en html3  |
| 3.0     | `php7.4 /var/www/html1/githooks` | Binario en desarrollo            |
| 3.1     | `php7.4 /var/www/html1/githooks` | Binario en desarrollo (rc-3.1.0) |

## Ramas y commits

### Rama `2.x` (v2.8)

| SHA       | Descripción                                           |
| --------- | ----------------------------------------------------- |
| `d09e7e3` | Base: source files + composer                         |
| `435cad2` | + githooks.php (config principal v2)                  |
| `aa8b476` | + failfast-githooks.php                               |
| `5090094` | + pertool-githooks.php                                |
| `f439254` | + custom/githooks.php                                 |
| `d6b846b` | + configs rotas (empty, no-tools, bad-exec, conflict) |

### Rama `master` (v3.0 + v3.1)

| SHA       | Descripción                                                                       |
| --------- | --------------------------------------------------------------------------------- |
| `d09e7e3` | Base: source files + composer                                                     |
| `d2dadd9` | + githooks-v3.php (config principal v3)                                           |
| `1802458` | + githooks.php + githooks-v2-migrate.php (legacy)                                 |
| `5ab9f60` | + githooks-missing-tools.php (phpunit/psalm)                                      |
| `1ab3ace` | + edge cases (empty, bad-type, bad-path, etc.)                                    |
| `30fe56f` | + githooks-prefix.php, githooks-prefix-perjob.php, githooks-local-test.php (v3.1) |

---

## Tests v2.8

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php7.4 vendor/bin/githooks`

### Ejecución básica de tools

| ID      | Área | Test                                  | Comando                 | Salida esperada                                                                                                                                                       | Exit | SHA       | Config       |
| ------- | ---- | ------------------------------------- | ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- | --------- | ------------ |
| V28-001 | tool | Ejecutar todas las tools en modo full | `GH tool all full`      | Ejecuta phpstan, parallel-lint, phpcs, phpcbf, phpmd, my-script. Fallan parallel-lint (SyntaxError), phpcs (formatting), phpmd (unused var), phpstan (undefined prop) | 1    | `435cad2` | githooks.php |
| V28-002 | tool | Ejecutar todas las tools en modo fast | `GH tool all fast`      | Mismo que V28-001 pero solo analiza ficheros modificados (staged/unstaged). Sin cambios staged puede dar 0 errores                                                    | 0/1  | `435cad2` | githooks.php |
| V28-003 | tool | Tool individual: phpcs                | `GH tool phpcs`         | Falla: FileWithErrors.php tiene errores PSR12 (indentación, espacios)                                                                                                 | 1    | `435cad2` | githooks.php |
| V28-004 | tool | Tool individual: parallel-lint        | `GH tool parallel-lint` | Falla: SyntaxError.php tiene `$a = ;`                                                                                                                                 | 1    | `435cad2` | githooks.php |
| V28-005 | tool | Tool individual: phpstan              | `GH tool phpstan`       | Falla: FileWithErrors.php accede a `$x->undefinedProperty`                                                                                                            | 1    | `435cad2` | githooks.php |
| V28-006 | tool | Tool individual: phpmd                | `GH tool phpmd`         | Falla: FileWithErrors.php tiene `$unused` sin usar                                                                                                                    | 1    | `435cad2` | githooks.php |
| V28-007 | tool | Tool individual en modo fast          | `GH tool phpcs fast`    | Modo fast: solo analiza ficheros modificados. Sin cambios puede pasar                                                                                                 | 0/1  | `435cad2` | githooks.php |
| V28-008 | tool | Tool inexistente                      | `GH tool inventado`     | Error: tool "inventado" no encontrada o no configurada                                                                                                                | 1    | `435cad2` | githooks.php |

### Flags CLI del comando tool

| ID      | Área  | Test                              | Comando                                            | Salida esperada                                                                  | Exit | SHA       | Config              |
| ------- | ----- | --------------------------------- | -------------------------------------------------- | -------------------------------------------------------------------------------- | ---- | --------- | ------------------- |
| V28-009 | flags | --ignoreErrorsOnExit=true en all  | `GH tool all full --ignoreErrorsOnExit=true`       | Ejecuta todas las tools, reporta errores pero exit code 0                        | 0    | `435cad2` | githooks.php        |
| V28-010 | flags | --ignoreErrorsOnExit=false en all | `GH tool all full --ignoreErrorsOnExit=false`      | Ejecuta todas, falla con exit 1 (como por defecto)                               | 1    | `435cad2` | githooks.php        |
| V28-011 | flags | --failFast=true en all            | `GH tool all full --failFast=true`                 | Se detiene tras la primera tool que falle. Tools restantes "skipped by failFast" | 1    | `435cad2` | githooks.php        |
| V28-012 | flags | --processes=2 en all              | `GH tool all full --processes=2`                   | Ejecuta 2 tools en paralelo. Resultado similar a V28-001                         | 1    | `435cad2` | githooks.php        |
| V28-013 | flags | --paths override a fichero limpio | `GH tool phpstan --paths=src/CleanFile.php`        | Solo analiza CleanFile.php que no tiene errores → pasa                           | 0    | `435cad2` | githooks.php        |
| V28-014 | flags | --otherArguments extra            | `GH tool phpstan --otherArguments="--no-progress"` | Pasa --no-progress a phpstan. Funciona igual pero sin barra de progreso          | 1    | `435cad2` | githooks.php        |
| V28-015 | flags | --config con ruta a subdirectorio | `GH tool all full --config=custom/githooks.php`    | Usa config de custom/. Ejecuta solo phpcs y parallel-lint                        | 1    | `f439254` | custom/githooks.php |

### Comportamiento failFast

| ID      | Área     | Test                                         | Comando                                     | Salida esperada                                                                                      | Exit | SHA       | Config                |
| ------- | -------- | -------------------------------------------- | ------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ---- | --------- | --------------------- |
| V28-016 | failFast | failFast en config — tools restantes skipped | `GH tool all full -c failfast-githooks.php` | parallel-lint falla (failFast=true en config), phpcs y my-script se saltan con "skipped by failFast" | 1    | `aa8b476` | failfast-githooks.php |
| V28-017 | failFast | failFast + ignoreErrors conflict             | `GH tool all full -c githooks-conflict.php` | Warning: failFast e ignoreErrorsOnExit ambos true. failFast prevalece                                | 1    | `d6b846b` | githooks-conflict.php |

### Ejecución per-tool

| ID      | Área     | Test                                 | Comando                                    | Salida esperada                                                              | Exit | SHA       | Config               |
| ------- | -------- | ------------------------------------ | ------------------------------------------ | ---------------------------------------------------------------------------- | ---- | --------- | -------------------- |
| V28-018 | per-tool | phpcs en fast, parallel-lint en full | `GH tool all full -c pertool-githooks.php` | phpcs usa modo fast (override per-tool), parallel-lint usa full (del global) | 1    | `5090094` | pertool-githooks.php |

### conf:check

| ID      | Área       | Test                        | Comando                                  | Salida esperada                                                                   | Exit | SHA       | Config                |
| ------- | ---------- | --------------------------- | ---------------------------------------- | --------------------------------------------------------------------------------- | ---- | --------- | --------------------- |
| V28-019 | conf:check | Config válida por defecto   | `GH conf:check`                          | Muestra tabla Options y tabla Tools con todas las tools configuradas. Sin errores | 0    | `435cad2` | githooks.php          |
| V28-020 | conf:check | Config válida ruta custom   | `GH conf:check -c custom/githooks.php`   | Muestra config de custom/. 2 tools (phpcs, parallel-lint)                         | 0    | `f439254` | custom/githooks.php   |
| V28-021 | conf:check | Config con tool sin binario | `GH conf:check -c githooks-bad-exec.php` | Muestra warning/error: executable '/usr/bin/nonexistent-phpstan' no encontrado    | 0/1  | `d6b846b` | githooks-bad-exec.php |
| V28-022 | conf:check | Config vacía                | `GH conf:check -c githooks-empty.php`    | Error o warning: no hay Tools configuradas                                        | 0/1  | `d6b846b` | githooks-empty.php    |

### Hooks

| ID      | Área | Test                     | Comando                        | Salida esperada                      | Exit | SHA       | Config       |
| ------- | ---- | ------------------------ | ------------------------------ | ------------------------------------ | ---- | --------- | ------------ |
| V28-023 | hook | Instalar pre-commit hook | `GH hook`                      | Crea script en .git/hooks/pre-commit | 0    | `435cad2` | githooks.php |
| V28-024 | hook | Verificar hook instalado | `ls -la .git/hooks/pre-commit` | Fichero existe y es ejecutable       | 0    | `435cad2` | -            |
| V28-025 | hook | Limpiar hook             | `GH hook:clean`                | Borra .git/hooks/pre-commit          | 0    | `435cad2` | -            |
| V28-026 | hook | Instalar hook no-default | `GH hook post-commit`          | Crea .git/hooks/post-commit          | 0    | `435cad2` | githooks.php |

### conf:init

| ID      | Área      | Test                     | Comando                                         | Salida esperada                                                 | Exit | SHA       | Config |
| ------- | --------- | ------------------------ | ----------------------------------------------- | --------------------------------------------------------------- | ---- | --------- | ------ |
| V28-027 | conf:init | Crear config por defecto | Renombrar githooks.php, ejecutar `GH conf:init` | Genera githooks.php desde template. Detecta tools en vendor/bin | 0    | `435cad2` | -      |

### Error handling

| ID      | Área  | Test                            | Comando                                       | Salida esperada                                     | Exit | SHA       | Config                  |
| ------- | ----- | ------------------------------- | --------------------------------------------- | --------------------------------------------------- | ---- | --------- | ----------------------- |
| V28-028 | error | Config vacía                    | `GH tool all full -c githooks-empty.php`      | Error: no hay Tools configuradas                    | 1    | `d6b846b` | githooks-empty.php      |
| V28-029 | error | Config sin Tools                | `GH tool all full -c githooks-no-tools.php`   | Error: sección Tools no encontrada                  | 1    | `d6b846b` | githooks-no-tools.php   |
| V28-030 | error | Config sin Options              | `GH tool all full -c githooks-no-options.php` | Funciona con defaults (execution=full, processes=1) | 0/1  | `d6b846b` | githooks-no-options.php |
| V28-031 | error | Tool con executable inexistente | `GH tool all full -c githooks-bad-exec.php`   | Error de ejecución: phpstan no encontrado en ruta   | 1    | `d6b846b` | githooks-bad-exec.php   |
| V28-032 | error | Config file inexistente         | `GH tool all full -c noexiste.php`            | Error: fichero de configuración no encontrado       | 1    | `d6b846b` | -                       |

### phpcbf y script

| ID      | Área   | Test                                 | Comando             | Salida esperada                                                                   | Exit | SHA       | Config       |
| ------- | ------ | ------------------------------------ | ------------------- | --------------------------------------------------------------------------------- | ---- | --------- | ------------ |
| V28-033 | phpcbf | phpcbf corrige errores de estilo     | `GH tool phpcbf`    | Intenta corregir errores PSR12 en FileWithErrors.php. Reporta ficheros corregidos | 0/1  | `435cad2` | githooks.php |
| V28-034 | script | Script con nombre custom (my-script) | `GH tool my-script` | Ejecuta `echo Script tool works!`. Output contiene "Script tool works!"           | 0    | `435cad2` | githooks.php |
| V28-035 | script | Script tool en tool all              | `GH tool all full`  | my-script aparece en la lista de tools ejecutadas con su output                   | 0/1  | `435cad2` | githooks.php |

---

## Tests v3.0

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php7.4 /var/www/html1/githooks`

### Flow — happy path

| ID      | Área | Test                              | Comando                                      | Salida esperada                                                                                                 | Exit | SHA       | Config          |
| ------- | ---- | --------------------------------- | -------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ---- | --------- | --------------- |
| V30-001 | flow | Flow qa completo (4 jobs)         | `GH flow qa --config=githooks-v3.php`        | Ejecuta parallel_lint, phpcs_src, phpmd_src, phpstan_src. Varios fallan. Muestra "Results: X/4 passed"          | 1    | `d2dadd9` | githooks-v3.php |
| V30-002 | flow | Flow lint con fail-fast en config | `GH flow lint --config=githooks-v3.php`      | Ejecuta parallel_lint, phpcs_src. fail-fast=true en config del flow. Si parallel_lint falla, phpcs_src se salta | 1    | `d2dadd9` | githooks-v3.php |
| V30-003 | flow | Flow inexistente                  | `GH flow inventado --config=githooks-v3.php` | Error: flow "inventado" no encontrado. Muestra lista de flows disponibles (qa, lint)                            | 1    | `d2dadd9` | githooks-v3.php |

### Job — happy path

| ID      | Área | Test                          | Comando                                         | Salida esperada                                                         | Exit | SHA       | Config          |
| ------- | ---- | ----------------------------- | ----------------------------------------------- | ----------------------------------------------------------------------- | ---- | --------- | --------------- |
| V30-004 | job  | Job individual: parallel_lint | `GH job parallel_lint --config=githooks-v3.php` | Falla: SyntaxError.php tiene error de sintaxis                          | 1    | `d2dadd9` | githooks-v3.php |
| V30-005 | job  | Job individual: phpstan_src   | `GH job phpstan_src --config=githooks-v3.php`   | Falla: FileWithErrors.php accede a undefinedProperty                    | 1    | `d2dadd9` | githooks-v3.php |
| V30-006 | job  | Job inexistente               | `GH job inventado --config=githooks-v3.php`     | Error: job "inventado" no encontrado. Muestra lista de jobs disponibles | 1    | `d2dadd9` | githooks-v3.php |

### Formatos de salida

| ID      | Área   | Test                                | Comando                                                       | Salida esperada                                                                                   | Exit | SHA       | Config          |
| ------- | ------ | ----------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ---- | --------- | --------------- |
| V30-007 | format | JSON válido en flow                 | `GH flow qa --config=githooks-v3.php --format=json`           | JSON parseable con: flow, success, totalTime, passed, failed, jobs[{name, success, time, output}] | 1    | `d2dadd9` | githooks-v3.php |
| V30-008 | format | JUnit XML válido en flow            | `GH flow qa --config=githooks-v3.php --format=junit`          | XML con `<testsuites><testsuite name tests failures time><testcase/></testsuite></testsuites>`    | 1    | `d2dadd9` | githooks-v3.php |
| V30-009 | format | Formato inválido → fallback         | `GH flow qa --config=githooks-v3.php --format=csv`            | Warning "Unknown format" + fallback a texto normal                                                | 1    | `d2dadd9` | githooks-v3.php |
| V30-010 | format | JSON en job individual              | `GH job phpstan_src --config=githooks-v3.php --format=json`   | JSON con misma estructura que flow pero un solo job                                               | 1    | `d2dadd9` | githooks-v3.php |
| V30-011 | format | Texto por defecto                   | `GH flow qa --config=githooks-v3.php`                         | Output con colores/unicode, línea "Results: X/Y passed in Zs"                                     | 1    | `d2dadd9` | githooks-v3.php |
| V30-012 | format | JSON dry-run incluye campo command  | `GH flow qa --config=githooks-v3.php --dry-run --format=json` | JSON con campo `command` en cada job. time="0ms", output=""                                       | 0    | `d2dadd9` | githooks-v3.php |
| V30-013 | format | JSON sin dry-run también incluye command | `GH flow qa --config=githooks-v3.php --format=json`       | JSON con campo `command` en cada job (el comando real ejecutado)                                  | 1    | `d2dadd9` | githooks-v3.php |

### Flags individuales

| ID      | Área  | Test                                 | Comando                                                                                  | Salida esperada                                                                                                      | Exit | SHA       | Config            |
| ------- | ----- | ------------------------------------ | ---------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ---- | --------- | ----------------- |
| V30-014 | flags | --exclude-jobs un job                | `GH flow qa --exclude-jobs=phpmd_src --config=githooks-v3.php`                           | Ejecuta 3 jobs (sin phpmd_src). "Results: X/3 passed"                                                                | 1    | `d2dadd9` | githooks-v3.php   |
| V30-015 | flags | --only-jobs un job                   | `GH flow qa --only-jobs=parallel_lint --config=githooks-v3.php`                          | Solo ejecuta parallel_lint. "Results: X/1 passed"                                                                    | 1    | `d2dadd9` | githooks-v3.php   |
| V30-016 | flags | --only-jobs CSV (2 jobs)             | `GH flow qa --only-jobs=parallel_lint,phpcs_src --config=githooks-v3.php`                | Ejecuta solo parallel_lint y phpcs_src. "Results: X/2 passed"                                                        | 1    | `d2dadd9` | githooks-v3.php   |
| V30-017 | flags | --only-jobs + --exclude-jobs → error | `GH flow qa --only-jobs=parallel_lint --exclude-jobs=phpmd_src --config=githooks-v3.php` | Error: "cannot be used together" o similar                                                                           | 1    | `d2dadd9` | githooks-v3.php   |
| V30-018 | flags | --only-jobs vacío → ejecuta todos    | `GH flow qa --only-jobs= --config=githooks-v3.php`                                       | Ejecuta los 4 jobs (no filtra nada)                                                                                  | 1    | `d2dadd9` | githooks-v3.php   |
| V30-019 | flags | --only-jobs inexistente → 0 jobs     | `GH flow qa --only-jobs=inventado --config=githooks-v3.php`                              | 0 jobs ejecutados. Exit 0 (nada falló)                                                                               | 0    | `d2dadd9` | githooks-v3.php   |
| V30-020 | flags | --only-jobs no pertenece al flow     | `GH flow lint --only-jobs=phpmd_src --config=githooks-v3.php`                            | 0 jobs (phpmd_src no está en flow lint). Exit 0                                                                      | 0    | `d2dadd9` | githooks-v3.php   |
| V30-021 | flags | --dry-run en flow                    | `GH flow qa --dry-run --config=githooks-v3.php`                                          | Muestra nombre + comando de cada job. No ejecuta. Time=0ms                                                           | 0    | `d2dadd9` | githooks-v3.php   |
| V30-022 | flags | --dry-run en job                     | `GH job phpcs_src --dry-run --config=githooks-v3.php`                                    | Muestra comando de phpcs. No ejecuta                                                                                 | 0    | `d2dadd9` | githooks-v3.php   |
| V30-023 | flags | --fail-fast en flow                  | `GH flow qa --fail-fast --config=githooks-v3.php`                                        | Se detiene en primer job que falle. Restantes "skipped by fail-fast"                                                 | 1    | `d2dadd9` | githooks-v3.php   |
| V30-024 | flags | --fast en flow (dry-run)             | `GH flow qa --fast --dry-run --config=githooks-fast.php`                                 | Con fichero staged: paths de jobs acelerables sustituidos por ficheros filtrados. Sin staged: todos los jobs skipped | 0    | `9d5795a` | githooks-fast.php |
| V30-025 | flags | --monitor en flow                    | `GH flow qa --monitor --config=githooks-v3.php`                                          | Muestra al final: "Thread monitor: peak ~N threads (budget: M)"                                                      | 1    | `d2dadd9` | githooks-v3.php   |
| V30-026 | flags | --processes=1 (secuencial)           | `GH flow qa --processes=1 --config=githooks-v3.php`                                      | Ejecuta jobs secuencialmente (1 a la vez)                                                                            | 1    | `d2dadd9` | githooks-v3.php   |

### Combinaciones de flags

| ID      | Área  | Test                           | Comando                                                                             | Salida esperada                                                                                    | Exit | SHA       | Config          |
| ------- | ----- | ------------------------------ | ----------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ---- | --------- | --------------- |
| V30-027 | combo | --dry-run + --only-jobs        | `GH flow qa --dry-run --only-jobs=parallel_lint --config=githooks-v3.php`           | Solo muestra el job parallel_lint (dry-run). No ejecuta                                            | 0    | `d2dadd9` | githooks-v3.php |
| V30-028 | combo | --dry-run + --format=json      | `GH flow qa --dry-run --format=json --config=githooks-v3.php`                       | JSON con campo `command`, time=0, output=""                                                        | 0    | `d2dadd9` | githooks-v3.php |
| V30-029 | combo | --dry-run + --format=junit     | `GH flow qa --dry-run --format=junit --config=githooks-v3.php`                      | XML JUnit válido con time=0 para todos los testcases                                               | 0    | `d2dadd9` | githooks-v3.php |
| V30-030 | combo | --dry-run + --monitor          | `GH flow qa --dry-run --monitor --config=githooks-v3.php`                           | --monitor se ignora silenciosamente en dry-run (no hay threads que monitorizar, peak=0 y budget=0) | 0    | `d2dadd9` | githooks-v3.php |
| V30-031 | combo | --only-jobs + --fail-fast      | `GH flow qa --only-jobs=phpcs_src,phpstan_src --fail-fast --config=githooks-v3.php` | Ejecuta solo 2 jobs. Si phpcs falla primero, phpstan se salta                                      | 1    | `d2dadd9` | githooks-v3.php |
| V30-032 | combo | --exclude-jobs + --format=json | `GH flow qa --exclude-jobs=phpmd_src --format=json --config=githooks-v3.php`        | JSON con 3 jobs (sin phpmd_src)                                                                    | 1    | `d2dadd9` | githooks-v3.php |

### Fast mode (--fast)

Requiere `githooks-fast.php` en html3. Los tests que necesitan staging requieren rama de prueba.

**Setup staging** (para V30-062 a V30-069, V30-071): `echo "// test" >> src/CleanFile.php && git add src/CleanFile.php`

**Teardown staging**: `git checkout src/CleanFile.php && git reset HEAD src/CleanFile.php 2>/dev/null`

| ID      | Área | Test                                            | Comando                                                                                                              | Salida esperada                                                                 | Exit | Teardown                                                               | Config            |
| ------- | ---- | ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ---- | ---------------------------------------------------------------------- | ----------------- |
| V30-061 | fast | Sin staged files → todos skip                   | `GH flow qa --fast --dry-run --config=githooks-fast.php`                                                             | Todos los jobs skipped: "no staged files match its paths."                      | 0    | —                                                                      | githooks-fast.php |
| V30-062 | fast | Con staged file → paths filtrados (dry-run)     | Setup staging + `GH flow qa --fast --dry-run --config=githooks-fast.php`                                             | Cada job muestra el fichero staged, no el directorio                            | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-063 | fast | Ejecución real con fichero limpio staged        | Setup staging + `GH flow qa --fast --config=githooks-fast.php`                                                       | parallel_lint, phpcs, phpstan pasan                                             | 1    | Teardown staging                                                       | githooks-fast.php |
| V30-064 | fast | job --fast --dry-run                            | Setup staging + `GH job phpstan_src --fast --dry-run --config=githooks-fast.php`                                     | Muestra `analyse src/CleanFile.php`                                             | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-065 | fast | Custom job con executablePath+paths+accelerable | Setup staging + `GH flow custom_flow --fast --dry-run --config=githooks-fast.php`                                    | custom_lint: `/bin/echo src/CleanFile.php --checked`. custom_legacy: sin cambio | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-066 | fast | accelerable=false override ignora --fast        | Setup staging + `GH flow override --fast --dry-run --config=githooks-fast.php`                                       | phpstan_no_accel: `analyse src` (directorio completo)                           | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-067 | fast | --fast + --format=json                          | Setup staging + `GH flow qa --fast --format=json --config=githooks-fast.php`                                         | JSON válido. parallel_lint "Checked 1 files"                                    | 1    | Teardown staging                                                       | githooks-fast.php |
| V30-068 | fast | --fast + --fail-fast                            | Setup staging + `GH flow qa --fast --fail-fast --config=githooks-fast.php`                                           | Se detiene en primer fallo                                                      | 1    | Teardown staging                                                       | githooks-fast.php |
| V30-069 | fast | --fast + --exclude-jobs                         | Setup staging + `GH flow qa --fast --exclude-jobs=phpmd_src --dry-run --config=githooks-fast.php`                    | 3 jobs, todos con fichero filtrado                                              | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-070 | fast | Fichero eliminado no se pasa                    | `git rm --cached src/DuplicateA.php && GH flow qa --fast --dry-run --config=githooks-fast.php`                       | Todos skip (eliminado no pasa filtro)                                           | 0    | `git reset HEAD src/DuplicateA.php && git checkout src/DuplicateA.php` | githooks-fast.php |
| V30-071 | fast | Mixed flow (acelerado + no acelerado)           | Setup staging + `GH flow mixed --fast --dry-run --config=githooks-fast.php`                                          | phpstan: fichero filtrado. phpunit: sin filtrar                                 | 0    | Teardown staging                                                       | githooks-fast.php |
| V30-072 | fast | Sin --fast → paths completos (regresión)        | `GH flow qa --dry-run --config=githooks-fast.php`                                                                    | Todos usan `src` (directorio completo)                                          | 0    | —                                                                      | githooks-fast.php |
| V30-073 | fast | Staged file fuera de paths del job no se pasa   | `echo "test" > /tmp/outside.php && git add /tmp/outside.php; GH flow qa --fast --dry-run --config=githooks-fast.php` | Jobs no reciben ficheros fuera de paths. Todos skip                             | 0    | `git reset HEAD /tmp/outside.php 2>/dev/null`                          | githooks-fast.php |

### conf:check

| ID      | Área       | Test                       | Comando                                             | Salida esperada                                                         | Exit | SHA       | Config                     |
| ------- | ---------- | -------------------------- | --------------------------------------------------- | ----------------------------------------------------------------------- | ---- | --------- | -------------------------- |
| V30-033 | conf:check | Config v3 válida           | `GH conf:check --config=githooks-v3.php`            | Tabla Jobs con Status ✔ para todos. Tabla Flows, Hooks                  | 0    | `d2dadd9` | githooks-v3.php            |
| V30-034 | conf:check | Config legacy v2 → warning | `GH conf:check --config=githooks.php`               | Tablas Options/Tools + warning de migración a v3                        | 0    | `1802458` | githooks.php               |
| V30-035 | conf:check | Tool sin binario (phpunit) | `GH conf:check --config=githooks-missing-tools.php` | Status: "executable 'phpunit' not found" para phpunit_tests y psalm_src | 0    | `5ab9f60` | githooks-missing-tools.php |
| V30-036 | conf:check | Path inexistente           | `GH conf:check --config=githooks-bad-path.php`      | Status: "path 'directorio_inexistente' not found"                       | 0    | `1ab3ace` | githooks-bad-path.php      |

### conf:migrate

| ID      | Área         | Test                | Comando                                                                                                 | Salida esperada                                           | Exit | Teardown                                                                 | Config               |
| ------- | ------------ | ------------------- | ------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- | ---- | ------------------------------------------------------------------------ | -------------------- |
| V30-037 | conf:migrate | Migrar v2 a v3      | `cp githooks.php /tmp/githooks-v2-migrate.php && GH conf:migrate --config=/tmp/githooks-v2-migrate.php` | Genera formato v3 (hooks/flows/jobs). Crea backup .v2.bak | 0    | `rm -f /tmp/githooks-v2-migrate.php /tmp/githooks-v2-migrate.php.v2.bak` | githooks.php (copia) |
| V30-038 | conf:migrate | Migrar config ya v3 | `GH conf:migrate --config=githooks-v3.php`                                                              | Detecta que ya es v3. No migra                            | 0    | —                                                                        | githooks-v3.php      |

### cache:clear

| ID      | Área  | Test                       | Comando                                                         | Salida esperada                        | Exit | SHA       | Config          |
| ------- | ----- | -------------------------- | --------------------------------------------------------------- | -------------------------------------- | ---- | --------- | --------------- |
| V30-039 | cache | Sin cachés → not found     | `GH cache:clear --config=githooks-v3.php`                       | Reporta cada path como "(not found)"   | 0    | `d2dadd9` | githooks-v3.php |
| V30-040 | cache | Con caché creada → deleted | `touch .phpcs.cache && GH cache:clear --config=githooks-v3.php` | Borra .phpcs.cache, reporta "deleted"  | 0    | `d2dadd9` | githooks-v3.php |
| V30-041 | cache | Job específico             | `GH cache:clear phpcs_src --config=githooks-v3.php`             | Solo intenta borrar caché de phpcs_src | 0    | `d2dadd9` | githooks-v3.php |
| V30-042 | cache | Job inexistente            | `GH cache:clear inventado --config=githooks-v3.php`             | Warning: job no encontrado             | 1    | `d2dadd9` | githooks-v3.php |

### Hooks

Ejecutar en orden. El Teardown de V30-045 limpia el estado para los siguientes tests.

| ID      | Área | Test                               | Comando                              | Salida esperada                             | Exit | Teardown                       | Config          |
| ------- | ---- | ---------------------------------- | ------------------------------------ | ------------------------------------------- | ---- | ------------------------------ | --------------- |
| V30-043 | hook | Instalar hooks v3 (core.hooksPath) | `GH hook --config=githooks-v3.php`   | Crea .githooks/ + configura core.hooksPath  | 0    | — (V30-044 necesita el estado) | githooks-v3.php |
| V30-044 | hook | Status tras instalar → synced      | `GH status --config=githooks-v3.php` | pre-commit: synced (targets: qa)            | 0    | — (V30-045 necesita el estado) | githooks-v3.php |
| V30-045 | hook | Limpiar hooks                      | `GH hook:clean`                      | Borra .githooks/ + desactiva core.hooksPath | 0    | — (este ES el teardown)        | githooks-v3.php |
| V30-046 | hook | Status tras limpiar → missing      | `GH status --config=githooks-v3.php` | pre-commit: missing                         | 0    | —                              | githooks-v3.php |

### Hook real (commit + revert)

Requiere rama de prueba. Verificar que el hook generado dispara GitHooks al commitear.

| ID      | Área | Test                                     | Comando                                                                                                                                      | Salida esperada                                                           | Exit | Teardown                                                                                     | Config          |
| ------- | ---- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ---- | -------------------------------------------------------------------------------------------- | --------------- |
| V30-074 | hook | Commit real dispara pre-commit           | `GH hook --config=githooks-v3.php && echo "// test" >> src/CleanFile.php && git add src/CleanFile.php && git commit -m "test hook"`          | El output incluye resultados de GitHooks (OK/KO). Si pasa, commit se crea | 0    | `git reset --hard HEAD~1 && git config --unset core.hooksPath && rm -rf .githooks/`          | githooks-v3.php |
| V30-075 | hook | Commit con hook que falla bloquea commit | `GH hook --config=githooks-v3.php && echo "// test" >> src/SyntaxError.php && git add src/SyntaxError.php && git commit -m "test hook fail"` | parallel_lint falla → commit NO se crea                                   | 1    | `git checkout src/SyntaxError.php && git config --unset core.hooksPath && rm -rf .githooks/` | githooks-v3.php |

### Conditional execution

Requiere config con condiciones `only-on`, `exclude-on`, `only-files`, `exclude-files`. Crear `githooks-conditional.php` en html3.

| ID      | Área | Test                                       | Comando                                                                                                                  | Salida esperada                          | Exit | Teardown         | Config                   |
| ------- | ---- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- | ---- | ---------------- | ------------------------ |
| V30-076 | cond | only-on rama que coincide                  | Estar en rama `3.x-prueba`. `GH hook:run pre-commit --config=githooks-conditional.php` (config con `only-on: ['3.x-*']`) | Flow se ejecuta (rama coincide con glob) | 0    | —                | githooks-conditional.php |
| V30-077 | cond | only-on rama que NO coincide               | Estar en rama `3.x-prueba`. Config con `only-on: ['main', 'develop']`                                                    | Flow se salta (rama no coincide)         | 0    | —                | githooks-conditional.php |
| V30-078 | cond | exclude-on rama que coincide               | Config con `exclude-on: ['3.x-*']`                                                                                       | Flow se salta (rama excluida)            | 0    | —                | githooks-conditional.php |
| V30-079 | cond | only-files con fichero staged que coincide | Setup staging (CleanFile.php) + config con `only-files: ['src/**/*.php']`                                                | Flow se ejecuta                          | 0/1  | Teardown staging | githooks-conditional.php |
| V30-080 | cond | only-files sin fichero staged que coincide | Setup staging (CleanFile.php) + config con `only-files: ['tests/**/*.php']`                                              | Flow se salta                            | 0    | Teardown staging | githooks-conditional.php |
| V30-081 | cond | exclude-files excluye fichero staged       | Setup staging (CleanFile.php) + config con `only-files: ['src/**'], exclude-files: ['src/Clean*']`                       | Flow se salta (fichero excluido)         | 0    | Teardown staging | githooks-conditional.php |
| V30-082 | cond | exclude-on prevails over only-on           | Config con `only-on: ['3.x-*'], exclude-on: ['3.x-prueba']`                                                              | Flow se salta (exclude prevails)         | 0    | —                | githooks-conditional.php |

### Job inheritance (extends) — ejecución real

| ID      | Área    | Test                                  | Comando                                                                         | Salida esperada                                | Exit | Teardown | Config               |
| ------- | ------- | ------------------------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------- | ---- | -------- | -------------------- |
| V30-083 | extends | Job hereda config de parent y ejecuta | `GH flow qa --config=githooks-extends.php` (con `phpmd_src extends phpmd_base`) | phpmd ejecuta con rules y paths heredados      | 0/1  | —        | githooks-extends.php |
| V30-084 | extends | Job overridea key de parent           | `GH flow qa --dry-run --config=githooks-extends.php` (child overridea `paths`)  | Comando muestra paths del child, no del parent | 0    | —        | githooks-extends.php |
| V30-085 | extends | Herencia encadenada A→B→C             | `GH flow qa --dry-run --config=githooks-extends.php` (A extends B, B extends C) | Comando refleja config resuelta desde C        | 0    | —        | githooks-extends.php |

### Status y system:info

| ID      | Área   | Test                                 | Comando                                   | Salida esperada                                              | Exit | SHA       | Config          |
| ------- | ------ | ------------------------------------ | ----------------------------------------- | ------------------------------------------------------------ | ---- | --------- | --------------- |
| V30-047 | system | system:info muestra CPUs y processes | `GH system:info --config=githooks-v3.php` | Muestra CPUs detectadas, processes configurados, budget info | 0    | `d2dadd9` | githooks-v3.php |
| V30-048 | status | Status sin hooks instalados          | `GH status --config=githooks-v3.php`      | pre-commit: missing (no hay .githooks/)                      | 0    | `d2dadd9` | githooks-v3.php |

### Compatibilidad legacy

| ID      | Área   | Test                                      | Comando                                     | Salida esperada                                                           | Exit | SHA       | Config          |
| ------- | ------ | ----------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------- | ---- | --------- | --------------- |
| V30-049 | legacy | tool all full con config v2 → deprecation | `GH tool all full --config=githooks.php`    | Deprecation warning: "use flow/job instead". Ejecuta las tools igualmente | 1    | `1802458` | githooks.php    |
| V30-050 | legacy | tool con config v3                        | `GH tool all full --config=githooks-v3.php` | Error o redirección: config v3 no compatible con comando tool             | 1    | `1802458` | githooks-v3.php |

### Edge cases de configuración

| ID      | Área | Test                            | Comando                                             | Salida esperada                                            | Exit | SHA       | Config                        |
| ------- | ---- | ------------------------------- | --------------------------------------------------- | ---------------------------------------------------------- | ---- | --------- | ----------------------------- |
| V30-051 | edge | Config vacía (return [])        | `GH flow qa --config=githooks-empty.php`            | Error: no hay flows/jobs definidos                         | 1    | `1ab3ace` | githooks-empty.php            |
| V30-052 | edge | Flow referencia job inexistente | `GH flow qa --config=githooks-bad-job-ref.php`      | Warning: job "noexiste_job" no definido. Ejecuta phpcs_src | 0/1  | `1ab3ace` | githooks-bad-job-ref.php      |
| V30-053 | edge | Job con type inválido           | `GH flow qa --config=githooks-bad-type.php`         | Error: type "inventado" no es una tool soportada           | 1    | `1ab3ace` | githooks-bad-type.php         |
| V30-054 | edge | Custom job sin script           | `GH flow qa --config=githooks-custom-no-script.php` | Error: campo script requerido para type custom             | 1    | `1ab3ace` | githooks-custom-no-script.php |
| V30-055 | edge | Job con paths vacío             | `GH flow qa --config=githooks-empty-paths.php`      | No analiza nada o error. No crashea                        | 0/1  | `1ab3ace` | githooks-empty-paths.php      |

### Tools no instaladas

| ID      | Área    | Test                         | Comando                                                              | Salida esperada                                    | Exit | SHA       | Config                     |
| ------- | ------- | ---------------------------- | -------------------------------------------------------------------- | -------------------------------------------------- | ---- | --------- | -------------------------- |
| V30-056 | missing | Job phpunit sin binario      | `GH job phpunit_tests --config=githooks-missing-tools.php`           | Error descriptivo: phpunit no encontrado. No crash | 1    | `5ab9f60` | githooks-missing-tools.php |
| V30-057 | missing | Job psalm sin binario        | `GH job psalm_src --config=githooks-missing-tools.php`               | Error descriptivo: psalm no encontrado. No crash   | 1    | `5ab9f60` | githooks-missing-tools.php |
| V30-058 | missing | dry-run con tool sin binario | `GH job phpunit_tests --dry-run --config=githooks-missing-tools.php` | Muestra comando (no ejecuta). No falla             | 0    | `5ab9f60` | githooks-missing-tools.php |

### Auto-detección de executablePath

| ID      | Área | Test                                        | Comando                                                          | Salida esperada                                                         | Exit | SHA       | Config                     |
| ------- | ---- | ------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------- | ---- | --------- | -------------------------- |
| V30-059 | exec | Desde html3 sin executablePath → vendor/bin | `GH job phpcs_src --dry-run --config=githooks-v3.php`            | El comando mostrado usa vendor/bin/phpcs (auto-detectado)               | 0    | `d2dadd9` | githooks-v3.php            |
| V30-060 | exec | Con executablePath explícito → respeta      | `GH job phpcs_src --dry-run --config=githooks-explicit-exec.php` | El comando usa vendor/bin/phpcs (el valor explícito, no auto-detectado) | 0    | `1ab3ace` | githooks-explicit-exec.php |

### Execution modes (full / fast / fast-branch)

Requiere config `githooks-execution.php` en html3 con modos de ejecución por job y flow. Ejemplo:

```php
return [
    'hooks' => [
        'command' => 'php7.4 vendor/bin/githooks',
        'pre-commit' => [
            ['flow' => 'qa', 'execution' => 'fast'],
        ],
        'pre-push' => ['qa'],
    ],
    'flows' => [
        'options' => ['fail-fast' => false, 'processes' => 1],
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src', 'phpmd_src']],
        'mixed' => ['execution' => 'fast', 'jobs' => ['phpstan_src', 'phpcs_full']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
        'phpcs_src' => ['type' => 'phpcs', 'standard' => 'PSR12', 'paths' => ['src']],
        'phpmd_src' => ['type' => 'phpmd', 'paths' => ['src'], 'rules' => 'unusedcode'],
        'phpcs_full' => ['type' => 'phpcs', 'standard' => 'PSR12', 'paths' => ['src'], 'execution' => 'full'],
    ],
];
```

Y config `githooks-fast-branch.php` con `main-branch` y `fast-branch-fallback`:

```php
return [
    'flows' => [
        'options' => ['main-branch' => 'master', 'fast-branch-fallback' => 'full'],
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
        'phpcs_src' => ['type' => 'phpcs', 'standard' => 'PSR12', 'paths' => ['src']],
    ],
];
```

**Setup staging** (para tests que necesitan staged files): `echo "// test" >> src/CleanFile.php && git add src/CleanFile.php`

**Teardown staging**: `git checkout src/CleanFile.php && git reset HEAD src/CleanFile.php 2>/dev/null`

#### CLI flag --fast-branch

| ID      | Área        | Test                                           | Comando                                                                        | Salida esperada                                                                     | Exit | Teardown | Config                   |
| ------- | ----------- | ---------------------------------------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- | ---- | -------- | ------------------------ |
| V30-086 | fast-branch | --fast-branch dry-run muestra ficheros de rama | `GH flow qa --fast-branch --dry-run --config=githooks-fast-branch.php`         | Jobs muestran ficheros diff de rama vs master (no solo staged)                      | 0    | —        | githooks-fast-branch.php |
| V30-087 | fast-branch | --fast-branch ejecución real                   | `GH flow qa --fast-branch --config=githooks-fast-branch.php`                   | Ejecuta análisis sobre ficheros diff rama. Resultado depende de errores en ficheros | 0/1  | —        | githooks-fast-branch.php |
| V30-088 | fast-branch | --fast-branch en job individual                | `GH job phpstan_src --fast-branch --dry-run --config=githooks-fast-branch.php` | Muestra ficheros del branch diff, no directorio completo                            | 0    | —        | githooks-fast-branch.php |
| V30-089 | fast-branch | --fast + --fast-branch → fast prevalece        | `GH flow qa --fast --fast-branch --dry-run --config=githooks-fast-branch.php`  | --fast prevalece (es el primer flag evaluado). Solo staged files                    | 0    | —        | githooks-fast-branch.php |
| V30-090 | fast-branch | --fast-branch + --format=json                  | `GH flow qa --fast-branch --format=json --config=githooks-fast-branch.php`     | JSON válido con resultados de branch diff                                           | 0/1  | —        | githooks-fast-branch.php |
| V30-091 | fast-branch | --fast-branch sin rama principal detectable    | (en entorno sin remote ni branch master/main)                                  | Fallback según `fast-branch-fallback` config                                        | 0/1  | —        | githooks-fast-branch.php |

#### Execution mode por configuración (job/flow)

| ID      | Área        | Test                                            | Comando                                                                                          | Salida esperada                                                                                 | Exit | Teardown         | Config                       |
| ------- | ----------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- | ---- | ---------------- | ---------------------------- |
| V30-092 | exec-config | Flow con execution=fast (dry-run)               | Setup staging + `GH flow mixed --dry-run --config=githooks-execution.php`                        | phpstan_src: paths filtrados a ficheros staged. phpcs_full (execution=full): usa `src` completo | 0    | Teardown staging | githooks-execution.php       |
| V30-093 | exec-config | Job execution=full override flow execution=fast | Setup staging + `GH flow mixed --dry-run --config=githooks-execution.php`                        | phpcs_full tiene `execution: full` → ignora el `fast` del flow. Muestra `src` completo          | 0    | Teardown staging | githooks-execution.php       |
| V30-094 | exec-config | Job sin execution hereda del flow               | Setup staging + `GH flow mixed --dry-run --config=githooks-execution.php`                        | phpstan_src (sin execution) hereda fast del flow → paths filtrados                              | 0    | Teardown staging | githooks-execution.php       |
| V30-095 | exec-config | Invocation mode override config                 | Setup staging + `GH flow mixed --fast-branch --dry-run --config=githooks-execution.php`          | --fast-branch del CLI overridea execution=fast del flow y execution=full del job                | 0    | Teardown staging | githooks-execution.php       |
| V30-096 | exec-config | Sin execution en config → full por defecto      | `GH flow qa --dry-run --config=githooks-execution.php`                                           | Sin --fast ni execution en config → todos los jobs usan paths completos                         | 0    | —                | githooks-execution.php       |
| V30-097 | exec-config | Job con execution=fast-branch (dry-run)         | `GH flow qa --dry-run --config=githooks-fast-branch-job.php` (un job con execution: fast-branch) | Ese job usa ficheros del branch diff                                                            | 0    | —                | githooks-fast-branch-job.php |

#### HookRef execution mode

| ID      | Área         | Test                                           | Comando                                                                               | Salida esperada                                                                         | Exit | Teardown         | Config                 |
| ------- | ------------ | ---------------------------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ---- | ---------------- | ---------------------- |
| V30-098 | hookref-exec | hook:run pre-commit con HookRef execution=fast | Setup staging + `GH hook:run pre-commit --config=githooks-execution.php`              | El hook ejecuta el flow con fast mode (paths filtrados a staged)                        | 0/1  | Teardown staging | githooks-execution.php |
| V30-099 | hookref-exec | hook:run pre-push sin execution → full         | `GH hook:run pre-push --config=githooks-execution.php`                                | El hook no tiene execution → ejecuta full (paths completos)                             | 0/1  | —                | githooks-execution.php |
| V30-100 | hookref-exec | hook:run pre-commit ya no fuerza fast mode     | `GH hook:run pre-commit --config=githooks-fast.php` (config sin execution en hookRef) | **Regresión clave**: pre-commit ejecuta en full mode (paths completos), NO fast forzado | 0/1  | —                | githooks-fast.php      |

#### Opciones de configuración (main-branch, fast-branch-fallback)

| ID      | Área    | Test                                              | Comando                                           | Salida esperada                                                         | Exit | SHA | Config                          |
| ------- | ------- | ------------------------------------------------- | ------------------------------------------------- | ----------------------------------------------------------------------- | ---- | --- | ------------------------------- |
| V30-101 | options | conf:check con main-branch y fast-branch-fallback | `GH conf:check --config=githooks-fast-branch.php` | No warnings para main-branch ni fast-branch-fallback (claves conocidas) | 0    | —   | githooks-fast-branch.php        |
| V30-102 | options | fast-branch-fallback inválido                     | Config con `'fast-branch-fallback' => 'turbo'`    | Error: "'fast-branch-fallback' must be 'fast' or 'full'"                | 1    | —   | githooks-bad-fallback.php       |
| V30-103 | options | execution inválido en job                         | Config con `'execution' => 'turbo'` en un job     | Error: "'execution' must be one of: full, fast, fast-branch"            | 1    | —   | githooks-bad-execution.php      |
| V30-104 | options | execution inválido en flow                        | Config con `'execution' => 123` en un flow        | Error similar                                                           | 1    | —   | githooks-bad-execution-flow.php |

---

## Tests v3.1

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php7.4 /var/www/html1/githooks`

Features v3.1: argumentos extra por CLI (`-- separator`), executable-prefix (global y per-job), local override (`githooks.local.php`).

### CLI extra arguments (`--` separator) — solo `job`

El separador `--` solo funciona con el comando `job`. En `flow` se ignora silenciosamente.

Usan configs existentes. No requieren SHA específico.

| ID      | Área       | Test                                        | Comando                                                                              | Salida esperada                                                   | Exit | Config                     |
| ------- | ---------- | ------------------------------------------- | ------------------------------------------------------------------------------------ | ----------------------------------------------------------------- | ---- | -------------------------- |
| V31-001 | extra-args | Job dry-run muestra extra args              | `GH job phpstan_src --dry-run --config=githooks-v3.php -- --memory-limit=2G`         | Comando incluye `--memory-limit=2G` al final                      | 0    | githooks-v3.php            |
| V31-002 | extra-args | Múltiples extra args                        | `GH job phpcs_src --dry-run --config=githooks-v3.php -- --colors --report=summary`   | Comando incluye `--colors` y `--report=summary`                   | 0    | githooks-v3.php            |
| V31-003 | extra-args | Flow ignora `--` args                       | `GH flow qa --dry-run --config=githooks-v3.php -- --no-cache`                        | Ningún job muestra `--no-cache`. Comandos normales sin args extra | 0    | githooks-v3.php            |
| V31-004 | extra-args | Sin `--` funciona normal (regresión)        | `GH job phpstan_src --dry-run --config=githooks-v3.php`                              | Comando NO incluye args extra                                     | 0    | githooks-v3.php            |
| V31-005 | extra-args | Ejecución real con extra args               | `GH job phpstan_src --config=githooks-clean.php -- --no-progress`                    | phpstan ejecuta con --no-progress. Pasa (src/clean sin errores)   | 0    | githooks-clean.php         |
| V31-006 | extra-args | Job `--` + --format=json                    | `GH job phpstan_src --dry-run --format=json --config=githooks-v3.php -- --extra`     | JSON parseable. Campo `command` incluye `--extra`                 | 0    | githooks-v3.php            |
| V31-007 | extra-args | Job ejecución real con múltiples extra args | `GH job phpstan_src --config=githooks-clean.php -- --no-progress --error-format=raw` | phpstan ejecuta con ambos extra args. Pasa (src/clean)            | 0    | githooks-clean.php         |
| V31-008 | extra-args | Job ejecución real con /bin/echo            | `GH job job_global --config=githooks-prefix-perjob.php -- --appended`                | Output contiene "src --appended". Extra args llegan al ejecutable | 0    | githooks-prefix-perjob.php |

### Executable prefix

Requieren `githooks-prefix.php` y `githooks-prefix-perjob.php` en html3.

| ID      | Área   | Test                                    | Comando                                                           | Salida esperada                                                                                                                             | Exit | SHA       | Config                     |
| ------- | ------ | --------------------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---- | --------- | -------------------------- |
| V31-009 | prefix | Prefix global en dry-run (flow)         | `GH flow qa --dry-run --config=githooks-prefix.php`               | Ambos jobs: `PREFIX vendor/bin/phpcs...` / `PREFIX vendor/bin/phpstan...`                                                                   | 0    | `30fe56f` | githooks-prefix.php        |
| V31-010 | prefix | Prefix global ejecución real            | `GH flow qa --config=githooks-prefix.php`                         | Exit 0 (echo imprime el comando). Output contiene "PREFIX"                                                                                  | 0    | `30fe56f` | githooks-prefix.php        |
| V31-011 | prefix | Per-job override del global             | `GH flow qa --dry-run --config=githooks-prefix-perjob.php`        | job_global: `GLOBAL /bin/echo`, job_custom: `LOCAL /bin/echo`, job_nopre: `/bin/echo src` sin prefix, job_empty: `/bin/echo src` sin prefix | 0    | `30fe56f` | githooks-prefix-perjob.php |
| V31-012 | prefix | Per-job null opt-out                    | `GH job job_nopre --dry-run --config=githooks-prefix-perjob.php`  | Comando empieza con `/bin/echo src`, sin GLOBAL                                                                                             | 0    | `30fe56f` | githooks-prefix-perjob.php |
| V31-013 | prefix | Per-job string vacío opt-out            | `GH job job_empty --dry-run --config=githooks-prefix-perjob.php`  | Comando empieza con `/bin/echo src`, sin GLOBAL                                                                                             | 0    | `30fe56f` | githooks-prefix-perjob.php |
| V31-014 | prefix | Prefix + --format=json                  | `GH flow qa --dry-run --format=json --config=githooks-prefix.php` | JSON con campo `command` que incluye PREFIX en ambos jobs                                                                                   | 0    | `30fe56f` | githooks-prefix.php        |
| V31-015 | prefix | conf:check con prefix → sin warnings    | `GH conf:check --config=githooks-prefix.php`                      | Tabla Jobs sin warnings. executable-prefix visible en Options                                                                               | 0    | `30fe56f` | githooks-prefix.php        |
| V31-016 | prefix | conf:check sin prefix → no muestra fila | `GH conf:check --config=githooks-v3.php`                          | Options NO incluye executable-prefix                                                                                                        | 0    | —         | githooks-v3.php            |

### Local override (`githooks.local.php`)

Requiere `githooks-local-test.php` en html3. Los ficheros `.local.php` se crean/borran como setup/teardown de cada test.

**Setup local override** (contenido varía por test — se indica en columna "Setup .local.php"):
```bash
cat > githooks-local-test.local.php << 'LOCALEOF'
<?php return [<contenido del test>];
LOCALEOF
```

**Teardown local override**:
```bash
rm -f githooks-local-test.local.php
```

| ID      | Área  | Test                                           | Comando                                                       | Setup .local.php                                                        | Salida esperada                                                                       | Exit | Teardown      |
| ------- | ----- | ---------------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ---- | ------------- |
| V31-017 | local | Override inyecta executable-prefix             | `GH flow qa --dry-run --config=githooks-local-test.php`       | `'flows' => ['options' => ['executable-prefix' => '/bin/echo DOCKER']]` | Ambos jobs muestran `DOCKER vendor/bin/...` en su comando                             | 0    | rm .local.php |
| V31-018 | local | Override reemplaza otherArguments              | `GH job phpcs_src --dry-run --config=githooks-local-test.php` | `'jobs' => ['phpcs_src' => ['otherArguments' => '--overridden']]`       | Muestra `--overridden`, NO `--colors`                                                 | 0    | rm .local.php |
| V31-019 | local | Sin .local.php funciona normal (regresión)     | `GH flow qa --dry-run --config=githooks-local-test.php`       | (ninguno)                                                               | Sin prefix. phpcs muestra `--colors` en su comando                                    | 0    | —             |
| V31-020 | local | conf:check reporta local override              | `GH conf:check --config=githooks-local-test.php`              | `'flows' => ['options' => ['executable-prefix' => '/bin/echo DOCKER']]` | Output incluye "Local override: githooks-local-test.local.php"                        | 0    | rm .local.php |
| V31-021 | local | Merge profundo: override parcial mantiene keys | `GH job phpcs_src --dry-run --config=githooks-local-test.php` | `'jobs' => ['phpcs_src' => ['otherArguments' => '--tab-width=2']]`      | standard sigue siendo PSR12 (del base). otherArguments es `--tab-width=2` (del local) | 0    | rm .local.php |

### Combinadas

| ID      | Área     | Test                            | Comando                                                                            | Setup                                                                                  | Salida esperada                                                          | Exit | Teardown      |
| ------- | -------- | ------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ | ---- | ------------- |
| V31-022 | combined | Local override + prefix (flow)  | `GH flow qa --dry-run --config=githooks-local-test.php`                            | .local.php con `'flows' => ['options' => ['executable-prefix' => '/bin/echo DOCKER']]` | Comando: `DOCKER vendor/bin/phpcs ...` y `DOCKER vendor/bin/phpstan ...` | 0    | rm .local.php |
| V31-023 | combined | Job prefix + extra args         | `GH job phpcs_src --dry-run --config=githooks-prefix.php -- --strict`              | —                                                                                      | Comando: `PREFIX vendor/bin/phpcs ... --strict`                          | 0    | —             |
| V31-024 | combined | Job local override + extra args | `GH job phpcs_src --dry-run --config=githooks-local-test.php -- --report=summary`  | .local.php con `'flows' => ['options' => ['executable-prefix' => '/bin/echo DOCKER']]` | Comando: `DOCKER vendor/bin/phpcs ... --report=summary`                  | 0    | rm .local.php |
| V31-025 | combined | Job prefix + json + extra args  | `GH job phpcs_src --dry-run --format=json --config=githooks-prefix.php -- --extra` | —                                                                                      | JSON válido. Campo `command` incluye PREFIX y `--extra`                  | 0    | —             |

### Output visual (colores ANSI)

Verifican que ciertos mensajes usan los códigos de color correctos. Requieren terminal con soporte ANSI o capturar raw output.

**Nota**: Para capturar códigos ANSI, redirigir a fichero o usar `cat -v`:
```bash
GH flow qa --fast --config=githooks-fast.php 2>&1 | cat -v
# Los códigos ANSI aparecen como ^[[43m^[[30m (fondo naranja, texto negro)
```

| ID      | Área  | Test                                                      | Comando                                                       | Salida esperada                                                                                       | Exit | Config                   |
| ------- | ----- | --------------------------------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ---- | ------------------------ |
| V31-026 | color | Jobs skipped por --fast sin staged muestran fondo naranja | `GH flow qa --fast --config=githooks-fast.php 2>&1 \| cat -v` | Mensajes "was skipped" contienen `^[[43m^[[30m` (fondo naranja). NO salen como warning amarillo plano | 0    | githooks-fast.php        |
| V31-027 | color | Job referenciado inexistente muestra fondo naranja        | `GH flow qa --config=githooks-bad-job-ref.php 2>&1 \| cat -v` | Mensaje "will be skipped" contiene `^[[43m^[[30m`                                                     | 0/1  | githooks-bad-job-ref.php |

---

## Tests v3.2

Todos los comandos se ejecutan desde `/var/www/html3`.
Abreviatura: `GH` = `php{X.Y} /var/www/html1/githooks`

Features v3.2 **sin cobertura en `@group release`**. Ejecutar a mano tras cada build nuevo del `.phar` hasta que se añadan al pipeline.

Las configs de este bloque son nuevas — crearlas en html3 como describa cada caso (se listan inline para que queden autocontenidas).

### cores: N y paratest (nuevas en gh-49-cores)

| ID      | Área     | Test                                                  | Comando                                                                                | Salida esperada                                                                                                                                        | Exit | Config                             |
| ------- | -------- | ----------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---- | ---------------------------------- |
| V32-001 | cores    | `cores: N` propaga `--parallel=N` a phpcs (dry-run)   | `GH flow qa --dry-run --format=json --config=githooks-cores.php`                       | JSON parseable. `jobs[].command` del job phpcs_src contiene `--parallel=2`                                                                             | 0    | githooks-cores.php                 |
| V32-002 | cores    | `cores: N` propaga `--processes=N` a paratest         | `GH flow qa --dry-run --format=json --config=githooks-paratest.php`                    | JSON parseable. `jobs[].command` del job paratest_all contiene `vendor/bin/paratest` y `--processes=4`                                                 | 0    | githooks-paratest.php              |
| V32-003 | cores    | Conflict warning `cores` vs flag nativo en conf:check | `GH conf:check --config=githooks-cores-conflict.php`                                   | Output incluye `'cores' overrides 'parallel' (cores=2, parallel=8)`                                                                                    | 0/1  | githooks-cores-conflict.php        |
| V32-004 | cores    | `cores` solo reserva en phpstan (no tiene flag CLI)   | `GH flow qa --monitor --dry-run --config=githooks-cores-phpstan.php`                   | Monitor peak refleja el `cores` declarado. `jobs[].command` NO contiene flags desconocidos                                                             | 0    | githooks-cores-phpstan.php         |
| V32-005 | cores    | `cores` en custom job solo afecta budget              | `GH flow qa --monitor --dry-run --config=githooks-cores-custom.php`                    | Monitor peak refleja el `cores`. Command del custom no modificado                                                                                      | 0    | githooks-cores-custom.php          |
| V32-006 | cores    | Override en parallel: varios jobs con cores           | `GH flow qa --dry-run --format=json --config=githooks-cores-mixed.php --processes=10`  | Cada job recibe su `cores` exacto; los controllable reciben su flag nativo. `peakEstimatedThreads` = suma de todos                                     | 0    | githooks-cores-mixed.php           |

### Output unificado `--output=PATH`

| ID      | Área   | Test                                  | Comando                                                                                  | Salida esperada                                                                                   | Exit | Config                   |
| ------- | ------ | ------------------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ---- | ------------------------ |
| V32-007 | output | `--output=PATH` para `--format=json`   | `GH flow qa --format=json --output=/tmp/qa.json --config=githooks-v3.php`                 | Exit 0. Info "Report written to: /tmp/qa.json". Fichero contiene JSON parseable                   | 0    | githooks-v3.php          |
| V32-008 | output | `--output=PATH` para `--format=junit`  | `GH flow qa --format=junit --output=/tmp/qa.xml --config=githooks-v3.php`                 | Exit 0. Fichero contiene XML válido (`xmllint --noout /tmp/qa.xml`)                               | 0    | githooks-v3.php          |
| V32-009 | output | `--output=PATH` para `--format=codeclimate` | `GH flow qa --format=codeclimate --output=/tmp/qa-cc.json --config=githooks-v3.php`   | Exit 0. Fichero contiene array JSON (`head -c 1 /tmp/qa-cc.json` devuelve `[`)                    | 0    | githooks-v3.php          |
| V32-010 | output | `--output=PATH` para `--format=sarif`  | `GH flow qa --format=sarif --output=/tmp/qa.sarif --config=githooks-v3.php`              | Exit 0. Fichero contiene SARIF 2.1.0 (objeto JSON con `"version": "2.1.0"` y `"runs"`)            | 0    | githooks-v3.php          |
| V32-011 | output | Stdout por defecto en los 4 formatos  | `GH flow qa --format={json,junit,codeclimate,sarif} --config=githooks-v3.php` (x 4)      | Payload por stdout. No se crean ficheros `gl-code-quality-report.json` ni `githooks-results.sarif` | 0    | githooks-v3.php          |
| V32-012 | output | `--stdout` ignorado silenciosamente   | `GH flow qa --format=sarif --stdout --config=githooks-v3.php`                             | Igual que sin `--stdout`: payload a stdout, exit 0 (flag legacy ignorado)                         | 0    | githooks-v3.php          |

### Otras features v3.2 sin cobertura

| ID      | Área      | Test                                                  | Comando                                                                           | Salida esperada                                                                                                                     | Exit | Config                 |
| ------- | --------- | ----------------------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ---- | ---------------------- |
| V32-013 | junit     | `<skipped>` element para job skippado por fast mode   | `GH flow qa --fast --format=junit --config=githooks-fast.php` (sin staged)        | Output contiene `<skipped message="..."/>` dentro del `<testcase>` del job skippado                                                 | 0    | githooks-fast.php      |
| V32-014 | ci        | GitLab CI annotations                                 | `GITLAB_CI=true GH flow qa --config=githooks-failing.php 2>&1`                    | Output incluye markers `section_start:` / `section_end:` por cada job                                                               | 1    | githooks-failing.php   |
| V32-015 | confcheck | Truncation a 80 chars                                 | `GH conf:check --config=githooks-long-command.php`                                | Tabla Jobs: comando con `…` al final si excede 80 chars. `GH job X --dry-run` muestra el comando completo                           | 0    | githooks-long-command.php |
| V32-016 | output    | Live streaming en `flow --processes=1`                | `GH flow qa --processes=1 --config=githooks-v3.php`                               | Cada job emite output en tiempo real con separador `--- job_name ---` entre ellos (nombre literal del job, no capitalizado)         | 0    | githooks-v3.php        |
| V32-017 | output    | Dashboard TTY paralelo (validación visual)            | `GH flow qa --processes=4 --config=githooks-v3.php` (terminal interactivo)        | **Verificación visual imprescindible** (no cubrible por unit tests, ver DashboardOutputHandlerTest): (1) los glifos ⏳/⏺/✓/✗ aparecen como emojis, no como `?` o `□`; (2) los colores (gris queued, amarillo running, verde OK, rojo KO) se perciben; (3) el timer de los jobs en running avanza de forma fluida cada ~100ms; (4) el cursor vuelve arriba y reescribe en el mismo espacio — no se acumulan líneas en el scroll; (5) al terminar todos, el dashboard colapsa y sólo quedan las líneas finales de OK/KO en orden. | 0    | githooks-v3.php        |

### Formatos estructurados + fast mode

| ID      | Área     | Test                                                 | Comando                                                                               | Salida esperada                                                                                                                                     | Exit | Config            |
| ------- | -------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ---- | ----------------- |
| V32-018 | fast+fmt | `--fast --format=json` con skipped: stdout limpio    | `GH flow qa --fast --format=json --config=githooks-fast.php 1>/tmp/o 2>/tmp/e` (sin staged, sin TTY) | `/tmp/o` es JSON parseable (empieza por `{`). `/tmp/e` vacío (stderr silencioso sin TTY y sin `-v`). Mensajes `⏩ was skipped` solo aparecen con formato estructurado + stderr forzado a fichero en TTY | 0/1  | githooks-fast.php |
| V32-019 | fast+fmt | `--fast --format=junit` con skipped: stdout limpio   | `GH flow qa --fast --format=junit --config=githooks-fast.php 1>/tmp/o 2>/tmp/e` (sin staged, sin TTY) | `/tmp/o` empieza por `<?xml`. `/tmp/e` vacío. Sin contaminación cruzada                                                                               | 0/1  | githooks-fast.php |
| V32-024 | stderr | Progreso stderr con TTY                              | `GH flow qa --format=json --config=githooks-v3.php` (desde terminal interactivo)                    | stderr muestra `OK/KO jobname (time) [n/m]` + `Done. N/N`. stdout solo JSON                                                                          | 0/1  | githooks-v3.php |
| V32-025 | stderr | Progreso stderr silencio sin TTY                     | `GH flow qa --format=json --config=githooks-v3.php 2>/tmp/e` (redirigir stderr a fichero)            | `/tmp/e` vacío (sin TTY + sin `-v` → handler desactivado). stdout JSON intacto                                                                       | 0/1  | githooks-v3.php |
| V32-026 | stderr | Progreso stderr forzado con `-v`                     | `GH flow qa --format=json -v --config=githooks-v3.php 2>/tmp/e`                                      | `/tmp/e` contiene `OK/KO jobname [n/m]` + `Done. N/N`, aunque no haya TTY (CI-friendly)                                                              | 0/1  | githooks-v3.php |
| V32-027 | dry-run | Dry-run no emite progreso                            | `GH flow qa --dry-run --format=json --config=githooks-v3.php 2>/tmp/e`                              | `/tmp/e` vacío (dry-run no es ejecución real, no llama `onFlowStart`/`flush`). stdout JSON con `totalTime: "0ms"`                                    | 0    | githooks-v3.php |
| V32-020 | fast+fmt | JSON `executionMode` refleja flag real               | `GH flow qa --format=json --config=githooks-v3.php` vs `--fast` vs `--fast-branch`    | Campo `executionMode` del JSON vale `full` / `fast` / `fast-branch` según el flag. Nunca siempre `full`                                              | 0/1  | githooks-v3.php   |
| V32-021 | cc       | Code Climate `location.path` relativo al CWD         | `GH flow qa --format=codeclimate --config=githooks-v3.php` (con errores en `src/`)    | Cada issue tiene `location.path` relativo: `src/errors/SyntaxError.php`, no `/var/www/html3/src/errors/SyntaxError.php`                              | 1    | githooks-v3.php   |
| V32-022 | sarif    | SARIF `artifactLocation.uri` relativo al CWD         | `GH flow qa --format=sarif --config=githooks-v3.php` (con errores en `src/`)          | Cada result tiene `locations[].physicalLocation.artifactLocation.uri` relativo: `src/errors/SyntaxError.php`, no absoluto                            | 1    | githooks-v3.php   |
| V32-023 | fail-fast | Jobs no ejecutados por fail-fast aparecen como skipped en JSON | `GH flow qa --fail-fast --format=json --config=githooks-v3.php` | `jobs[]` contiene TODOS los del plan. Los no ejecutados tienen `"skipped": true` y `"skipReason": "skipped by fail-fast"`. `totalSkipped` > 0 | 1 | githooks-v3.php |

### Configs ejemplo (crear en html3)

```php
// githooks-cores.php
<?php
return [
    'jobs' => [
        'phpcs_src' => [
            'type' => 'phpcs',
            'executablePath' => 'vendor/bin/phpcs',
            'paths' => ['src'],
            'cores' => 2,
        ],
    ],
    'flows' => ['qa' => ['jobs' => ['phpcs_src'], 'options' => ['processes' => 8]]],
];

// githooks-paratest.php
<?php
return [
    'jobs' => [
        'paratest_all' => [
            'type' => 'paratest',
            'executablePath' => 'vendor/bin/paratest',
            'configuration' => 'phpunit.xml',
            'cores' => 4,
        ],
    ],
    'flows' => ['qa' => ['jobs' => ['paratest_all'], 'options' => ['processes' => 8]]],
];

// githooks-cores-conflict.php
<?php
return [
    'jobs' => [
        'phpcs_src' => [
            'type' => 'phpcs',
            'executablePath' => 'vendor/bin/phpcs',
            'paths' => ['src'],
            'parallel' => 8,
            'cores' => 2,
        ],
    ],
    'flows' => ['qa' => ['jobs' => ['phpcs_src']]],
];
```

Las demás configs siguen el mismo patrón adaptado a cada test. Nombrarlas tal como indica la columna Config.

---

## Resumen

| Versión   | Tests   | Áreas cubiertas                                                                                                                                                                                                                      |
| --------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| v2.8      | 35      | tool, flags, failFast, per-tool, conf:check, hook, conf:init, error handling, phpcbf, script                                                                                                                                         |
| v3.0      | 104     | flow, job, format, flags, combos, fast mode, fast-branch, execution modes, conf:check, conf:migrate, cache, hooks, hook real, conditional execution, extends, status, system:info, legacy, edge cases, missing tools, exec detection |
| v3.1      | 27      | extra-args, executable-prefix, local override, combined features, output visual (colores ANSI)                                                                                                                                       |
| v3.2      | 27      | cores override (phpcs/paratest/phpstan/custom/mixed), conflict warning, `--output=PATH` en los 4 formatos, stdout por defecto, `--stdout` ignorado, JUnit `<skipped>`, GitLab annotations, conf:check truncation, live streaming, dashboard TTY, fast+estructurado stdout limpio, executionMode real en JSON, Code Climate + SARIF paths relativos, fail-fast listado en JSON, stderr TTY/verbose/dry-run |
| **Total** | **193** |                                                                                                                                                                                                                                      |
