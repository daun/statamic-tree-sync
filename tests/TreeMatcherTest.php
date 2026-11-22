<?php

namespace Daun\StatamicTreeSync\Tests;

use Daun\StatamicTreeSync\TreeMatcher;
use PHPUnit\Framework\TestCase;

class TreeMatcherTest extends TestCase
{
    /** Ids of the target are the source ids prefixed with `en-` */
    protected function match(array $source, array $target): array
    {
        $keys = [];
        array_walk_recursive($target, function ($id, $field) use (&$keys) {
            if ($field === 'entry' && str_starts_with($id, 'en-')) {
                $keys[$id] = substr($id, 3);
            }
        });

        return TreeMatcher::match(TreeMatcher::normalize($source), TreeMatcher::normalize($target), $keys);
    }

    protected function ids(array $branches): array
    {
        $ids = [];
        array_walk_recursive($branches, function ($id, $field) use (&$ids) {
            if ($field === 'entry') {
                $ids[] = $id;
            }
        });
        sort($ids);

        return $ids;
    }

    public function test_reorders_and_renests_shared_entries(): void
    {
        $source = [['entry' => 'home'], ['entry' => 'b', 'children' => [['entry' => 'c']]], ['entry' => 'a']];
        $target = [['entry' => 'en-home'], ['entry' => 'en-a', 'children' => [['entry' => 'en-c']]], ['entry' => 'en-b']];

        $this->assertSame(
            [['entry' => 'en-home'], ['entry' => 'en-b', 'children' => [['entry' => 'en-c']]], ['entry' => 'en-a']],
            $this->match($source, $target)
        );
    }

    public function test_keeps_a_target_only_entry_after_its_preceding_sibling(): void
    {
        $source = [['entry' => 'home'], ['entry' => 'b'], ['entry' => 'a']];
        $target = [['entry' => 'en-home'], ['entry' => 'en-a'], ['entry' => 'x'], ['entry' => 'en-b']];

        $this->assertSame(
            [['entry' => 'en-home'], ['entry' => 'en-b'], ['entry' => 'en-a'], ['entry' => 'x']],
            $this->match($source, $target)
        );
    }

    public function test_keeps_consecutive_target_only_entries_in_order(): void
    {
        $source = [['entry' => 'a'], ['entry' => 'b']];
        $target = [['entry' => 'en-b'], ['entry' => 'x'], ['entry' => 'y'], ['entry' => 'en-a']];

        $this->assertSame(
            [['entry' => 'en-a'], ['entry' => 'en-b'], ['entry' => 'x'], ['entry' => 'y']],
            $this->match($source, $target)
        );
    }

    public function test_puts_a_target_only_entry_first_if_no_preceding_sibling_remains(): void
    {
        $source = [['entry' => 'p', 'children' => [['entry' => 'b']]], ['entry' => 'a']];
        $target = [['entry' => 'en-p', 'children' => [['entry' => 'en-a'], ['entry' => 'x'], ['entry' => 'en-b']]]];

        $this->assertSame(
            [['entry' => 'en-p', 'children' => [['entry' => 'x'], ['entry' => 'en-b']]], ['entry' => 'en-a']],
            $this->match($source, $target)
        );
    }

    public function test_target_only_entries_move_along_with_their_parent(): void
    {
        $source = [['entry' => 'a', 'children' => [['entry' => 'p']]]];
        $target = [['entry' => 'en-a'], ['entry' => 'en-p', 'children' => [['entry' => 'x']]]];

        $this->assertSame(
            [['entry' => 'en-a', 'children' => [['entry' => 'en-p', 'children' => [['entry' => 'x']]]]]],
            $this->match($source, $target)
        );
    }

    public function test_target_only_parents_keep_their_target_only_children_but_release_shared_ones(): void
    {
        $source = [['entry' => 'a'], ['entry' => 'b']];
        $target = [['entry' => 'x', 'children' => [['entry' => 'en-b'], ['entry' => 'y']]], ['entry' => 'en-a']];

        $this->assertSame(
            [['entry' => 'x', 'children' => [['entry' => 'y']]], ['entry' => 'en-a'], ['entry' => 'en-b']],
            $this->match($source, $target)
        );
    }

    public function test_hoists_children_of_source_only_entries(): void
    {
        $source = [['entry' => 'a'], ['entry' => 's', 'children' => [['entry' => 'b'], ['entry' => 'c']]], ['entry' => 'd']];
        $target = [['entry' => 'en-d'], ['entry' => 'en-c'], ['entry' => 'en-b'], ['entry' => 'en-a']];

        $this->assertSame(
            [['entry' => 'en-a'], ['entry' => 'en-b'], ['entry' => 'en-c'], ['entry' => 'en-d']],
            $this->match($source, $target)
        );
    }

    public function test_never_loses_or_duplicates_entries(): void
    {
        $source = [['entry' => 'a', 'children' => [['entry' => 's', 'children' => [['entry' => 'b']]]]], ['entry' => 'c']];
        $target = [
            ['entry' => 'x', 'children' => [['entry' => 'en-c', 'children' => [['entry' => 'y']]]]],
            ['entry' => 'en-b', 'children' => [['entry' => 'en-a'], ['entry' => 'z']]],
        ];

        $matched = $this->match($source, $target);

        $this->assertSame($this->ids($target), $this->ids($matched));
    }

    public function test_keeps_extra_branch_data_of_the_target(): void
    {
        $source = [['entry' => 'b'], ['entry' => 'a']];
        $target = [['entry' => 'en-a', 'foo' => 'bar'], ['entry' => 'en-b']];

        $this->assertSame([['entry' => 'en-b'], ['entry' => 'en-a', 'foo' => 'bar']], $this->match($source, $target));
    }

    public function test_leaves_a_matching_tree_untouched(): void
    {
        $source = [['entry' => 'a', 'children' => [['entry' => 'b']]]];
        $target = [['entry' => 'en-a', 'children' => [['entry' => 'en-b']]]];

        $this->assertSame(TreeMatcher::normalize($target), $this->match($source, $target));
    }
}
