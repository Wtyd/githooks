# Integración de una Tool en GitHub Actions

## Visión general de los workflows

| Workflow | Cuándo se ejecuta | Qué hace con las tools |
|---|---|---|
| `main-tests.yml` | Push/PR (no rc) | Instala tools globalmente + ejecuta phpunit |
| `release.yml` | Push a rama `rc**` | Build `.phar` + test con tools reales |
| `code-analysis.yml` | Push/PR (no rc) | `php githooks tool all full` (usa el propio proyecto) |
| `schedule-ci.yml` | Domingos 04:00 | Coverage + Infection (no instala tools extra) |

## main-tests.yml — Instalación de tools

Job `tests` (Linux), step `Install PHP`:

```yaml
- name: Install PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: ${{ matrix.php-versions }}
    tools: phpcs, phpcbf, phpmd, phpstan:1.4, mytool
    coverage: none
```

Notas:
- `shivammathur/setup-php` instala tools globalmente via Composer
- Se puede fijar versión con `:` (e.g., `phpstan:1.4`)
- Si la tool no está disponible en Packagist como herramienta global, necesitarás un step separado para instalarla (como se hace con `parallel-lint`)
- El job `tests_windows` NO instala tools — solo ejecuta `--group windows`

Si la tool se instala con Composer global pero no está en `shivammathur/setup-php`:

```yaml
- name: Install Global MyTool
  run: tools/composer global require vendor/mytool
```

## release.yml — Test de la build

Job `test_rc`, step `Install PHP`:

```yaml
tools: phpcs, phpcbf, phpmd, phpstan, parallel-Lint, phpcpd, mytool
```

Este job ejecuta `vendor/bin/phpunit --group release` contra el `.phar` compilado.
Las tools deben estar disponibles globalmente porque el binario las invoca via `passthru()`.

## code-analysis.yml — Sin cambios directos

Este workflow ejecuta `php githooks tool all full`, que lee `qa/githooks.php`.
Si has añadido la tool a `qa/githooks.php`, se ejecutará automáticamente.
No necesitas modificar el workflow.

## schedule-ci.yml — Sin cambios necesarios

Este workflow ejecuta tests + coverage + mutation testing. No instala tools QA.
No necesitas modificarlo al añadir una nueva tool.

## Ejemplo completo: añadir Psalm a main-tests.yml

Antes:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan:1.4
```

Después:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan:1.4, psalm
```

Si Psalm requiere una versión específica:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan:1.4, psalm:5.0
```
