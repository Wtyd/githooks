<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Al llamar a setOutputCallback escondemos cualquier output por la consola que no venga de phpunit
 */
class SystemTestCase extends TestCase
{
    public function __construct()
    {
        parent::__construct();

        $this->setOutputCallback(function () {
        });
    }
}
