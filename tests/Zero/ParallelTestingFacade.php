<?php

namespace Tests\Zero;

/**
 * @method static void setUpProcess(callable $callback)
 * @method static void setUpTestCase(callable $callback)
 * @method static void setUpTestDatabase(callable $callback)
 * @method static void tearDownProcess(callable $callback)
 * @method static void tearDownTestCase(callable $callback)
 * @method static int|false token()
 *
 * @see \Illuminate\Testing\ParallelTesting
 */
class ParallelTestingFacade
{
    public static $parallelTesting;

    public function __construct(ParallelTesting $parallelTesting)
    {
        self::$parallelTesting = $parallelTesting;
    }

    public static function setUpProcess($callback): void
    {
        self::$parallelTesting->setUpProcess($callback);
    }

    public static function setUpTestCase($callback): void
    {
        self::$parallelTesting->setUpTestCase($callback);
    }

    public static function setUpTestDatabase($callback): void
    {
        self::$parallelTesting->setUpTestDatabase($callback);
    }

    public static function tearDownProcess($callback): void
    {
        self::$parallelTesting->tearDownProcess($callback);
    }

    public static function tearDownTestCase($callback): void
    {
        self::$parallelTesting->tearDownTestCase($callback);
    }

    public static function token($callback): int
    {
        self::$parallelTesting->token($callback);
    }
}
