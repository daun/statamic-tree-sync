<?php

namespace Daun\StatamicTreeSync\Sync\Collections;

use stdClass;

/**
 * Rearranges a target tree to match the shape of a source tree.
 *
 * Entries are matched by their root origin. The target never loses entries: source-only ones are
 * skipped, and target-only ones stay after their nearest preceding sibling.
 */
class CollectionMatcher
{
    /**
     * @param  array<int, array<string, mixed>>  $source
     * @param  array<int, array<string, mixed>>  $target
     * @param  array<string, string>  $keys  Entry id => shared key; unmapped ids are their own key
     * @return array<int, array<string, mixed>>
     */
    public static function match(array $source, array $target, array $keys): array
    {
        $key = fn (string $id): string => $keys[$id] ?? $id;

        $targetIds = [];
        $targetInfo = [];
        static::index($target, null, $targetInfo);
        foreach (array_keys($targetInfo) as $id) {
            $targetIds[$key($id)] ??= $id;
        }

        $root = static::node(null);
        $nodes = [];

        $build = function (array $branches, stdClass $parent) use (&$build, $key, $targetIds, $targetInfo, &$nodes): void {
            foreach ($branches as $branch) {
                $id = $targetIds[$key((string) $branch['entry'])] ?? null;
                if ($id !== null && ! isset($nodes[$id])) {
                    $nodes[$id] = static::node($id, $targetInfo[$id]['data']);
                    $parent->children[] = $nodes[$id];
                    $build($branch['children'] ?? [], $nodes[$id]);
                } else {
                    $build($branch['children'] ?? [], $parent);
                }
            }
        };
        $build($source, $root);

        foreach ($targetInfo as $id => $info) {
            if (isset($nodes[$id])) {
                continue;
            }

            $parent = $info['parent'] === null ? $root : $nodes[$info['parent']];
            $position = 0;
            foreach (array_reverse($info['preceding']) as $sibling) {
                $index = isset($nodes[$sibling]) ? array_search($nodes[$sibling], $parent->children, true) : false;
                if (is_int($index)) {
                    $position = $index + 1;
                    break;
                }
            }

            $nodes[$id] = static::node($id, $info['data']);
            array_splice($parent->children, $position, 0, [$nodes[$id]]);
        }

        return static::toBranches($root->children);
    }

    /**
     * Normalize branches for comparison: string ids, no empty children.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $branches): array
    {
        return array_map(function (array $branch): array {
            $children = static::normalize($branch['children'] ?? []);
            unset($branch['children']);
            $branch['entry'] = (string) $branch['entry'];

            return $children ? [...$branch, 'children' => $children] : $branch;
        }, array_values($branches));
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<string, array{parent: ?string, preceding: string[], data: array<string, mixed>}>  $info
     */
    protected static function index(array $branches, ?string $parent, array &$info): void
    {
        $preceding = [];
        foreach ($branches as $branch) {
            $id = (string) $branch['entry'];
            $data = $branch;
            unset($data['entry'], $data['children']);
            $info[$id] = ['parent' => $parent, 'preceding' => $preceding, 'data' => $data];
            $preceding[] = $id;
            static::index($branch['children'] ?? [], $id, $info);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function node(?string $id, array $data = []): stdClass
    {
        return (object) ['id' => $id, 'data' => $data, 'children' => []];
    }

    /**
     * @param  stdClass[]  $nodes
     * @return array<int, array<string, mixed>>
     */
    protected static function toBranches(array $nodes): array
    {
        return array_map(function (stdClass $node): array {
            $branch = ['entry' => $node->id, ...$node->data];

            return $node->children ? [...$branch, 'children' => static::toBranches($node->children)] : $branch;
        }, $nodes);
    }
}
