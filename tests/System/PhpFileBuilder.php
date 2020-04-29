<?php

namespace Tests\System;

class PhpFileBuilder
{
    protected $name;

    protected $header;

    protected $body;

    public function __construct(string $name)
    {
        $this->name = $name;

        $this->header = $this->setHeader();

        $this->body = $this->setBody();
    }

    public function build()
    {
        return $this->header . "\n" . $this->body;
    }

    public function setHeader()
    {
        return "<?php

namespace Tests;

class " . $this->name;
    }

    public function setBody()
    {
        return '{

    public function add($a, $b)
    {
        return $a + $b;
    }
}';
    }

    // public function messDetectorFail()
    // {

    // }
}
