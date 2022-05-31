<?php

namespace Consilience\Flysystem\Azure\Exceptions;

/**
 * To distinguish a missing directory from other errors while
 * attempting to read a directory.
 */

use League\Flysystem\FilesystemOperationFailed;
use RuntimeException;
use Throwable;

class DirectoryDoesNotExist extends RuntimeException implements FilesystemOperationFailed
{
    /**
     * @var string
     */
    private $location = '';

    public static function forLocation(string $location, Throwable $previous = null): DirectoryDoesNotExist
    {
        $e = new static(sprintf('Directory does not exist at location: %s.', rtrim($location)), 404, $previous);
        $e->location = $location;

        return $e;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function operation(): string
    {
        return FilesystemOperationFailed::OPERATION_DIRECTORY_EXISTS;
    }
}
