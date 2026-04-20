<?php

namespace Tests\Unit\Utils;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage as FacadesStorage;
use Tests\Zero\ZeroTestCase;
use Wtyd\GitHooks\Utils\Storage;

class StorageTest extends ZeroTestCase
{
    /** @var array<array{path: string, contents: string, lock: bool}> */
    private array $putCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->putCalls = [];

        $calls = &$this->putCalls;

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')
            ->andReturnUsing(function ($path, $contents, $lock) use (&$calls) {
                $calls[] = ['path' => $path, 'contents' => $contents, 'lock' => $lock];
                return true;
            });

        FacadesStorage::shouldReceive('disk')
            ->with(Storage::$disk)
            ->andReturn($disk);
    }

    /**
     * @test
     * @dataProvider lockValuesProvider
     */
    function put_forwards_lock_argument_to_underlying_disk(bool $callerLock)
    {
        Storage::put('foo.txt', 'hello', $callerLock);

        $this->assertSame(
            [['path' => 'foo.txt', 'contents' => 'hello', 'lock' => $callerLock]],
            $this->putCalls
        );
    }

    /** @return array<string, array{bool}> */
    public function lockValuesProvider(): array
    {
        return [
            'caller passes false' => [false],
            'caller passes true'  => [true],
        ];
    }

    /** @test */
    function put_defaults_lock_to_false_when_argument_is_omitted()
    {
        Storage::put('foo.txt', 'hello');

        $this->assertSame(
            [['path' => 'foo.txt', 'contents' => 'hello', 'lock' => false]],
            $this->putCalls
        );
    }
}
