---
name: check-strict-types-in-src
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
Es obligatorio en este proyecto (53/53 ficheros lo tienen). PHPStan nivel 8 depende de ello.

Añade esta línea al inicio del fichero:
```php
<?php

declare(strict_types=1);
```
