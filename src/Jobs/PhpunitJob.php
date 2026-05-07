<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

class PhpunitJob extends JobAbstract
{
    public const SUPPORTS_FAST = false;

    protected const ARGUMENT_MAP = [
        'group'         => ['flag' => '--group', 'type' => 'value', 'separator' => ' '],
        'exclude-group' => ['flag' => '--exclude-group', 'type' => 'value', 'separator' => ' '],
        'filter'        => ['flag' => '--filter', 'type' => 'value', 'separator' => ' '],
        'configuration' => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'config'        => ['flag' => '-c', 'type' => 'value', 'separator' => ' '],
        'log-junit'     => ['flag' => '--log-junit', 'type' => 'value', 'separator' => ' '],
    ];

    public static function getDefaultExecutable(): string
    {
        return 'phpunit';
    }

    /** @return string[] */
    public function getCachePaths(): array
    {
        $configFile = $this->locateConfigFile();
        if ($configFile !== null) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($configFile);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml !== false) {
                // cacheDirectory takes precedence over cacheResultFile: it is the
                // PHPUnit 10+ replacement and, when both are declared, PHPUnit
                // itself uses cacheDirectory and ignores the deprecated attribute.
                if (isset($xml['cacheDirectory'])) {
                    $value = trim((string) $xml['cacheDirectory']);
                    if ($value !== '') {
                        return [$this->resolveRelativeToConfig($value, $configFile)];
                    }
                }
                if (isset($xml['cacheResultFile'])) {
                    $value = trim((string) $xml['cacheResultFile']);
                    if ($value !== '') {
                        return [$this->resolveRelativeToConfig($value, $configFile)];
                    }
                }
            }
        }
        return ['.phpunit.result.cache'];
    }

    private function locateConfigFile(): ?string
    {
        $explicit = $this->args['configuration'] ?? $this->args['config'] ?? '';
        if (is_string($explicit) && $explicit !== '' && is_file($explicit) && is_readable($explicit)) {
            return $explicit;
        }
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function resolveRelativeToConfig(string $path, string $configFile): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }
        return dirname($configFile) . DIRECTORY_SEPARATOR . $path;
    }
}
