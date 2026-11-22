# Statamic Tree Sync Documentation

Tree Sync keeps collection page order and nav structure in sync across sites. Rearrange a tree in one site and the others follow. It never creates, deletes, publishes, or translates entries.

[Configuration](#configuration) · [Collections](#collections) · [Navigations](#navigations) · [Commands](#commands) · [Pausing sync](#pausing-sync) · [Caveats](#caveats)

## Configuration

Choose what to sync in `config/statamic-tree-sync.php`. Nothing syncs by default.

```php
return [
    'collections' => ['pages'],  // specific handles
    'navigations' => ['*'],      // all eligible handles
];
```

Use `'*'` for all, a list of exact handles, or `[]` for none. Patterns like `'footer_*'` aren't supported. Commands respect the config too, so unconfigured handles are skipped.

If the config file is missing, publish it with `php artisan vendor:publish --tag=statamic-tree-sync-config`.

## Collections

Applies to ordered or nested collections in more than one site. Saving the tree in any site updates page order and parents in the others.

- **Translations count as the same page**, even translations of translations.
- **New translations take their original's position** instead of landing at the end.
- **Pages that exist in only one site stay put** under their parent, after the nearest sibling that still exists.
- **Pages missing from a site are skipped there**, and their children move up into their place.

## Navigations

Applies to navs with a tree in more than one site. Unlike collection syncing, nav syncing also adds and removes items.

- **Items are matched by ID**, not by linked entry, so a page can appear in a nav more than once.
- **New items are copied, deleted items are removed.** Removing a parent promotes its children.
- **Entry links point to each site's translation**, or to the original until a translation exists.
- **Adding a site to a nav** fills its empty tree from the default site.

### Localized fields

Title, URL, and fields marked `localizable` can differ by site:

- New items copy their values to every site.
- Edits in the default site update other sites only where the value hasn't been translated yet.
- Edits from other sites and command runs leave localized values alone.

Non-localized fields always follow the saved site.

### Importing existing navs

Navs that were built separately in each site before installing the addon need preparing first. Nav items are matched by ID, and items created by hand in each site have different IDs. The first sync would then give every site the other sites' items on top of its own, so each item shows up twice.

To give a site the same IDs as the default site, remove the site from the nav and add it back. Its tree is then copied from the default site. Afterwards, translate its titles and other localized fields again, and re-add any items that only existed in that site.

## Commands

Installing the addon doesn't touch existing trees. Syncing starts with the next save. To align existing trees first:

```sh
php please trees:sync --dry-run   # list trees that would change
php please trees:sync             # sync everything from the default site
```

To sync one type, specific handles, or from another site, use the scoped commands:

```sh
php please trees:sync:collections pages --from=en
php please trees:sync:navigations main footer --dry-run
```

`--from` is only available on the scoped commands. Structures that don't include the source site are skipped.

## Pausing sync

During batch operations like imports, pause syncing and sync once afterward. The setting resets for each request and queued job.

```php
use Daun\StatamicTreeSync\TreeSync;

TreeSync::withoutSyncing(fn () => $this->import());
TreeSync::sync();

// or toggle manually
TreeSync::disable();
TreeSync::enable();
```

## Caveats

- **The last save wins.** If two people save different sites at the same time, the later save wins, even if it was based on an older layout. For navs, this can remove a newly added item from every site.
- **Unique URLs and max depth are only enforced in the tree you save**, not in the trees updated by syncing.
- **Sync errors are logged, not thrown.** Your original save still succeeds.

## Development

```sh
composer test      # run tests
composer lint      # check code style
composer format    # fix code style
composer analyse   # run static analysis
```
