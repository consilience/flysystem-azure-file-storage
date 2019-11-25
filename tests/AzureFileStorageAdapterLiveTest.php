<?php

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use Consilience\Flysystem\Azure\AzureFileAdapter;
use phpseclib\System\SSH\Agent;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use MicrosoftAzure\Storage\File\Internal\IFile;
use MicrosoftAzure\Storage\File\FileRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

class AzureFileStorageAdapterLiveTest extends TestCase
{
    public const PREFIX_ONE = 'test-prefix';
    public const PREFIX_TWO = 'test-prefix-level1/test-prefix-level2';

    public const FILENAME_PREFIX_TEMPLATE = 'test-foo-{number}.txt';

    public const SUBDIR_ONE = 'test-subdir1';
    public const SUBDIR_TWO = 'test-subdir1/test-subdir2';
    public const SUBDIR_THREE = 'test-subdir3';

    /**
     * Provides a live adapter based on config.
     * There are three adapters - one with no prefex, one with a single level
     * prefix and one with a two level prefix.
     * The prefixes were proving problematic enough to need specific testing.
     */
    public function adapterProvider()
    {
        // Assert that required environment variables are set.
        // Will be bool (false) if not set at all.

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
            []
        );

        $filesystem = new Filesystem(new AzureFileAdapter(
            $fileService,
            $config
        ));

        $filesystemPrefixOne = new Filesystem(new AzureFileAdapter(
            $fileService,
            $config,
            self::PREFIX_ONE
        ));

        $filesystemPrefixTwo = new Filesystem(new AzureFileAdapter(
            $fileService,
            $config,
            self::PREFIX_TWO
        ));

        // The data provider supports no prefix, a single level prefix,
        // and a two-level prefix.

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

        $this->assertTrue($filesystem->deleteDir(self::PREFIX_ONE));
        $this->assertTrue($filesystem->deleteDir(self::PREFIX_TWO));

        $this->assertTrue($filesystem->deleteDir(self::SUBDIR_ONE));
        $this->assertTrue($filesystem->deleteDir(self::SUBDIR_TWO));

        // Delete any files left behind.

        for ($i = 0; $i < 20; $i++) {
            $filename = $this->filename($i);

            if ($filesystem->has($filename)) {
                $filesystem->delete($filename);
            }
        }
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testHas($filesystem)
    {
        // Create file and confirm it exists.

        $this->assertTrue($filesystem->write($this->filename(1), 'content'));

        $this->assertTrue($filesystem->has($this->filename(1)));

        // Create files two levels of subdirectory they exist.

        $this->assertTrue($filesystem->put($this->filename(2, self::SUBDIR_ONE), 'content'));
        $this->assertTrue($filesystem->has($this->filename(2, self::SUBDIR_ONE)));

        $this->assertTrue($filesystem->put($this->filename(3, self::SUBDIR_TWO), 'content'));
        $this->assertTrue($filesystem->has($this->filename(3, self::SUBDIR_TWO)));

        // Some consistency for directories.
        // This driver will treat a directory as a file when checking if it exists.

        $this->assertTrue($filesystem->has(self::SUBDIR_ONE));
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
     * @expectedException League\Flysystem\FileExistsException
     */
    public function testWrite($filesystem)
    {
        // write() will only create new files.
        // Try writing again and we get an exception.
        // It is unclear what condition triggers a `false` return.

        foreach ([1, 2] as $attempt) {
            $this->assertTrue($filesystem->write(
                $this->filename(5),
                'content',
                ['visibility' => 'public'])
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileExistsException
     */
    public function testWriteDir($filesystem)
    {
        // write() will only create new files.
        // Try writing again and we get an exception.
        // It is unclear what condition triggers a `false` return.

        foreach ([1, 2] as $attempt) {
            $this->assertTrue(
                $filesystem->write(
                    $this->filename(6, self::SUBDIR_ONE),
                    'content',
                    ['visibility' => 'public']
                )
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileExistsException
     */
    public function testWriteDirTwo($filesystem)
    {
        // write() will only create new files.
        // Try writing again and we get an exception.
        // It is unclear what condition triggers a `false` return.

        foreach ([1, 2] as $attempt) {
            $this->assertTrue(
                $filesystem->write(
                    $this->filename(7, self::SUBDIR_TWO),
                    'content',
                    ['visibility' => 'public']
                )
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileExistsException
     */
    public function testWriteStream($filesystem)
    {
        foreach ([1, 2] as $attempt) {
            $this->assertTrue(
                $filesystem->writeStream(
                    $this->filename(8),
                    $this->stream(),
                    ['visibility' => 'public']
                )
            );

            $this->assertSame(
                'content',
                $filesystem->read($this->filename(8))
            );
        }
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileExistsException
     */
    public function testWriteStreamDirOne($filesystem)
    {
        foreach ([1, 2] as $attempt) {
            $this->assertTrue(
                $filesystem->writeStream(
                    $this->filename(8, self::SUBDIR_ONE),
                    $this->stream(),
                    ['visibility' => 'public']
                )
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
    public function testDeleteFile($filesystem)
    {
        // Deleting the files that exist.

        $this->assertTrue($filesystem->delete($this->filename(8)));
        $this->assertTrue($filesystem->delete($this->filename(8, self::SUBDIR_ONE)));
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileNotFoundException
     */
    public function testDeleteFileFail($filesystem)
    {
        // Deleting again will thow an exception.
        // It is flysystem core that does that.

        $this->assertTrue($filesystem->delete($this->filename(8)));
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileNotFoundException
     */
    public function testDeleteFileFailDirOne($filesystem)
    {
        // Deleting again will thow an exception.
        // It is flysystem core that does that.

        $this->assertTrue($filesystem->delete($this->filename(8, self::SUBDIR_ONE)));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testUpdate($filesystem)
    {
        // Update a bunch of files.

        $this->assertTrue($filesystem->update($this->filename(5), 'foobar5'));
        $this->assertTrue($filesystem->update($this->filename(6, self::SUBDIR_ONE), 'foobar6'));
        $this->assertTrue($filesystem->update($this->filename(7, self::SUBDIR_TWO), 'foobar7'));

        // Check they have all been updated.

        $this->assertSame('foobar5', $filesystem->read($this->filename(5)));
        $this->assertSame('foobar6', $filesystem->read($this->filename(6, self::SUBDIR_ONE)));
        $this->assertSame('foobar7', $filesystem->read($this->filename(7, self::SUBDIR_TWO)));
    }

    /**
     * @dataProvider adapterProvider
     * @expectedException League\Flysystem\FileNotFoundException
     */
    public function testUpdateFail($filesystem)
    {
        // Updating a file that does not exist will throw an exception.
        // It is flysystem core that does that.

        $filesystem->update($this->filename(15), 'foobar15');
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testUpdateStream($filesystem)
    {
        // Update a bunch of files.

        $this->assertTrue($filesystem->updateStream($this->filename(5), $this->stream('stream5')));
        $this->assertTrue($filesystem->updateStream($this->filename(6, self::SUBDIR_ONE), $this->stream('stream6')));
        $this->assertTrue($filesystem->updateStream($this->filename(7, self::SUBDIR_TWO), $this->stream('stream7')));

        // Check they have all been updated.

        $this->assertSame('stream5', $filesystem->read($this->filename(5)));
        $this->assertSame('stream6', $filesystem->read($this->filename(6, self::SUBDIR_ONE)));
        $this->assertSame('stream7', $filesystem->read($this->filename(7, self::SUBDIR_TWO)));
    }

    /**
     * The LogicException is an unusual choice, but it's what the flysystem
     * polyfill provides for file systems that do not support visibility.
     *
     * @dataProvider adapterProvider
     * @expectedException LogicException
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
     * @expectedException LogicException
     */
    public function testGetVisibility($filesystem)
    {
        // Visibility is not supported by this driver.

        $filesystem->getVisibility($this->filename(5));
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testRename($filesystem)
    {
        // Rename in same directory.

        $this->assertTrue(
            $filesystem->rename(
                $this->filename(5),
                $this->filename(15)
            )
        );

        // Old name is gone.

        $this->assertFalse(
            $filesystem->has($this->filename(5))
        );

        // New name exists.

        $this->assertTrue(
            $filesystem->has($this->filename(15))
        );

        // Rename to an existing directory.

        $this->assertTrue($filesystem->rename(
            $this->filename(15),
            $this->filename(15, self::SUBDIR_ONE)
        ));

        // Old name is gone.

        $this->assertFalse(
            $filesystem->has($this->filename(15))
        );

        // New name exists.

        $this->assertTrue(
            $filesystem->has($this->filename(15, self::SUBDIR_ONE))
        );

        // Rename to a non-existent directory (fails to move).

        $this->assertFalse($filesystem->rename(
            $this->filename(15, self::SUBDIR_ONE),
            $this->filename(15, self::SUBDIR_THREE))
        );

        // Old name STILL exists.

        $this->assertTrue(
            $filesystem->has($this->filename(15, self::SUBDIR_ONE))
        );
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testDeleteDir($filesystem)
    {
        // TBC
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testListContents($filesystem)
    {
        $allContents = $filesystem->listContents('', true);

        $this->assertInternalType('array', $allContents);

        // Look for the directories and files we have created.

        // Files created in testHas() at three levels

        $this->assertContains([
            'type' => 'file',
            'path' => $this->filename(1),
            'dirname' => '',
            'basename' => $this->filename(1),
            'extension' => 'txt',
            'filename' => basename($this->filename(1), '.txt'),
        ], $allContents);

        $this->assertContains([
            'type' => 'file',
            'path' => $this->filename(2, self::SUBDIR_ONE),
            'dirname' => dirname($this->filename(2, self::SUBDIR_ONE)),
            'basename' => $this->filename(2),
            'extension' => 'txt',
            'filename' => basename($this->filename(2), '.txt'),
        ], $allContents);

        $this->assertContains([
            'type' => 'file',
            'path' => $this->filename(3, self::SUBDIR_TWO),
            'dirname' => dirname($this->filename(3, self::SUBDIR_TWO)),
            'basename' => $this->filename(3),
            'extension' => 'txt',
            'filename' => basename($this->filename(3), '.txt'),
        ], $allContents);

        // Directories creates in testHas()
        // TODO: check these meet the specs.

        $directory = dirname($this->filename(3, self::SUBDIR_ONE));

        $this->assertContains([
            'type' => 'dir',
            'path' => $directory,
            'dirname' => (dirname($directory) === '.' ? '' : dirname($directory)),
            'basename' => basename($directory),
            'filename' => basename($directory),
        ], $allContents);

        $directory = dirname($this->filename(3, self::SUBDIR_TWO));

        $this->assertContains([
            'type' => 'dir',
            'path' => $directory,
            'dirname' => dirname($directory),
            'basename' => basename($directory),
            'filename' => basename($directory),
        ], $allContents);

        // Start a level or two up.

        $subdirContents = $filesystem->listContents(self::SUBDIR_TWO, true);

        $this->assertInternalType('array', $subdirContents);

        // The full paths are as for the recursive list, but only comntain the
        // two files in the selected direectory.

        $this->assertCount(2, $subdirContents);

        $this->assertContains([
            'type' => 'file',
            'path' => $this->filename(3, self::SUBDIR_TWO),
            'dirname' => dirname($this->filename(3, self::SUBDIR_TWO)),
            'basename' => $this->filename(3),
            'extension' => 'txt',
            'filename' => basename($this->filename(3), '.txt'),
        ], $allContents);

        // File 7 from testWriteDirTwo()

        $this->assertContains([
            'type' => 'file',
            'path' => $this->filename(7, self::SUBDIR_TWO),
            'dirname' => dirname($this->filename(7, self::SUBDIR_TWO)),
            'basename' => $this->filename(7),
            'extension' => 'txt',
            'filename' => basename($this->filename(7), '.txt'),
        ], $allContents);
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testGetMeta($filesystem)
    {
        //var_dump($filesystem->getMetadata($this->filename(1)));
        // Note that minetype is the mimetype of the API endpoint, and not
        // of the actual file. Some further research may be needed there.

        $this->assertInternalType('int', $filesystem->getTimestamp($this->filename(1))); // Unix timetamp
        $this->assertSame(7, $filesystem->getSize($this->filename(1))); // "content"
    }

    /**
     * @dataProvider adapterProvider
     */
    public function testRead($filesystem)
    {
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
        $stream = $filesystem->readStream($this->filename(1));

        $this->assertInternalType('resource', $stream);

        $this->assertSame(
            'content',
            stream_get_contents($stream)
        );

        // Copy a file by stream.

        $this->assertTrue(
            $filesystem->writeStream(
                $this->filename(10),
                $filesystem->readStream($this->filename(1))
            )
        );

        $this->assertSame(
            'content',
            $filesystem->read($this->filename(10))
        );
    }
}
