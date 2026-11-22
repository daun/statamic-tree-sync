<?php

namespace Daun\StatamicTreeSync\Tests;

use Daun\StatamicTreeSync\ServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Mockery;
use Statamic\Statamic;

class ConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        Statamic::runAfterInstalledCallbacks(Mockery::mock(Command::class)->shouldIgnoreMissing());

        parent::setUp();
    }

    public function test_syncing_is_opt_in_by_default(): void
    {
        $this->assertSame([], config('statamic-tree-sync.collections'));
        $this->assertSame([], config('statamic-tree-sync.navigations'));
    }

    public function test_statamic_publishes_the_config_after_installation_without_overwriting_it(): void
    {
        $paths = LaravelServiceProvider::pathsToPublish(ServiceProvider::class, 'statamic-tree-sync-config');

        $this->assertSame([
            realpath(__DIR__.'/../config/statamic-tree-sync.php') => config_path('statamic-tree-sync.php'),
        ], $paths);

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('call')->once()->with('vendor:publish', [
            '--tag' => 'statamic-tree-sync-config',
        ]);

        Statamic::runAfterInstalledCallbacks($command);
    }
}
