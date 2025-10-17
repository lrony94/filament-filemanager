# Filament Filemanager

[Filament](https://filamentphp.com/) Admin/Forms.


## Installation

1-Install the package via composer

```bash
composer require lrony94/filament-filemanager
```

2-Publish assets

```bash
php artisan vendor:publish --provider="Lrony94\FilamentFileManager\Providers\FileManagerServiceProvider"
```


## Usage

The editor extends the default Field class so most other methods available on that class can be used when adding it to a form.

```php
use Lrony94\FilamentFileManager\Forms\Components\FileManagerPicker;

FileManagerPicker::make('avatar')
	->columnSpanFull()
	->required()
	->label('Avatar'),
```

## Config

The plugin will work without publishing the config, but should you need to change any of the default settings you can publish the config file with the following Artisan command:

```bash
php artisan vendor:publish --tag="filament-filemanager-config"
```

### Profiles / Tools

The package comes with 4 profiles (or toolbars) out of the box. You can also use a pipe `|` to separate tools into groups. The default profile is the full set of tools.

```php
"disk" => "local",
"allowed_mimes" => [
	'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'mp4', 'mp3'
],
```

## Versioning

This project follow the [Semantic Versioning](https://semver.org/) guidelines.

## License

Licensed under the MIT license, see [LICENSE.md](LICENSE.md) for details.
