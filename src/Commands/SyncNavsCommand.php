<?php

namespace Daun\StatamicTreeSync\Commands;

class SyncNavsCommand extends SyncStructuresCommand
{
    protected $signature = 'statamic:trees:sync:navigations
        {handles?* : Handles of the navigations to sync (default: all configured ones)}
        {--from= : Site to take the tree from (default: the default site)}
        {--dry-run : Only list the trees that would change}';

    protected $description = 'Sync nav structure and shared fields across sites';

    protected bool $navs = true;
}
