---
name: docs
description: >
  Gestión de la documentación externa de GitHooks (MkDocs + Material for MkDocs).
  Usa esta skill cuando el usuario quiera añadir, modificar o reorganizar páginas de documentación,
  actualizar el sitio de docs, añadir una guía, documentar una feature nueva,
  o cuando mencione "docs", "documentación", "mkdocs", "página de docs".
---

# Documentación externa de GitHooks

La documentación se genera con MkDocs + Material for MkDocs y se despliega en GitHub Pages (`https://wtyd.github.io/githooks/`).

## Estructura del proyecto

```
mkdocs.yml                  ← Configuración MkDocs (nav, theme, plugins, extensions)
docs/
  index.md                  ← Landing page
  execution-modes.md        ← full/fast/fast-branch
  comparison.md             ← vs GrumPHP, CaptainHook
  getting-started/          ← Tutorial (3 páginas)
  configuration/            ← Referencia de config (6 páginas)
  tools/                    ← Una página por herramienta QA (9 páginas)
  cli/                      ← Una página por comando (10 páginas)
  how-to/                   ← Guías prácticas (8 páginas)
  migration/                ← Guías de migración (4 páginas)
.github/workflows/docs.yml  ← GitHub Action: auto-deploy en push a master
```

## Flujo de decisión

### ¿Qué tipo de cambio necesitas?

| Cambio | Acción |
|---|---|
| **Nueva herramienta QA** (ej: PHP CS Fixer) | Crear `docs/tools/phpcsfixer.md` + añadir a nav en `mkdocs.yml` + actualizar `docs/tools/index.md` |
| **Nuevo comando CLI** | Crear `docs/cli/nuevo-comando.md` + añadir a nav + actualizar `docs/cli/index.md` |
| **Nueva opción en comando existente** | Actualizar la página del comando en `docs/cli/` + actualizar `docs/getting-started/` si afecta al tutorial |
| **Nueva feature configurable** | Actualizar `docs/configuration/` (sección correspondiente) + crear how-to si aplica |
| **Nueva guía práctica** | Crear `docs/how-to/nombre.md` + añadir a nav + actualizar `docs/how-to/index.md` |
| **Cambio en comportamiento existente** | Actualizar las páginas afectadas (buscar con grep en docs/) |
| **Nueva versión major** | Actualizar migration/, comparison.md, y landing page |

### ¿Dónde se documenta cada cosa?

| Contenido | Página |
|---|---|
| Keywords de una herramienta QA | `docs/tools/{tool}.md` |
| Opciones de un comando CLI | `docs/cli/{command}.md` |
| Sección hooks/flows/jobs/options del config | `docs/configuration/{section}.md` |
| Modos full/fast/fast-branch | `docs/execution-modes.md` |
| Recetas y escenarios | `docs/how-to/` |
| Comparativa con competencia | `docs/comparison.md` |
| Migración desde otras herramientas | `docs/migration/` |

## Paso 1: Leer la referencia de la estructura actual

Antes de añadir o modificar, lee `references/page-templates.md` para ver la estructura estándar de cada tipo de página.

## Paso 2: Hacer el cambio

### Añadir una nueva página

1. Crear el fichero `.md` en el directorio correspondiente.
2. Añadir la entrada en `nav:` de `mkdocs.yml`.
3. Si el directorio tiene `index.md`, actualizar la tabla de contenidos.

### Modificar una página existente

1. Leer la página actual.
2. Hacer el cambio manteniendo la estructura de la página (ver templates).
3. Verificar que los enlaces internos siguen funcionando.

### Actualizar la navegación

El nav se define en `mkdocs.yml`. Cada sección usa `index.md` como página de índice de sección:

```yaml
nav:
  - Tools Reference:
    - tools/index.md          # Página de sección (navigation.indexes)
    - PHPStan: tools/phpstan.md
    - Nueva Tool: tools/nueva.md  # ← Añadir aquí
```

## Paso 3: Verificar

```bash
# Build con validación estricta (detecta links rotos)
mkdocs build --strict

# Preview local con hot-reload
mkdocs serve

# Build para producción
mkdocs build -d public
```

## Paso 4: Sincronizar README

Si el cambio afecta a algo que también está en el README (nueva herramienta, nuevo comando, cambio en install), actualizar también `README.md`.

El README debe ser un resumen conciso que enlaza a la documentación completa. No duplicar contenido extenso.

**Enlaces en el README:**
- Referencia de config → `https://wtyd.github.io/githooks/configuration/`
- Referencia de comandos → `https://wtyd.github.io/githooks/cli/`
- Wiki (legacy, mantener hasta que la docs esté consolidada)

## Convenciones

### Formato de páginas

- **Inglés** para todo el contenido de docs (el proyecto es en inglés).
- **Markdown estándar** + extensiones Material (admonitions, tabs, mermaid).
- **Ejemplos de código** con bloques PHP o bash anotados.
- **Tablas** para referencia de keywords y opciones.

### Estructura de una página de herramienta

```markdown
# Nombre de la herramienta

Descripción en una línea.

- **Type:** `tipo`
- **Accelerable:** Yes/No
- **Default executable:** `vendor/bin/tool`

## Keywords

| Keyword | Type | Description | Example |
|---|---|---|---|

## Examples

Minimal + Full

## Threading / Cache (si aplica)
```

### Estructura de una página de comando CLI

```markdown
# githooks comando

Descripción breve.

## Synopsis
## Options (tabla)
## Examples
## Exit codes
## See also
```

### Admonitions disponibles

```markdown
!!! tip "Título opcional"
    Contenido del tip.

!!! note
    Nota informativa.

!!! warning
    Advertencia importante.

!!! important
    Información crítica.
```

### Diagramas Mermaid

```markdown
```mermaid
graph LR
    A[Hook] --> B[Flow]
    B --> C[Job]
```​
```

## Checklist de verificación

- [ ] Página creada/modificada en el directorio correcto
- [ ] Entrada añadida en `nav:` de `mkdocs.yml` (si es página nueva)
- [ ] `index.md` de la sección actualizado (si es página nueva)
- [ ] `mkdocs build --strict` pasa sin errores
- [ ] Enlaces internos verificados (rutas relativas correctas)
- [ ] README actualizado si el cambio afecta a info que está allí
- [ ] Contenido en inglés
- [ ] Ejemplos de código probados o verificados
