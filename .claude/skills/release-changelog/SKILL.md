---
name: release-changelog
description: >
  Genera el archivo de release notes (CHANGELOG-X.Y.Z.md) para pegar en
  GitHub Releases a partir de la sección [X.Y.Z] ya escrita en
  docs/changelog.md. Usa esta skill al cerrar un release: cuando el usuario
  pida "preparar release notes", "generar changelog de release",
  "CHANGELOG-X.Y.Z.md", "release notes para GitHub", o cuando se vaya a
  publicar una release y haga falta el texto pulido para GitHub Releases.
  NO usar durante el desarrollo — docs/changelog.md se mantiene en caliente
  por tarea, fuera de esta skill.
---

# Release Changelog Generator

Esta skill destila la sección `[X.Y.Z]` de [docs/changelog.md](../../../docs/changelog.md) hacia `CHANGELOG-X.Y.Z.md` en la raíz del repo, listo para pegar en GitHub Releases.

`docs/changelog.md` es el changelog "interno" (rico, con detalle técnico, mantenido durante el desarrollo). El archivo de release notes es el destilado externo: compacto, en inglés, sin internals, con ejemplos de código seleccionados y enlaces al sitio MkDocs.

Plantilla canónica: [`references/template.md`](references/template.md). Referencia viva: [CHANGELOG-3.2.0.md](../../../CHANGELOG-3.2.0.md).

## Cuándo usarla

| Situación | Acción |
|---|---|
| Usuario va a publicar release y pide release notes | Usar la skill |
| `docs/changelog.md` ya tiene la sección `[X.Y.Z]` consolidada | Usar la skill |
| Sección `[X.Y.Z]` no existe o está vacía | **Parar** y avisar — la skill no recopila info, solo destila |
| Durante el desarrollo de una feature | **No usar** — actualizar `docs/changelog.md` a mano en la tarea |
| Generar release notes desde git log/PRs | **No usar** — fuera de alcance, traería ruido |

## Input

**Único input válido**: [docs/changelog.md](../../../docs/changelog.md), sección `[X.Y.Z]`. Localizar con:

```
Grep pattern:"^## \[X\.Y\.Z\]" path:"docs/changelog.md" output_mode:"content" -n:true
```

Y leer desde esa línea hasta el siguiente `## [` (o EOF). Si la sección está vacía o ausente, parar y avisar al usuario — esta skill no inventa contenido.

## Reglas de transformación

### Mantener (con reformulación si hace falta)

- **Features de cara al usuario**: nuevos comandos, nuevos flags, nuevos formatos, nuevos job types, nuevos keywords de configuración.
- **Breaking changes**: deprecaciones, requisitos mínimos elevados, formatos retirados.
- **Bug fixes visibles**: fallos que el usuario puede observar (output mal formado, exit codes, contadores erróneos, comportamiento incorrecto en ciertos contornos).
- **Cambios de comportamiento por defecto** que el usuario notará (ej. "live streaming en lugar de buffered").

### Descartar

- **Clases internas, refactors, renombrados** (`ToolRegistry`, `JobConfiguration`, `FlowExecutor` extraído de X…). Aunque estén en `docs/changelog.md`, fuera de release notes.
- **Mejoras de tooling de desarrollo** (CI propia, scripts de build, mejoras de cobertura, MSI subido, dependencias de dev internas que no afectan al consumidor del `.phar`).
- **"Internal Improvements" / "Build Improvements"** salvo que el usuario final los note.
- **Bugs internos cerrados durante el dev** que nunca llegaron a una versión publicada.
- **Comentarios de QA, PHPStan, PHPMD, mensajes de tests**.

### Reformular

- **Títulos técnicos → descriptivos** cuando aporte: `Per-job cores reservation` puede pasar a `Per-job cores reservation` (mantener si el término ya es conocido) o `Configure parallelism once per job` si quieres orientarlo a beneficio. Decidir caso a caso: si la feature ya está documentada con un nombre técnico, mantener ese nombre para que el usuario lo encuentre.
- **Detalle interno → beneficio concreto**: "implementado con `posix_isatty(STDOUT)`" → "se activa automáticamente en TTY".
- **Lista plana de bullets → H3 + descripción + ejemplo** cuando una feature merece destacarse. Las features menores quedan como bullet bajo `### Other improvements`.

### Enlaces

| Origen (en `docs/changelog.md`) | Destino (en `CHANGELOG-X.Y.Z.md`) |
|---|---|
| `[texto](how-to/output-formats.md#json-v2)` | `[texto](https://wtyd.github.io/githooks/how-to/output-formats/#json-v2)` |
| `[texto](tools/phpcsfixer.md)` | `[texto](https://wtyd.github.io/githooks/tools/phpcsfixer/)` |
| `[texto](configuration/jobs.md#cores)` | `[texto](https://wtyd.github.io/githooks/configuration/jobs/#cores)` |

Reglas: quitar `.md`, añadir `/` final antes del `#anchor`, prefijar `https://wtyd.github.io/githooks/`. **Nunca** enlazar a `github.com/Wtyd/githooks/blob/master/docs/...`.

### Ejemplos de código

Añadir bloque ` ```bash ` o ` ```php ` cuando aclare uso:

- **Sí**: opciones nuevas (`--format=sarif`, `--output=PATH`), keywords de configuración, sintaxis de un nuevo job type.
- **No**: features descriptivas que se entienden sin ver código ("dashboard interactivo en TTY", "live streaming").

Un ejemplo por feature como mucho. Mínimo y autoexplicativo. No copiar bloques largos del changelog interno.

## Estructura del output

Ver [`references/template.md`](references/template.md) para la plantilla completa con notas de uso. Resumen:

```
## Breaking Changes              ← solo si aplica
## What's New
  ### <feature title>
  ### <feature title>
  …
### Other improvements           ← solo si hay >1 mejora menor
### Bug Fixes                    ← solo si hay bugs visibles
---
**Full changelog**: https://wtyd.github.io/githooks/changelog/
```

Notas:
- Sin H1 ni título de versión (GitHub Releases ya pone el título).
- Inglés, sin emojis.
- Sin sección "Installation/upgrade" salvo matiz real (cambio de tier PHP, migración obligatoria, breaking).

## Salida

Escribir `CHANGELOG-X.Y.Z.md` en la raíz del repo. Ya está cubierto por `*.md` en `.gitignore` (verificable con `git check-ignore -v CHANGELOG-X.Y.Z.md`), no hay que tocar nada más.

Tras crear el archivo, confirmar al usuario:
- Ruta del archivo creado.
- Lista de items que se han descartado del input (clases internas, refactors, etc.) para que pueda revisar si alguno debería entrar.
- Recordatorio: copiar al body de la release en GitHub.

## Anti-patrones

- **No mezclar idiomas** — todo el cuerpo en inglés. El frontmatter de la skill está en castellano (consistente con el resto del proyecto), el output no.
- **No usar emojis** — los release notes de v3.0.0 y v3.1.0 no los usan; mantener consistencia.
- **No incluir "Installation/upgrade"** salvo que el upgrade tenga un matiz real. La 3.1.0 no la lleva; la 3.2.0 que escribí inicialmente sí, y se quitó por innecesaria.
- **No enlazar a `github.com/Wtyd/githooks/blob/master/docs/...`** — usar siempre `https://wtyd.github.io/githooks/`.
- **No incluir refactors, clases internas, mejoras de cobertura ni cambios de CI propios**. Aunque estén en `docs/changelog.md`. La regla es "qué se nota desde fuera del proyecto".
- **No inventar features no presentes en `docs/changelog.md`** — si crees que falta algo, parar y avisar al usuario; no recopilar de git log.
- **No actualizar `docs/changelog.md`** — la skill solo lee. El changelog interno se mantiene a mano durante el desarrollo.

## Checklist de verificación

Antes de reportar terminado:

- [ ] Archivo `CHANGELOG-X.Y.Z.md` existe en la raíz del repo.
- [ ] No contiene emojis.
- [ ] Todo el cuerpo está en inglés.
- [ ] Cada feature de cara al usuario es un H3 bajo `## What's New`.
- [ ] Las mejoras menores están agrupadas bajo `### Other improvements` (si hay >1).
- [ ] Los bugs visibles están bajo `### Bug Fixes` (si hay alguno).
- [ ] Si hay breaking changes, llevan su propia sección `## Breaking Changes` arriba.
- [ ] Todos los enlaces apuntan a `https://wtyd.github.io/githooks/...` con `/` final y sin `.md`.
- [ ] Footer presente: `**Full changelog**: https://wtyd.github.io/githooks/changelog/`.
- [ ] No hay sección "Installation/upgrade" salvo que aplique por matiz real.
- [ ] No quedan referencias a clases internas, refactors, MSI, cobertura ni cambios de CI propios.
- [ ] Los ejemplos de código son mínimos y autoexplicativos (un bloque por feature como mucho).
- [ ] Verificación informal: comparar output con [CHANGELOG-3.2.0.md](../../../CHANGELOG-3.2.0.md); el patrón debe ser consistente.
