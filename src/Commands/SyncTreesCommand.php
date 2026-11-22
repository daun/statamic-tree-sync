<?php

namespace Daun\StatamicTreeSync\Commands;

use Daun\StatamicTreeSync\NavSyncer;
use Daun\StatamicTreeSync\Syncer;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

class SyncTreesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:tree-sync
        {handles?* : Handles of the collections or navigations to sync (default: all synced ones)}
        {--from= : Site to take the tree from (default: the default site)}
        {--dry-run : Only list the trees that would change}';

    protected $description = 'Rearrange the trees of all sites to match the tree of one site';

    public function handle(Syncer $syncer, NavSyncer $navSyncer): int
    {
        $from = is_string($this->option('from')) ? $this->option('from') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($from !== null && ! Site::get($from)) {
            $this->components->error("Site [{$from}] not found");

            return self::FAILURE;
        }

        $structures = $this->structures($syncer, $navSyncer);

        if ($structures === null) {
            return self::FAILURE;
        }

        foreach ($structures as $structure) {
            if ($from !== null && ! $structure->sites()->contains($from)) {
                $this->components->twoColumnDetail($this->label($structure), "<fg=yellow>skipped: not in site {$from}</>");

                continue;
            }

            $changed = $structure instanceof NavContract
                ? $navSyncer->sync($structure, $from, save: ! $dryRun)
                : $syncer->sync($structure, $from, save: ! $dryRun);

            $this->components->twoColumnDetail(
                $this->label($structure),
                $changed ? ($dryRun ? 'would change: ' : 'changed: ').implode(', ', $changed) : 'in sync'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, CollectionContract|NavContract>|null
     */
    protected function structures(Syncer $syncer, NavSyncer $navSyncer): ?array
    {
        /** @var string[] $handles */
        $handles = $this->argument('handles');

        if (! $handles) {
            return [
                ...Collection::all()->filter(fn (CollectionContract $collection) => $syncer->syncs($collection))->values(),
                ...Nav::all()->filter(fn (NavContract $nav) => $navSyncer->syncs($nav))->values(),
            ];
        }

        $structures = [];
        foreach ($handles as $handle) {
            $found = array_filter([Collection::findByHandle($handle), Nav::findByHandle($handle)]);

            if (! $found) {
                $this->components->error("Collection or navigation [{$handle}] not found");

                return null;
            }

            $synced = array_filter($found, fn ($structure) => $structure instanceof NavContract
                ? $navSyncer->syncs($structure)
                : $syncer->syncs($structure));

            if (! $synced) {
                $this->components->error("[{$handle}] is not enabled for syncing or does not have a supported multisite structure");

                return null;
            }

            array_push($structures, ...$synced);
        }

        return $structures;
    }

    protected function label(CollectionContract|NavContract $structure): string
    {
        return $structure instanceof NavContract ? "nav:{$structure->handle()}" : $structure->handle();
    }
}
