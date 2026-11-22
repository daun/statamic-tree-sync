<?php

namespace Daun\StatamicTreeSync\Listeners;

use Daun\StatamicTreeSync\Sync\Collections\CollectionSyncer;
use Daun\StatamicTreeSync\Sync\Navs\NavSyncer;
use Daun\StatamicTreeSync\Sync\TreeSync;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryCreated;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\Subscriber;
use Throwable;

class SyncTrees extends Subscriber
{
    /** @var array<class-string, string> */
    protected $listeners = [
        CollectionTreeSaved::class => 'handleTreeSaved',
        EntryCreated::class => 'handleEntryCreated',
        NavTreeSaved::class => 'handleNavTreeSaved',
    ];

    protected function collectionSyncer(): CollectionSyncer
    {
        return app(CollectionSyncer::class);
    }

    protected function navSyncer(): NavSyncer
    {
        return app(NavSyncer::class);
    }

    /**
     * A tree was rearranged: rearrange the trees of the other sites to match.
     */
    public function handleTreeSaved(CollectionTreeSaved $event): void
    {
        $tree = $event->tree;

        if (! TreeSync::listening() || ! $this->collectionSyncer()->syncs($tree->collection())) {
            return;
        }

        $this->safely(function () use ($tree) {
            if ($site = $this->collectionSyncer()->localizationSource($tree)) {
                $this->collectionSyncer()->pull($tree, $site);
            } else {
                // tree() re-appends entries being deleted: Statamic saves their tree before deleting them.
                $this->collectionSyncer()->push($tree, branches: $tree->fileData()['tree']);
            }
        });
    }

    /**
     * A localization was created: place it in its tree like its origin.
     * Covers localizations created without being added to the tree.
     */
    public function handleEntryCreated(EntryCreated $event): void
    {
        $entry = $event->entry;

        if (! TreeSync::listening() || ! $entry->hasOrigin() || ! $this->collectionSyncer()->syncs($entry->collection())) {
            return;
        }

        $this->safely(function () use ($entry) {
            if ($tree = $entry->collection()->structure()->in($entry->locale())) {
                $this->collectionSyncer()->pull($tree, $entry->origin()->locale());
            }
        });
    }

    /**
     * A nav tree was saved: mirror its changes in the trees of the other sites.
     */
    public function handleNavTreeSaved(NavTreeSaved $event): void
    {
        $tree = $event->tree;

        if (! TreeSync::listening()) {
            return;
        }

        $this->safely(function () use ($tree) {
            // Read the original tree first: the Stache resets it whenever the tree is looked up again
            $original = $tree->getOriginal('tree') ?? [];
            $seed = $this->navSyncer()->seedSource($tree);

            if (! $this->navSyncer()->syncs($tree->structure())) {
                return;
            }

            if ($seed) {
                $this->navSyncer()->pull($tree, $seed);
            } else {
                $this->navSyncer()->push($tree, $original);
            }
        });
    }

    /**
     * Report sync failures instead of throwing: the triggering tree is already saved.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
