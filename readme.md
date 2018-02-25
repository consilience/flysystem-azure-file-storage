[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

# Azure File Storage adapater for Flysystem

This repo is fork of [League\Flysystem\Azure](https://github.com/thephpleague/flysystem-azure)

# Why forked?

The original Azure Flysystem adapter supported Blobs.
This alternative adapter supports Azure File Storage, with directory support.

I separate service provider package for Laravel 5.5+ will be available.

# How to install

Install package
```bash
composer require consilience/flysystem-azure-file
```

# How to use this driver

```php
use League\Flysystem\Filesystem;
use Consilience\Flysystem\Azure\AzureFileAdapter;
use MicrosoftAzure\Storage\File\FileRestProxy;
use Illuminate\Support\ServiceProvider;

// A helper method for constructing the connectionString will be implemented in time.

$connectionString = sprintf(
    'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
    '{storage account name}',
    '{file storage key}'
);

$config = [
    'endpoint' => $connectionString,
    'container' => '{file share name}',
    // Optional to prevent directory deletion recursively deleting
    // all descendant files and direcories.
    //'disableRecursiveDelete' => true,
];

$fileService = FileRestProxy::createFileService(
    $connectionString,,
    [] // $optionsWithMiddlewares
);


$filesystem = new Filesystem(new AzureFileAdapter(
    $fileService,
    $config,
    '' // Optional base directory
));

// Now the $filesystem object can be used as a standard
// Flysystem file system.

$content = $filesystem->read('path/to/my/file.txt');
$resource = $filesystem->readResource('path/to/my/file.txt');
$success = $filesystem->createDir('new/directory/here');
$success = $filesystem->rename('path/to/my/file.txt', 'some/other/folder/another.txt');

// etc.

```

