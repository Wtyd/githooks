# Correcciones para v2.8.x

Bugs encontrados durante el desarrollo de v3.0.0 que afectan al sistema v2 y deberían corregirse en una hipotética v2.8.1. Se documentan aquí porque se corrigieron en la rama 3.x pero no se han backporteado a la rama 2.x.

---

## 1. Shortcut `-c` nunca funcionó (MEDIA)

**Afecta a:** `ExecuteToolCommand`, `CheckConfigurationFileCommand`

El signature del comando `tool` define `{-c|--config=}`, que la documentación v2 lista como `-c, --config[=CONFIG]`. Sin embargo, este formato no es válido en Symfony Console — interpreta `-c` como un argumento posicional requerido en vez de como shortcut de opción. En PHP 7.x con Symfony 5.x no genera error visible (se ignora silenciosamente), pero en PHP 8.1+ con Symfony 6+ provoca un fatal error al registrar el comando.

**Impacto en v2:** El flag `-c` nunca funcionó para el usuario. `--config` sí funciona.

**Corrección en v3:** Eliminado el shortcut. Formato cambiado a `{--config=}`.

**Backport:** Cambiar `{-c|--config=}` por `{--config=}` en `ExecuteToolCommand.php` y `CheckConfigurationFileCommand.php`.

---

## 2. Tests no pasan en PHP 8.1+ (ALTA)

**Afecta a:** toda la suite de tests de integración y sistema

`tests/Zero/IlluminateTestCase.php` usa el trait `Illuminate\Foundation\Testing\Concerns\MocksApplicationServices`, que fue marcado como `@deprecated` y eliminado en versiones modernas de Laravel. Cuando Composer resuelve dependencias para PHP 8.1+, instala versiones de `laravel-zero/foundation` que ya no incluyen este trait, causando un fatal error al arrancar PHPUnit.

El trait nunca se usa en ningún test del proyecto — está incluido por herencia del `TestCase` original de Laravel.

**Impacto en v2:** Los system tests e integration tests no se pueden ejecutar en PHP 8.1+. El binario `.phar` funciona correctamente, pero no se pueden verificar con tests.

**Corrección en v3:** Eliminada la línea `use \Illuminate\Foundation\Testing\Concerns\MocksApplicationServices;` de `IlluminateTestCase.php`.

**Backport:** Eliminar la línea 31 de `tests/Zero/IlluminateTestCase.php`.

---

## 3. Phpcbf no re-stagea ficheros corregidos en pre-commit (ALTA)

**Afecta a:** hook pre-commit con phpcbf configurado

Cuando phpcbf se ejecuta durante un pre-commit:
1. Git dispara el hook con los ficheros staged
2. Phpcbf corrige los ficheros en el working tree (exit code 1 = fixes applied)
3. GitHooks trata el exit 1 como éxito (correcto)
4. Phpcs se ejecuta sobre los ficheros del disco (ya corregidos) → pasa
5. El commit se completa con los ficheros del **staging area** (versión SIN corregir)

El resultado es que el commit contiene el código sin formatear, y los fixes de phpcbf quedan como cambios unstaged.

**Impacto en v2:** El usuario cree que phpcbf ha corregido y el commit es correcto, pero el commit tiene el código antiguo. Los fixes se pierden si no hace `git add` manual.

**Corrección en v3:** `FlowExecutor` inyecta `GitStagerInterface` y llama a `stageTrackedFiles()` cuando un job reporta `fixApplied`. Esto re-stagea los ficheros modificados por phpcbf antes de que el commit se complete.

**Backport:** La arquitectura v2 usa `ProcessExecutionAbstract` en vez de `FlowExecutor`. El punto de inyección sería en `SingleProcessExecution::run()` o `MultiProcessesExecution::run()`, después de detectar que phpcbf devolvió exit 1. Requiere:
1. Inyectar `GitStagerInterface` en el flujo de ejecución
2. Después de ejecutar cada tool, si `handleFixApplied()` es true, llamar a `$gitStager->stageTrackedFiles()`

Alternativa más simple para v2: modificar `hooks/default.php` para que haga `git add -u` después de que GitHooks termine con exit 0:

```php
#!/bin/php
<?php

$backFiles = shell_exec('git diff --cached --name-only --diff-filter=ACM | grep ".php$\\|^composer.json$\\|^composer.lock$"');

if (!empty($backFiles)) {
    passthru('php vendor/bin/githooks tool all', $exit);

    if ($exit === 0) {
        // Re-stage files that may have been modified by auto-fixing tools (phpcbf)
        exec('git diff --cached --name-only --diff-filter=d', $stagedFiles);
        if (!empty($stagedFiles)) {
            $escaped = array_map('escapeshellarg', $stagedFiles);
            exec('git add ' . implode(' ', $escaped));
        }
    }

    exit($exit);
}
```

---

## 4. Documentación: `-c` documentado pero no funciona

**Afecta a:** wiki 2x-ConsoleCommands.md

La documentación v2 lista `-c, --config[=CONFIG]` como opción del comando `tool` (línea 147). Dado que el shortcut nunca funcionó, la documentación debería indicar solo `--config`.

**Backport:** Actualizar la línea 147 de `2x-ConsoleCommands.md`:
```
-c, --config[=CONFIG]                      Path to configuration file
```
→
```
--config[=CONFIG]                          Path to configuration file
```

---

## Resumen

| # | Severidad | Descripción | Backport |
|---|---|---|---|
| 1 | MEDIA | `-c` shortcut no funciona | Cambiar signature en 2 ficheros |
| 2 | ALTA | Tests no pasan en PHP 8.1+ | Eliminar 1 línea de IlluminateTestCase |
| 3 | ALTA | Phpcbf no re-stagea en pre-commit | Modificar hooks/default.php o inyectar GitStager |
| 4 | BAJA | Documentación dice `-c` | Actualizar wiki |

Los puntos 1 y 2 son triviales (1 línea cada uno). El punto 3 tiene una solución rápida (modificar `hooks/default.php`) que no requiere tocar la arquitectura interna.
