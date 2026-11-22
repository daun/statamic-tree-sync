<?php

namespace Daun\StatamicTreeSync\Tests\Eloquent;

use Daun\StatamicTreeSync\Tests\Concerns\UsesEloquentDriver;
use Daun\StatamicTreeSync\Tests\SyncCollectionsTest;

/** Collections, navigations, entries and trees in the database */
class FullSyncCollectionsTest extends SyncCollectionsTest
{
    use UsesEloquentDriver;

    protected function eloquentRepositories(): array
    {
        return ['collections', 'collection_trees', 'entries', 'navigations', 'navigation_trees'];
    }
}
