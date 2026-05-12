# Tool output fixtures — BUG-1 regression suite

Capturas reales (exit code, stdout, stderr, comando) de cada QA tool cuando
se la ejecuta contra distintos escenarios. Generadas durante el QA de
v3.3.3 (BUG-1: fast-branch ignora exclusiones internas del tool).

Sirven para escribir tests unitarios que **doblan** la ejecución del proceso
con estas salidas reales — sin necesitar la tool real instalada en el runner.

## Layout

```
{tool}/
  {scenario}.cmd       ← comando shell que se ejecutó
  {scenario}.exit      ← exit code (1 línea)
  {scenario}.stdout    ← stdout capturado
  {scenario}.stderr    ← stderr capturado
```

## Escenarios

- `A_valid_php`             — fichero PHP válido (control: la tool procesa normalmente).
- `B_php_out_of_scope`      — fichero PHP fuera del directorio típico de la tool.
- `C_md` / `C_json`         — fichero no-PHP (verifica que la tool no crashea).
- `D_excluded_all`          — config interna de la tool excluye TODO el input → trigger BUG-1.
- `E_mixed_md_php`          — argumento mixto (no-PHP + PHP).

## Tools cubiertas (versiones del momento de captura)

| Tool | Versión | Notas |
|---|---|---|
| phpstan | 2.2.x-dev | **Dispara BUG-1** en escenario D (exit 1 + "No files found to analyse") |
| phpcs (Squiz) | 3.13.6 | Exit 0 silente — no necesita reinterpretación |
| phpcs (PHPCSStandards 4.x) | 4.0.1 | **Dispara BUG-1 con exit 16** + "All specified files were excluded" (carpeta `phpcs-4.0.1/`) |
| phpcbf | 3.13.6 | Hereda phpcs |
| phpmd | 2.15.0 | Tolera nativamente (exit 1 con error reporting, no por empty set) |
| parallel-lint | 1.4.0 | Procesa todo como PHP (no respeta excludes en args) |
| psalm | 6.x-dev | Respeta ignoreFiles silenciosamente |
| rector | 2.4.3 | Procesa silenciosamente |
| php-cs-fixer | 3.95.1 | Sobreescribe paths con args, procesa solo PHP |
| phpunit | 9.6.34 | A_passing / B_failing |
| phpcpd | 6.0.3 | Sin estado especial |

## Capturas adicionales via githooks (`_via-githooks/`)

Outputs JSON estructurados del wrapper (`githooks job/flow --format=json`)
para validar la reinterpretación a `skipped: true`:

- `phpstan_excluded_BUG1.json` — escenario D, debe salir `skipped: true`.
- `phpcs4_excluded_BUG1.json` — phpcs 4.0.1 exit 16, debe salir `skipped: true`.
- `phpstan_real_error.json` — error real, debe salir `success: false skipped: false`.
- `fastbranch_BUG1.json` — escenario real del cliente (PROD-4492).
