<?php

namespace Daun\StatamicTreeSync\Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider as EloquentServiceProvider;

/**
 * Store the repositories returned by `eloquentRepositories()` in an in-memory database
 * instead of the Stache. Everything else stays in flat files.
 */
trait UsesEloquentDriver
{
    use RefreshDatabase;

    /**
     * @return string[] Keys of the eloquent driver config to store in the database
     */
    abstract protected function eloquentRepositories(): array;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), EloquentServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $config = $app['config'];
        $config->set('statamic.eloquent-driver', require $this->eloquentDriverPath('config/eloquent-driver.php'));
        $config->set('statamic.eloquent-driver.entries.model', UuidEntryModel::class);
        foreach ($this->eloquentRepositories() as $repository) {
            $config->set("statamic.eloquent-driver.{$repository}.driver", 'eloquent');
        }
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom($this->eloquentDriverPath('database/migrations'));
        $this->loadMigrationsFrom($this->eloquentDriverPath('database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php'));
    }

    private function eloquentDriverPath(string $path): string
    {
        return dirname((new ReflectionClass(EloquentServiceProvider::class))->getFileName(), 2).'/'.$path;
    }
}
