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

    /**
     * Write the contents of a file.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  bool  $lock
     * @return int|bool
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public static function put($path, $contents, $lock = false)
    {
        return FacadesStorage::disk(self::$disk)->put($path, $contents, $lock = false);
    }
}
