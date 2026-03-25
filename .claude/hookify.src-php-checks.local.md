---
name: warn-src-php-checks
enabled: true
event: file
conditions:
  - field: file_path
    operator: regex_match
    pattern: src/.*\.php$
  - field: new_text
    operator: not_contains
    pattern: declare(strict_types=1)
action: warn
---

**`declare(strict_types=1)` ausente en fichero de `src/`**

Todos los ficheros PHP en `src/` deben incluir `declare(strict_types=1);` tras la etiqueta `<?php`.
Es obligatorio en este proyecto. PHPStan nivel 8 depende de ello.

```php
<?php

declare(strict_types=1);
```
