[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

# Azure Blob custom filesystem for Laravel 5
This repo is fork of [League\Flysystem\Azure [BETA]](https://github.com/thephpleague/flysystem-azure)

# Why forked?
Need to integrate with L5 out of the box, and **url** method for **Storage** interface  
All examples below L5 related.   

# How to install in Laravel 5 application

Install package
```bash
composer require ijin82/flysystem-azure
```

Open **config/app.php** and add this to providers section
```
Ijin82\Flysystem\Azure\AzureBlobServiceProvider::class,
```

Open **config/filesystems.php** and add this stuff to disks section
```
'my_azure_disk1' => [
    'driver' => 'azure_blob',
    'endpoint' => env('AZURE_BLOB_STORAGE_ENDPOINT'),
    'container' => env('AZURE_BLOB_STORAGE_CONTAINER1'),
    'blob_service_url' => env('AZURE_BLOB_SERVICE_URL'),
],
```

Open your **.env** and add variables for your disk
```
AZURE_BLOB_SERVICE_URL={your-blob-service-url}
AZURE_BLOB_STORAGE_ENDPOINT="DefaultEndpointsProtocol=https;AccountName={your-account-name};AccountKey={your-account-key};"
AZURE_BLOB_STORAGE_CONTAINER1={your-container-name}
```
1. You can get **AZURE_BLOB_SERVICE_URL** variable from **Properties** section of your Storage account settings.
That is an url named *PRIMARY BLOB SERVICE ENDPOINT* or *SECONDARY BLOB SERVICE ENDPOINT*
1. You can get **AZURE_BLOB_STORAGE_ENDPOINT** variable from **Access keys** section of your Storage account settings.
That is named *CONNECTION STRING*
1. **AZURE_BLOB_STORAGE_CONTAINER1** is the name of your pre-created container, that you can add at **Overview** 
section of your Storage account settings.

# How to upload file
```php
public function someUploadFuncName(Request $request)
{
    $file = $request->file('file_name_from_request');
    // check mime type
    if ($file->getClientMimeType() == 'application/x-font-ttf') {

        // feel free to change this logic, that is an example
        $baseFileName = strtolower($file->getClientOriginalName());
        $ext = strtolower($file->getClientOriginalExtension());
        $filenameWithoutExt = preg_replace("~\." . $ext . "$~i", '', $baseFileName);
        $diskFileName = $font->id . '-' . preg_replace_array([
                "~[\r\n\t ]~",
                "~[^a-z0-9\_\-]~",
            ], [
                "_",
                "_",
            ],
            $filenameWithoutExt
            ) . '.' . $ext;
        // folder name in container, could be empty
        $folderName='some-folder-name';
        
        // store file on azure blob
        $file->storeAs($folderName, $diskFileName, ['disk' => 'account_fonts']);

        // save file name somewhere
        $saveFileName = $folderName . '/' . $diskFileName;
    }
    
    // go back or etc..
}
```

# How to get file URL

We got file name for selected disk (folder related if folder exists)
```php
echo Storage::disk('my_azure_disk1')->url($file_name);
```
That is also working in blade templates like this
```
<a href="{{ Storage::disk('my_azure_disk1')->url($file_name) }}"
    target="_blank">{{ $file_name }}</a>
```

# How to delete file 
```php
public function someDeleteFuncName($id)
{
    $file = SomeFileModel::findOrFail($id);
    Storage::disk('my_azure_disk1')->delete($file->name);
    $file->delete();

    // go back or etc..
}
```

# Additions
1. Original repo is [here](https://github.com/thephpleague/flysystem-azure)
2. [How to use blob storage from PHP](https://docs.microsoft.com/en-us/azure/storage/storage-php-how-to-use-blobs)
3. [Flysystem azure adapter](http://flysystem.thephpleague.com/adapter/azure/)
4. Feel free to send pull requests and issues.
