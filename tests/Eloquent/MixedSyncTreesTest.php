<?php

namespace Daun\StatamicTreeSync\Tests\Eloquent;

use Daun\StatamicTreeSync\Tests\Concerns\UsesEloquentDriver;
use Daun\StatamicTreeSync\Tests\SyncTreesTest;

/** Collections and navigations in files, entries and trees in the database */
class MixedSyncTreesTest extends SyncTreesTest
{
    use UsesEloquentDriver;

    protected function eloquentRepositories(): array
    {
        return ['collection_trees', 'entries', 'navigation_trees'];
    }
}
