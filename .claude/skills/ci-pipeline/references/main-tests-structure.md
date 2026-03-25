# Estructura de main-tests.yml

## Triggers

```yaml
on:
  push:
    branches-ignore: [ rc** ]   # No se ejecuta en ramas de release
  pull_request:
    branches-ignore: [ master ] # No se ejecuta en PRs a master
```

## Job: tests (Linux)

- **OS:** ubuntu-latest
- **PHP:** 7.2, 7.4, 8.1, 8.4
- **fail-fast:** false

### Steps en orden

1. **Checkout** — `actions/checkout@v4`
2. **Install PHP** — `shivammathur/setup-php@v2`
   - Tools: `phpcs, phpcbf, phpmd, phpstan:1.4`
   - Extensions: `:opcache, fileinfo` (`:` desactiva opcache)
   - Coverage: `none`
3. **Get composer cache directory** — para cache key
4. **Cache dependencies** — composer + phpstan result cache (`tools/tmp/resultCache.php`)
5. **Install dependencies** — `tools/composer install` (usa el composer vendorizado en `tools/`)
6. **Install Global Parallel-Lint** — `tools/composer global require php-parallel-lint/php-parallel-lint`
7. **Install Global Phpcpd for Php7.1** — step condicional (actualmente dead code, PHP 7.1 no está en la matriz Linux)
8. **Testing** — Ejecuta dos suites:
   ```bash
   vendor/bin/phpunit --order-by random      # Unit + Integration + System (sin release, git, windows)
   vendor/bin/phpunit --group git             # Tests que necesitan git staging
   ```

### Notas de implementación

- Se usa `tools/composer` (no el composer del sistema) para mantener control sobre la versión
- `phpstan:1.4` está fijado a una versión compatible con PHP 7.1
- El cache key incluye el hash de `composer.json` para invalidar cuando cambian dependencias

## Job: tests_windows

- **OS:** windows-latest
- **PHP:** 7.1, 8.1
- **fail-fast:** false

### Steps en orden

1. **Checkout**
2. **Install PHP** — Sin tools extra, sin coverage
3. **Cache dependencies**
4. **Install dependencies** — `composer install` (usa composer del sistema, no `tools/`)
5. **Testing** — Solo: `php vendor\bin\phpunit --group windows`

### Diferencias clave con Linux

- Usa backslash en paths (`vendor\bin\phpunit`)
- No instala QA tools (los tests de Windows no las necesitan)
- Usa `composer` del sistema (no `tools/composer`)
- Solo ejecuta el grupo `windows`
