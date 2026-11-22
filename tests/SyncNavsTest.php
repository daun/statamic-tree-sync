<?php

namespace Daun\StatamicTreeSync\Tests;

use Daun\StatamicTreeSync\NavSyncer;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Events\NavTreeSaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\User;

class SyncNavsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic-tree-sync.navigations' => ['*']]);

        Collection::make('pages')->sites(['de', 'en'])->save();
        foreach (['a', 'b', 'c'] as $id) {
            Entry::make()->collection('pages')->locale('de')->id($id)->slug($id)->saveQuietly();
            if ($id !== 'c') {
                Entry::make()->collection('pages')->locale('en')->id("en-{$id}")->origin($id)->slug($id)->saveQuietly();
            }
        }

        Nav::make('main')->title('Main')->save();
        Blink::put('nav-blueprint-main', Blueprint::makeFromFields([
            'suffix' => ['type' => 'text'],
            'note' => ['type' => 'text', 'localizable' => true],
        ])->setHandle('main')->setNamespace('navigation'));

        // The same entry in several branches, and a branch without entry
        $this->setTree('de', '1:a, 2:b(3:b, 4:b), 5');
        $this->setTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
    }

    #[DataProvider('syncConfiguration')]
    public function test_only_configured_handles_sync(array $handles, bool $enabled): void
    {
        config(['statamic-tree-sync.navigations' => $handles]);

        $this->saveTree('de', '2:b(4:b(3:b)), 5, 1:a');

        if ($enabled) {
            $this->assertTree('en', '2:en-b(4:en-b(3:en-b)), 5, 1:en-a');
        } else {
            $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
            $this->artisan('statamic:trees:sync:navigations')->assertSuccessful();
            $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
            $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main']])->assertFailed();
            $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
        }
    }

    public static function syncConfiguration(): array
    {
        return [
            'disabled' => [[], false],
            'all' => [['*'], true],
            'selected' => [['main'], true],
            'another handle' => [['other'], false],
            'not a glob' => [['m*'], false],
        ];
    }

    public function test_the_command_can_sync_from_another_site(): void
    {
        $this->setTree('en', '5, 1:en-a, 2:en-b(3:en-b, 4:en-b)');

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main'], '--from' => 'en'])->assertSuccessful();

        $this->assertTree('de', '5, 1:a, 2:b(3:b, 4:b)');
    }

    public function test_unknown_nav_handles_fail_before_syncing(): void
    {
        $this->setTree('de', '5, 1:a, 2:b(3:b, 4:b)');

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main', 'missing']])
            ->expectsOutputToContain('Navigation [missing] not found')
            ->assertFailed();

        $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
    }

    public function test_saving_a_tree_rearranges_the_trees_of_the_other_sites(): void
    {
        $this->saveTree('de', '2:b(4:b(3:b)), 5, 1:a');

        $this->assertTree('en', '2:en-b(4:en-b(3:en-b)), 5, 1:en-a');
    }

    public function test_syncing_works_from_any_site(): void
    {
        $this->saveTree('en', '5, 2:en-b(4:en-b, 3:en-b), 1:en-a');

        $this->assertTree('de', '5, 2:b(4:b, 3:b), 1:a');
    }

    public function test_new_branches_are_created_with_the_localized_entry(): void
    {
        $this->saveTree('de', '1:a(6:b, 7:c), 2:b(3:b, 4:b), 5');

        $this->assertTree('en', '1:en-a(6:en-b, 7:c), 2:en-b(3:en-b, 4:en-b), 5');
    }

    public function test_branches_linking_to_an_entry_localized_later_are_updated(): void
    {
        $this->setTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5, 6:c');
        Entry::make()->collection('pages')->locale('en')->id('en-c')->origin('c')->slug('c')->saveQuietly();

        $this->saveTree('de', '1:a, 2:b(3:b, 4:b), 5, 6:c');

        $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5, 6:en-c');
    }

    public function test_removed_branches_are_removed(): void
    {
        $this->saveTree('de', '1:a, 2:b(4:b)');

        $this->assertTree('en', '1:en-a, 2:en-b(4:en-b)');
    }

    public function test_emptying_a_tree_empties_the_other_trees(): void
    {
        $this->saveTree('de', []);

        $this->assertTree('en', '');
        $this->assertTree('de', '');
    }

    public function test_branches_only_in_one_site_are_kept_after_their_preceding_sibling(): void
    {
        $this->setTree('en', '1:en-a, 8, 2:en-b(3:en-b, 9:en-a, 4:en-b), 5');

        $this->saveTree('de', '5, 2:b(4:b, 3:b), 1:a');

        $this->assertTree('en', '5, 2:en-b(4:en-b, 3:en-b, 9:en-a), 1:en-a, 8');
    }

    public function test_children_of_removed_branches_only_in_the_target_move_up(): void
    {
        $this->setTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b(9)), 5');

        $this->saveTree('de', '1:a, 2:b(3:b), 5');

        $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 9), 5');
    }

    public function test_localized_fields_are_kept_and_others_synced(): void
    {
        $this->setTree('de', [
            ['id' => '1', 'entry' => 'a', 'title' => 'Titel', 'data' => ['suffix' => '#de', 'note' => 'Notiz']],
        ]);
        $this->setTree('en', [
            ['id' => '1', 'entry' => 'en-a', 'title' => 'Title', 'data' => ['suffix' => '#en', 'note' => 'Note']],
        ]);

        $this->saveTree('de', [
            ['id' => '1', 'entry' => 'a', 'title' => 'Neuer Titel', 'data' => ['suffix' => '#tickets', 'note' => 'Neu']],
            ['id' => '2', 'title' => 'Extern', 'url' => 'https://example.com', 'data' => ['note' => 'Notiz']],
        ]);

        $this->assertSame([
            ['id' => '1', 'entry' => 'en-a', 'title' => 'Title', 'data' => ['suffix' => '#tickets', 'note' => 'Note']],
            ['id' => '2', 'title' => 'Extern', 'url' => 'https://example.com', 'data' => ['note' => 'Notiz']],
        ], $this->tree('en')->tree());
    }

    public function test_localized_fields_follow_while_they_are_in_sync(): void
    {
        $this->setTree('de', [
            ['id' => '1', 'entry' => 'a', 'title' => 'Titel', 'data' => ['note' => 'Notiz']],
            ['id' => '2', 'title' => 'Shop', 'url' => 'https://shop.example.com'],
            ['id' => '3', 'title' => 'Kontakt', 'url' => '/kontakt'],
            ['id' => '4', 'entry' => 'b'],
        ]);
        $this->setTree('en', [
            ['id' => '1', 'entry' => 'en-a', 'title' => 'Titel', 'data' => ['note' => 'Notiz']],
            ['id' => '2', 'title' => 'Shop', 'url' => 'https://shop.example.com'],
            ['id' => '3', 'title' => 'Contact', 'url' => '/en/contact'],
            ['id' => '4', 'entry' => 'en-b'],
        ]);

        $this->saveTree('de', [
            ['id' => '1', 'entry' => 'a', 'title' => 'Neuer Titel', 'data' => ['note' => 'Neue Notiz']],
            ['id' => '2', 'url' => 'https://example.com/shop'],
            ['id' => '3', 'title' => 'Kontakt & Anfahrt', 'url' => '/kontakt-anfahrt'],
            ['id' => '4', 'entry' => 'b', 'title' => 'Eigener Titel'],
        ]);

        $this->assertSame([
            ['id' => '1', 'entry' => 'en-a', 'title' => 'Neuer Titel', 'data' => ['note' => 'Neue Notiz']],
            ['id' => '2', 'url' => 'https://example.com/shop'],
            ['id' => '3', 'title' => 'Contact', 'url' => '/en/contact'],
            ['id' => '4', 'entry' => 'en-b'],
        ], $this->tree('en')->tree());
    }

    public function test_translating_a_localized_field_does_not_sync_it(): void
    {
        $this->setTree('de', [['id' => '1', 'entry' => 'a', 'title' => 'Titel']]);
        $this->setTree('en', [['id' => '1', 'entry' => 'en-a', 'title' => 'Titel']]);

        $this->saveTree('en', [['id' => '1', 'entry' => 'en-a', 'title' => 'Title']]);
        $this->assertSame([['id' => '1', 'entry' => 'a', 'title' => 'Titel']], $this->tree('de')->tree());

        $this->saveTree('de', [['id' => '1', 'entry' => 'a', 'title' => 'Neuer Titel']]);
        $this->assertSame([['id' => '1', 'entry' => 'en-a', 'title' => 'Title']], $this->tree('en')->tree());
    }

    public function test_titles_edited_in_the_control_panel_stay_localized(): void
    {
        $this->setTree('de', [['id' => '1', 'entry' => 'a', 'title' => 'Titel']]);
        $this->setTree('en', [['id' => '1', 'entry' => 'en-a', 'title' => 'Titel']]);
        $this->actingAs(User::make()->makeSuper()->save());

        $this->saveTitleInCp('de', 'a', 'Neuer Titel');
        $this->assertSame('Neuer Titel', $this->tree('en')->tree()[0]['title']);

        $this->saveTitleInCp('en', 'en-a', 'Title');
        $this->assertSame('Neuer Titel', $this->tree('de')->tree()[0]['title']);

        $this->saveTitleInCp('de', 'a', 'Anderer Titel');
        $this->assertSame('Title', $this->tree('en')->tree()[0]['title']);
    }

    protected function saveTitleInCp(string $site, string $entry, string $title): void
    {
        $this->patch(cp_route('navigation.tree.update', 'main'), [
            'site' => $site,
            'pages' => [['id' => '1', 'children' => []]],
            'data' => ['1' => ['entry' => $entry, 'values' => ['title' => $title], 'localizedFields' => ['title']]],
        ])->assertOk();
        Blink::flush();
    }

    public function test_the_command_keeps_localized_fields(): void
    {
        $this->setTree('de', [['id' => '1', 'entry' => 'a', 'title' => 'Neuer Titel']]);
        $this->setTree('en', [['id' => '1', 'entry' => 'en-a', 'title' => 'Titel']]);

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main']])->assertSuccessful();

        $this->assertSame([['id' => '1', 'entry' => 'en-a', 'title' => 'Titel']], $this->tree('en')->tree());
    }

    public function test_a_new_empty_tree_is_copied_from_the_default_site(): void
    {
        $this->tree('en')->delete();

        Nav::findByHandle('main')->makeTree('en')->save();

        $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');
    }

    public function test_the_command_syncs_navs(): void
    {
        $this->setTree('de', '5, 1:a, 2:b(3:b, 4:b), 6:c');

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main'], '--dry-run' => true])->assertSuccessful();
        $this->assertTree('en', '1:en-a, 2:en-b(3:en-b, 4:en-b), 5');

        $this->artisan('statamic:trees:sync:navigations')->assertSuccessful();
        $this->assertTree('en', '5, 1:en-a, 2:en-b(3:en-b, 4:en-b), 6:c');
    }

    #[DataProvider('treesWithoutBranchIds')]
    public function test_dry_run_detects_missing_branch_ids_without_changing_trees(array $source, array $target): void
    {
        $this->setTree('de', $source);
        $this->setTree('en', $target);
        $tree = $this->tree('de');
        $saved = [];
        Event::listen(NavTreeSaved::class, function ($event) use (&$saved) {
            $saved[] = $event->tree->locale();
        });

        $this->assertTrue(app(NavSyncer::class)->pull($this->tree('en'), 'de', save: false));

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main'], '--dry-run' => true])
            ->expectsOutputToContain('would change: en')
            ->assertSuccessful();

        $this->assertSame($source, $tree->tree());
        $this->assertSame($source, $this->tree('de')->tree());
        $this->assertSame($target, $this->tree('en')->tree());
        $this->assertSame([], $saved);

        $this->artisan('statamic:trees:sync:navigations', ['handles' => ['main']])
            ->expectsOutputToContain('changed: en')
            ->assertSuccessful();

        $expected = $this->tree('de')->tree();
        $expected[0]['entry'] = 'en-a';
        if (isset($expected[0]['children'])) {
            $expected[0]['children'][0]['entry'] = 'en-b';
        }
        $this->assertSame($expected, $this->tree('en')->tree());
    }

    public static function treesWithoutBranchIds(): array
    {
        return [
            'root' => [[['entry' => 'a']], []],
            'nested' => [
                [['id' => '1', 'entry' => 'a', 'children' => [['entry' => 'b']]]],
                [['id' => '1', 'entry' => 'en-a']],
            ],
        ];
    }

    public function test_syncing_twice_does_not_save_unchanged_trees(): void
    {
        $this->setTree('de', '5, 1:a, 2:b(3:b, 4:b), 6:c');
        $syncer = app(NavSyncer::class);
        $saved = [];
        Event::listen(NavTreeSaved::class, function ($event) use (&$saved) {
            $saved[] = $event->tree->locale();
        });

        $this->assertSame(['en'], $syncer->sync(Nav::findByHandle('main')));
        $this->assertSame(['en'], $saved);
        $expected = $this->tree('en')->tree();
        $saved = [];

        $this->assertSame([], $syncer->sync(Nav::findByHandle('main')));
        $this->assertSame([], $saved);
        $this->assertSame($expected, $this->tree('en')->tree());
    }

    protected function tree(string $site)
    {
        // The Eloquent driver saves new trees empty, so create them before filling them
        if (! Nav::findByHandle('main')->in($site)) {
            Nav::findByHandle('main')->makeTree($site)->saveQuietly();
        }

        return Nav::findByHandle('main')->in($site);
    }

    protected function setTree(string $site, string|array $shape): void
    {
        $this->tree($site)->tree(is_array($shape) ? $shape : $this->parse($shape))->saveQuietly();
    }

    protected function saveTree(string $site, string|array $shape): void
    {
        $this->tree($site)->tree(is_array($shape) ? $shape : $this->parse($shape))->save();
    }

    protected function assertTree(string $site, string $shape): void
    {
        $this->assertSame($shape, $this->format($this->tree($site)->tree()));
    }

    /** Parse `1:a, 2:b(3, 4:c)` into branches with id and optional entry */
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
                default => $stack[count($stack) - 1][] = (function (string $token) {
                    [$id, $entry] = array_pad(explode(':', $token), 2, null);

                    return $entry ? ['id' => $id, 'entry' => $entry] : ['id' => $id];
                })($token),
            };
        }

        return $stack[0];
    }

    protected function format(array $branches): string
    {
        return implode(', ', array_map(
            fn ($branch) => $branch['id'].(isset($branch['entry']) ? ':'.$branch['entry'] : '')
                .(empty($branch['children']) ? '' : '('.$this->format($branch['children']).')'),
            $branches
        ));
    }
}
