# Plantilla canónica de release notes

Basada en el estilo de [v3.1.0](https://github.com/Wtyd/githooks/releases/tag/v3.1.0) y lo aprendido al reescribir la 3.2.0. Referencia viva: [CHANGELOG-3.2.0.md](../../../../CHANGELOG-3.2.0.md).

## Esqueleto

```markdown
## Breaking Changes

- <punto por ruptura, una línea>
- <…>

## What's New

### <Feature title — frase corta o nombre técnico conocido>

<1-3 líneas explicando el beneficio concreto. No descripción interna.>

```bash
<ejemplo mínimo si aporta — opcional>
```

Ver [<doc anchor>](https://wtyd.github.io/githooks/<path>/).

### <Feature 2>

<…>

### Other improvements

- <mejora menor, una línea por bullet>
- <…>

### Bug Fixes

- <bug visible, una línea por bullet>
- <…>

---

**Full changelog**: https://wtyd.github.io/githooks/changelog/
```

## Notas de uso

### Secciones opcionales

| Sección | Cuándo incluir | Cuándo omitir |
|---|---|---|
| `## Breaking Changes` | Hay al menos una ruptura (API, config, comando retirado, PHP mínimo subido, formato deprecado) | No hay rupturas. **Nunca** poner "Breaking Changes: none" |
| `### Other improvements` | ≥2 mejoras menores que no merecen H3 propio | 0-1 mejoras menores (integrarla en la feature correspondiente o como bullet suelto) |
| `### Bug Fixes` | ≥1 bug visible desde fuera | 0 bugs visibles. **No** incluir bugs internos cerrados durante el dev |
| Sección "Installation/upgrade" | Matiz real: cambio de tier PHP, migración obligatoria, paso manual de upgrade | Upgrade estándar (`composer update wtyd/githooks`). La 3.1.0 no la lleva; por defecto, no la pongas |

### Cómo elegir qué features llevan ejemplo

| Lleva ejemplo | No lleva ejemplo |
|---|---|
| Opción nueva (`--format=sarif`, `--output=PATH`) | "Live streaming reemplaza buffered output" |
| Nuevo keyword de configuración (`cores: N`) | "Interactive parallel dashboard en TTY" |
| Nuevo job type (sintaxis de `type: rector`) | "stdout/stderr split para structured formats" |
| Sintaxis no obvia (`--` para pasar args) | Cambios de comportamiento por defecto |

Máximo un ejemplo por feature. Mínimo, autoexplicativo, sin contexto inventado.

### Cómo nombrar los H3

- **Si la feature ya está documentada con un nombre técnico**, úsalo (`JSON schema v2`, `Per-job cores reservation`). Facilita que el usuario encuentre la referencia cruzada.
- **Si es un cambio de comportamiento sin nombre fijo**, describe el beneficio (`Live tool output`, `Structured formats stay clean by default`).
- Evitar verbos en imperativo (`Add live streaming…`) y gerundios largos. Nombre corto, estilo "headline".

### Enlaces

Siempre al sitio MkDocs, nunca al blob de GitHub:

| Origen | Destino |
|---|---|
| `docs/how-to/output-formats.md#json-v2` | `https://wtyd.github.io/githooks/how-to/output-formats/#json-v2` |
| `docs/tools/phpcsfixer.md` | `https://wtyd.github.io/githooks/tools/phpcsfixer/` |

### Orden de las features dentro de `## What's New`

Criterio: **impacto percibido** por el usuario, no orden de aparición en `docs/changelog.md`.

1. Cambios de comportamiento que se notan al instante (ej. "Live tool output").
2. Features nuevas de uso frecuente (formatos, flags que mucha gente usará).
3. Features nuevas de nicho (job types específicos, opciones avanzadas).
4. `Other improvements` → `Bug Fixes`.

Si no está claro el impacto, seguir el orden del changelog interno.

## Anti-patrones que corregir

Estos son los "olores" típicos a buscar y corregir antes de dar por cerrado el archivo:

- **H3 que es un nombre de clase**: `ToolRegistry extraction` → fuera, es interno.
- **Bullet que describe un refactor**: "Typed properties added to X, Y, Z" → fuera.
- **Feature con enlace a `github.com/.../blob/master/docs/...`** → cambiar al sitio MkDocs.
- **Sección "Internal Improvements" completa** → borrar, no pertenece a release notes.
- **Mezcla de inglés y castellano en el cuerpo** → todo inglés.
- **Emojis en los H2/H3** (`## ✨ New features`) → quitar.
- **Bullets con cobertura / MSI / PHPStan** → quitar salvo que se hayan publicado números como release highlight explícito.
- **"No breaking changes" como bullet** → la ausencia de ruptura se expresa omitiendo la sección `## Breaking Changes`.
- **Sección "Installation" copiada de otras releases sin matiz real** → quitar.

## Ejemplo completo de referencia

Ver [CHANGELOG-3.2.0.md](../../../../CHANGELOG-3.2.0.md) en la raíz del repo. Notar:

- Sin H1.
- Sin sección `## Breaking Changes` (no había rupturas).
- 10 H3 bajo `## What's New`, ordenados por impacto.
- `### Other improvements` para las dos mejoras menores.
- `### Bug Fixes` para dos bugs visibles.
- Footer con el enlace al sitio docs.
- Ejemplos de código solo donde aportan (`--format=codeclimate`, jq, etc.).
- Todos los enlaces a `https://wtyd.github.io/githooks/...`.
