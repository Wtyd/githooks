<?php

namespace Tests\Utils\Traits;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        !defined('APP_ENV') &&  define('APP_ENV', 'testing');

        $app = require getcwd() . '/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
