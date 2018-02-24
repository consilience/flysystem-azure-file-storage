<?php

namespace Consilience\Flysystem\Azure;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use MicrosoftAzure\Storage\File\Internal\IFile;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesOptions;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesResult;

use MicrosoftAzure\Storage\File\Models\FileProperties;
use MicrosoftAzure\Storage\File\Models\GetDirectoryPropertiesResult;
use MicrosoftAzure\Storage\File\Models\CreateFileFromContentOptions;

class AzureFileAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $container;

    /**
     * @var IFile
     */
    protected $client;

    /**
     * @var array
     */
    protected $fsConfig;

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
     * @param IFile  $azureClient
     * @param string $container
     */
    public function __construct(IFile $azureClient, $config = [], $prefix = null)
    {
        $this->client = $azureClient;
        $this->container = $config['container'];
        $this->fsConfig = $config;
        $this->setPathPrefix($prefix);
    }

    /**
     * @param string $pathName the normalised file pathname
     * @return string URL for the file, needed for some API methods
     */
    protected function getUrl($pathName)
    {
        return sprintf(
            '%s%s/%s',
            (string)$this->client->getPsrPrimaryUri(),
            $this->container,
            $pathName
        );
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourcePath, $newPath)
    {
        // There is no rename in the REST API, so we copy
        // and then delete the original.

        if ($this->copy($sourcePath, $newPath)) {
            return $this->delete($sourcePath);
        }

        return false;
    }

    public function copy($sourcePath, $newPath)
    {
        $sourcePath = $this->applyPathPrefix($sourcePath);
        $newPath = $this->applyPathPrefix($newPath);

        // The source file must be supplied as the full URL.

        try {
            $this->client->copyFile(
                $this->container,
                $newPath,
                $this->getUrl($sourcePath)
            );
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->deleteFile($this->container, $path);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * Recursively delete the directories and all files within them.
     * Setting the disableRecursiveDelete option will disable recursive
     * deletion, which can offer more control and fewer surprises.
     *
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $prefixedDirname = $this->applyPathPrefix($dirname);

        // Allow the recursion to be disabled through an option.

        if (empty($this->fsConfig['disableRecursiveDelete'])) {
            // Remove the contents of the direcory.

            try {
                $listResults = $this->getContents($prefixedDirname, true);
            } catch (ServiceException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }

                return false;
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
                    return $result;
                }
            }
        }

        // Remove the requested direcory.

        return $this->deleteEmptyDirectory($dirname);
    }

    /**
     * Delete a single empty directory.
     *
     * @param string $dirname the normalised directory pathnem
     * @return bool true if the directory was deleted; false if not found
     * @throws ServiceException
     */
    protected function deleteEmptyDirectory($dirname)
    {
        $dirname = $this->applyPathPrefix($dirname);

        try {
            $this->client->deleteDirectory($this->container, $dirname);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $dirname = trim($this->applyPathPrefix($dirname), '/');

        if ($dirname === '') {
            // There is no directory to create.

            return ['path' => $dirname, 'type' => 'dir'];
        }

        // We need to recursively create the directories if we have a path.

        $dirParts = explode('/', $dirname);

        for ($i = 1; $i <= count($dirParts); $i++) {
            $partialDirectory = implode('/', array_slice($dirParts, 0, $i));

            if (! $this->hasDirectory($partialDirectory)) {
                $this->client->createDirectory($this->container, $partialDirectory, null); // TODO: options
            }
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        // Try the resource as a file first.

        if ($this->hasFile($path)) {
            return true;
        }

        // Fallback: try the resource as a directory.

        return $this->hasDirectory($path);
    }

    /**
     * See if a file exists.
     * The directory prefix has already been added.
     */
    protected function hasFile($pathName)
    {
        try {
            $this->client->getFileProperties($this->container, $pathName);

            return true;
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return false;
    }

    /**
     * See if a directory exists.
     * The directory prefix has already been added.
     */
    protected function hasDirectory($pathName)
    {
        try {
            $this->client->getDirectoryProperties($this->container, $pathName);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($pathName)
    {
        $result = $this->readStream($pathName);

        $result['contents'] = stream_get_contents($result['stream']);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($pathName)
    {
        $pathName = $this->applyPathPrefix($pathName);

        $fileResult = $this->client->getFile($this->container, $pathName);

        return array_merge(
            $this->normalizeFileProperties($pathName, $fileResult->getProperties()),
            ['stream' => $fileResult->getContentStream()]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);

        $contents = $this->getContents($directory, $recursive);

        return Util::emulateDirectories($contents);
    }

    /**
     * Order of recursive results is root-first.
     * The result array can be reversed to get depth-first.
     */
    protected function getContents($directory, $recursive = false)
    {
        $options = new ListDirectoriesAndFilesOptions();

        $directory = trim($directory, '/');

        /** @var ListDirectoriesAndFilesResult $listResults */
        $listResults = $this->client->listDirectoriesAndFiles(
            $this->container,
            $directory,
            $options
        );

        $contents = [];

        // Collect the directories.

        foreach ($listResults->getDirectories() as $directoryObject) {
            // Azure does not return any path context, so we add that on.
            $pathName = trim($directory . '/' . $directoryObject->getName(), '/');

            $contents[] = $this->normalizeDirectoryProperties($pathName);

            if ($recursive) {
                $contents = array_merge(
                    $contents,
                    $this->getContents($pathName, $recursive)
                );
            }
        }

        // Collect the files.

        foreach ($listResults->getFiles() as $fileObject) {
            $contents[] = $this->normalizeFileProperties(
                trim($directory . '/' . $fileObject->getName(), '/')
            );
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            /** @var \MicrosoftAzure\Storage\File\Models\FileProperties $result */
            $result = $this->client->getFileProperties($this->container, $path);

            return $this->normalizeFileProperties(
                $path,
                $result
            );
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            /** @var \MicrosoftAzure\Storage\File\Models\GetDirectoryPropertiesResult $result */
            $result = $this->client->getDirectoryProperties($this->container, $path);

            return $this->normalizeDirectoryProperties(
                $path,
                $result
            );
        }

    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
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
     * Builds the normalized output array from a Directory object.
     *
     * @param string $pathName
     *
     * @return array
     */
    protected function normalizeDirectoryProperties(
        $pathName,
        GetDirectoryPropertiesResult $directoryProperties = null
    ) {
        $properties = [
            'path' => $this->removePathPrefix($pathName),
            'type' => 'dir',
        ];

        if ($directoryProperties) {
            $properties['timestamp'] = $directoryProperties->getLastModified()->getTimestamp();
            $properties['etag'] = $directoryProperties->getETag();
        }

        return $properties;
    }

    /**
     * Builds the normalized output array from a Directory object.
     *
     * @param string $pathName
     *
     * @return array
     */
    protected function normalizeFileProperties($pathName, FileProperties $fileProperties = null)
    {
        $pathName = $this->removePathPrefix($pathName);

        $properties = [
            'type' => 'file',
            'path' => $pathName,
            'dirname' => Util::dirname($pathName),
        ];

        if ($fileProperties) {
            $properties['size'] = $fileProperties->getContentLength();
            $properties['timestamp'] = $fileProperties->getLastModified()->getTimestamp();

            $properties['mimetype'] = $fileProperties->getContentType();
            $properties['etag'] = $fileProperties->getETag();
        }

        return $properties;
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
     *
     * @param string           $path     Path
     * @param string|resource  $contents Either a string or a stream.
     * @param Config           $config   Config
     *
     * @return array
     */
    protected function upload($path, $contents, Config $config)
    {
        // Make sure the directory has been created first.

        $this->createDir(dirname($path), $config);

        $path = $this->applyPathPrefix($path);

        // The result will be null, or an exception.
        $this->client->createFileFromContent(
            $this->container,
            $path,
            $contents,
            $this->getOptionsFromConfig($config)
        );

        // We need to fetch the file metadata as a separate request.

        $result = $this->getMetadata($path);

        return array_merge($result, ['contents' => $contents]);
    }

    /**
     * Retrieve options from a Config instance.
     *
     * @param Config $config
     *
     * @return CreateBlobOptions
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = new CreateFileFromContentOptions();

        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
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
