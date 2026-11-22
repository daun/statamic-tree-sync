# Statamic Tree Sync Documentation

[Back to the quick start](README.md#quick-start)

## Two kinds of syncing

The addon syncs **collection page layouts** and **navs**. These have different rules:

| | Collections | Navigations |
| --- | --- | --- |
| Which ones? | Opted-in ordered or nested collections available in more than one site | Opted-in navigations with a tree in more than one site |
| What moves? | Existing pages and their translations take matching positions | Nav items take matching positions |
| What gets added or removed in other sites? | No pages | New or deleted shared nav items |
| What stays local? | Pages that exist only in one site | Translated titles and other local fields; items that exist only in another site's nav |

Both kinds are optional and disabled by default. After you opt in, saving a selected tree in any site syncs its layout to the other sites. The last saved layout takes precedence.

The addon saves changed trees through Statamic, so Statamic updates page addresses, entry order, and caches as usual. If syncing fails, the addon logs the error without failing the save you made.

## Configuration

Statamic publishes `config/statamic-tree-sync.php` after installation:

```php
return [
    'collections' => [
        // '*',
    ],
    'navigations' => [
        // '*',
    ],
];
```

For each array, uncomment `'*'` to sync all eligible collections or navigations, or enter specific handles, such as `['pages']` or `['main', 'footer']`. Leave either array empty to disable that kind of syncing. You can enable collections without navigations, navigations without collections, or both.

These settings apply to automatic syncing and the [command](#sync-existing-layouts). Passing a handle to the command does not override the configuration. Use exact handles; only the standalone `'*'` enables all handles.

If you need to publish the file manually, run:

```sh
php artisan vendor:publish --tag=statamic-tree-sync-config
```

Publishing preserves an existing config file.

## Collections: page order and parents

A page and its translations count as the same page when the addon compares layouts. It follows the chain back to their shared original page, even if a translation was made from another translation.

- Reordering pages, changing a parent, or deleting a page from a collection tree updates the order in other sites.
- Adding a translation places it beside its original page instead of leaving it at the end of the section. This also works when you create a translation without adding it to the tree yourself.
- Pages that exist only in another site stay under their current parent, just after the closest earlier sibling that still exists. If none remains, they become the first child.
- If a page exists only in the site you saved, the other sites skip it. Its children move up into its place if they exist there.

Collection syncing changes the layout of pages that already exist. It does not create, delete, or publish entries.

## Navigations: nav items and fields

A navigation is different from a collection: its items are part of the nav itself, not pages in a collection. When you add an item to a nav, the addon copies it to the other sites; when you delete a shared item and save, it removes that item there too. It matches nav items by their shared item ID, so you can link to the same page more than once. An item that belongs only to another site's nav stays in place, with the same positioning rule used for site-only collection pages. If you remove a shared parent item, its remaining children take its place.

When you add a site to a navigation and Statamic creates an empty tree, the addon fills it from the default site's tree, or another populated site's tree if needed. This keeps the shared item IDs. **If you built each site's navigation separately**, the items have different IDs. Syncing can then duplicate items instead of recognizing them as the same nav item. To start with shared IDs, remove the site from the navigation and add it again; review its nav before saving changes.

### Links and fields

If a navigation item links to an entry, the copy in each site links to that site's translation. Until a translation exists, it keeps linking to the original entry; a later sync can update the link.

Fields marked `localizable` in the navigation blueprint can have different values per site. Title and URL are treated as localized too. When the addon creates a new item, it copies those values. After that, edits in the default site update another site's localized value only if it still matches the value from before the edit. Once you translate it, the addon leaves it alone. Empty values do not count as matching: an empty title can mean “use this site's entry title.”

Other navigation fields follow the saved site. Changes made from a non-default site, the command, and newly created trees copy only those non-localized fields to existing items.

## Sync existing layouts

Installing the addon does not rewrite existing trees immediately. Automatic syncing begins with the next saved tree after you opt in. To make the existing layouts match now, use the command. It takes its layout from the default site unless you choose another site. If the default site is unavailable for a particular collection or navigation, it uses that structure's first site.

```sh
php please tree-sync --dry-run           # see which trees would change; save nothing
php please tree-sync                     # sync all configured, eligible collections and navigations
php please tree-sync pages main          # sync only these collection or navigation handles
php please tree-sync --from=en           # use site en as the source
```

You can combine the handle and `--from` options with `--dry-run`. The command skips collections or navigations that do not include the chosen source site. It reports which sites changed or were already in sync. A dry run lists trees that would change, without saving them.

## Pause automatic syncing

During an import, you can pause automatic syncing and run the command once afterward:

```php
use Daun\StatamicTreeSync\TreeSync;

TreeSync::withoutSyncing(fn () => $this->import());

TreeSync::disable();
TreeSync::enable();
```

`withoutSyncing` restores the previous setting after the callback, including if it throws. The setting resets for each request or queued job, including on long-running workers.

## Things to watch for

- Two people can save different sites' trees at almost the same time. The later save wins, even if it was based on an older layout. For navigations, this can remove a recently added shared item from every site.
- Statamic checks for unique page addresses only in the tree you save directly, not in the trees updated by syncing.
- A page that exists in only one site moves with its parent. This can put it deeper than the collection's maximum depth.
- The Eloquent driver, through version 5.13, does not send an `EntryCreated` event for translations it creates automatically. In orderable collections, those translations may be added at the end instead of beside the original page. This matters only if the trees were already out of sync.

## Development

The tests cover flat files and the Eloquent driver, both with a full database setup and with only entries and trees in the database.

```sh
composer install
composer test      # run tests
composer lint      # check code style
composer format    # fix code style
composer analyse   # run static analysis
```
