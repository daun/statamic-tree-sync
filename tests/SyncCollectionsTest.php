<?php

namespace Daun\StatamicTreeSync\Tests;

use Daun\StatamicTreeSync\Commands\SyncTreesCommand;
use Daun\StatamicTreeSync\Sync\Collections\CollectionSyncer;
use Daun\StatamicTreeSync\Sync\TreeSync;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Stache;

class SyncCollectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic-tree-sync.collections' => ['*']]);

        Collection::make('pages')->sites(['de', 'en', 'fr'])->structureContents(['root' => true])->routes('{parent_uri}/{slug}')->save();

        // de: home, a, b(c, d) — en: same, but without c — fr: same as de
        foreach (['home', 'a', 'b', 'c', 'd'] as $id) {
            $this->page($id);
            $this->page("fr-{$id}", 'fr', $id);
            if ($id !== 'c') {
                $this->page("en-{$id}", 'en', $id);
            }
        }
        $this->setTree('de', 'home, a, b(c, d)');
        $this->setTree('en', 'en-home, en-a, en-b(en-d)');
        $this->setTree('fr', 'fr-home, fr-a, fr-b(fr-c, fr-d)');
    }

    #[DataProvider('syncConfiguration')]
    public function test_only_configured_handles_sync(array $handles, bool $enabled): void
    {
        config(['statamic-tree-sync.collections' => $handles]);

        $this->saveTree('de', 'home, b(c, d, a)');

        if ($enabled) {
            $this->assertTree('en', 'en-home, en-b(en-d, en-a)');
        } else {
            $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
            $this->artisan('statamic:trees:sync:collections')->assertSuccessful();
            $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
            $this->artisan('statamic:trees:sync:collections', ['handles' => ['pages']])->assertFailed();
            $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
        }
    }

    public static function syncConfiguration(): array
    {
        return [
            'disabled' => [[], false],
            'all' => [['*'], true],
            'selected' => [['pages'], true],
            'another handle' => [['other'], false],
            'not a glob' => [['p*'], false],
        ];
    }

    #[DataProvider('scopedCommands')]
    public function test_commands_distinguish_overlapping_handles(string $command, array $options, bool $collections, bool $navigations): void
    {
        config(['statamic-tree-sync.navigations' => ['pages']]);
        Nav::make('pages')->title('Pages')->save();
        foreach (['de', 'en'] as $site) {
            Nav::findByHandle('pages')->makeTree($site)->saveQuietly();
        }
        $source = [['id' => 'a'], ['id' => 'b']];
        $target = array_reverse($source);
        Nav::findByHandle('pages')->in('de')->tree($source)->saveQuietly();
        Nav::findByHandle('pages')->in('en')->tree($target)->saveQuietly();
        $this->setTree('de', 'home, b(c, d, a)');

        $this->artisan($command, $options)->assertSuccessful();

        $this->assertTree('en', $collections ? 'en-home, en-b(en-d, en-a)' : 'en-home, en-a, en-b(en-d)');
        $this->assertSame($navigations ? $source : $target, Nav::findByHandle('pages')->in('en')->tree());
    }

    public static function scopedCommands(): array
    {
        return [
            'all' => ['statamic:trees:sync', [], true, true],
            'all preview' => ['statamic:trees:sync', ['--dry-run' => true], false, false],
            'collections' => ['statamic:trees:sync:collections', [], true, false],
            'collection handle' => ['statamic:trees:sync:collections', ['handles' => ['pages']], true, false],
            'navigations' => ['statamic:trees:sync:navigations', [], false, true],
            'nav handle' => ['statamic:trees:sync:navigations', ['handles' => ['pages']], false, true],
        ];
    }

    public function test_the_catch_all_accepts_only_dry_run(): void
    {
        $command = new SyncTreesCommand;

        $this->assertSame([], $command->getDefinition()->getArguments());
        $this->assertSame(['dry-run'], array_keys($command->getDefinition()->getOptions()));
    }

    public function test_the_catch_all_respects_configuration(): void
    {
        config(['statamic-tree-sync.collections' => []]);
        $this->setTree('de', 'home, b(c, d, a)');

        $this->artisan('statamic:trees:sync')->assertSuccessful();

        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
    }

    public function test_unknown_collection_handles_fail_before_syncing(): void
    {
        $this->setTree('de', 'home, b(c, d, a)');

        $this->artisan('statamic:trees:sync:collections', ['handles' => ['pages', 'missing']])
            ->expectsOutputToContain('Collection [missing] not found')
            ->assertFailed();

        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
    }

    public function test_saving_a_tree_rearranges_the_trees_of_the_other_sites(): void
    {
        $this->saveTree('de', 'home, b(c, d, a)');

        $this->assertTree('en', 'en-home, en-b(en-d, en-a)');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-d, fr-a)');
        $this->assertSame('/en-b/en-a', Entry::find('en-a')->uri());
    }

    public function test_syncing_works_from_any_site(): void
    {
        $this->saveTree('en', 'en-home, en-b(en-d), en-a');

        $this->assertTree('de', 'home, b(c, d), a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-d), fr-a');
    }

    public function test_entries_missing_in_the_saved_site_keep_their_place(): void
    {
        $this->saveTree('en', 'en-home, en-b(en-a, en-d)');

        $this->assertTree('de', 'home, b(c, a, d)');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-a, fr-d)');
    }

    public function test_entries_only_in_one_site_stay_after_their_preceding_sibling(): void
    {
        $this->page('x', 'en');
        $this->setTree('en', 'en-home, en-a, x, en-b(en-d)');

        $this->saveTree('de', 'home, b(c, d), a');

        $this->assertTree('en', 'en-home, en-b(en-d), en-a, x');
    }

    public function test_a_new_localization_takes_the_position_of_its_origin(): void
    {
        Entry::find('c')->makeLocalization('en')->id('en-c')->save();

        $this->assertTree('en', 'en-home, en-a, en-b(en-c, en-d)');
        $this->assertTree('de', 'home, a, b(c, d)');
        $this->assertTree('fr', 'fr-home, fr-a, fr-b(fr-c, fr-d)');
    }

    public function test_a_localization_created_outside_of_the_tree_takes_the_position_of_its_origin(): void
    {
        $this->page('en-c', 'en', 'c', quietly: false);

        $this->assertTree('en', 'en-home, en-a, en-b(en-c, en-d)');
        $this->assertTree('de', 'home, a, b(c, d)');
    }

    #[DataProvider('originChainSources')]
    public function test_entries_are_matched_through_multi_level_origin_chains(string $site, string $shape): void
    {
        Entry::find('fr-a')->origin('en-a')->saveQuietly();

        $this->saveTree($site, $shape);

        $this->assertTree('de', 'home, b(c, d, a)');
        $this->assertTree('en', 'en-home, en-b(en-d, en-a)');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-d, fr-a)');
    }

    public static function originChainSources(): array
    {
        return [
            'root origin' => ['de', 'home, b(c, d, a)'],
            'end of chain' => ['fr', 'fr-home, fr-b(fr-c, fr-d, fr-a)'],
        ];
    }

    public function test_a_new_localization_of_a_localization_takes_the_position_of_its_origin(): void
    {
        $this->page('e');
        $this->page('en-e', 'en', 'e');
        $this->setTree('de', 'home, e, a, b(c, d)');
        $this->setTree('en', 'en-home, en-e, en-a, en-b(en-d)');

        Entry::find('en-e')->makeLocalization('fr')->id('fr-e')->save();

        $this->assertTree('fr', 'fr-home, fr-e, fr-a, fr-b(fr-c, fr-d)');
    }

    public function test_deleting_a_localization_removes_it_from_its_own_tree_only(): void
    {
        $this->deleteEntry('en-a');

        $this->assertTree('en', 'en-home, en-b(en-d)');
        $this->assertTree('de', 'home, a, b(c, d)');
        $this->assertTree('fr', 'fr-home, fr-a, fr-b(fr-c, fr-d)');
        $this->assertNotNull(Entry::find('a'));
        $this->assertNotNull(Entry::find('fr-a'));
    }

    public function test_deleting_a_localization_with_children_moves_them_up_in_all_trees(): void
    {
        $this->deleteEntry('en-b');

        $this->assertTree('en', 'en-home, en-a, en-d');
        $this->assertTree('de', 'home, a, b(c), d');
        $this->assertTree('fr', 'fr-home, fr-a, fr-b(fr-c), fr-d');
    }

    public function test_deleting_an_entry_in_every_site_removes_it_from_every_tree(): void
    {
        $this->deleteEntry('fr-c');
        $this->deleteEntry('c');

        $this->assertTree('de', 'home, a, b(d)');
        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
        $this->assertTree('fr', 'fr-home, fr-a, fr-b(fr-d)');
    }

    public function test_syncing_twice_does_not_save_unchanged_trees(): void
    {
        $this->setTree('de', 'home, b(d, c), a');
        $collectionSyncer = app(CollectionSyncer::class);
        $saved = [];
        Event::listen(CollectionTreeSaved::class, function ($event) use (&$saved) {
            $saved[] = $event->tree->locale();
        });

        $this->assertSame(['en', 'fr'], $collectionSyncer->sync(Collection::findByHandle('pages')));
        $this->assertSame(['en', 'fr'], $saved);
        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');
        $saved = [];

        $this->assertSame([], $collectionSyncer->sync(Collection::findByHandle('pages')));
        $this->assertSame([], $saved);
        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');
    }

    public function test_running_the_command_twice_leaves_the_trees_alone(): void
    {
        $this->setTree('de', 'home, b(d, c), a');

        $this->artisan('statamic:trees:sync:collections')->expectsOutputToContain('changed: en, fr')->assertSuccessful();

        $saved = [];
        Event::listen(CollectionTreeSaved::class, function ($event) use (&$saved) {
            $saved[] = $event->tree->locale();
        });

        $this->artisan('statamic:trees:sync:collections')->expectsOutputToContain('in sync')->assertSuccessful();

        $this->assertSame([], $saved);
        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');
    }

    public function test_saving_an_already_synced_tree_again_syncs_nothing(): void
    {
        $this->saveTree('de', 'home, b(c, d, a)');

        $saved = [];
        Event::listen(CollectionTreeSaved::class, function ($event) use (&$saved) {
            $saved[] = $event->tree->locale();
        });

        $this->saveTree('de', 'home, b(c, d, a)');

        $this->assertSame(['de'], $saved);
        $this->assertTree('en', 'en-home, en-b(en-d, en-a)');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-d, fr-a)');
    }

    public function test_syncing_works_with_a_cold_stache(): void
    {
        Stache::clear();

        $this->saveTree('de', 'home, b(c, d, a)');

        $this->assertTree('en', 'en-home, en-b(en-d, en-a)');
    }

    public function test_the_command_works_with_a_cold_stache(): void
    {
        $this->setTree('de', 'home, b(d, c), a');
        Stache::clear();

        $this->artisan('statamic:trees:sync:collections')->assertSuccessful();

        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
    }

    public function test_syncing_can_be_disabled(): void
    {
        TreeSync::withoutSyncing(fn () => $this->saveTree('de', 'home, b(c, d), a'));

        $this->assertTrue(TreeSync::enabled());
        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');

        TreeSync::disable();
        $this->saveTree('de', 'home, b(c, d, a)');
        TreeSync::enable();

        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
    }

    public function test_syncing_can_be_triggered_manually(): void
    {
        TreeSync::disable();
        $this->saveTree('de', 'home, b(c, d), a');
        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');

        $this->assertSame(['collection:pages' => ['en', 'fr']], TreeSync::sync());

        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-c, fr-d), fr-a');
        $this->assertFalse(TreeSync::enabled());
    }

    public function test_the_switch_resets_with_every_request_and_job(): void
    {
        TreeSync::disable();

        $this->app->forgetScopedInstances();

        $this->assertTrue(TreeSync::enabled());
    }

    public function test_the_command_fails_for_an_unknown_site(): void
    {
        $this->setTree('de', 'home, b(d, c), a');

        $this->artisan('statamic:trees:sync:collections', ['--from' => 'es'])
            ->expectsOutputToContain('Site [es] not found')
            ->assertFailed();

        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');
    }

    public function test_the_command_skips_collections_not_in_the_source_site(): void
    {
        Collection::make('news')->sites(['de', 'en'])->structureContents(['max_depth' => 1])->save();
        $this->setTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');

        $this->artisan('statamic:trees:sync:collections', ['--from' => 'fr'])
            ->expectsOutputToContain('skipped: not in site fr')
            ->assertSuccessful();

        $this->assertTree('de', 'home, b(d, c), a');
    }

    public function test_the_command_syncs_all_trees_from_the_default_site(): void
    {
        $this->setTree('de', 'home, b(d, c), a');

        $this->artisan('statamic:trees:sync:collections', ['--dry-run' => true])->assertSuccessful();
        $this->assertTree('en', 'en-home, en-a, en-b(en-d)');

        $this->artisan('statamic:trees:sync:collections')->assertSuccessful();
        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
        $this->assertTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');
    }

    public function test_the_command_syncs_from_another_site(): void
    {
        $this->setTree('fr', 'fr-home, fr-b(fr-d, fr-c), fr-a');

        $this->artisan('statamic:trees:sync:collections', ['handles' => ['pages'], '--from' => 'fr'])->assertSuccessful();

        $this->assertTree('de', 'home, b(d, c), a');
        $this->assertTree('en', 'en-home, en-b(en-d), en-a');
    }

    public function test_propagated_entries_of_orderable_collections_follow_their_origin(): void
    {
        Collection::make('locations')->sites(['de', 'en'])->structureContents(['max_depth' => 1])->propagate(true)->save();
        foreach (['l1', 'l2', 'l3'] as $id) {
            Entry::make()->collection('locations')->locale('de')->id($id)->slug($id)->save();
        }
        $this->skipIfPropagationIsBroken();

        $this->saveTree('de', 'l3, l1, l2', 'locations');

        $this->assertSame(['l3', 'l1', 'l2'], $this->origins('en', 'locations'));
    }

    public function test_propagated_localizations_of_orderable_collections_take_the_position_of_their_origin(): void
    {
        Collection::make('locations')->sites(['de', 'en'])->structureContents(['max_depth' => 1])->routes('{slug}')->propagate(true)->save();
        foreach (['l1', 'l2'] as $id) {
            Entry::make()->collection('locations')->locale('de')->id($id)->slug($id)->save();
        }
        $this->setTree('de', 'l2, l1', 'locations');
        $this->skipIfPropagationIsBroken();

        Entry::make()->collection('locations')->locale('de')->id('l3')->slug('l3')->save();

        $this->assertSame(['l2', 'l1', 'l3'], $this->origins('en', 'locations'));
    }

    /**
     * The Eloquent driver caches saved entries by uri, under the key of a `null` id while their uri is
     * empty. Propagated localizations have no id before saving, so they aren't seen as new: no EntryCreated.
     */
    protected function skipIfPropagationIsBroken(): void
    {
        if (Entry::find(null)) {
            $this->markTestSkipped('Entry::find(null) returns an entry, so propagated localizations fire no EntryCreated event');
        }
    }

    protected function page(string $id, string $site = 'de', ?string $origin = null, bool $quietly = true): void
    {
        $entry = Entry::make()->collection('pages')->locale($site)->id($id)->slug($id)->data(['title' => $id]);
        if ($origin) {
            $entry->origin($origin);
        }
        $quietly ? $entry->saveQuietly() : $entry->save();
    }

    /** Delete an entry and flush the caches that still hold it, as a new request would */
    protected function deleteEntry(string $id): void
    {
        Entry::find($id)->delete();
        Blink::flush();
    }

    protected function tree(string $site, string $collection = 'pages')
    {
        return Collection::findByHandle($collection)->structure()->in($site);
    }

    protected function setTree(string $site, string $shape, string $collection = 'pages'): void
    {
        $this->tree($site, $collection)->tree($this->parse($shape))->saveQuietly();
    }

    protected function saveTree(string $site, string $shape, string $collection = 'pages'): void
    {
        $this->tree($site, $collection)->tree($this->parse($shape))->save();
    }

    protected function assertTree(string $site, string $shape, string $collection = 'pages'): void
    {
        $this->assertSame($shape, $this->format($this->tree($site, $collection)->tree()));
    }

    protected function origins(string $site, string $collection): array
    {
        return collect($this->tree($site, $collection)->tree())
            ->map(fn ($branch) => Entry::find($branch['entry'])->origin()?->id() ?? $branch['entry'])
            ->all();
    }

    /** Parse `a, b(c, d)` into tree branches */
    protected function parse(string $shape): array
    {
        $tokens = preg_split('/\s*([(),])\s*/', $shape, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $stack = [[]];
        foreach ($tokens as $token) {
            match ($token) {
                ',' => null,
                '(' => $stack[] = [],
                ')' => (function () use (&$stack) {
                    $children = array_pop($stack);
                    $stack[count($stack) - 1][array_key_last($stack[count($stack) - 1])]['children'] = $children;
                })(),
                default => $stack[count($stack) - 1][] = ['entry' => $token],
            };
        }

        return $stack[0];
    }

    protected function format(array $branches): string
    {
        return implode(', ', array_map(
            fn ($branch) => $branch['entry'].(empty($branch['children']) ? '' : '('.$this->format($branch['children']).')'),
            $branches
        ));
    }
}
