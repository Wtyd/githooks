<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class SystemTestCase extends TestCase
{
    public function __construct()
    {
        parent::__construct();

        $this->setOutputCallback(function () {
        });
    }
}
