<?php

namespace Daun\StatamicTreeSync\Tests\Eloquent;

use Daun\StatamicTreeSync\Tests\Concerns\UsesEloquentDriver;
use Daun\StatamicTreeSync\Tests\SyncTreesTest;

/** Collections, navigations, entries and trees in the database */
class FullSyncTreesTest extends SyncTreesTest
{
    use UsesEloquentDriver;

    protected function eloquentRepositories(): array
    {
        return ['collections', 'collection_trees', 'entries', 'navigations', 'navigation_trees'];
    }
}
