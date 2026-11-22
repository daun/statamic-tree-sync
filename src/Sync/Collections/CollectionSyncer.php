<?php

namespace Daun\StatamicTreeSync\Sync\Collections;

use Daun\StatamicTreeSync\Sync\TreeSync;
use Statamic\Contracts\Entries\Collection;
use Statamic\Eloquent\Entries\EntryQueryBuilder as EloquentEntryQueryBuilder;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Structures\CollectionTree;
use stdClass;

class CollectionSyncer
{
    /**
     * Opted-in collections with structures in more than one site.
     */
    public function syncs(?Collection $collection): bool
    {
        $handles = config('statamic-tree-sync.collections', []);

        return $collection
            && (in_array('*', $handles, true) || in_array($collection->handle(), $handles, true))
            && $collection->hasStructure()
            && $collection->sites()->count() > 1;
    }

    /**
     * Rearrange the trees of all other sites to match the given tree.
     *
     * @param  array<int, array<string, mixed>>|null  $branches  Source branches to use instead of the validated tree
     * @return string[] Handles of the changed sites
     */
    public function push(CollectionTree $source, bool $save = true, ?array $branches = null): array
    {
        $collection = $source->collection();
        $keys = $this->keys($collection);
        $branches ??= $source->tree();

        return $collection->sites()
            ->reject(fn (string $site) => $site === $source->locale())
            ->filter(fn (string $site) => $this->apply($branches, $collection->structure()->in($site), $keys, $save))
            ->values()
            ->all();
    }

    /**
     * Rearrange the given tree to match the tree of another site.
     */
    public function pull(CollectionTree $target, string $site, bool $save = true): bool
    {
        $collection = $target->collection();

        if ($site === $target->locale() || ! $collection->sites()->contains($site)) {
            return false;
        }

        return $this->apply($collection->structure()->in($site)->tree(), $target, $this->keys($collection), $save);
    }

    /**
     * Rearrange all trees of a collection to match the tree of one site, the default site by default.
     *
     * @return string[] Handles of the changed sites
     */
    public function sync(Collection $collection, ?string $site = null, bool $save = true): array
    {
        $sites = $collection->sites();
        $site ??= $sites->contains(Site::default()->handle()) ? Site::default()->handle() : $sites->first();

        if (! $sites->contains($site)) {
            throw new \InvalidArgumentException("Collection [{$collection->handle()}] is not available in site [{$site}]");
        }

        return $this->push($collection->structure()->in($site), $save);
    }

    /**
     * If a tree was saved only to add new localizations, return the site of their origin.
     * Such a save appends them to their parent, which isn't worth pushing to other sites.
     */
    public function localizationSource(CollectionTree $tree): ?string
    {
        $diff = $tree->diff();

        if (! $diff->added() || $diff->moved() || $diff->removed()) {
            return null;
        }

        foreach ($diff->added() as $id) {
            $origin = Entry::find($id)?->origin();
            if ($origin && $origin->locale() !== $tree->locale()) {
                return $origin->locale();
            }
        }

        return null;
    }

    /**
     * Rearrange the target tree to match the source tree; saves it if anything changed.
     *
     * @param  array<int, array<string, mixed>>  $source
     * @param  array<string, string>  $keys
     */
    protected function apply(array $source, ?CollectionTree $target, array $keys, bool $save): bool
    {
        if (! $target) {
            return false;
        }

        $current = CollectionMatcher::normalize($target->tree());
        $matched = CollectionMatcher::match(CollectionMatcher::normalize($source), $current, $keys);

        if ($matched === $current) {
            return false;
        }

        if ($save) {
            TreeSync::whileSyncing(fn () => $target->tree($matched)->save());
        }

        return true;
    }

    /**
     * Shared keys of all entries in a collection: entry id => id of the root origin.
     *
     * @return array<string, string>
     */
    protected function keys(Collection $collection): array
    {
        $origins = $this->origins($collection);

        $keys = [];
        foreach (array_keys($origins) as $id) {
            $key = $id;
            $seen = [];
            while (isset($origins[$key]) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $key = $origins[$key];
            }
            $keys[$id] = $key;
        }

        return $keys;
    }

    /**
     * Origin ids of all entries in a collection, without loading the entries.
     *
     * @return array<string, ?string> Entry id => id of the direct origin
     */
    protected function origins(Collection $collection): array
    {
        // The Eloquent driver hydrates every entry to pluck by key; the Stache plucks from its indexes
        $pairs = Entry::query() instanceof EloquentEntryQueryBuilder
            ? app('statamic.eloquent.entries.model')::query()
                ->where('collection', $collection->handle())
                ->toBase()
                ->select(['id', 'origin_id'])
                ->lazyById(1000)
                ->mapWithKeys(fn (stdClass $row) => [$row->id => $row->origin_id])
            : Entry::query()->where('collection', $collection->handle())->pluck('origin', 'id');

        $origins = [];
        foreach ($pairs as $id => $origin) {
            $origins[(string) $id] = filled($origin) ? (string) $origin : null;
        }

        return $origins;
    }
}
