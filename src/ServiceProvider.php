<?php

namespace Daun\StatamicTreeSync;

use Daun\StatamicTreeSync\Commands\SyncCollectionsCommand;
use Daun\StatamicTreeSync\Commands\SyncNavigationsCommand;
use Daun\StatamicTreeSync\Commands\SyncTreesCommand;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $subscribe = [
        SyncTrees::class,
    ];

    protected $commands = [
        SyncTreesCommand::class,
        SyncCollectionsCommand::class,
        SyncNavigationsCommand::class,
    ];

    public function bootAddon(): void
    {
        Statamic::afterInstalled(function ($command) {
            $command->call('vendor:publish', [
                '--tag' => 'statamic-tree-sync-config',
            ]);
        });
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(TreeSync::class);
        $this->app->singleton(Syncer::class);
        $this->app->singleton(NavSyncer::class);
    }
}
