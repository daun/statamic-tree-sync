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

abstract class SyncStructuresCommand extends Command
{
    use RunsInPlease;

    protected bool $navigations = false;

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
            return $this->navigations
                ? Nav::all()->filter(fn (NavContract $nav) => $navSyncer->syncs($nav))->values()->all()
                : Collection::all()->filter(fn (CollectionContract $collection) => $syncer->syncs($collection))->values()->all();
        }

        $structures = [];
        foreach (array_unique($handles) as $handle) {
            $structure = $this->navigations ? Nav::findByHandle($handle) : Collection::findByHandle($handle);
            $type = $this->navigations ? 'Navigation' : 'Collection';

            if (! $structure) {
                $this->components->error("{$type} [{$handle}] not found");

                return null;
            }

            $synced = $structure instanceof NavContract
                ? $navSyncer->syncs($structure)
                : $syncer->syncs($structure);

            if (! $synced) {
                $this->components->error("{$type} [{$handle}] is not enabled for syncing or does not have a supported multisite structure");

                return null;
            }

            $structures[] = $structure;
        }

        return $structures;
    }

    protected function label(CollectionContract|NavContract $structure): string
    {
        return $structure instanceof NavContract ? "nav:{$structure->handle()}" : "collection:{$structure->handle()}";
    }
}
