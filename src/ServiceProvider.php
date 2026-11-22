<?php

namespace Daun\StatamicTreeSync;

use Daun\StatamicTreeSync\Commands\SyncCollectionsCommand;
use Daun\StatamicTreeSync\Commands\SyncNavsCommand;
use Daun\StatamicTreeSync\Commands\SyncTreesCommand;
use Daun\StatamicTreeSync\Listeners\SyncTrees;
use Daun\StatamicTreeSync\Sync\Collections\CollectionSyncer;
use Daun\StatamicTreeSync\Sync\Navs\NavSyncer;
use Daun\StatamicTreeSync\Sync\TreeSync;
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
        SyncNavsCommand::class,
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
        $this->app->singleton(CollectionSyncer::class);
        $this->app->singleton(NavSyncer::class);
    }
}
