<?php

namespace Consilience\Flysystem\Azure;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use League\Flysystem\FileNotFoundException;

use MicrosoftAzure\Storage\File\Internal\IFile;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesOptions;
use MicrosoftAzure\Storage\File\Models\ListDirectoriesAndFilesResult;

use MicrosoftAzure\Storage\File\Models\FileProperties;
use MicrosoftAzure\Storage\File\Models\GetDirectoryPropertiesResult;
use MicrosoftAzure\Storage\File\Models\CreateFileFromContentOptions;

use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

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
     * Issue #2 Encode the path parts but not the directory separators.
     *
     * @param string $pathName the normalised file pathname
     * @return string URL for the file, needed for some API methods
     */
    public function getUrl($pathName)
    {
        return sprintf(
            '%s%s/%s',
            (string)$this->client->getPsrPrimaryUri(),
            $this->container,
            implode(
                '/',
                array_map(
                    'rawurlencode',
                    explode('/', $pathName)
                )
            )
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
        $sourceLocation = $this->applyPathPrefix($sourcePath);
        $newLocation = $this->applyPathPrefix($newPath);

        // The source file must be supplied as the full URL.

        try {
            $this->client->copyFile(
                $this->container,
                $newLocation,
                $this->getUrl($sourceLocation)
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
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->deleteFile($this->container, $location);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            // CHECKME: If the file could not be found, then should it
            // not still be considered a successful deletion?

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
        $location = $this->applyPathPrefix($dirname);

        // Allow the recursion to be disabled through an option.

        if (empty($this->fsConfig['disableRecursiveDelete'])) {
            // Remove the contents of the direcory.

            try {
                $listResults = $this->getContents($location, true);
            } catch (FileNotFoundException $e) {
                // If the directory we are trying to get the contents of does
                // not exist, then the desired state is reached; we want it gone
                // and it is gone.

                return true;
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
        $location = $this->applyPathPrefix($dirname);

        try {
            $this->client->deleteDirectory($this->container, $location);
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
        $location = trim($this->applyPathPrefix($dirname), '/');

        if ($location === '' || $location === '.') {
            // There is no directory to create.

            return ['path' => $dirname, 'type' => 'dir'];
        }

        // We need to recursively create the directories if we have a path.

        $locationParts = explode('/', $location);

        for ($i = 1; $i <= count($locationParts); $i++) {
            $partialDirectory = implode('/', array_slice($locationParts, 0, $i));

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
        $location = $this->applyPathPrefix($path);

        // Try the resource as a file first.

        if ($this->hasFile($location)) {
            return true;
        }

        // Fallback: try the resource as a directory.

        return $this->hasDirectory($location);
    }

    /**
     * See if a file exists.
     * The directory prefix has already been added.
     */
    protected function hasFile($location)
    {
        try {
            $this->client->getFileProperties($this->container, $location);

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
    protected function hasDirectory($location)
    {
        try {
            $this->client->getDirectoryProperties($this->container, $location);
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

        unset($result['stream']);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($pathName)
    {
        $location = $this->applyPathPrefix($pathName);

        try {
            $fileResult = $this->client->getFile($this->container, $location);
        } catch (ServiceException $e) {
            if ($e->getCode() === 404) {
                throw new FileNotFoundException($pathName, $e->getCode(), $e);
            }

            throw $e;
        }

        // Copy the remote stream to a local stream.
        // The remote stream does not allow streaming into another remote file.
        // I means we read the whole remote file, but we are not limited to what can
        // be held in memory.

        $stream = fopen('php://temp', 'w+');
        stream_copy_to_stream($fileResult->getContentStream(), $stream);
        rewind($stream);

        return array_merge(
            $this->normalizeFileProperties($pathName, $fileResult->getProperties()),
            [
                'stream' => $stream,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        try {
            $contents = $this->getContents($directory, $recursive);
        } catch (FileNotFoundException $e) {
            // Flysytem core is not expecting an exception.

            return Util::emulateDirectories([]);
        }

        return Util::emulateDirectories($contents);
    }

    /**
     * Order of recursive results is root-first.
     * The result array can be reversed to get depth-first.
     */
    protected function getContents($directory, $recursive = false)
    {
        // Options include maxResults and prefix.
        // The prefix is a matching filename prefix, which can be useful to extract
        // files starting with a given string.
        // $options->setPrefix('')

        $options = new ListDirectoriesAndFilesOptions();

        $directory = trim($directory, '/');

        $location = $this->applyPathPrefix($directory);

        $contents = [];

        try {
            /** @var ListDirectoriesAndFilesResult $listResults */

            $listResults = $this->client->listDirectoriesAndFiles(
                $this->container,
                $location,
                $options
            );
        } catch (ServiceException $e) {
            if ($e->getCode() === 404) {
                throw new FileNotFoundException($directory, $e->getCode(), $e);
            }

            throw $e;
        }

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
        $location = $this->applyPathPrefix($path);

        try {
            /** @var \MicrosoftAzure\Storage\File\Models\FileProperties $result */
            $result = $this->client->getFileProperties($this->container, $location);

            return $this->normalizeFileProperties(
                $path,
                $result
            );
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            /** @var \MicrosoftAzure\Storage\File\Models\GetDirectoryPropertiesResult $result */
            $result = $this->client->getDirectoryProperties($this->container, $location);

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
            $properties['etag'] = trim($directoryProperties->getETag(), '"');
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
        //$pathName = $this->removePathPrefix($pathName); // !!!!

        $properties = [
            'type' => 'file',
            'path' => $pathName,
            'dirname' => Util::dirname($pathName),
        ];

        if ($fileProperties) {
            $properties['size'] = $fileProperties->getContentLength();
            $properties['timestamp'] = $fileProperties->getLastModified()->getTimestamp();

            $properties['mimetype'] = $fileProperties->getContentType();
            $properties['etag'] = trim($fileProperties->getETag(), '"');
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
     * This will overwrite a file if it already exists.
     *
     * @param string           $path     Path
     * @param string|resource  $contents Either a string or a stream.
     * @param Config           $config   Config
     *
     * @return array|false
     */
    protected function upload($path, $contents, Config $config)
    {
        // Make sure the directory has been created first.

        $this->createDir(dirname($path), $config);

        $location = $this->applyPathPrefix($path);

        // The result will be null, or an exception.

        $this->client->createFileFromContent(
            $this->container,
            $location,
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
