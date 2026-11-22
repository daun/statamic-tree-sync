<?php

namespace Daun\StatamicTreeSync;

use Closure;

/**
 * Global switch for the automatic syncing of trees.
 *
 * The state is a scoped container instance: it resets with every request and queued job,
 * so a switch left off in a long-running Octane or queue worker doesn't leak into the next one.
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
     * Whether the subscriber should react to events right now:
     * enabled, and not reacting to the tree saves of an ongoing sync.
     */
    public static function listening(): bool
    {
        $state = static::state();

        return $state->enabled && ! $state->syncing;
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
     * Run a callback while marking a sync as in progress, so the
     * tree saves it causes don't trigger another round of syncing.
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
