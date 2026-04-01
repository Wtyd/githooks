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

Las herramientas QA se ejecutan a través de GitHooks usando la configuración de `qa/githooks.php`:

```bash
# Flow completo (todas las herramientas)
php7.4 githooks flow qa

# Job individual
php7.4 githooks job "Phpstan Src"
php7.4 githooks job "Phpmd Src"
php7.4 githooks job "Phpcs"
php7.4 githooks job "Phpcbf"
php7.4 githooks job "Phpcpd"
php7.4 githooks job "Parallel-lint"
php7.4 githooks job "Phpunit"
php7.4 githooks job "Composer Audit"

# Tests
php7.4 vendor/bin/phpunit --order-by random
```

**NUNCA ejecutar las herramientas directamente** (ej: `vendor/bin/phpstan`). Siempre usar `githooks flow` o `githooks job` para que se aplique la configuración del proyecto.

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
