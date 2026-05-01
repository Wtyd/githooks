# Factors — InputFiles ↔ job.paths matcher

Componente: `ExecutionContext::fileIsInPaths(string $file, array $paths): bool`
(usado por `filterFilesForPaths()` y `filterFilesForMode()`).

## Invariante

`fileIsInPaths` devuelve `true` si y solo si `$file` apunta físicamente al
mismo recurso que algún `$path` (igualdad de fichero) o vive bajo el directorio
`$path`, **independientemente de si `$file` es CWD-relativo o absoluto** —
siempre que el path absoluto, si lo es, viva bajo el CWD del proceso. Los
paths absolutos fuera del CWD se consideran fuera de cualquier `$path` del
job (que siempre es relativo a la raíz del proyecto).

## Factores

| Factor | Clases de equivalencia | Valores AVL |
|---|---|---|
| Forma de `$file` | rel-CWD, abs-en-CWD, abs-fuera-CWD | `src/Foo.php`, `/cwd/src/Foo.php`, `/other/Foo.php` |
| Forma de `$path` (job) | rel-CWD (siempre — viene del config) | `src`, `src/Foo.php` |
| Tipo de `$path` | dir prefijo, fichero exacto | `src` (`is_dir`), `src/Foo.php` (`is_file`) |
| Match físico | dentro/igual, fuera | — |

## Decision table

| # | `$file` | `$path` | Tipo | Esperado | Estado pre-fix |
|---|---|---|---|---|---|
| 1 | rel `src/Foo.php`              | `src`         | dir prefijo    | `true`  | OK (control)             |
| 2 | abs-en-CWD `/cwd/src/Foo.php`  | `src`         | dir prefijo    | `true`  | **FAIL (V33-029)**       |
| 3 | rel `src/Foo.php`              | `src/Foo.php` | fichero exacto | `true`  | OK (control)             |
| 4 | abs-en-CWD `/cwd/src/Foo.php`  | `src/Foo.php` | fichero exacto | `true`  | **FAIL**                 |
| 5 | rel `tests/Bar.php`            | `src`         | dir prefijo    | `false` | OK (control)             |
| 6 | abs-en-CWD `/cwd/tests/Bar.php`| `src`         | dir prefijo    | `false` | OK (accidental: el normalize-fail acertó) |
| 7 | abs-fuera-CWD `/other/Foo.php` | `src`         | dir prefijo    | `false` | OK (no comparte prefijo) |

## Clase patógena

Filas 2 y 4: `$file` absoluto y dentro del CWD vs `$path` relativo. El matcher
real (`FileUtils::directoryContainsFile` y `FileUtils::isSameFile`) compara
strings literalmente sin normalizar, así que la igualdad falla.

## Fix

En `fileIsInPaths`, normalizar `$file` a CWD-relative cuando es absoluto y
comparte prefijo con el CWD, antes de delegar al matcher. La fila 7 sigue
devolviendo `false` porque la normalización no acorta paths fuera del CWD.

Output del filtro: se preserva el `$file` original (lo que el usuario pasó).
La normalización es solo para el cruce contra `paths`.
