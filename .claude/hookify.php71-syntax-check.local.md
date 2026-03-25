---
name: warn-php71-compat
enabled: true
event: file
conditions:
  - field: file_path
    operator: regex_match
    pattern: \.php$
  - field: new_text
    operator: regex_match
    pattern: (public|protected|private)\s+(readonly\s+)?(int|string|float|bool|array|object|mixed|null|self|static|parent|iterable)\s+\$|(\bfn\s*\()|(\bmatch\s*\()|(\breadonly\s+(class|int|string|float|bool|array))|(\benum\s+\w+)|(\#\[)
action: warn
---

**Syntax incompatible con PHP 7.1 detectada**

Este proyecto requiere PHP >=7.1. El `.phar` se compila para 3 versiones (7.1, 7.3, 8.1+).

**Syntax prohibida** (detectada en tu código):
- **Typed properties** (`public string $name`) — requiere PHP 7.4
- **Arrow functions** (`fn($x) => $x + 1`) — requiere PHP 7.4
- **`match` expression** — requiere PHP 8.0
- **`readonly`** — requiere PHP 8.1
- **`enum`** — requiere PHP 8.1
- **Attributes** (`#[Route]`) — requiere PHP 8.0

**Alternativas compatibles con 7.1:**
- Typed properties → usa PHPDoc `/** @var string */` + propiedad sin tipo
- Arrow functions → usa `function ($x) { return $x + 1; }`
- Match → usa `switch`
- Enum → usa constantes de clase
