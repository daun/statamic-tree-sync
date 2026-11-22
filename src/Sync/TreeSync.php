<?php

namespace Daun\StatamicTreeSync\Sync;

use Closure;
use Daun\StatamicTreeSync\Sync\Collections\CollectionSyncer;
use Daun\StatamicTreeSync\Sync\Navs\NavSyncer;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Nav;

/**
 * Global switch for the automatic syncing of trees.
 *
 * Scoped instance, so a switch left off doesn't leak into the next Octane request or queued job.
 */
class TreeSync
{
    protected bool $enabled = true;

    protected bool $syncing = false;

    public static function enable(): void
    {
        static::state()->enabled = true;
    }

    public static function disable(): void
    {
        static::state()->enabled = false;
    }

    public static function enabled(): bool
    {
        return static::state()->enabled;
    }

    /**
     * Whether the subscriber should react to events right now.
     */
    public static function listening(): bool
    {
        $state = static::state();

        return $state->enabled && ! $state->syncing;
    }

    /**
     * Sync all configured collections and navigations from the default site.
     * Runs even while automatic syncing is disabled.
     *
     * @return array<string, string[]> Changed sites, keyed by `collection:handle` or `nav:handle`
     */
    public static function sync(): array
    {
        $collectionSyncer = app(CollectionSyncer::class);
        $navSyncer = app(NavSyncer::class);
        $changed = [];

        Collection::all()
            ->filter(fn (CollectionContract $collection) => $collectionSyncer->syncs($collection))
            ->each(function (CollectionContract $collection) use ($collectionSyncer, &$changed) {
                $changed["collection:{$collection->handle()}"] = $collectionSyncer->sync($collection);
            });

        Nav::all()
            ->filter(fn (NavContract $nav) => $navSyncer->syncs($nav))
            ->each(function (NavContract $nav) use ($navSyncer, &$changed) {
                $changed["nav:{$nav->handle()}"] = $navSyncer->sync($nav);
            });

        return $changed;
    }

    /**
     * Run a callback with automatic syncing disabled.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutSyncing(Closure $callback): mixed
    {
        $state = static::state();
        $enabled = $state->enabled;
        $state->enabled = false;

        try {
            return $callback();
        } finally {
            $state->enabled = $enabled;
        }
    }

    /**
     * Run a callback as part of a sync, so the tree saves it causes don't trigger another one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function whileSyncing(Closure $callback): mixed
    {
        $state = static::state();
        $syncing = $state->syncing;
        $state->syncing = true;

        try {
            return $callback();
        } finally {
            $state->syncing = $syncing;
        }
    }

    protected static function state(): static
    {
        return app(static::class);
    }
}
