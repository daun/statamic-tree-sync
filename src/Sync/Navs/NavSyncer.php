<?php

namespace Daun\StatamicTreeSync\Sync\Navs;

use Daun\StatamicTreeSync\Sync\TreeSync;
use Facades\Statamic\Structures\BranchIds;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Structures\Nav;
use Statamic\Contracts\Structures\NavTree;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Field;

class NavSyncer
{
    /** @var array<string, ?EntryContract> */
    protected array $entries = [];

    /**
     * Opted-in navigations with a tree in more than one site.
     */
    public function syncs(?Nav $nav): bool
    {
        $handles = config('statamic-tree-sync.navigations', []);

        return $nav
            && (in_array('*', $handles, true) || in_array($nav->handle(), $handles, true))
            && $nav->trees()->count() > 1;
    }

    /**
     * Rearrange the trees of all other sites to match the given tree.
     *
     * @param  array<int, array<string, mixed>>  $original  The tree before saving, to detect removed branches and edited fields
     * @return string[] Handles of the changed sites
     */
    public function push(NavTree $source, array $original = [], bool $save = true): array
    {
        $source = $this->ensureBranchIds($source, $save);

        return $source->structure()->trees()
            ->reject(fn (NavTree $tree, string $site) => $site === $source->locale())
            ->filter(fn (NavTree $tree) => $this->apply($source, $tree, $original, $save))
            ->keys()
            ->all();
    }

    /**
     * Rearrange the given tree to match the tree of another site.
     */
    public function pull(NavTree $target, string $site, bool $save = true): bool
    {
        $source = $site !== $target->locale() ? $target->structure()->in($site) : null;

        if (! $source) {
            return false;
        }

        $source = $this->ensureBranchIds($source, $save);

        return $this->apply($source, $target, [], $save);
    }

    /**
     * Rearrange all trees of a nav to match the tree of one site, the default site by default.
     *
     * @return string[] Handles of the changed sites
     */
    public function sync(Nav $nav, ?string $site = null, bool $save = true): array
    {
        $sites = $nav->sites();
        $site ??= $sites->contains(Site::default()->handle()) ? Site::default()->handle() : $sites->first();

        if (! $sites->contains($site)) {
            throw new \InvalidArgumentException("Navigation [{$nav->handle()}] has no tree in site [{$site}]");
        }

        return $this->push($nav->in($site), save: $save);
    }

    /**
     * The site to copy a newly created, empty tree from, e.g. after adding a site to the nav.
     */
    public function seedSource(NavTree $tree): ?string
    {
        if ($tree->tree() || $tree->getOriginal('tree')) {
            return null;
        }

        return $tree->structure()->trees()
            ->reject(fn (NavTree $other, string $site) => $site === $tree->locale() || ! $other->tree())
            ->sortBy(fn (NavTree $other, string $site) => $site === Site::default()->handle() ? 0 : 1)
            ->keys()
            ->first();
    }

    /**
     * Rearrange the target tree to match the source tree; saves it if anything changed.
     *
     * @param  array<int, array<string, mixed>>  $original
     */
    protected function apply(NavTree $source, NavTree $target, array $original, bool $save): bool
    {
        $this->entries = [];
        $site = $target->locale();
        $dataFields = $this->syncedFields($source->structure());
        $before = NavMatcher::flatten($original);
        // Only the default site passes on localized fields, or translating one would push it back
        $origins = $source->locale() === Site::default()->handle() ? $before : [];

        $current = NavMatcher::normalize($target->tree());
        $matched = NavMatcher::match(
            NavMatcher::normalize($source->tree()),
            $current,
            array_diff(array_keys($before), NavMatcher::ids($source->tree())),
            fn (array $from, ?array $to) => $to === null
                ? $this->create($from, $site)
                : $this->update($from, $to, $origins[$from['id']] ?? [], $site, $dataFields)
        );

        if ($matched === $current) {
            return false;
        }

        if ($save) {
            TreeSync::whileSyncing(fn () => $target->tree($matched)->save());
        }

        return true;
    }

    /**
     * A branch that is new to the target site: a copy, linking to the target site's entry.
     *
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    protected function create(array $branch, string $site): array
    {
        if (isset($branch['entry'])) {
            $branch['entry'] = $this->localize((string) $branch['entry'], $site);
        }

        return $branch;
    }

    /**
     * An existing branch of the target site: takes the synced fields, and the localized fields
     * the target hadn't changed from the source.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array<string, mixed>  $before  The source branch before saving
     * @param  string[]  $dataFields
     * @return array<string, mixed>
     */
    protected function update(array $from, array $to, array $before, string $site, array $dataFields): array
    {
        if (! isset($from['entry'])) {
            unset($to['entry']);
        } elseif (! isset($to['entry']) || (string) $to['entry'] !== $this->localize((string) $from['entry'], $site)) {
            $to['entry'] = $this->localize((string) $from['entry'], $site);
        }

        $to = $this->copy($from, $to, $this->unchanged($before, $to, ['title', 'url']));

        $fromData = $this->data($from);
        $beforeData = $this->data($before);
        $data = $this->copy($fromData, $this->data($to), $dataFields);
        $data = $this->copy($fromData, $data, $this->unchanged($beforeData, $data, array_diff(array_keys($beforeData), $dataFields)));

        if ($data !== $this->data($to)) {
            if ($data) {
                $to['data'] = $data;
            } else {
                unset($to['data']);
            }
        }

        return $to;
    }

    /**
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    protected function data(array $branch): array
    {
        return is_array($branch['data'] ?? null) ? $branch['data'] : [];
    }

    /**
     * Keys whose value in the target is still the source value from before the save.
     * Empty values don't count: an empty title means the entry title, which differs between sites.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $to
     * @param  string[]  $keys
     * @return string[]
     */
    protected function unchanged(array $before, array $to, array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key) => filled($before[$key] ?? null) && ($to[$key] ?? null) === $before[$key]
        ));
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  string[]  $keys
     * @return array<string, mixed>
     */
    protected function copy(array $from, array $to, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $from)) {
                $to[$key] = $from[$key];
            } else {
                unset($to[$key]);
            }
        }

        return $to;
    }

    /**
     * Keys of the branch data that are synced like the non-localizable fields of entries.
     * Title and url stay localized: the CP adds them to the blueprint as non-localizable fields.
     *
     * @return string[]
     */
    protected function syncedFields(Nav $nav): array
    {
        return $nav->blueprint()->fields()->all()
            ->reject(fn (Field $field) => $field->isLocalizable() || in_array($field->handle(), ['title', 'url']))
            ->keys()
            ->all();
    }

    /**
     * The target site's localization of an entry; the entry itself if there is none.
     */
    protected function localize(string $id, string $site): string
    {
        return $this->entry($id)?->in($site)?->id() ?? $id;
    }

    protected function entry(string $id): ?EntryContract
    {
        return array_key_exists($id, $this->entries) ? $this->entries[$id] : $this->entries[$id] = Entry::find($id);
    }

    /**
     * Branches are matched by id; make sure the source tree has ids on all branches.
     */
    protected function ensureBranchIds(NavTree $tree, bool $save): NavTree
    {
        if (! NavMatcher::missesIds($tree->tree())) {
            return $tree;
        }

        if (! $save) {
            // Don't mutate the repository's cached tree on dry runs
            return (clone $tree)->tree(BranchIds::ensure($tree->tree()));
        }

        TreeSync::whileSyncing(function () use ($tree): void {
            $tree->ensureBranchIds();
        });

        return $tree;
    }
}
