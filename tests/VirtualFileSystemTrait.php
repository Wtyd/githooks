<?php

namespace Tests;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\visitor\vfsStreamStructureVisitor;

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

    public function addFileToStructure(string $name, string $content, $path = null)
    {
        if ($path === null) {
            $path = $this->root;
        }
        vfsStream::newFile($name)->at($path)->setContent($content);
    }

    public function getContent(string $file)
    {
        return $this->root->getChild($file)->getContent();
    }

    /**
     * Method for debuggin
     *
     * @return array The file system structure
     */
    public function inspectStructure(): array
    {
        return vfsStream::inspect(new vfsStreamStructureVisitor())->getStructure();
    }
}
