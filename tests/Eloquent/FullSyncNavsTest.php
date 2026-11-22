<?php

namespace Daun\StatamicTreeSync\Tests\Eloquent;

use Daun\StatamicTreeSync\Tests\Concerns\UsesEloquentDriver;
use Daun\StatamicTreeSync\Tests\SyncNavsTest;

/** Collections, navigations, entries and trees in the database */
class FullSyncNavsTest extends SyncNavsTest
{
    use UsesEloquentDriver;

    protected function eloquentRepositories(): array
    {
        return ['collections', 'collection_trees', 'entries', 'navigations', 'navigation_trees'];
    }
}
