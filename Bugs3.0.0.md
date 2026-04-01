# Bugs v3.0.0 — Testing funcional

Resultado de testing QA manual ejecutando comandos reales contra configuraciones de prueba.

---

## Bugs encontrados

### #1 — `--exclude-jobs` no se aplica (ALTA)

**Comando:** `githooks flow qa --exclude-jobs="Phpunit,Phpcpd"`
**Esperado:** Los jobs listados no se ejecutan.
**Real:** Todos los jobs se ejecutan. La opción se ignora silenciosamente.

**Causa:** `FlowCommand::handle()` nunca lee `$this->option('exclude-jobs')`. La opción está definida en el signature (línea 24) pero no se pasa a `FlowPreparer::prepare()`, que tampoco tiene parámetro para ello.

**Ficheros:** `app/Commands/FlowCommand.php`, `src/Execution/FlowPreparer.php`

---

### #2 — `--fail-fast` del CLI no se aplica (ALTA)

**Comando:** `githooks flow qa --fail-fast` (con un job que falla en medio)
**Esperado:** La ejecución se detiene tras el primer fallo.
**Real:** Todos los jobs se ejecutan independientemente de los fallos.

**Causa:** Igual que #1 — `$this->option('fail-fast')` nunca se lee en `handle()`. El flag CLI no sobrescribe la opción `fail-fast` de la configuración del flow.

**Ficheros:** `app/Commands/FlowCommand.php`

**Test de reproducción:**
```php
// Config con 3 jobs: first (OK), fail_mid (exit 1), should_skip
// githooks flow qa --fail-fast
// Resultado: should_skip se ejecuta igualmente
```

---

### #3 — `--processes` del CLI no se aplica (ALTA)

**Comando:** `githooks flow qa --processes=4` (con config que tiene processes=1)
**Esperado:** Se ejecutan 4 jobs en paralelo.
**Real:** Se ejecutan en secuencial. `--monitor` confirma "budget: 1".

**Causa:** Mismo patrón — `$this->option('processes')` nunca se lee en `handle()`.

**Ficheros:** `app/Commands/FlowCommand.php`

**Test de reproducción:**
```bash
# 4 jobs de 200ms cada uno
# Sin --processes: totalTime 0.82s (secuencial)
# Con --processes=4: totalTime 0.82s (sigue secuencial)
# Con --monitor: "budget: 1" en ambos casos
```

---

### #4 — `githooks hook` instala hook legacy en vez de v3 (ALTA)

**Comando:** `githooks hook`
**Esperado:** Crea `.githooks/pre-commit` con script universal + configura `core.hooksPath`.
**Real:** Copia `hooks/default.php` a `.git/hooks/pre-commit` (comportamiento v2). No crea `.githooks/`. No configura `core.hooksPath`.

**Causa:** `CreateHookCommand` nunca se actualizó. Sigue usando `Storage::copy()` hacia `.git/hooks/`. La clase `HookInstaller` (que implementa el comportamiento v3 correctamente) existe en `src/Hooks/HookInstaller.php` pero ningún comando la usa — es código muerto.

**Ficheros:** `app/Commands/CreateHookCommand.php`, `src/Hooks/HookInstaller.php`

**Evidencia:**
```bash
$ githooks hook
# Output: "Hook pre-commit created"
$ cat .git/hooks/pre-commit
# Contiene el script legacy con `passthru('php vendor/bin/githooks tool all')`
$ git config core.hooksPath
# Vacío — no configurado
$ githooks status
# "hooks path: not configured"
```

---

### #5 — `conf:init` busca fichero dist inexistente (ALTA)

**Comando:** `githooks conf:init` (en directorio sin config)
**Esperado:** Genera `githooks.php` con formato v3.
**Real:** Error: "Failed to copy vendor/wtyd/githooks/qa/githooks.v3.dist.php to githooks.php"

**Causa:** `CreateConfigurationFileCommand` busca `githooks.v3.dist.php` (línea 32), que se eliminó durante el rewrite de la fase 3. El dist file real es `qa/githooks.dist.php` (ya en formato v3).

**Ficheros:** `app/Commands/CreateConfigurationFileCommand.php`

---

### #6 — Shortcut `-c` no funciona en ningún comando (MEDIA)

**Comando:** `githooks flow qa -c /ruta/config.php` (o cualquier comando con `-c`)
**Esperado:** Usa la config indicada.
**Real:** `The "-c" option does not exist.`

**Causa:** El formato `{-c|--config=}` en el signature de Laravel Zero no se parsea correctamente. Afecta a todos los comandos que lo definen: `flow`, `conf:check`, `status`, `conf:migrate`.

**Workaround:** Usar `--config=` en vez de `-c`.

**Ficheros:** `app/Commands/FlowCommand.php`, `app/Commands/CheckConfigurationFileCommand.php`, `app/Commands/StatusCommand.php`, `app/Commands/MigrateConfigurationFileCommand.php`

---

### #7 — Validación de tipos de argumentos de job incompleta (MEDIA)

**Comando:** `githooks conf:check` con config:
```php
'myjob' => [
    'type' => 'phpstan',
    'level' => 'esto_no_es_un_numero',  // debería ser int
    'config' => 123,                     // debería ser string
    'argumento_inventado' => true,
    'paths' => 'deberia_ser_array',
]
```

**Esperado:** Errores de tipo para `level`, `config` y `paths`.
**Real:**
- `argumento_inventado` -> warning (correcto)
- `paths` string -> warning (correcto)
- `config` int -> sin warning, genera `-c 123`
- `level` string -> sin warning, genera `-l esto_no_es_un_numero`
- Exit code 0, dice "correct format"

**Causa:** `JobConfiguration::validateArguments()` solo valida `paths` (debe ser array) y claves desconocidas. No valida el tipo del valor contra lo declarado en `ARGUMENT_MAP` (value, boolean, csv, etc.).

**Ficheros:** `src/Configuration/JobConfiguration.php`

---

### #8 — Config inexistente produce stack trace (MEDIA)

**Comando:** `githooks flow qa --config=/ruta/no_existe.php`
**Esperado:** Error amigable tipo "Configuration file not found: /ruta/no_existe.php".
**Real:** Stack trace completo de PHP:
```
ErrorException
require(/ruta/no_existe.php): failed to open stream: No such file or directory
at src/Configuration/ConfigurationParser.php:175
```

**Causa:** `ConfigurationParser::readFile()` no captura la excepción del `require` de un fichero inexistente.

**Ficheros:** `src/Configuration/ConfigurationParser.php`

---

### #9 — Warning confuso cuando un job tiene type inválido (BAJA)

**Comando:** `githooks conf:check` con `'type' => 'herramienta_inventada'`
**Esperado:** Error claro indicando que el type no es soportado.
**Real:** Muestra el error correcto ("type 'herramienta_inventada' is not a supported tool") PERO también emite un warning "Flow 'qa' references undefined job 'myjob'. It will be skipped." — cuando el job sí está definido, lo que falla es su type.

**Causa:** El job con type inválido no se añade al mapa de jobs válidos, así que cuando el flow lo referencia, parece "undefined".

**Ficheros:** `src/Configuration/ConfigurationParser.php`

---

### #10 — `--format` inválido hace fallback silencioso (BAJA)

**Comando:** `githooks flow qa --format=csv`
**Esperado:** Error o warning indicando que el formato no es válido.
**Real:** Output en texto normal sin ningún aviso de que `csv` no es un formato soportado.

**Ficheros:** `app/Commands/Concerns/FormatsOutput.php`

---

### #11 — `conf:migrate` con config vacía da mensaje confuso (BAJA)

**Comando:** `githooks conf:migrate --config=<fichero con return []>`
**Esperado:** Error indicando que la config está vacía.
**Real:** "Configuration file is already in v3 format. No migration needed." — técnicamente no es legacy, pero tampoco es una config v3 válida.

**Ficheros:** `app/Commands/MigrateConfigurationFileCommand.php`

---

## Resumen

| Severidad | Cantidad | Bugs |
|---|---|---|
| ALTA | 5 | #1 #2 #3 (CLI flags dead code), #4 (hook legacy), #5 (conf:init roto) |
| MEDIA | 3 | #6 (-c shortcut), #7 (validación tipos), #8 (stack trace) |
| BAJA | 3 | #9 (warning confuso), #10 (format fallback), #11 (migrate vacía) |

### Causa raíz de #1, #2, #3

Los tres comparten la misma causa: `FlowCommand::handle()` define las opciones `--exclude-jobs`, `--fail-fast` y `--processes` en el signature pero nunca las lee con `$this->option()`. `FlowPreparer::prepare()` tampoco acepta estos parámetros — solo recibe la config del fichero. No hay mecanismo para que las opciones CLI sobrescriban los valores de la configuración en runtime.

### Lo que funciona correctamente

- `flow` y `job` ejecutan jobs según la config
- `--format=json` y `--format=junit` generan output correcto y parseable
- `--monitor` muestra report de threads
- `--fast` propaga `$GITHOOKS_STAGED_FILES` a custom jobs
- `conf:check` valida la mayoría de errores (type inválido, hook inválido, flow con nombre de hook, processes negativo, fail-fast no booleano, custom sin script, jobs missing en flows)
- `conf:migrate` convierte v2 a v3 con backup
- `status` detecta hooks missing/synced/orphan
- `system:info` detecta CPUs
- `hook:run` resuelve hooks -> flows -> jobs
- `hook:clean` limpia hooks
- `tool` (legacy) muestra deprecation warning
- Thread budget allocation distribuye cores internamente
- Validación de claves desconocidas en jobs
