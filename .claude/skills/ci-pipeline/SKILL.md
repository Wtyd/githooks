---
name: ci-pipeline
description: >
  Guía para modificar los workflows de GitHub Actions y el proceso de build del proyecto GitHooks.
  Usa esta skill cuando el usuario quiera modificar CI, cambiar versiones de PHP en la matriz,
  añadir/quitar jobs, modificar el release pipeline, tocar el build del .phar, o cuando mencione
  "GitHub Actions", "workflow", "CI", "pipeline", "build", "release", "matriz de PHP",
  "phar", "deploy", "artefacto". También cuando se toquen ficheros en .github/workflows/,
  box.json, o los comandos PreBuildCommand/BuildCommand.
allowed-tools: Read, Edit, Write, Glob, Grep, Bash(php7.1 *), Bash(git add *), Bash(git commit *), Bash(git status), Bash(git diff *), Bash(git log *), Agent
---

# CI Pipeline y Build de GitHooks

El proyecto tiene 4 workflows de GitHub Actions y un proceso de build del `.phar`.

## Mapa de workflows

```
Push/PR (no rc) ──┬── main-tests.yml     Tests unitarios, integración, sistema
                  └── code-analysis.yml   Análisis estático (php githooks tool all full)

Push a rama rc** ──── release.yml         Build .phar → Test release → Commit builds

Domingo 04:00 UTC ─── schedule-ci.yml     Coverage + Infection + PhpMetrics
```

## main-tests.yml — Tests principales

Lee `references/main-tests-structure.md` para la estructura completa.

### Resumen de jobs

| Job | OS | PHP versions | Qué ejecuta |
|---|---|---|---|
| `tests` | ubuntu-latest | 7.2, 7.4, 8.1, 8.4 | `phpunit --order-by random` + `phpunit --group git` |
| `tests_windows` | windows-latest | 7.1, 8.1 | `phpunit --group windows` |

### Tareas comunes

**Añadir versión de PHP a la matriz:**
```yaml
matrix:
  php-versions: ['7.2', '7.4', '8.1', '8.4', '8.5']  # Añadir aquí
```

**Añadir una QA tool global:**
```yaml
tools: phpcs, phpcbf, phpmd, phpstan:1.4, nuevatool
```

Si la tool no está disponible via `shivammathur/setup-php`, añadir un step:
```yaml
- name: Install Global NewTool
  run: tools/composer global require vendor/newtool
```

**Añadir un grupo de test:**
```yaml
- name: Testing
  run: |
    vendor/bin/phpunit --order-by random
    vendor/bin/phpunit --group git
    vendor/bin/phpunit --group mynewgroup  # Nuevo
```

## release.yml — Pipeline de release

### Cadena de jobs

```
build_rc (PHP 7.1, 7.3, 8.1)
    ↓ (genera artefactos .tar por versión)
test_rc (PHP 7.2, 8.0, 8.4)
    ↓ (phpunit --group release)
commit_rc (PHP 7.1)
    ↓ (commit builds al branch rc)
```

### Detalles clave

- **build_rc** compila el `.phar` para cada versión PHP con `app:pre-build` + `app:build`
- Las versiones de build (7.1, 7.3, 8.1) y test (7.2, 8.0, 8.4) son **deliberadamente diferentes** para verificar compatibilidad cruzada
- **test_rc** instala TODAS las QA tools globalmente porque ejecuta `--group release` donde el `.phar` las invoca realmente
- **commit_rc** sube los binarios al branch `rc-*`

### Tareas comunes

**Añadir tool a release tests:**
En job `test_rc`, step `Install PHP`:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan, parallel-Lint, phpcpd, nuevatool
```

**Cambiar versiones de PHP del build:**
```yaml
# build_rc
php-versions: ['7.1', '7.3', '8.1']  # Versiones de compilación
# test_rc
php-versions: ['7.2', '8.0', '8.4']  # Versiones de test (cruzadas)
```

## code-analysis.yml — Análisis estático

Job único que ejecuta `php githooks tool all full` en PHP 7.1.
Lee la configuración de `qa/githooks.php`. Si añades una tool ahí, se ejecuta automáticamente.
Normalmente NO necesitas modificar este workflow.

## schedule-ci.yml — Métricas semanales

4 jobs encadenados:
```
code_coverage (phpunit + xdebug)
    ↓
infection (mutation testing)  ←─┐
phpMetrics (análisis)         ←─┘ (en paralelo)
    ↓
reports (agrega artefactos)
```

Solo se ejecuta domingos. Usa PHP 8.4 con xdebug. Normalmente NO necesitas modificarlo.

## Proceso de build del .phar

### Comandos

```bash
php githooks app:pre-build php    # Elimina dependencias dev de composer.json
php githooks app:build            # Compila con Humbug Box
```

### PreBuildCommand

`app/Commands/PreBuildCommand.php` — ejecuta `composer remove --dev` para 10 paquetes de desarrollo.
Si añades una nueva dependencia dev, considerar si debe añadirse a la lista de `DEV_DEPENDENCIES`.

### BuildCommand

`app/Commands/Zero/BuildCommand.php` — pipeline: `prepare()` → `compile()` → `tarBuild()` → `clear()`.
- Extrae la versión del nombre del branch (`rc-X.Y.Z`)
- Compila con Box, genera `.phar`, lo mueve a `builds/`
- Crea un `.tar` para distribución como artefacto de GitHub

### box.json

```json
{
  "directories": ["app", "bootstrap", "config", "src", "vendor"],
  "files": ["composer.json"],
  "permissions": "0755",
  "compression": "GZ"
}
```

Si añades un nuevo directorio al proyecto que deba incluirse en el `.phar`, añádelo a `directories`.

### Build paths por versión PHP

`src/Build/Build.php` gestiona los paths:
- PHP 7.1-7.2: `builds/php7.1/githooks`
- PHP 7.3-8.0: `builds/php7.3/githooks`
- PHP >= 8.1: `builds/githooks`

## Grupos de test y su relación con CI

| Grupo | Excluido por defecto | Se ejecuta en | Propósito |
|---|---|---|---|
| `@group release` | Sí | `release.yml` (test_rc) | Smoke test del `.phar` |
| `@group git` | Sí | `main-tests.yml` (Linux) | Tests que necesitan git staging |
| `@group windows` | Sí | `main-tests.yml` (Windows) | Paths Windows |
| (sin grupo) | No | Todos los workflows con phpunit | Tests estándar |

## Checklist

- [ ] Workflow modificado tiene sintaxis YAML válida
- [ ] Las versiones PHP de la matriz cubren el rango soportado (7.1+)
- [ ] Si se añade una tool: está en `main-tests.yml` Y en `release.yml` (test_rc)
- [ ] Si se cambia el build: `box.json` incluye los directorios necesarios
- [ ] Si se añade dependencia dev: considerado si debe ir en `PreBuildCommand::DEV_DEPENDENCIES`
- [ ] `fail-fast: false` está en las estrategias de matriz (para no perder resultados parciales)

## Permisos necesarios

### Lectura de ficheros
- `.github/workflows/` — `main-tests.yml`, `release.yml` y cualquier otro workflow
- `box.json` — Configuración del build del .phar
- `app/Commands/Zero/BuildCommand.php` — Comando de build
- `app/Commands/Zero/PreBuildCommand.php` — Preparación pre-build
- `src/Build/Build.php` — Lógica de rutas y tiers de build
- `composer.json` — Dependencias y versiones PHP
- `builds/` — Estructura de directorios de output

### Escritura de ficheros
- `.github/workflows/*.yml` — Workflows de GitHub Actions
- `box.json` — Configuración del .phar
- `app/Commands/Zero/BuildCommand.php` — Si cambia el proceso de build
- `app/Commands/Zero/PreBuildCommand.php` — Si cambian dependencias pre-build
- `src/Build/Build.php` — Si cambian tiers o rutas

### Comandos Bash
- `php7.1 githooks app:pre-build php` — Pre-build
- `php7.1 githooks app:build` — Build del .phar
- `php7.1 vendor/bin/phpunit --order-by random` — Tests completos
- `php7.1 githooks tool all full` — QA completo
- `git add` / `git commit` — Commits (solo si el usuario lo pide)

### Agentes
- **Explore** — Para investigar la estructura de workflows y matriz de versiones PHP
