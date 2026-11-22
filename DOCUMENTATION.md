# Statamic Tree Sync Documentation

Tree Sync keeps collection page order and nav structure in sync across Statamic sites, so you can rearrange a tree once instead of repeating the work in each site. It moves existing pages and their translations into matching positions, and copies or removes shared nav items. It does not create, delete, publish, or translate entries.

You choose which collections and navigations to sync. Both are optional, and nothing syncs by default.

Works with Statamic's flat files and the Eloquent driver.

[Quick start](README.md#quick-start) · [Configuration](#configuration) · [Collections](#collections) · [Navigations](#navigations) · [Commands](#sync-existing-layouts)

## Configuration

### Choose what to sync

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

Set each array independently:

- **All eligible handles:** uncomment `'*'`.
- **Specific handles:** enter a list, such as `['pages']` or `['main', 'footer']`.
- **None:** leave the array empty.

For example, to sync the `pages` collection while keeping all navs independent:

```php
'collections' => ['pages'],
'navigations' => [],
```

Use exact handles. Only the standalone `'*'` enables all handles; patterns such as `'footer_*'` are not supported.

These settings apply to automatic syncing and the [commands](#sync-existing-layouts). Passing a handle to a command does not override the configuration: unconfigured handles are skipped.

### Publish the config manually

If the config file is missing, run:

```sh
php artisan vendor:publish --tag=statamic-tree-sync-config
```

## Collections

Enable collection syncing for **ordered or nested collections available in more than one site**. When you save a selected collection's tree in any site, the other sites follow its page order and parent relationships.

Only the layout changes. The addon does not create, delete, or publish entries.

### Reordering pages and adding translations

Reordering pages, changing a parent, or deleting a page from a collection tree updates the order in other sites.

A page and its translations count as the same page when comparing layouts. The addon follows their origin chain back to the shared original, even when a translation was made from another translation.

When you add a translation, it takes the corresponding position of its original page rather than staying at the end of the section. This also works when you create the translation without adding it to the tree yourself.

### Pages that exist in only one site

Sites do not need to contain exactly the same pages:

- A page found only in another site stays under its current parent, just after the closest earlier sibling that still exists. If none remains, it becomes the first child.
- A page found only in the site you saved is skipped in the other sites. Its children move up into its place if they exist there.

### Collection caveats

**Page addresses:** Statamic checks for unique addresses only in the tree you save directly, not in trees updated by syncing.

**Maximum depth:** A site-only page moves with its parent. This can put it deeper than the collection's maximum depth.

**Eloquent translations:** Through version 5.13, the Eloquent driver does not send an `EntryCreated` event for translations it creates automatically. In orderable collections, those translations may end up at the end instead of beside the original page. This matters only if the trees were already out of sync.

## Navigations

Enable navigation syncing for **navs with a tree in more than one site**. Saving a selected nav in any site updates its structure in the other sites.

Nav items belong to the nav itself, not to a collection. Unlike collection syncing, navigation syncing can add and remove items in other sites.

### Adding, moving, and removing items

- New nav items are copied to the other sites.
- Shared items take matching positions. Deleting one and saving removes it from the other sites too.
- Items found only in another site's nav stay under their current parent, after the closest earlier sibling that remains, or first if none remains.
- When you remove a shared parent item, its remaining children take its place.

The addon matches nav items by their **shared item ID**, not by the entry they link to. You can link to the same page more than once.

### Adding a site to a nav

When you add a site and Statamic creates an empty nav tree, the addon fills it from the default site's tree. If needed, it uses another populated site's tree instead. Copying the tree preserves shared item IDs.

### Navs built separately in each site

**Review existing navs before enabling syncing.** If you built each site's nav separately, their items have different IDs. Syncing can duplicate items instead of recognizing them as the same nav item.

To start with shared IDs, remove the site from the navigation and add it again. Review the resulting nav before saving changes.

### Links to translated entries

When a nav item links to an entry, its copy in each site links to that site's translation.

If no translation exists yet, the item keeps linking to the original entry. A later sync can update the link once a translation exists.

### Titles, URLs, and localized fields

Fields marked `localizable` in the navigation blueprint can differ by site. Title and URL count as localized fields too.

For **new items**, the addon copies these values to the other sites.

For **existing items**, edits in the default site update another site's localized value only if it still matches the value from before the edit. Once you translate a value, the addon leaves it alone.

Empty values do not count as matching. For example, an empty title can mean “use this site's entry title.”

### Other fields

Non-localized fields follow the saved site.

Changes from a non-default site, command runs, and newly created trees copy only non-localized fields to existing items. They leave those items' localized values alone.

## Sync existing layouts

Installing the addon does not rewrite existing trees. After you opt in, automatic syncing begins with the next saved tree. Use the commands if you want to align existing layouts before editing.

### Sync everything

Check which trees would change:

```sh
php please trees:sync --dry-run
```

Then sync all configured, eligible collections and navigations:

```sh
php please trees:sync
```

This catch-all command always takes the layout from the default site and accepts only `--dry-run`. It takes no handles and no `--from` option; use the scoped commands below for that.

The command reports which site trees changed or were already in sync. A dry run saves nothing and lists affected trees, not individual item changes.

### Sync collections or navigations only

Two scoped commands handle one type each:

```sh
php please trees:sync:collections
php please trees:sync:navigations
```

Without arguments, each one syncs all configured, eligible handles of its type. Pass handles to narrow the run further:

```sh
php please trees:sync:collections pages
php please trees:sync:navigations main footer
```

Because each command covers a single type, a handle used by both a collection and a nav is never ambiguous.

### Choose a source site

By default, every command takes the layout from the default site. If that site is unavailable for a collection or navigation, it uses that structure's first site.

The scoped commands accept `--from` to choose a source site explicitly:

```sh
php please trees:sync:collections --from=en
```

They skip collections or navigations that do not include the chosen source site. You can combine handles, `--from`, and `--dry-run`:

```sh
php please trees:sync:navigations main --from=en --dry-run
```

## Automatic syncing and concurrent edits

You can save a configured tree in any site; there is no fixed source site for automatic syncing. **The last saved layout takes precedence.**

If two people edit different sites' trees at nearly the same time, the later save wins, even if it was based on an older layout. For navs, this can remove a recently added shared item from every site.

The addon saves changed trees through Statamic, which updates page addresses, entry order, and caches as usual. If automatic syncing fails, the addon logs the error without failing your original save.

## Pause automatic syncing

During an import, pause automatic syncing and run `php please trees:sync` once afterward:

```php
use Daun\StatamicTreeSync\TreeSync;

TreeSync::withoutSyncing(fn () => $this->import());
```

`withoutSyncing` restores the previous setting after the callback, even if it throws.

You can also toggle automatic syncing explicitly:

```php
TreeSync::disable();
TreeSync::enable();
```

The setting resets for each request or queued job, including on long-running workers.

## Development

The tests cover flat files and the Eloquent driver, both with a full database setup and with only entries and trees in the database.

```sh
composer install
composer test      # run tests
composer lint      # check code style
composer format    # fix code style
composer analyse   # run static analysis
```
