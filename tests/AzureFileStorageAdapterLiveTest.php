<?php

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\DirectoryListing;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use MicrosoftAzure\Storage\File\FileRestProxy;
use Consilience\Flysystem\Azure\AzureFileAdapter;

class AzureFileStorageAdapterLiveTest extends TestCase
{
    public const PREFIX_ONE = 'test-prefix';
    public const PREFIX_TWO = 'test-prefix-level1/test-prefix-level2';

    public const FILENAME_PREFIX_TEMPLATE = 'test-foo-{number}.txt';

    public const SUBDIR_ONE = 'test-subdir1';
    public const SUBDIR_TWO = 'test-subdir1/test-subdir2';
    public const SUBDIR_THREE = 'test-subdir3';

    /**
     * @fixme does not work here; seems to get reset each test
     */
    public function setUp(): void
    {
        parent::setUp();

        // $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
        // $dotenv->load();

        // @fixme move this to a one-time setup method

        $azureFileStorageAccount = getenv('AZURE_FILE_STORAGE_ACCOUNT');
        $azureFileStorageAccessKey = getenv('AZURE_FILE_STORAGE_ACCESS_KEY');
        $azureFileStorageShareName = getenv('AZURE_FILE_STORAGE_SHARE_NAME');

        $this->assertNotEmpty(
            $azureFileStorageAccount,
            'Environment variable AZURE_FILE_STORAGE_ACCOUNT is not set'
        );

        $this->assertNotEmpty(
            $azureFileStorageAccessKey,
            'Environment variable AZURE_FILE_STORAGE_ACCESS_KEY is not set'
        );

        $this->assertNotEmpty(
            $azureFileStorageShareName,
            'Environment variable AZURE_FILE_STORAGE_SHARE_NAME is not set'
        );
    }

    protected static function createFilesystemAdapter(string $prefix = ''): FilesystemAdapter
    {
        // $mockAzureClient = Mockery::mock(FileRestProxy::class)->makePartial();

        // return new AzureFileAdapter($mockAzureClient, 'foo-container', [
        //     'container' => 'foo-container',
        // ]);

        $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
        $dotenv->load();

        $azureFileStorageAccount = getenv('AZURE_FILE_STORAGE_ACCOUNT');
        $azureFileStorageAccessKey = getenv('AZURE_FILE_STORAGE_ACCESS_KEY');
        $azureFileStorageShareName = getenv('AZURE_FILE_STORAGE_SHARE_NAME');

        $connectionString = sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
            $azureFileStorageAccount,
            $azureFileStorageAccessKey
        );

        $config = [
            'endpoint' => $connectionString,
            'container' => $azureFileStorageShareName,
            // Optional to prevent directory deletion recursively deleting
            // all descendant files and direcories.
            //'disableRecursiveDelete' => true,
        ];

        $fileService = FileRestProxy::createFileService(
            $connectionString,
            [],
        );

        return new AzureFileAdapter(
            $fileService,
            $azureFileStorageShareName,
            $config,
            $prefix,
        );
    }

    /**
     * Provides a live adapter based on config.
     * There are three adapters - one with no prefex, one with a single level
     * prefix and one with a two level prefix.
     * The prefixes were proving problematic enough to need specific testing.
     */
    public function adapterProvider()
    {
        // FilesystemOperator
        $filesystem = new Filesystem(static::createFilesystemAdapter());
        $filesystemPrefixOne = new Filesystem(static::createFilesystemAdapter(self::PREFIX_ONE));
        $filesystemPrefixTwo = new Filesystem(static::createFilesystemAdapter(self::PREFIX_TWO));

        // The data provider supplies:
        // (1) no prefix;
        // (2) a single level prefix; and
        // (3) a two-level prefix.

        return [
            'no-prefix' => ['filesystem' => $filesystem],
            'single-prefix' => ['filesystem' => $filesystemPrefixOne],
            'double-prefix' => ['filesystem' => $filesystemPrefixTwo],
        ];
    }

    protected function filename(int $number, string $directory = null)
    {
        $filename = str_replace(
            '{number}',
            (string)$number,
            self::FILENAME_PREFIX_TEMPLATE
        );

        if ($directory === null) {
            return $filename;
        }

        return trim(trim($directory, '/') . '/' . $filename, '/');
    }

    protected function stream($content = 'content')
    {
        $stream = tmpfile();
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * Set up a consistent state on the remote filestore.
     * This is a bit chicken-and-egg, but it's all we have to work with.
     */
    public function testClearState()
    {
        $filesystem = $this->adapterProvider()['no-prefix']['filesystem'];

        // Directories are a second-class citizen in flysystem.
        // There is no consistent way to tell if a directory exists.
        // So if these directories do not exist, then no exception
        // should be thrown. Following up this assumption here:
        //
        // https://github.com/thephpleague/flysystem/issues/1099

        $filesystem->deleteDirectory(self::PREFIX_ONE);
        $filesystem->deleteDirectory(dirname(self::PREFIX_TWO));

        $filesystem->deleteDirectory(self::SUBDIR_ONE);
        $filesystem->deleteDirectory(self::SUBDIR_TWO);

        // Delete any files left behind.

        for ($i = 0; $i < 20; $i++) {
            $filename = $this->filename($i);

            if ($filesystem->fileExists($filename)) {
                $filesystem->delete($filename);
            }
        }
    }

    /**
     * has() is back for flysystem 3.0
     * 
     * @dataProvider adapterProvider
     */
    public function testHasSuccess($filesystem)
    {
        // Create file and confirm it exists.

        $filesystem->write($this->filename(1), 'content');

        $this->assertTrue($filesystem->fileExists($this->filename(1)));
        $this->assertTrue($filesystem->has($this->filename(1)));

        // Create files two levels of subdirectory they exist.

        $filesystem->write($this->filename(2, self::SUBDIR_ONE), 'content');
        $this->assertTrue($filesystem->fileExists($this->filename(2, self::SUBDIR_ONE)));
        $this->assertTrue($filesystem->has($this->filename(2, self::SUBDIR_ONE)));

        $filesystem->write($this->filename(3, self::SUBDIR_TWO), 'content');
        $this->assertTrue($filesystem->fileExists($this->filename(3, self::SUBDIR_TWO)));
        $this->assertTrue($filesystem->has($this->filename(3, self::SUBDIR_TWO)));

        // Some consistency for directories.
        // This driver will treat a directory as a file when checking if it exists.

        $this->assertTrue($filesystem->directoryExists(self::SUBDIR_ONE));
        $this->assertTrue($filesystem->has(self::SUBDIR_ONE));

        $this->assertTrue($filesystem->directoryExists(self::SUBDIR_TWO));
        $this->assertTrue($filesystem->has(self::SUBDIR_TWO));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testHasFail($filesystem)
    {
        // Confirm files "4" do not exist.

        $this->assertFalse($filesystem->has($this->filename(4)));
        $this->assertFalse($filesystem->has($this->filename(4, self::SUBDIR_ONE)));
        $this->assertFalse($filesystem->has($this->filename(4, self::SUBDIR_TWO)));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testWriteFile($filesystem)
    {
        // write() will allow a file to be written and overwritten.

        foreach ([1, 2, 3] as $attempt) {
            $filesystem->write(
                $this->filename(5),
                'content', // length 7
                ['visibility' => 'public'],
            );
        }

        // Test a few new v3 getters

        $this->assertIsInt($filesystem->lastModified($this->filename(5)));
        $this->assertIsString($filesystem->mimeType($this->filename(5)));
        $this->assertSame(7, $filesystem->fileSize($this->filename(5)));
        $this->assertSame('private', $filesystem->visibility($this->filename(5))); // Not really supported yet.

        $filesystem->delete($this->filename(5));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testWriteDirOne($filesystem)
    {
        // The same directory can be written multiple times with no errors.

        foreach ([1, 2] as $attempt) {
            $filesystem->write(
                $this->filename(6, self::SUBDIR_ONE),
                'content',
                ['visibility' => 'public']
            );
        }

        $this->assertTrue($filesystem->directoryExists(self::SUBDIR_ONE));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testWriteDirTwo($filesystem)
    {
        // The same multi-level directory can be written multiple times with no errors.

        foreach ([1, 2] as $attempt) {
            $filesystem->write(
                $this->filename(7, self::SUBDIR_TWO),
                'content',
                ['visibility' => 'public']
            );
        }

        $this->assertTrue($filesystem->directoryExists(self::SUBDIR_TWO));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testWriteStreamRoot($filesystem)
    {
        foreach ([1, 2] as $attempt) {
            $filesystem->writeStream(
                $this->filename(8),
                $this->stream(),
                ['visibility' => 'public']
            );

            $this->assertSame(
                'content',
                $filesystem->read($this->filename(8))
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testWriteStreamDirOne($filesystem)
    {
        foreach ([1, 2] as $attempt) {
            $filesystem->writeStream(
                $this->filename(8, self::SUBDIR_ONE),
                $this->stream(),
                ['visibility' => 'public']
            );

            $this->assertSame(
                'content',
                $filesystem->read($this->filename(8, self::SUBDIR_ONE))
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testDeleteFileSuccess($filesystem)
    {
        // Deleting the files that exist.

        $filesystem->delete($this->filename(8));
        $filesystem->delete($this->filename(8, self::SUBDIR_ONE));

        $this->assertFalse($filesystem->fileExists($this->filename(8)));
        $this->assertFalse($filesystem->fileExists(self::SUBDIR_ONE . '/' . $this->filename(8)));
    }

    /**
     * @dataProvider adapterProvider
     * Flystem 3 does not throw errors on a missing file being deleted.
     */
    public function testDeleteFileFailRoot($filesystem)
    {
        // Deleting again will throw an exception.
        // It is flysystem core that does that.

        $this->assertFalse($filesystem->fileExists($this->filename(8)));

        $filesystem->delete($this->filename(8));

        $this->assertFalse($filesystem->fileExists($this->filename(8)));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testDeleteFileFailDirOne($filesystem)
    {
        // Deleting again will thow an exception.
        // It is flysystem core that does that.

        $filesystem->delete($this->filename(8, self::SUBDIR_ONE));

        $this->assertFalse($filesystem->fileExists(self::SUBDIR_ONE . '/' . $this->filename(8)));
    }

    /**
     * The LogicException is an unusual choice, but it's what the flysystem
     * polyfill provides for file systems that do not support visibility.
     *
     * @dataProvider adapterProvider
     */
    public function testSetVisibility($filesystem)
    {
        // Visibility is not supported by this driver.

        $filesystem->setVisibility($this->filename(5), 'public');
    }

    /**
     * The LogicException is an unusual choice, but it's what the flysystem
     * polyfill provides for file systems that do not support visibility.
     *
     * @dataProvider adapterProvider
     * @expectedException \League\Flysystem\UnableToCheckExistence
     */
    public function testGetVisibility($filesystem)
    {
        // Visibility is not supported by this driver, but the file does
        // have to exist.

        $filesystem->write($this->filename(5), 'content');

        $filesystem->visibility($this->filename(5));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testRename($filesystem)
    {
        // Rename in same directory.

        $filesystem->write($this->filename(5), 'content to move');
        $filesystem->delete($this->filename(15));

        $filesystem->move(
            $this->filename(5),
            $this->filename(15)
        );

        // Old name is gone.

        $this->assertFalse(
            $filesystem->fileExists($this->filename(5))
        );

        // New name exists.

        $this->assertTrue(
            $filesystem->fileExists($this->filename(15))
        );

        // Rename to an existing directory.

        $filesystem->delete($this->filename(15, self::SUBDIR_ONE));


        $filesystem->move(
            $this->filename(15),
            $this->filename(15, self::SUBDIR_ONE)
        );

        // Old name is gone.

        $this->assertFalse(
            $filesystem->fileExists($this->filename(15))
        );

        // New name exists.

        $this->assertTrue(
            $filesystem->fileExists($this->filename(15, self::SUBDIR_ONE))
        );

        // Old name STILL exists.

        $this->assertTrue(
            $filesystem->has($this->filename(15, self::SUBDIR_ONE))
        );
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testRenameFailException($filesystem)
    {
        // Rename to a non-existent directory (fails to move).

        $this->expectException(UnableToCopyFile::class);

        $filesystem->write($this->filename(15, self::SUBDIR_ONE), 'content');

        $filesystem->move(
            $this->filename(15, self::SUBDIR_ONE),
            $this->filename(15, self::SUBDIR_THREE),
        );
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testRenameFailDoesNotMove($filesystem)
    {
        // Rename to a non-existent directory (fails to move).

        $filesystem->write($this->filename(15, self::SUBDIR_ONE), 'content');

        try {
            $filesystem->move(
                $this->filename(15, self::SUBDIR_ONE),
                $this->filename(15, self::SUBDIR_THREE),
            );
        } catch (UnableToCopyFile) {
            //
        }

        $this->assertTrue($filesystem->fileExists($this->filename(15, self::SUBDIR_ONE)));
    }

    /**
     * @dataProvider adapterProvider
     */
    // public function testDeleteDirectory($filesystem)
    // {
    //     // TBC
    // }

    /**
     * @dataProvider adapterProvider
     */
    public function testListContents($filesystem)
    {
        $directoryListing = $filesystem->listContents('', true);
        
        $this->assertIsIterable($directoryListing);
        $this->assertInstanceOf(DirectoryListing::class, $directoryListing);

        $allDirectories = $directoryListing->filter(fn (StorageAttributes $attributes) => $attributes->isDir())->toArray();
        $allFiles = $directoryListing->filter(fn (StorageAttributes $attributes) => $attributes->isFile())->toArray();

        // Look for the directories and files we have created.

        // $allContents = $directoryListing->toArray();

        // var_dump($allFiles);

        // Files created in testHas() at three levels

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $this->filename(1))
                ->toArray()
        );

        // $this->assertContains([
        //     'type' => 'file',
        //     'path' => $this->filename(1),
        //     'dirname' => '',
        //     'basename' => $this->filename(1),
        //     'extension' => 'txt',
        //     'filename' => basename($this->filename(1), '.txt'),
        // ], $allContents);

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $this->filename(2, self::SUBDIR_ONE))
                ->toArray()
        );

        // $this->assertContains([
        //     'type' => 'file',
        //     'path' => $this->filename(2, self::SUBDIR_ONE),
        //     'dirname' => dirname($this->filename(2, self::SUBDIR_ONE)),
        //     'basename' => $this->filename(2),
        //     'extension' => 'txt',
        //     'filename' => basename($this->filename(2), '.txt'),
        // ], $allContents);

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $this->filename(3, self::SUBDIR_TWO))
                ->toArray()
        );

        // $this->assertContains([
        //     'type' => 'file',
        //     'path' => $this->filename(3, self::SUBDIR_TWO),
        //     'dirname' => dirname($this->filename(3, self::SUBDIR_TWO)),
        //     'basename' => $this->filename(3),
        //     'extension' => 'txt',
        //     'filename' => basename($this->filename(3), '.txt'),
        // ], $allContents);

        // Directories creates in testHas()
        // TODO: check these meet the specs.

        $directory = dirname($this->filename(3, self::SUBDIR_ONE));

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isDir())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $directory)
                ->toArray()
        );

        // $this->assertContains([
        //     'path' => $directory,
        //     'type' => 'dir',
        //     'dirname' => (dirname($directory) === '.' ? '' : dirname($directory)),
        //     'basename' => basename($directory),
        //     'filename' => basename($directory),
        // ], $allContents);

        $directory = dirname($this->filename(3, self::SUBDIR_TWO));

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isDir())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $directory)
                ->toArray()
        );

        // $this->assertContains([
        //     'path' => $directory,
        //     'type' => 'dir',
        //     'dirname' => dirname($directory),
        //     'basename' => basename($directory),
        //     'filename' => basename($directory),
        // ], $allContents);

        // Start a level or two up.

        $subdirContents = $filesystem->listContents(self::SUBDIR_TWO, true);

        $this->assertIsIterable($subdirContents);
        $this->assertInstanceOf(DirectoryListing::class, $subdirContents);

        // The full paths are as for the recursive list, but only contain the
        // two files in the selected directory.

        $this->assertCount(2, $subdirContents->toArray());

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $this->filename(3, self::SUBDIR_TWO))
                ->toArray()
        );

        // $this->assertContains([
        //     'type' => 'file',
        //     'path' => $this->filename(3, self::SUBDIR_TWO),
        //     'dirname' => dirname($this->filename(3, self::SUBDIR_TWO)),
        //     'basename' => $this->filename(3),
        //     'extension' => 'txt',
        //     'filename' => basename($this->filename(3), '.txt'),
        // ], $allContents);

        // File 7 from testWriteDirTwo()

        $this->assertCount(1, 
            $directoryListing
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile())
                ->filter(fn (StorageAttributes $attributes) => $attributes->path() === $this->filename(7, self::SUBDIR_TWO))
                ->toArray()
        );

        // $this->assertContains([
        //     'type' => 'file',
        //     'path' => $this->filename(7, self::SUBDIR_TWO),
        //     'dirname' => dirname($this->filename(7, self::SUBDIR_TWO)),
        //     'basename' => $this->filename(7),
        //     'extension' => 'txt',
        //     'filename' => basename($this->filename(7), '.txt'),
        // ], $allContents);
    }

    /**
     * @todo add all other metadata calls here
     * 
     * @dataProvider adapterProvider
     */
    public function testGetMeta($filesystem)
    {
        //var_dump($filesystem->getMetadata($this->filename(1)));
        // Note that minetype is the mimetype of the API endpoint, and not
        // of the actual file. Some further research may be needed there.

        $this->assertIsInt($filesystem->lastModified($this->filename(1))); // Unix timetamp
        $this->assertSame(7, $filesystem->fileSize($this->filename(1))); // "content"
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testReadContent($filesystem)
    {
        $filesystem->write($this->filename(1), 'content');
        $filesystem->writeStream($this->filename(7, self::SUBDIR_TWO), $this->stream('stream7'));

        $this->assertSame(
            'content',
            $filesystem->read($this->filename(1))
        );

        $this->assertSame(
            'stream7',
            $filesystem->read($this->filename(7, self::SUBDIR_TWO))
        );
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testReadStream($filesystem)
    {
        $filesystem->write($this->filename(1), 'content-stream');

        $stream = $filesystem->readStream($this->filename(1));

        $this->assertIsResource($stream);

        $this->assertSame(
            'content-stream',
            stream_get_contents($stream)
        );

        // Copy a file by stream.

        $filesystem->writeStream(
            $this->filename(10),
            $filesystem->readStream($this->filename(1))
        );

        $this->assertSame(
            'content-stream',
            $filesystem->read($this->filename(10))
        );
    }
}
