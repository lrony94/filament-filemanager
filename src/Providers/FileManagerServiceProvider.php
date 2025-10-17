<?php

namespace Lrony94\FilamentFileManager\Providers;

use Lrony94\FilamentFileManager\Http\Middleware\AccessPanelPermission;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FileManagerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('filament-filemanager')
            ->hasConfigFile()
            //->hasViews()
            ->hasInstallCommand(
                function (InstallCommand $command) {
                    $command
                        ->publishConfigFile()
                        ->copyAndRegisterServiceProviderInApp()
                        ->askToStarRepoOnGitHub($this->getAssetPackageName());
                }
            );
    }

    public function packageRegistered(): void
    {
    }

    public function packageBooted(): void
    {
        // Comment out the shield requirement check for now
        // if (!class_exists(\BezhanSalleh\FilamentShield\FilamentShieldPlugin::class)) {
        //     throw new \RuntimeException(
        //         'This package requires [bezhan-salleh/filament-shield]. Please install it via composer: composer require bezhan-salleh/filament-shield'
        //     );
        // }

        // Load package views
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'filament-filemanager');
        // Load package routes
        \Lrony94\FilamentFileManager\Controllers\FileManagerController::routes();

        app('router')->aliasMiddleware('filemanger.permission', AccessPanelPermission::class);
    }

    protected function getAssetPackageName(): ?string
    {
        return 'Lrony94/filament-filemanager';
    }

}
