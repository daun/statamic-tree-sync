<?php

namespace Daun\StatamicTreeSync\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class SyncTreesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:trees:sync
        {--dry-run : Only list the trees that would change}';

    protected $description = 'Sync all configured collection and nav trees from the default site';

    public function handle(): int
    {
        $options = ['--dry-run' => (bool) $this->option('dry-run')];

        $collections = $this->call('statamic:trees:sync:collections', $options);
        $navigations = $this->call('statamic:trees:sync:navigations', $options);

        return $collections === self::SUCCESS && $navigations === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }
}
