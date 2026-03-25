---
name: block-src-importing-app
enabled: true
event: file
conditions:
  - field: file_path
    operator: regex_match
    pattern: src/.*\.php$
  - field: new_text
    operator: regex_match
    pattern: use\s+Wtyd\\GitHooks\\App\\
action: block
---

**Violación de dirección de dependencias: `src/` no puede importar de `app/`**

La arquitectura del proyecto establece que la dependencia va de `app/` → `src/`, nunca al revés.
`src/` es la librería core y `app/` es la capa CLI de Laravel Zero que depende de ella.

Si necesitas funcionalidad de `app/` en `src/`:
- Extrae la lógica a una interfaz en `src/` e implementa en `app/`
- O mueve la clase compartida a `src/`
