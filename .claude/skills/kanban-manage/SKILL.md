---
name: kanban-manage
description: >
  Gestión del kanban local del proyecto GitHooks (`.kanban-tasks/.kanban`,
  extensión `harehare.portable-kanban`). Usa esta skill cuando el usuario quiera
  añadir, mover, actualizar, cerrar o eliminar tasks del backlog; cuando pida
  "añade al kanban", "mueve FEAT-X a Doing", "cierra BUG-X", "haz limpieza
  post-release", "crea una idea nueva", "qué labels lleva esta task", o cuando
  haya que crear el `.md` de detalle de una task siguiendo las plantillas. NO
  uses esta skill para edición que el usuario haga directamente desde la UI del
  editor (drag-and-drop, asignación visual de labels) — solo para operaciones
  iniciadas por Claude.
---

# Kanban Manage — Gestión del backlog del proyecto

## Estructura

```
.kanban-tasks/                   ← gitignored entero (no se trackea nada).
├── .kanban                      ← Estado del editor (lists, cards, labels).
├── docs/                        ← <ID>-<slug>.md por task.
└── templates/
    ├── feature.md               ← Plantilla L (features grandes).
    └── bug.md                   ← Plantilla S (bugs / chores pequeños).
```

**Regla dura**: el `.kanban` es JSON validado por la extensión con un decoder estricto. Un edit malformado lo deja ilegible. **Nunca editar con `Edit` el `.kanban` directamente** — siempre cargar con Python, modificar el dict, escribir con `json.dump(..., indent=2, ensure_ascii=False)`. Snippets más abajo.

## Convenciones

### Columnas y ciclo de vida

| Columna | Significado | Entrada | Salida |
|---|---|---|---|
| `Ideas` | Sin triar. Brainstorm, exploratorias. | Cualquier ocurrencia. | Triada con caso de uso + label de versión. |
| `Backlog` | Triada, con shape, futura. | Tiene tipo + prioridad + área + versión target. | Asignada al cycle activo. |
| `To Do` | Cola del ciclo en curso. | Decidida para la versión actual. | Empezada. |
| `Doing` | En progreso. | WIP — no hard limit, pero típicamente 1-2. | Mergeada o cerrada. |
| `Done` | Cerrada en el ciclo. **Transitoria.** | Mergeada en la rama de la versión. | Release publicada → eliminación. |

**Tras publicar v3.X.Y** se borran las cards de `Done` con esa versión + sus `.md` (con margen de unos días). Fuente de verdad post-release: `docs/changelog.md`.

**Archive** (botón "Archive" en cada card, UI de `harehare.portable-kanban`): mueve la card a una colección oculta `k["archive"]["cards"]` fuera de las listas. **Usar para won't-fix o pospuesto sin versión**: ideas/bugs que se descartan o se posponen indefinidamente, pero cuyo `.md` se quiere conservar como contexto histórico (a diferencia de `Done`, que se vacía cada release). El `.md` asociado **no se borra** al archivar; queda en `docs/` por si la card se restaura en el futuro.

| Estado | Cuándo | Visibilidad |
|---|---|---|
| `Done` | Cerrada en el ciclo, mergeada → se elimina post-release | Visible en el board |
| `Archive` | Won't-fix o pospuesto sin versión target | Oculta del board, recuperable desde la UI |

### Títulos e IDs

- **`BUG-N · descripción corta`** para bugs y **`FEAT-N · descripción corta`** para features. Separador `·`. Descripción ≤ 70 chars.
- Versión y prioridad **NO van en el título** — van en labels (filtrables).
- Cards pequeñas (chores, refactors menores, docs, tests) **pueden ir sin ID** — solo título.
- **Contadores independientes** para BUG y FEAT. Antes de asignar un nuevo ID, comprobar el siguiente disponible (ver snippet *Siguiente ID*).

### Score y coste

| Score | Significado |
|---|---|
| 9-10 | Imprescindible / regresión / bloqueante |
| 7-8 | Alto valor, claro caso de uso, bajo riesgo |
| 5-6 | Útil, cuestionable timing |
| 3-4 | Marginal, "solo si sobra" |
| 1-2 | Reconsiderar si merece estar en backlog |

| Coste | Significado |
|---|---|
| `bajo` | <1 día, cambio localizado, tests existentes cubren |
| `medio` | 1-3 días, varios módulos, tests nuevos |
| `alto` | >3 días, refactor estructural, decisiones abiertas |

### Áreas, versiones, marcadores

- **Áreas**: `cli`, `config`, `execution`, `output`, `hooks`, `qa-tools`, `build`, `tests`, `infra`, `docs`. Una card puede tener varias.
- **Versiones**: `v3.3`, `v3.3.1`, `v3.4`, `v3.5`, `next`. Vacío en `Ideas`. Obligatorio a partir de `Backlog`.
- **Marcadores**: `blocked`, `needs-info`, `breaking`, `exploratory`, `epic:<slug>`. 0 o más.

## Catálogo de labels (en `settings.labels`)

| Eje | Labels | Color |
|---|---|---|
| **tipo** (1 obligatoria) | `tipo: bug` | `#eb5a46` rojo |
| | `tipo: feat` | `#61bd4f` verde |
| | `tipo: refactor` | `#0079bf` azul |
| | `tipo: docs` | `#00c2e0` cian |
| | `tipo: test` | `#f2d600` amarillo |
| | `tipo: chore` | `#344563` gris azulado |
| **prioridad** (1 obligatoria) | `prioridad: crit` | `#7f1e28` rojo oscuro |
| | `prioridad: alta` | `#eb6f01` naranja oscuro |
| | `prioridad: med` | `#ff9f1a` naranja |
| | `prioridad: baja` | `#FF7F50` coral |
| **versión** (0-1) | `versión: v3.3` `v3.3.1` `v3.4` `v3.5` `next` | `#c377e0` morado (todas) |
| **área** (1+) | `área: cli` `config` `execution` `output` `hooks` `qa-tools` `build` `tests` `infra` `docs` | `#0079bf` azul (todas) |
| **marcadores** (0+) | `blocked` `needs-info` `breaking` `exploratory` `epic:<slug>` | `#FF4500` naranja-rojo (todos) |

**Coincidencias de color** (intencionales — la paleta de la extensión solo tiene 14 hex):
- `tipo: refactor` y todas las `área: *` comparten azul. Diferenciación por prefijo `tipo:` / `área:`.
- `tipo: chore` (gris azulado) es color único.

**Para crear un epic nuevo**: añadir un label `epic:<slug>` al catálogo con color `#FF4500`. Aplicar a las 2+ cards que componen el epic.

## Operaciones

Todas las operaciones siguen el patrón "leer JSON → modificar dict → escribir JSON". Plantilla base:

```bash
python3 <<'PY'
import json
PATH = "/var/www/html1/.kanban-tasks/.kanban"
with open(PATH) as f:
    k = json.load(f)
# … modificar k …
with open(PATH, "w") as f:
    json.dump(k, f, indent=2, ensure_ascii=False)
PY
```

### Siguiente ID (BUG-N o FEAT-N)

```bash
python3 <<'PY'
import json, re
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
nums = {"BUG": [0], "FEAT": [0]}
for lst in k["lists"]:
    for c in lst["cards"]:
        m = re.match(r"(BUG|FEAT)-(\d+)", c["title"])
        if m:
            nums[m.group(1)].append(int(m.group(2)))
for c in k.get("archive", {}).get("cards", []):
    m = re.match(r"(BUG|FEAT)-(\d+)", c["title"])
    if m:
        nums[m.group(1)].append(int(m.group(2)))
print("next BUG:", max(nums["BUG"]) + 1)
print("next FEAT:", max(nums["FEAT"]) + 1)
PY
```

También revisar los `.md` de `docs/` (referencias históricas) para no reusar un ID ya quemado.

### Añadir una card nueva

1. **Decide la columna**: `Ideas` (sin triar) o `Backlog` (con versión target).
2. **Asigna ID si aplica** (BUG-N o FEAT-N siguiendo el contador). Si es chore/refactor pequeño, sin ID.
3. **Crea el `.md`** en `.kanban-tasks/docs/<ID-slug>.md` o `<slug>.md` (sin ID), copiando de `templates/feature.md` o `templates/bug.md` y rellenando el front matter.
4. **Añade la card al `.kanban`** vía Python:

```bash
python3 <<'PY'
import json, uuid
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
target = next(l for l in k["lists"] if l["title"] == "Backlog")  # o "Ideas"
labels_index = {l["title"]: l for l in k["settings"]["labels"]}

def L(*titles):
    return [dict(labels_index[t]) for t in titles]

CB = ["Código", "Tests", "QA pasa", "Docs", "Changelog"]

card = {
    "id": str(uuid.uuid4()),
    "listId": target["id"],
    "title": "FEAT-14 · descripción corta",
    "description": (
        "**Caso**: resumen 3-5 líneas.\n\n"
        "**Score**: X/10 · **Coste**: bajo/medio/alto · **Versión**: v3.X\n\n"
        "Detalle: `.kanban-tasks/docs/FEAT-14-slug.md`"
    ),
    "labels": L("tipo: feat", "prioridad: med", "versión: v3.4", "área: execution"),
    "checkboxes": [{"id": str(uuid.uuid4()), "title": t, "checked": False} for t in CB],
    "comments": [],
}
target["cards"].append(card)
json.dump(k, open("/var/www/html1/.kanban-tasks/.kanban", "w"), indent=2, ensure_ascii=False)
PY
```

### Mover una card entre columnas

```bash
python3 <<'PY'
import json
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
target_title = "Doing"      # destino
match_prefix = "FEAT-1 ·"   # identificar la card
target = next(l for l in k["lists"] if l["title"] == target_title)
moved = None
for lst in k["lists"]:
    for i, c in enumerate(lst["cards"]):
        if c["title"].startswith(match_prefix):
            moved = lst["cards"].pop(i)
            break
    if moved: break
moved["listId"] = target["id"]
target["cards"].append(moved)
json.dump(k, open("/var/www/html1/.kanban-tasks/.kanban", "w"), indent=2, ensure_ascii=False)
PY
```

### Actualizar descripción / labels de una card

```bash
python3 <<'PY'
import json
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
labels_index = {l["title"]: l for l in k["settings"]["labels"]}

def L(*titles):
    return [dict(labels_index[t]) for t in titles]

for lst in k["lists"]:
    for c in lst["cards"]:
        if c["title"].startswith("FEAT-1 ·"):
            c["description"] = "nuevo resumen…"
            c["labels"] = L("tipo: feat", "prioridad: alta", "versión: v3.4", "área: execution", "área: config", "epic:flow-entry-attrs")
            break

json.dump(k, open("/var/www/html1/.kanban-tasks/.kanban", "w"), indent=2, ensure_ascii=False)
PY
```

### Cerrar una card (marcar checkboxes y mover a Done)

Antes de cerrar: confirmar que **todos los AC del `.md`** están verificados (los `[ ]` en el `.md` pasan a `[x]`).

```bash
python3 <<'PY'
import json
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
done = next(l for l in k["lists"] if l["title"] == "Done")
match = "FEAT-1 ·"
for lst in k["lists"]:
    for i, c in enumerate(lst["cards"]):
        if c["title"].startswith(match):
            for cb in c["checkboxes"]:
                cb["checked"] = True
            c["listId"] = done["id"]
            done["cards"].append(lst["cards"].pop(i))
            break
json.dump(k, open("/var/www/html1/.kanban-tasks/.kanban", "w"), indent=2, ensure_ascii=False)
PY
```

### Eliminar una card (release cleanup)

Tras publicar v3.X.Y y mover lo relevante a `docs/changelog.md`. Borra cards de `Done` con `versión: vX.Y.Z` Y sus `.md` asociados.

**Antes de borrar**, preguntar al usuario: "¿algo del `.md` de esta card debe sobrevivir en `CLAUDE.md`/`docs/`?". Solo entonces borrar.

```bash
VERSION="v3.3.1"
python3 - <<PY
import json, os, re
k = json.load(open("/var/www/html1/.kanban-tasks/.kanban"))
done = next(l for l in k["lists"] if l["title"] == "Done")
to_remove = [c for c in done["cards"]
             if any(lbl["title"] == "versión: $VERSION" for lbl in c["labels"])]
for c in to_remove:
    print("Remove:", c["title"])
    done["cards"].remove(c)
json.dump(k, open("/var/www/html1/.kanban-tasks/.kanban", "w"), indent=2, ensure_ascii=False)
PY
# Después: rm los .md correspondientes (no están tracked, no hay git rm)
```

### Archivar / restaurar una card

Para **won't-fix** o **pospuesto sin versión**: card sale del board pero sobrevive en `k["archive"]["cards"]`. El `.md` se conserva (no borrar). Para restaurar, volver a `Ideas` o `Backlog` según corresponda.

```bash
# Archivar
python3 <<'PY'
import json
PATH = "/var/www/html1/.kanban-tasks/.kanban"
k = json.load(open(PATH))
match = "BUG-17 ·"
moved = None
for lst in k["lists"]:
    for i, c in enumerate(lst["cards"]):
        if c["title"].startswith(match):
            moved = lst["cards"].pop(i)
            break
    if moved: break
k.setdefault("archive", {"lists": [], "cards": []})["cards"].append(moved)
json.dump(k, open(PATH, "w"), indent=2, ensure_ascii=False)
PY

# Restaurar (de archive a Ideas)
python3 <<'PY'
import json
PATH = "/var/www/html1/.kanban-tasks/.kanban"
k = json.load(open(PATH))
match = "BUG-17 ·"
arch = k.get("archive", {}).get("cards", [])
for i, c in enumerate(arch):
    if c["title"].startswith(match):
        restored = arch.pop(i)
        ideas = next(l for l in k["lists"] if l["title"] == "Ideas")
        restored["listId"] = ideas["id"]
        ideas["cards"].append(restored)
        break
json.dump(k, open(PATH, "w"), indent=2, ensure_ascii=False)
PY
```

### Listar / inspeccionar el kanban

```bash
python3 -c "
import json
k = json.load(open('/var/www/html1/.kanban-tasks/.kanban'))
for lst in k['lists']:
    print(f'== {lst[\"title\"]} ({len(lst[\"cards\"])}) ==')
    for c in lst['cards']:
        labels = ', '.join(l['title'] for l in c['labels'])
        print(f'  - {c[\"title\"]}  [{labels}]')
arch = k.get('archive', {}).get('cards', [])
if arch:
    print(f'== Archive ({len(arch)}) ==')
    for c in arch:
        labels = ', '.join(l['title'] for l in c['labels'])
        print(f'  - {c[\"title\"]}  [{labels}]')
"
```

## Front matter de `.md`

Mínimo obligatorio:

```yaml
---
id: FEAT-N | BUG-N | null      # null si la card no lleva ID
title: descripción corta
tipo: feat | bug | refactor | docs | test | chore
prioridad: crit | alta | med | baja
version: vX.Y[.Z] | next | (vacío si Ideas)
area: [<una o más áreas>]
score: 1-10
coste: bajo | medio | alto
created: YYYY-MM-DD
---
```

Opcionales: `epic: <slug>`, `related: [IDs...]`, `updated: YYYY-MM-DD`.

## Reglas duras

1. **Nunca editar `.kanban` con `Edit`/`Write` directo a mano** — siempre vía Python para no romper el JSON.
2. **Todo `.kanban-tasks/` está gitignored** — `.kanban`, `docs/`, `templates/`. Ningún fichero de aquí va a git: backlog y `.md` de detalle son local-only por diseño. No proponerlos en un commit.
4. **Antes de borrar una card de `Done` post-release**, preguntar al usuario qué de su `.md` debe sobrevivir (a `CLAUDE.md` / `docs/changelog.md` / `docs/`).
5. **Title convention estricta**: `BUG-N · …` o `FEAT-N · …` con separador `·`. Sin prefijos `[v3.4]` ni `[ALTA]` (eso va en labels).
6. **Labels nuevas**: si añades una versión nueva (`v3.6`) o un epic nuevo, primero añadirla al `settings.labels` del kanban (no inventar la label en una card sin estar en el catálogo — la extensión la pintaría pero quedaría fuera del selector global).
7. **Acción del usuario tras editar**: cerrar y reabrir el `.kanban` en VSCode si lo tiene abierto — la extensión cachea el contenido.

## Checklist antes de declarar la operación terminada

- [ ] El `.kanban` sigue siendo JSON válido (re-leerlo con `json.load`).
- [ ] La card destino está en la columna correcta (verificar `listId`).
- [ ] Las labels asignadas existen en `settings.labels` (no inventadas).
- [ ] Si se creó card nueva: el `.md` correspondiente existe en `docs/` con front matter completo.
- [ ] Si se cerró card: todos los checkboxes están `checked: true` y los AC del `.md` están marcados.
- [ ] Si se eliminó card: el `.md` también se ha borrado (o el usuario ha decidido conservarlo).
- [ ] Avisar al usuario que cierre y reabra el `.kanban` en VSCode para refrescar la vista.
