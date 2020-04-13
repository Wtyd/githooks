## Introducción
El principal objetivo de la entrega continua de software es entregar software lo más rápido posible de la mayor calidad posible. Para ello es necesario encontrar los errores lo antes posible.
El propósito de esta librería es establecer ciertos git hooks en un proyecto. Un git hook no es más que script que se ejecuta antes de realizar una acción con git (pre-commit, pre-push, etc). Estos scripts pueden tener las siguientes funciones:
1. Validar que el código sigue los estándares del proyecto.
1. Verificar que el código no tiene errores de sintaxis del lenguaje.
1. Buscar errores en el código.
1. Ejecutar pruebas unitarios y/o smoke tests.

La herramienta GitHooks incluye las siguientes herramientas:
- Php CodeSniffer.
- Php Copy Paste Detector.
- Php Mess Detector.
- Parallel-lint.
- Php Stan.
- Composer - Security Check Plugin.

## Instalación
Esta librería se instala mediante el `composer.json` del proyecto. Para ello:

1. En el apartado `require-dev` añadimos la librería:

`composer require --dev githooks/githooks`

1. Instalar las herramientas que deba ejecutar GitHooks:
    - `squizlabs/php_codesniffer`
    - `sebastian/phpcpd`
    - `phpmd/phpmd`
    - `php-parallel-lint/php-parallel-lint`
    - `phpstan/phpstan`
    - `funkjedi/composer-plugin-security-check`

1. La forma de instalación de las herramientas puede ser:
    - En el composer global.
    - En el proyecto.
    - Como .phar siempre que la herramienta lo permita y este accesible de forma global.