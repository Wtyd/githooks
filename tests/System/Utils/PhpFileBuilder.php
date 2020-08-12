<?php

namespace Tests\System\Utils;

class PhpFileBuilder
{
    public const PHPMD = 'phpmd';

    public const PHPCS = 'phpcs';

    public const PHPCS_NO_FIXABLE = 'phpcs-no-fixable';

    public const PHPSTAN = 'phpstan';

    public const PARALLEL_LINT = 'parallel-lint';

    public const PHPCPD = 'phpcpd';

    protected $name;

    protected $header;

    protected $body;

    public function __construct(string $name)
    {
        $this->name = $name;

        $this->header = $this->setHeader();

        $this->body = $this->setBody();
    }

    /**
     * Se junta la cadena que formará el fichero y se devuelve.
     * Antes del return se sustituyen los saltos de línea de Windows por los de Unix
     *
     * @return string
     */
    public function build(): string
    {
        $file = $this->header . $this->body . $this->closeBody();
        return str_replace("\r\n", "\n", $file);
    }

    public function buildWithErrors(array $options)
    {
        $file = $this->header . $this->body;

        foreach ($options as $option) {
            switch ($option) {
                case self::PARALLEL_LINT:
                    $file .= $this->addParallelLintError();
                    break;
                case self::PHPMD:
                    $file .= $this->addMessDetectorError();
                    break;
                case self::PHPCS:
                    $file .= $this->addCodeSnifferError();
                    break;
                case self::PHPCS_NO_FIXABLE:
                    $file .= $this->addCodeSnifferNoFixableError();
                    break;
                case self::PHPSTAN:
                    $file .= $this->addPhpStanError();
                    break;
                case self::PHPCPD:
                    $file .= $this->addPhpCPDError();
                    break;
            }
        }
        $file = $file . $this->closeBody();
        return str_replace("\r\n", "\n", $file);
    }

    public function setHeader()
    {
        return '<?php

namespace Tests\System\tmp\src;

class ' . $this->name . "
{\n";
    }

    public function setBody()
    {
        return '    public function add($firstOperator, $secondOperator)
    {
        return $firstOperator + $secondOperator;
    }' . "\n";
    }

    protected function closeBody()
    {
        return "}\n";
    }

    public function setFileName(string $name)
    {
        if ($name !== null) {
            $this->name = $name;
        }
        return $this;
    }

    public function addMessDetectorError()
    {
        return "\n" . '    public function sub($a, $b)
    {
        $variableNoUsada = 10;
        return $a - $b;
    }' . "\n";
    }

    public function addCodeSnifferError()
    {
        return "\n" . '    public function phpcs($a, $b){ //Falta salto de línea
        
        return $a - $b;
    }' . "\n";
    }

    public function addCodeSnifferNoFixableError()
    {
        return "\n" . '    public function _sub($a, $b)
    {
        return $a - $b;
    }' . "\n";
    }

    public function addPhpStanError()
    {
        return "\n" . '    public function phpstan()
    {
        $this->add();
    }' . "\n";
    }

    public function addParallelLintError()
    {
        return "\n" . '    public function parallelLint()
    {
        echo "falta el ;"
    }' . "\n";
    }

    public function addPhpCPDError()
    {
        return "\n" . '    public function originalMethod()
    {
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
    }' . "\n" . '    public function copiedMethod()
    {
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";

        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
        echo "Hello World Hello World Hello World Hello World Hello World";
    }' . "\n";
    }
}
