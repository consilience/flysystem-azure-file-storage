<?php

namespace Consilience\Flysystem\Azure;

/**
 * The general approach to prefixes is that no method is given a
 * path with a prefix already added.
 */

use Throwable;
use League\Flysystem\Config;
use League\Flysystem\Visibility;
use League\Flysystem\PathPrefixer;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToRetrieveMetadata;

use MicrosoftAzure\Storage\File\Internal\IFile;
use MicrosoftAzure\Storage\File\Models\FileProperties;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\File\Models\CreateFileFromContentOptions;
use MicrosoftAzure\Storage\File\Models\GetDirectoryPropertiesResult;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesResult;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesOptions;

use Consilience\Flysystem\Azure\Exceptions\DirectoryDoesNotExist;
use Consilience\Flysystem\Azure\Exceptions\FileDoesNotExist;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;

class AzureFileAdapter implements FilesystemAdapter
{
    /**
     * @var PathPrefixer
     */
    protected $prefixer;

    /**
     * @var string[]
     */
    protected static $metaOptions = [
        'CacheControl',
        'ContentType',
        'Metadata',
        'ContentLanguage',
        'ContentEncoding',
    ];

    /**
     * Constructor.
     *
     * @param IFile $azureClient
     * @param string $container
     * @param array $fsConfig
     * @param string $prefix
     */
    public function __construct(
        protected IFile $azureClient,
        protected string $container,
        protected array $fsConfig = [],
        string $prefix = '',
    ) {
        $this->prefixer = new PathPrefixer($prefix);
   }

    /**
     * {@inheritdoc}
     * 
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     * 
     * @fixme handle exceptions UnableToWriteFile and FilesystemException
     */
    public function writeStream(string $path, $resource, Config $config): void
    {
        $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return stream_get_contents($this->readStream($path));
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function readStream(string $path)
    {
        try {
            $fileResult = $this->azureClient->getFile(
                $this->container,
                $this->prefixer->prefixPath($path),
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() === 404) {
                throw UnableToReadFile::fromLocation($path, 'File not found');
            }

            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);

        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, 'Unexpected error', $exception);
        }

        // Copy the remote stream to a local stream.
        // The remote stream does not allow streaming into another remote file.
        // I means we read the whole remote file, but we are not limited to what can
        // be held in memory.

        $stream = fopen('php://temp', 'w+');
        stream_copy_to_stream($fileResult->getContentStream(), $stream);
        rewind($stream);

        return $stream;
    }

    /**
     * @inheritDoc
     * 
     * Check if a file exists.
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->getFileMetadata($path);

            return true;

        } catch (FileDoesNotExist) {
            return false;
        }
    }

    /**
     * See if a directory exists.
     * 
     * @param string $path
     * @return boolean
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function directoryExists(string $path): bool
    {
        try {
            $this->getDirectoryMetadata($path);

            return true;

        } catch (DirectoryDoesNotExist) {
            return false;
        }
    }

    /**
     * Get the full metadata for a file.
     *
     * @param string $path
     * @return FileAttributes
     * @throws FilesystemException
     * @throws UnableToCheckExistence if the file does not exist
     */
    protected function getFileMetadata(string $path): FileAttributes
    {
        try {
            return $this->normalizeFileProperties(
                $path,
                $this->azureClient->getFileProperties(
                    $this->container,
                    $this->prefixer->prefixPath($path),
                ),
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() === 404) {
                throw FileDoesNotExist::forLocation($path, $exception);
            }

            throw UnableToCheckFileExistence::forLocation($path, $exception);

        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    /**
     * Get the full metadata for a directory.
     *
     * @param string $pathName
     * @return DirectoryAttributes
     * @throws FilesystemException
     * @throws UnableToCheckExistence if the file does not exist
     */
    protected function getDirectoryMetadata(string $path, bool $withPrefix = true): DirectoryAttributes
    {
        try {
            return $this->normalizeDirectoryProperties(
                $path,
                $this->azureClient->getDirectoryProperties(
                    $this->container,
                    $withPrefix ? $this->prefixer->prefixPath($path) : $path,
                ),
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() === 404) {
                throw DirectoryDoesNotExist::forLocation($path, $exception);
            }

            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);

        } catch (Throwable $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getFileMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getFileMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getFileMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getFileMetadata($path);
    }

    /**
     * @param string $path
     * @param string $visibility
     * @return void
     * @throws InvalidVisibilityProvided
     * @throws FilesystemException
     */
    public function setVisibility(string $path, string $visibility): void
    {
        // $prefixedPath = $this->prefixer->prefixPath($path);
        // Not supported.
    }

    /**
     * Constructs a FlySystem FileAttributes object from an Azure FileProperties object.
     *
     * @param string $path non-prefixed path
     * @return FileAttributes
     */
    protected function normalizeFileProperties(
        $path,
        FileProperties $fileProperties = null,
    ): FileAttributes
    {
        return FileAttributes::fromArray([
            StorageAttributes::ATTRIBUTE_PATH => $path,
            StorageAttributes::ATTRIBUTE_FILE_SIZE => $fileProperties->getContentLength(),
            StorageAttributes::ATTRIBUTE_VISIBILITY => Visibility::PRIVATE, // @todo think this through
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $fileProperties->getLastModified()->getTimestamp(),
            StorageAttributes::ATTRIBUTE_MIME_TYPE => $fileProperties->getContentType(),
            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => [
                'eTag' => trim($fileProperties->getETag(), '"'),
                'contentMD5' => $fileProperties->getContentMD5(),
                'lastModified' => $fileProperties->getLastModified(),
                'contentEncoding' => $fileProperties->getContentEncoding(),
                'contentLanguage' => $fileProperties->getContentLanguage(),
                'copyID' => $fileProperties->getCopyID(),
                'copyProgress' => $fileProperties->getCopyProgress(),
                'copySource' => $fileProperties->getCopySource(),
                'copyStatus' => $fileProperties->getCopyStatus(),
                'copyCompletionTime' => $fileProperties->getCopyCompletionTime(),
                'copyStatusDescription' => $fileProperties->getCopyStatusDescription(),
                'cacheControl' => $fileProperties->getCacheControl(),
                'contentDisposition' => $fileProperties->getContentDisposition(),
                'contentRange' => $fileProperties->getContentRange(),
                'rangeContentMD5' => $fileProperties->getRangeContentMD5(),
            ],
        ]);
    }

    /**
     * Constructs a Flysystem DirectoryAttributes from an Azure Directory object.
     *
     * @param string $path non-prefixed path
     * @return DirectoryAttributes
     */
    protected function normalizeDirectoryProperties(
        $path,
        GetDirectoryPropertiesResult $directoryProperties = null,
    ): DirectoryAttributes
    {
        return DirectoryAttributes::fromArray([
            StorageAttributes::ATTRIBUTE_VISIBILITY => Visibility::PRIVATE, // @todo think this through
            StorageAttributes::ATTRIBUTE_PATH => $path,
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $directoryProperties->getLastModified()->getTimestamp(),
            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => [
                'lastModified' => $directoryProperties->getLastModified(),
                'eTag' => trim($directoryProperties->getETag(), '"'),
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        // There is no rename in the REST API, so we copy
        // and then delete the original.

        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     * @throws UnableToCopyFile
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        // The source file must be supplied as the full URL.

        try {
            $this->azureClient->copyFile(
                $this->container,
                $this->prefixer->prefixPath($destination),
                $this->getUrl($source)
            );

        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * {@inheritdoc}
     * 
     * @fixme implement FilesystemException with some useful exceptions
     * @throws UnableToReadFile
     * @throws FilesystemException (interface)
     */
    public function delete(string $path): void
    {
        try {
            $this->azureClient->deleteFile(
                $this->container,
                $this->prefixer->prefixPath($path),
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * Order of recursive results is root-first.
     * The result array can be reversed to get depth-first.
     * 
     * @todo merge this into listContents() now there is little to
     * distinguish between them.
     */
    protected function getContents($path, $recursive = false)
    {
        // Options include maxResults and prefix.
        // The prefix is a matching filename prefix, which can be useful to extract
        // files starting with a given string.
        // $options->setPrefix('')

        $options = new ListDirectoriesAndFilesOptions();

        $contents = [];

        $prefixedPath = trim($this->prefixer->prefixPath($path), '/');

        try {
            /** @var ListDirectoriesAndFilesResult $listResults */

            $listResults = $this->azureClient->listDirectoriesAndFiles(
                $this->container,
                $prefixedPath,
                $options
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() === 404) {
                // The 404 specifically indicates everything worked,
                // but the file was not there.

                throw DirectoryDoesNotExist::forLocation(
                    $prefixedPath,
                    $exception,
                );
            }

            throw UnableToReadFile::fromLocation(
                $prefixedPath,
                sprintf('Got error: %s', $exception->getMessage()),
                $exception,
            );
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation(
                $prefixedPath,
                sprintf('Got error: %s', $exception->getMessage()),
                $exception,
            );
        }

        // Collect the sub-directories within this directory list.

        foreach ($listResults->getDirectories() as $directoryObject) {
            // Azure does not return any path context, so we add that on.
            $pathName = trim(sprintf('%s/%s', $path, $directoryObject->getName()), '/');

            // @fixme something feels repetitive here, with too many requests.
            $contents[] = $this->normalizeDirectoryProperties(
                $pathName,
                $this->azureClient->getDirectoryProperties(
                    $this->container,
                    $this->prefixer->prefixPath($pathName),
                ),
            );

            if ($recursive) {
                $contents = array_merge(
                    $contents,
                    $this->getContents($pathName, $recursive)
                );
            }
        }

        // Collect the files.

        foreach ($listResults->getFiles() as $fileObject) {
            $pathName = trim(sprintf('%s/%s', $path, $fileObject->getName()), '/');

            $contents[] = $this->normalizeFileProperties(
                $pathName,
                $this->azureClient->getFileProperties(
                    $this->container,
                    $this->prefixer->prefixPath($pathName),
                ),
            );
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     * 
     * @fixme handle FilesystemException
     */
    public function listContents($path = '', $recursive = false): iterable
    {
        $contents = $this->getContents($path, $recursive);

        return $contents;
    }

    /**
     * Issue #2 Encode the path parts but not the directory separators.
     *
     * @param string $pathName the normalised file pathname
     * @return string URL for the file, needed for some API methods
     */
    public function getUrl($pathName)
    {
        return sprintf(
            '%s%s/%s',
            (string)$this->azureClient->getPsrPrimaryUri(),
            $this->container,
            implode(
                '/',
                array_map(
                    'rawurlencode',
                    explode('/', $this->prefixer->prefixPath($pathName))
                )
            )
        );
    }

    /**
     * Recursively delete the directories and all files within them.
     * Setting the disableRecursiveDelete option will disable recursive
     * deletion, which can offer more control and fewer surprises.
     *
     * {@inheritdoc}
     * 
     * @throws UnableToDeleteDirectory
     * @throws FilesystemException
     */
    public function deleteDirectory(string $path): void
    {
        // Allow the recursion to be disabled through an option.
        // Do not apply prefix, as that is done in the methods it uses.

        if (empty($this->fsConfig['disableRecursiveDelete'])) {
            // Remove the contents of the directory.

            try {
                $listResults = $this->getContents($path, true);

            } catch (DirectoryDoesNotExist) {
                // If the directory we are trying to get the contents of does
                // not exist, then the desired state is reached; we want it gone
                // and it is gone.

                return;
            }

            foreach (array_reverse($listResults) as $object) {
                // These paths will be "normalised" and have their
                // prefixes removed.

                if ($object['type'] === 'file') {
                    $result = $this->delete($object['path']);
                }

                if ($object['type'] === 'dir') {
                    $result = $this->deleteEmptyDirectory($object['path']);
                }

                if (! $result) {
                    return;
                }
            }
        }

        // Remove the requested directory.

        $this->deleteEmptyDirectory($path);
    }

    /**
     * Delete a single empty directory.
     *
     * @param string $dirname the normalised directory pathnem
     * @return bool true if the directory was deleted; false if not found
     * @throws ServiceException
     */
    protected function deleteEmptyDirectory($path): bool
    {
        try {
            $this->azureClient->deleteDirectory(
                $this->container,
                $this->prefixer->prefixPath($path),
            );

        } catch (ServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     * 
     * @throws UnableToCreateDirectory
     * @throws FilesystemException
     */
    public function createDirectory(string $path, Config $config): void
    {
        // If there is a prefix, then we need to make sure that directory
        // is created first. If the root directory does not exist, it is
        // because the prefix directory has not been created yet.

        if (! $this->directoryExists('/')) {
            $prefixPath = trim($this->prefixer->prefixPath(''), '/');

            $prefixParts = explode('/', $prefixPath);

            for ($i = 1; $i <= count($prefixParts); $i++) {
                // Build up the directory on each pass: a -> a/b -> a/b/c etc.

                $partialDirectory = implode('/', array_slice($prefixParts, 0, $i));
    
                try {
                    // Try fetching metadata WITHOUT the prefix.
                    // An exception means we need to create the directory.

                    $this->getDirectoryMetadata($partialDirectory, false);
                
                } catch (DirectoryDoesNotExist) {
                    $this->azureClient->createDirectory(
                        $this->container,
                        $partialDirectory, // Without the prefix.
                        null,
                    );
                }
            }    
        }

        // Now we know the prefix is created, 

        if ($path === '' || $path === '.') {
            // There is no [further] directory to create.

            return;
        }

        // We need to recursively create the directories if we have a path.
        // @todo check if the full path exists first, to same some time for deep paths.

        $locationParts = explode('/', $path);

        for ($i = 1; $i <= count($locationParts); $i++) {
            $partialDirectory = implode('/', array_slice($locationParts, 0, $i));

            if (! $this->directoryExists($partialDirectory)) {
                $this->azureClient->createDirectory(
                    $this->container,
                    $this->prefixer->prefixPath($partialDirectory),
                    null,
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    // public function has($path)
    // {
    //     $location = $this->applyPathPrefix($path);

    //     // Try the resource as a file first.

    //     if ($this->hasFile($location)) {
    //         return true;
    //     }

    //     // Fallback: try the resource as a directory.

    //     return $this->directoryExists($location);
    // }

    /**
     * Removed as a core requirement for Flysystem 3.
     * May be removed if no longer used internally.
     *
     * @param string $path
     * @return FileAttributes|DirectoryAttributes
     * @throws UnableToRetrieveMetadata
     */
    protected function getMetadata(string $path)
    {
        try {
            return $this->getFileMetadata($path);

        } catch (UnableToCheckExistence $exception) {
            if ($exception->getCode() !== 404) {
                throw new UnableToRetrieveMetadata($exception->getMessage());
            }
        }

        return $this->getDirectoryMetadata($path);
    }

    /**
     * Builds the normalized output array.
     *
     * @param string $path
     * @param int    $timestamp
     * @param mixed  $content
     *
     * @return array
     */
    protected function normalize($path, $timestamp, $content = null)
    {
        $data = [
            'path' => $path,
            'timestamp' => (int) $timestamp,
            'dirname' => Util::dirname($path),
            'type' => 'file',
        ];

        if (is_string($content)) {
            $data['contents'] = $content;
        }

        return $data;
    }

    /**
     * Retrieves content streamed by Azure into a string.
     *
     * @param resource $resource
     *
     * @return string
     */
    protected function streamContentsToString($resource)
    {
        return stream_get_contents($resource);
    }

    /**
     * Upload a file.
     * This will overwrite a file if it already exists.
     *
     * @param string           $path
     * @param string|resource  $contents Either a string or a stream.
     * @param Config           $config
     * @return StorageAttributes
     */
    protected function upload(string $path, $contents, Config $config): StorageAttributes
    {
        // Make sure the directory has been created first.
        // @todo Make sure existing directory errors are hidden,
        // but other failures are not.

        $this->createDirectory(dirname($path), $config);

        // The result is void, or an exception.
        // @todo catch exceptions and map them to flysystem exceptions

        $this->azureClient->createFileFromContent(
            $this->container,
            $this->prefixer->prefixPath($path),
            $contents,
            $this->getOptionsFromConfig($config),
        );

        // We need to fetch the file metadata as a separate request.

        $result = $this->getMetadata($path);

        return $result;
    }

    /**
     * Retrieve options from a Flysystem Config instance and put them
     * into an Azure create-file options instance.
     *
     * @param Config $config
     *
     * @return CreateBlobOptions
     */
    protected function getOptionsFromConfig(Config $config): CreateFileFromContentOptions
    {
        $options = new CreateFileFromContentOptions();

        foreach (static::$metaOptions as $option) {
            if ($config->get($option) === null) {
                continue;
            }

            call_user_func([$options, "set$option"], $config->get($option));
        }

        if ($mimetype = $config->get('mimetype')) {
            $options->setContentType($mimetype);
        }

        return $options;
    }
}
