# Statamic Tree Sync

Keep entry order and navigation items in sync across Statamic sites.

## How it works

**Collections:** Move a page in one site's ordered or nested collection, and its translations move to the same place in other sites. The addon does not create, delete, or publish pages. Pages without a translation stay in their own site.

**Navigations:** Change a nav in one site, and the other sites follow its layout. New nav items are copied and deleted items are removed. Links can point to each site's translation, while translated titles and other local content stay different.

Works with Statamic's flat files and the Eloquent driver.

## When to use it

Use it when your sites should share page trees, nav items, or both. Collections and navigations are independently opt-in: choose the handles to sync and leave the rest independent. Nothing syncs by default.

## Quick start

### Install the addon

```sh
composer require daun/statamic-tree-sync
```

### Choose what to sync

Open `config/statamic-tree-sync.php`, which Statamic publishes after installation. Under `collections` and `navigations`, uncomment `'*'` to sync all eligible handles, or enter the specific handles you want to sync. An empty array disables syncing. Once enabled, saving a tree in any site updates the other sites.

```php
'collections' => ['pages'],
'navigations' => ['*'],
```

### Sync existing trees

If your existing sites already have different trees, choose which one should lead before you start editing. To sync the configured collections and navigations from your default site, check which trees would change and then run. Optionally define which site to sync from initially.

```sh
php please trees:sync --dry-run
php please trees:sync
php please trees:sync --from=en
```

## Documentation

Read the detailed [documentation](DOCUMENTATION) for how pages and navigations are matched, command options, import controls, and caveats.

## License

[MIT](LICENSE)
