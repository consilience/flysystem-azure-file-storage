<?php

namespace Ijin82\Flysystem\Azure;

use Storage;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Illuminate\Support\ServiceProvider;

class AzureBlobServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('azure_blob', function ($app, $config) {
            $blobService = BlobRestProxy::createBlobService(
                $config['endpoint'],
                [] // $optionsWithMiddlewares
            );

            return new Filesystem(new AzureAdapter(
                $blobService,
                $config
            ));
        });
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}