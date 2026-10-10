<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration\Exception;

use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

/**
 * The configuration file exists but is in a shape 4.0 no longer reads: the
 * v2 Options/Tools array, a YAML file, or an extension the runtime does not
 * know. Distinct from "not found" on purpose: `hook` falls back to a minimal
 * install when there is no file and must not do so when there is a wrong one.
 */
class UnsupportedConfigurationFormatException extends \RuntimeException implements GitHooksExceptionInterface
{
    public static function forV2Shape(string $path): self
    {
        return new self(
            "v2 configuration format (Options/Tools) is not supported since 4.0: $path. "
            . "Migrate it with GitHooks 3.8 ('githooks conf:migrate') before upgrading."
        );
    }

    public static function forYaml(string $path): self
    {
        return new self(
            "YAML configuration files are not supported since 4.0: $path. "
            . "Migrate it with GitHooks 3.8 ('githooks conf:migrate') to githooks.php before upgrading."
        );
    }

    public static function forExtension(string $path): self
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return new self("Unsupported configuration file type '.$extension': $path. Expected a .php file.");
    }
}
