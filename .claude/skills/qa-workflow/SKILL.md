---
name: qa-workflow
description: >
  Gestión del QA del proyecto GitHooks: ejecutar herramientas de análisis estático y tests,
  interpretar y corregir violaciones, y garantizar que el código está limpio antes de commitear.
  Usa esta skill SIEMPRE antes de commitear, o cuando un commit falle por un hook de QA,
  o cuando el usuario pida "ejecutar QA", "pasar análisis", "arreglar phpmd", "arreglar phpstan",
  "limpiar violaciones", "preparar para commit".
---

# QA Workflow — Análisis y corrección antes de commit

## Cómo ejecutar las herramientas

Las herramientas QA se ejecutan a través de GitHooks usando la configuración de `qa/githooks.php`. **Siempre con `--format=json 2>/dev/null`** para poder parsear la respuesta y extraer los jobs fallidos sin leer bloques ANSI:

```bash
# Flow completo (todas las herramientas)
php7.4 githooks flow qa --format=json

# Job individual
php7.4 githooks job phpstan-src --format=json
php7.4 githooks job phpmd-src --format=json
php7.4 githooks job phpcs --format=json
php7.4 githooks job phpcbf --format=json
php7.4 githooks job phpcpd --format=json
php7.4 githooks job parallel-lint --format=json
php7.4 githooks job phpunit --format=json
php7.4 githooks job composer-audit --format=json

# Tests
php7.4 vendor/bin/phpunit --order-by random
```

**NUNCA ejecutar las herramientas directamente** (ej: `vendor/bin/phpstan`). Siempre usar `githooks flow` o `githooks job` para que se aplique la configuración del proyecto.

### Parsear la respuesta JSON

Para extraer solo los jobs que han fallado y su causa:

```bash
php7.4 githooks flow qa --format=json | python3 -c "
import json, sys
d = json.load(sys.stdin)
print(f'{d[\"passed\"]}/{d[\"passed\"]+d[\"failed\"]} passed, {d[\"skipped\"]} skipped')
for j in d['jobs']:
    if not j['success'] and not j.get('skipped'):
        print(f'KO {j[\"name\"]} ({j[\"type\"]}): exitCode={j[\"exitCode\"]}')
        print(j['output'][:400])"
```

El campo `output` contiene el stdout del tool (sin ANSI) — suficiente para localizar la violación sin necesidad de re-ejecutar en modo texto.

### Pasar argumentos al tool (ej. `--filter` en phpunit)

`githooks job` acepta args extra tras el separador POSIX `--`. **No lanzar `vendor/bin/phpunit` directamente**; usar el job con `--` para preservar la config del proyecto (`--log-junit`, `--colors`, etc.):

```bash
# Filtrar un test concreto
php7.4 githooks job "Phpunit" --format=json -- --filter=FooTest

# Por grupo
php7.4 githooks job "Phpunit" --format=json -- --group=slow

# Combinado
php7.4 githooks job "Phpunit" --format=json -- --filter=Bar --stop-on-failure
```

Todo lo que va después de `--` se concatena al comando generado. Vale también para `--group`, `--exclude-group`, `--stop-on-failure`, `--testdox`, etc. No existe en `githooks flow` (cada tool del flow tiene flags distintos).

Los nombres de los jobs son los definidos en `qa/githooks.php`. Son case-sensitive y pueden tener espacios.

## Cuándo ejecutar QA

1. **Antes de commitear** — ejecutar `php7.4 githooks flow qa` y corregir todo
2. **Si un commit falla** por un hook pre-commit — corregir y reintentar
3. **Después de cada fase** de una tarea grande — no acumular deuda

## Cómo gestionar las violaciones

### Criterio 1: Fácil de corregir → corregir inmediatamente

Si la violación es sensata y se arregla en 1-5 líneas, corregirla en el acto.

Ejemplos:
- Variable con nombre corto (`$v` → `$value`)
- Import faltante (`Missing class import via use statement`)
- Variable no usada (`Avoid unused local variables`)
- Variable no definida (`Avoid using undefined variables`)

### Criterio 2: Sensata pero requiere refactor → suprimir + documentar

Si la violación es correcta pero corregirla requiere un cambio no trivial (ej: reducir CC de un método, romper acoplamiento de una clase), NO refactorizar en el momento. En su lugar:

1. Añadir `@SuppressWarnings` en el código:
```php
/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
private function myMethod(): void { ... }

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MyClass { ... }

/**
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
public function __construct(bool $flag) { ... }
```

2. Documentar en `Bugs3.0.0.md` o en un apartado de deuda técnica por qué se suprime y qué refactor sería necesario.
3. Informar al usuario.

### Criterio 3: Asumible (falso positivo o trade-off aceptado) → suprimir

Si la violación no es un problema real (ej: boolean flag en un value object, acoplamiento en un DI container), suprimirla con `@SuppressWarnings` e informar al usuario.

### Criterio 4: Test que falla → corregir

- Si falla por falta de mantenimiento del test (cambió una signature, un mensaje) → actualizar el test.
- Si falla por un bug en el SUT → corregir el SUT, no el test.
- **Nunca** marcar un test como `@incomplete` o `@skip`. Si crees que ya no aporta valor, preguntar al usuario.

## Anotaciones de supresión por herramienta

### PHPMD (`@SuppressWarnings`)
```php
/** @SuppressWarnings(PHPMD.CyclomaticComplexity) */
/** @SuppressWarnings(PHPMD.CouplingBetweenObjects) */
/** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
/** @SuppressWarnings(PHPMD.ShortVariable) */
/** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
/** @SuppressWarnings(PHPMD.UndefinedVariable) */  // Para falsos positivos de preg_match
```

Se ponen en el docblock del método o clase afectado.

### PHPStan (`phpstan-ignore`)
```php
/** @phpstan-ignore-next-line */
$result = $something; // PHPStan no puede inferir el tipo aquí
```

O en `qa/phpstan.neon`:
```neon
parameters:
    ignoreErrors:
        - '#Pattern description#'
```

### PHPCS (`phpcs:ignore`)
```php
// phpcs:ignore PSR12.Rule.Name -- razón
$code = something_non_psr12();
```

## Checklist pre-commit

- [ ] `php7.4 githooks flow qa` — **8/8 passed**
- [ ] `php7.4 vendor/bin/phpunit --order-by random` — 0 fallos
- [ ] Si hay violaciones nuevas: corregidas o suprimidas con justificación
- [ ] Si se suprimió algo: documentado y usuario informado
