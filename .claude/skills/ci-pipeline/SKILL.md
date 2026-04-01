---
name: ci-pipeline
description: >
  Guía para modificar los workflows de GitHub Actions y el proceso de build del proyecto GitHooks.
  Usa esta skill cuando el usuario quiera modificar CI, cambiar versiones de PHP en la matriz,
  añadir/quitar jobs, modificar el release pipeline, tocar el build del .phar, o cuando mencione
  "GitHub Actions", "workflow", "CI", "pipeline", "build", "release", "matriz de PHP",
  "phar", "deploy", "artefacto". También cuando se toquen ficheros en .github/workflows/,
  box.json, o los comandos PreBuildCommand/BuildCommand.
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

## Flujo de release — paso a paso

### 1. Crear rama `rc-X.Y.Z`

El nombre es **obligatorio** con formato semver. `BuildCommand::validatesBranchName()` valida con `/^rc-\d+\.\d+\.\d+$/`. Si el nombre no cumple el formato, el build hace `exit(1)`.

La versión se extrae automáticamente del nombre de la rama en `BuildCommand::extractVersionFromBranchName()` y se incrusta en el `.phar` compilado.

### 2. Preparar release tests

Antes de pushear la rama rc, verificar:

- **Nuevas features** tienen smoke tests en `tests/System/Release/` (ver sección "Release tests — infraestructura")
- **`NewVersionTest`** NO necesita cambios — extrae la versión de la rama dinámicamente
- **Todos los tests pasan localmente** (ver sección "Probar release tests en local")

### 3. Push a la rama rc

El push dispara `release.yml` que ejecuta la cadena:

```
build_rc (PHP 7.1, 7.3, 8.1)   ← Compila .phar por tier
    ↓ artefactos .tar
test_rc (PHP 7.2, 8.0, 8.5)    ← Tests cruzados con phpunit --group release
    ↓
commit_rc (PHP 7.1)             ← Commit binarios al branch rc
```

### 4. Verificar CI

Si `test_rc` falla, corregir y pushear de nuevo. Los artefactos se regeneran en cada push.

### 5. Merge a master

Cuando los tests de la rama rc pasan, se fusiona a master. **Revertir cualquier trigger temporal** añadido a los workflows para testing antes de fusionar.

## main-tests.yml — Tests principales

Lee `references/main-tests-structure.md` para la estructura completa.

### Resumen de jobs

| Job | OS | PHP versions | Qué ejecuta |
|---|---|---|---|
| `tests` | ubuntu-latest | 7.2, 7.4, 8.1, 8.5 | `phpunit --order-by random` + `phpunit --group git` |
| `tests_windows` | windows-latest | 7.1, 8.5 | `phpunit --group windows` |

### Tareas comunes

**Añadir versión de PHP a la matriz:**
```yaml
matrix:
  php-versions: ['7.2', '7.4', '8.1', '8.5']  # Añadir aquí
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

### Cadena de jobs (v3: 2 tiers)

```
build_rc (PHP 7.4, 8.1)
    ↓ (genera artefactos .tar por versión)
test_rc (PHP 8.0, 8.5)
    ↓ (phpunit --group release)
commit_rc (PHP 7.4)
    ↓ (commit builds al branch rc)
```

### build_rc — Compilación

Para cada PHP de la matriz (7.4, 8.1):
1. `tools/composer install` — instala dependencias
2. `tools/composer global require humbug/box` — instala Box compatible con el PHP
3. `php githooks app:pre-build php` — elimina dependencias dev
4. `php githooks app:build` — compila .phar, genera `.tar` con PharData (preserva permisos)
5. Upload artifact: `githooks-{php-version}.tar`

### test_rc — Tests cruzados

Las versiones de test (8.0, 8.5) son **deliberadamente diferentes** de las de build (7.4, 8.1) para verificar compatibilidad cruzada entre tiers.

Para cada PHP de la matriz:
1. `composer install` — instala deps incluyendo phpunit para ejecutar tests
2. Instala QA tools globales: `phpcs, phpcbf, phpmd, phpstan, parallel-Lint, phpcpd`
   - **Son necesarias** porque el `.phar` las invoca como subprocesos
   - phpunit y psalm se obtienen de `vendor/bin/` via `composer install`
3. Descarga todos los artefactos de build_rc
4. `php githooks app:extract-build` — extrae .tar al directorio `builds/`
5. `vendor/bin/phpunit --group release` — ejecuta los release tests

### commit_rc — Commit de binarios

1. Borra binarios viejos: `rm builds/githooks builds/php7.4/githooks`
2. Descarga y extrae artefactos frescos
3. Extrae versión del nombre de rama: `rc-X.Y.Z` → `X.Y.Z`
4. Commit con `GuillaumeFalourd/git-commit-push`: los 3 binarios

### Tareas comunes

**Añadir tool a release tests:**
En job `test_rc`, step `Install PHP`:
```yaml
tools: phpcs, phpcbf, phpmd, phpstan, parallel-Lint, phpcpd, nuevatool
```

**Cambiar versiones de PHP del build:**
```yaml
# build_rc — versiones de compilación (una por tier)
php-versions: ['7.4', '8.1']
# test_rc — versiones de test (cruzadas, una por tier distinta de build)
php-versions: ['8.0', '8.5']
```

**Añadir un nuevo tier:**
1. Añadir versión a `build_rc` y `test_rc` matrices
2. Actualizar `Build.php`: `ALL_BUILDS`, `setBuildPath()`, `getTarName()`
3. En `commit_rc`: añadir el nuevo path al `rm` y al `files` del commit
4. Crear directorio `builds/phpX.Y/` con placeholder

## Release tests — infraestructura

### Ficheros clave

| Fichero | Rol |
|---|---|
| `tests/ReleaseTestCase.php` | Base class — copia binary, crea/limpia testsDir, helpers de assertion |
| `tests/Utils/ConfigurationFileBuilder.php` | Genera `githooks.php` con defaults para cada tool |
| `tests/Utils/PhpFileBuilder.php` | Genera ficheros PHP con/sin errores para cada tool |
| `tests/System/Release/ExecuteToolTest.php` | Smoke tests de ejecución de tools via .phar |
| `tests/System/Release/NewVersionTest.php` | Verifica que la versión del .phar coincide con la rama |
| `tests/System/Release/CheckConfigurationFileTest.php` | Verifica conf:check via .phar |

### Cómo funciona ReleaseTestCase

1. **setUp()**: `deleteDirStructure()` → `createDirStructure()` → `copyReleaseBinary()` → crea ConfigurationFileBuilder
2. **copyReleaseBinary()**: usa `Build::getBuildPath()` para determinar el tier del PHP actual y copia el binary a `testsDir/githooks`
3. **tearDown()**: `deleteDirStructure()` → `git restore -- testsDir/.gitignore`
4. Los tests ejecutan el .phar con `passthru("$this->githooks tool ...", $exitCode)`

### ConfigurationFileBuilder — defaults que importan

El builder genera configs con defaults para TODAS las tools. Los release tests necesitan **sobreescribir** los defaults que interfieran:

```php
// setUp() de ExecuteToolTest limpia filtros de phpunit:
$this->configurationFileBuilder
    ->changeToolOption('phpunit', ['configuration' => self::TESTS_PATH . '/phpunit.xml'])
    ->changeToolOption('phpunit', ['group' => []])
    ->changeToolOption('phpunit', ['exclude-group' => []])
    ->changeToolOption('phpunit', ['filter' => ''])
    ->changeToolOption('phpunit', ['log-junit' => '']);
```

### Distribución de tools — phar vs vendor

| Tipo | Tools | Path en ConfigurationFileBuilder |
|---|---|---|
| Phar standalone | phpcs, phpcbf, phpmd, phpcpd, security-checker | `pharExecutables()` → `tools/php71/` o `tools/php80/` |
| Composer (vendor/bin) | phpunit, psalm, parallel-lint, phpstan | `vendorPath()` → `vendor/bin/` |

### Añadir un smoke test para una feature nueva

Patrón en `ExecuteToolTest`:
```php
/** @test */
function it_does_something_new()
{
    file_put_contents(
        'githooks.php',
        $this->configurationFileBuilder->setTools(['tool1', 'tool2'])
            ->changeToolOption('tool1', ['someOption' => 'value'])
            ->buildPhp()
    );

    passthru("$this->githooks tool all", $exitCode);

    $this->assertEquals(0, $exitCode);
    $this->assertToolHasBeenExecutedSuccessfully('tool1');
}
```

## Release tests — pitfalls

| Problema | Causa | Solución |
|---|---|---|
| `pathspec 'testsDir/.gitignore' did not match` | El fichero no está trackeado en git | Asegurar que `testsDir/.gitignore` existe con contenido `*` + `!.gitignore` |
| phpcs falla en ficheros generados | Ficheros sin namespace o newline final | Los PHP generados en setUp deben ser PSR-12 compliant |
| phpunit ejecuta 0 tests | Defaults de ConfigurationFileBuilder incluyen `--group integration --filter testSomething` | Limpiar filtros con `changeToolOption()` en setUp |
| psalm crashea con "Unexpected report format" | Default `output-format=ansi` no existe en psalm 4.30 | Usar `console` como output-format |
| psalm no encuentra config | No se crea `psalm.xml` en testsDir | Crear `testsDir/qa/psalm.xml` en setUp |
| phpunit/psalm binary not found | ConfigurationFileBuilder usa `pharExecutables()` | Deben usar `vendorPath()` — son packages de composer, no phars standalone |
| phpcbf muestra OK cuando el test espera KO | `handleFixApplied()` trata fix-applied como éxito | Adaptar assertions: phpcbf con fixes = OK, no KO |
| `git add` falla en fast execution | `testsDir/.gitignore` con `*` bloquea el add | Usar `git add -f` en los tests de fast execution |
| NewVersionTest falla con versión incorrecta | Versión hardcodeada en el test | El test extrae la versión de la rama `rc-X.Y.Z` dinámicamente |

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

Solo se ejecuta domingos. Usa PHP 8.5 con xdebug. Normalmente NO necesitas modificarlo.

## Proceso de build del .phar

### Comandos

```bash
php7.4 githooks app:pre-build php7.1   # Elimina deps dev (argumento = PHP para tools/composer)
php7.4 githooks app:build              # Compila con Humbug Box
```

**Importante:** El argumento de `app:pre-build` se usa internamente para ejecutar `tools/composer remove` y `tools/composer update`. Si pasas `php` a secas, usará el PHP del sistema (que puede ser otra versión).

### PreBuildCommand

`app/Commands/PreBuildCommand.php` — ejecuta `composer remove --dev` para 10 paquetes de desarrollo.
Si añades una nueva dependencia dev, considerar si debe añadirse a la lista de `DEV_DEPENDENCIES`.

**Efecto secundario:** modifica `composer.json` eliminando los paquetes. Hay que restaurarlo después del build:
```bash
git restore --staged --worktree composer.json
php7.4 tools/composer update
```

### BuildCommand

`app/Commands/Zero/BuildCommand.php` — pipeline: `prepare()` → `compile()` → `tarBuild()` → `clear()`.
- **prepare()**: extrae versión de la rama (`rc-X.Y.Z`), modifica `config/app.php` y `box.json` temporalmente
- **compile()**: llama a `box compile` (requiere Box 3+)
- **tarBuild()**: crea `.tar` con PharData preservando permisos de ejecución
- **clear()**: restaura `config/app.php` y `box.json` originales

### Box — versiones y compatibilidad

El BuildCommand invoca `box compile`. El contenedor tiene 3 versiones de Box en `/etc/repositorio/`:

| Binario | Versión | PHP requerido | Comando | Compatible con box.json |
|---|---|---|---|---|
| `box` | 2.7.5 | PHP 5.3+ | `build` | **NO** (formato v2) |
| `box3` | 3.16.0 | PHP ^7.4 | `compile` | Sí |
| `box4` | — | PHP ^8.2 | `compile` | Sí |

BuildCommand usa el nombre `'box'` que en el sistema resuelve a v2 (incompatible). **En CI se instala via `tools/composer global require humbug/box`** que pone la versión compatible en el PATH como `box`.

Localmente hay que hacer lo mismo:
```bash
php7.4 tools/composer global require humbug/box   # Instala Box 3.x compatible
export PATH="$(php7.4 tools/composer global config home)/vendor/bin:$PATH"
```

**Mejora futura:** mover box3/box4 a `tools/` y que BuildCommand seleccione el correcto según versión PHP. Eliminaría la dependencia de `composer global require`.

### box.json

```json
{
  "directories": ["app", "bootstrap", "config", "src", "vendor"],
  "files": ["composer.json"],
  "chmod": "0755",
  "compression": "GZ",
  "compactors": ["KevinGH\\Box\\Compactor\\Php", "KevinGH\\Box\\Compactor\\Json"],
  "exclude-composer-files": false,
  "exclude-dev-files": false
}
```

Si añades un nuevo directorio al proyecto que deba incluirse en el `.phar`, añádelo a `directories`.

### Build paths por versión PHP

`src/Build/Build.php` gestiona los paths (v3: 2 tiers):
- PHP 7.4-8.0: `builds/php7.4/githooks` → `githooks-7.4.tar`
- PHP >= 8.1: `builds/githooks` → `githooks-8.1.tar`

Definidos en `ALL_BUILDS`, `setBuildPath()` y `getTarName()`.

## Probar release tests en local

```bash
# 1. Instalar Box 3.x compatible con php7.1 (el 'box' del sistema es v2, incompatible)
php7.4 tools/composer global require humbug/box
export COMPOSER_HOME=$(php7.4 tools/composer global config home)
export PATH="$COMPOSER_HOME/vendor/bin:$PATH"

# 2. Build (el argumento de pre-build es el PHP para tools/composer)
php7.4 githooks app:pre-build php7.1
php7.4 githooks app:build

# 3. Restaurar deps dev (pre-build las elimina de composer.json)
git restore --staged --worktree composer.json
php7.4 tools/composer update

# 4. El .phar usa #!/usr/bin/env php → resuelve al php del sistema (8.4).
#    El binary Tier 1 (Laravel 5.8) crashea en 8.4 por deprecations.
#    Solución: forzar que 'php' apunte a php7.1:
mkdir -p /tmp/phpbin && ln -sf /usr/bin/php7.1 /tmp/phpbin/php
export PATH="/tmp/phpbin:$PATH"

# 5. Ejecutar release tests
php7.4 vendor/bin/phpunit --group release

# 6. Restaurar entorno (opcional)
export PATH=$(echo $PATH | sed 's|/tmp/phpbin:||')
```

## Grupos de test y su relación con CI

| Grupo | Excluido por defecto | Se ejecuta en | Propósito |
|---|---|---|---|
| `@group release` | Sí | `release.yml` (test_rc) | Smoke test del `.phar` |
| `@group git` | Sí | `main-tests.yml` (Linux) | Tests que necesitan git staging |
| `@group windows` | Sí | `main-tests.yml` (Windows) | Paths Windows |
| (sin grupo) | No | Todos los workflows con phpunit | Tests estándar |

## Checklist

- [ ] Workflow modificado tiene sintaxis YAML válida
- [ ] Las versiones PHP de la matriz cubren el rango soportado (7.4+)
- [ ] Si se añade una tool: está en `main-tests.yml` Y en `release.yml` (test_rc)
- [ ] Si se cambia el build: `box.json` incluye los directorios necesarios
- [ ] Si se añade dependencia dev: considerado si debe ir en `PreBuildCommand::DEV_DEPENDENCIES`
- [ ] `fail-fast: false` está en las estrategias de matriz (para no perder resultados parciales)
- [ ] Si se añade versión PHP: actualizar `Build::getTarName()`, `setBuildPath()` y `ALL_BUILDS`
- [ ] Si se modifican release tests: probar con build fresco localmente
- [ ] Ficheros PHP generados en release tests son PSR-12 compliant (namespace, newline final)
- [ ] `testsDir/.gitignore` existe y está trackeado en git
- [ ] Dependencias multi-versión con `|` en composer.json si hay incompatibilidad entre PHP tiers

## Permisos necesarios

### Lectura de ficheros
- `.github/workflows/` — `main-tests.yml`, `release.yml` y cualquier otro workflow
- `box.json` — Configuración del build del .phar
- `app/Commands/Zero/BuildCommand.php` — Comando de build
- `app/Commands/Zero/PreBuildCommand.php` — Preparación pre-build
- `app/Commands/ExtractBuildCommand.php` — Extracción de artefactos
- `src/Build/Build.php` — Lógica de rutas y tiers de build
- `composer.json` — Dependencias y versiones PHP
- `builds/` — Estructura de directorios de output
- `tests/ReleaseTestCase.php` — Base class de release tests
- `tests/System/Release/` — Release tests
- `tests/Utils/ConfigurationFileBuilder.php` — Configuración de tools para tests

### Escritura de ficheros
- `.github/workflows/*.yml` — Workflows de GitHub Actions
- `box.json` — Configuración del .phar
- `app/Commands/Zero/BuildCommand.php` — Si cambia el proceso de build
- `app/Commands/Zero/PreBuildCommand.php` — Si cambian dependencias pre-build
- `src/Build/Build.php` — Si cambian tiers o rutas
- `tests/System/Release/*.php` — Release tests

### Comandos Bash
- `php7.4 githooks app:pre-build php7.1` — Pre-build
- `php7.4 githooks app:build` — Build del .phar
- `php7.4 vendor/bin/phpunit --order-by random` — Tests completos
- `php7.4 vendor/bin/phpunit --group release` — Release tests
- `php7.4 githooks tool all full` — QA completo
- `git add` / `git commit` — Commits (solo si el usuario lo pide)

### Agentes
- **Explore** — Para investigar la estructura de workflows y matriz de versiones PHP
