# Templates de páginas

## Página de herramienta QA

Ubicación: `docs/tools/{tool}.md`

```markdown
# Nombre de la herramienta

Descripción en una línea de qué hace.

- **Type:** `tipo`
- **Accelerable:** Yes/No
- **Default executable:** `vendor/bin/tool`
- **Subcommand:** `analyse` (si aplica)

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|
| `config` | String | Path to configuration file. | `'tool.xml'` |
| `paths` | Array | Directories to analyze. | `['src']` |

Plus all [common keywords](../configuration/jobs.md#common-keywords).

## Examples

Minimal:

​```php
'tool_src' => [
    'type'  => 'tipo',
    'paths' => ['src'],
],
​```

Full:

​```php
'tool_src' => [
    'type'   => 'tipo',
    'paths'  => ['src', 'app'],
    'config' => 'qa/tool.xml',
    // ... todos los keywords
],
​```

## Threading (si soporta paralelismo interno)

Descripción de cómo se integra con el thread budget.

## Cache (si genera caché)

Default cache location: `ruta`. Cleared with `githooks cache:clear`.
```

## Página de comando CLI

Ubicación: `docs/cli/{command}.md`

```markdown
# githooks comando

Descripción breve del comando.

## Synopsis

​```
githooks comando <argumento> [options]
​```

## Options

| Option | Description |
|---|---|
| `--opcion` | Descripción. |
| `--config=PATH` | Path to configuration file. |
| `-- ARGS...` | Extra arguments passed to the tool (si aplica). |

## Examples

​```bash
githooks comando arg                    # Caso básico
githooks comando arg --opcion           # Con opción
​```

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success. |
| `1` | Failure. |

## See also

- [Página relacionada](../ruta/pagina.md)
```

## Página de How-To Guide

Ubicación: `docs/how-to/{guide}.md`

```markdown
# Título orientado al problema

Descripción breve del escenario.

## El problema / escenario

Contexto de por qué alguien necesita esto.

## La solución

​```php
// Ejemplo de configuración
​```

## Variantes

Otros escenarios relacionados con ejemplos.

## See also

- Enlaces a páginas de referencia relacionadas.
```

## Página de migración

Ubicación: `docs/migration/{from}.md`

```markdown
# From X to Y

## Concept mapping

| X concept | GitHooks equivalent |
|---|---|

## Example migration

Config original y equivalente en GitHooks.

## Key differences

| Feature | X | GitHooks |
|---|---|---|

## Steps

Lista numerada de pasos para migrar.
```

## Página índice de sección

Ubicación: `docs/{section}/index.md`

```markdown
# Nombre de la sección

Párrafo introductorio breve.

| Page | Description |
|---|---|
| [Página](pagina.md) | Descripción breve. |
```
