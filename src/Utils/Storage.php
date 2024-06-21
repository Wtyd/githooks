<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

use Illuminate\Support\Facades\Storage as FacadesStorage;

class Storage
{
    /** @var string */
    public static $disk = 'local';
    /**
     * Determine if a file or directory exists.
     *
     * @param  string  $path
     * @return bool
     */
    public static function exists($path)
    {
        return FacadesStorage::disk(self::$disk)->exists($path);
    }

    /**
     * Copy a file to a new location.
     *
     * @param  string  $path
     * @param  string  $target
     * @return bool
     */
    public static function copy($path, $target)
    {
        return FacadesStorage::disk(self::$disk)->copy($path, $target);
    }
}
