<?php

namespace Tests;

use org\bovigo\vfs\vfsStream;

/**
 * Trait para virtualizar ficheros durante las pruebas.
 * Usa la librería vfsstream (https://github.com/bovigo/vfsStream).
 */
trait VirtualFileSystemTrait
{
    protected $root;

    /**
     * Crea una estructura de ficheros virtuales que comienza en '/'
     *
     *
     * @param array $structure
     * @return void
     */
    public function createFileSystem(array $structure)
    {
        $this->root = vfsStream::setup('/', null, $structure);
    }

    public function getUrl(string $path)
    {
        return vfsStream::url('/' . $path);
    }
}
