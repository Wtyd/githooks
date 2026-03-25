---
name: warn-php-syntax-and-quality
enabled: true
event: file
conditions:
  - field: file_path
    operator: regex_match
    pattern: \.php$
  - field: new_text
    operator: regex_match
    pattern: (public|protected|private)\s+(readonly\s+)?(int|string|float|bool|array|object|mixed|null|self|static|parent|iterable)\s+\$|(\bfn\s*\()|(\bmatch\s*\()|(\breadonly\s+(class|int|string|float|bool|array))|(\benum\s+\w+)|(\#\[)|(===|!==)\s+[1-9]\d*\b
action: warn
---

**Problema de calidad o compatibilidad detectado en fichero PHP**

Revisa cuál de estos problemas aplica:

### Syntax incompatible con PHP 7.1
- **Typed properties** (`public string $name`) — requiere PHP 7.4 → usa PHPDoc `/** @var string */`
- **Arrow functions** (`fn($x) => ...`) — requiere PHP 7.4 → usa `function ($x) { return ...; }`
- **`match` expression** — requiere PHP 8.0 → usa `switch`
- **`readonly`** — requiere PHP 8.1
- **`enum`** — requiere PHP 8.1 → usa constantes de clase
- **Attributes** (`#[Route]`) — requiere PHP 8.0

### Magic number en comparación
Si hay un literal numérico (distinto de 0) en `===`/`!==`, extráelo a una constante:
```php
// Mal
if ($exitCode === 1) {

// Bien
public const EXIT_CODE_FILES_FIXED = 1;
if ($exitCode === self::EXIT_CODE_FILES_FIXED) {
```
