
[![Latest Stable Version](https://poser.pugx.org/consilience/flysystem-azure-file-storage/v/stable)](https://packagist.org/packages/consilience/flysystem-azure-file-storage)
[![Total Downloads](https://poser.pugx.org/consilience/flysystem-azure-file-storage/downloads)](https://packagist.org/packages/consilience/flysystem-azure-file-storage)
[![Latest Unstable Version](https://poser.pugx.org/consilience/flysystem-azure-file-storage/v/unstable)](https://packagist.org/packages/consilience/flysystem-azure-file-storage)
[![License](https://poser.pugx.org/consilience/flysystem-azure-file-storage/license)](https://packagist.org/packages/consilience/flysystem-azure-file-storage)

# Azure File Storage adapter for Flysystem

This repo is fork of [League\Flysystem\Azure](https://github.com/thephpleague/flysystem-azure)
with the underlying Azure API library changed from `microsoft/azure-storage`
to `microsoft/azure-storage-file`.
The original driver supports Azure blob storage, with a flat binary object structure.
This driver supports Azure file storage, which includes directory capabilities.

A separate service provider package for Laravel 5.5+ is available here:
https://github.com/academe/laravel-azure-file-storage-driver
The service provider allows Azure File Storage shares tbe be used
as a native filesystem within Laravel.

# Installation

Install package
```bash
composer require consilience/flysystem-azure-file-storage
```

# How to use this driver

*Note: if you are using Laravel then the
[filesystem driver](https://github.com/academe/laravel-azure-file-storage-driver)
will wrap and abstract all of this for you.*

```php
use League\Flysystem\Filesystem;
use Consilience\Flysystem\Azure\AzureFileAdapter;
use MicrosoftAzure\Storage\File\FileRestProxy;
use Illuminate\Support\ServiceProvider;

// A helper method for constructing the connectionString may be usedful,
// if there is a demand.

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
    [] // Optional driver options.
));

// Now the $filesystem object can be used as a standard
// Flysystem file system.
// See https://flysystem.thephpleague.com/api/

// A few examples:

$content    = $filesystem->read('path/to/my/file.txt');
$resource   = $filesystem->readResource('path/to/my/file.txt');
$success    = $filesystem->createDir('new/directory/here');
$success    = $filesystem->rename('path/to/my/file.txt', 'some/other/folder/another.txt');

// The URL of a file can be found like this:

$url = $filesystem->getAdapter()->getUrl('path/to/my/foo.bar');
```

## Testing

There are no tests yet.
These will be added when time permits.
