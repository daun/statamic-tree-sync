<?php

namespace Daun\StatamicTreeSync\Commands;

class SyncCollectionsCommand extends SyncStructuresCommand
{
    protected $signature = 'statamic:trees:sync:collections
        {handles?* : Handles of the collections to sync (default: all configured ones)}
        {--from= : Site to take the tree from (default: the default site)}
        {--dry-run : Only list the trees that would change}';

    protected $description = 'Sync collection page order and parents across sites';
}
