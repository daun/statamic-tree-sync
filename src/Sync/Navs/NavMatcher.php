<?php

namespace Daun\StatamicTreeSync\Sync\Navs;

use Closure;
use stdClass;

/**
 * Rearranges a target nav tree to match a source nav tree.
 *
 * Branches are matched by id, which sites share once a tree was copied. Source-only branches are
 * created, removed ones are removed, and target-only ones stay after their nearest preceding sibling.
 */
class NavMatcher
{
    /**
     * @param  array<int, array<string, mixed>>  $source
     * @param  array<int, array<string, mixed>>  $target
     * @param  string[]  $removed  Ids of the branches removed from the source
     * @param  Closure(array<string, mixed>, ?array<string, mixed>): array<string, mixed>  $map  (source branch, target branch) => new target branch, without children
     * @return array<int, array<string, mixed>>
     */
    public static function match(array $source, array $target, array $removed, Closure $map): array
    {
        $info = [];
        static::index($target, null, $info);
        $removed = array_flip($removed);

        $root = static::node([]);
        $nodes = [];

        $build = function (array $branches, stdClass $parent) use (&$build, &$nodes, $info, $map): void {
            foreach ($branches as $branch) {
                $id = isset($branch['id']) ? (string) $branch['id'] : null;
                if ($id === null || isset($nodes[$id])) {
                    $build($branch['children'] ?? [], $parent);

                    continue;
                }

                $data = $branch;
                unset($data['children']);
                $nodes[$id] = static::node($map($data, $info[$id]['data'] ?? null));
                $parent->children[] = $nodes[$id];
                $build($branch['children'] ?? [], $nodes[$id]);
            }
        };
        $build($source, $root);

        // Children of a removed branch take its place: same parent and anchors
        $removedParents = [];
        foreach ($info as $key => $item) {
            if (isset($nodes[$key])) {
                continue;
            }

            $anchors = array_reverse($item['preceding']);
            if ($removedParent = $removedParents[$item['parent']] ?? null) {
                $parent = $removedParent['parent'];
                array_push($anchors, ...$removedParent['anchors']);
            } else {
                $parent = $item['parent'] === null ? $root : $nodes[$item['parent']];
            }

            if (isset($removed[$key])) {
                $removedParents[$key] = ['parent' => $parent, 'anchors' => $anchors];

                continue;
            }

            $position = 0;
            foreach ($anchors as $sibling) {
                $index = isset($nodes[$sibling]) ? array_search($nodes[$sibling], $parent->children, true) : false;
                if (is_int($index)) {
                    $position = $index + 1;
                    break;
                }
            }

            $nodes[$key] = static::node($item['data']);
            array_splice($parent->children, $position, 0, [$nodes[$key]]);
        }

        return static::toBranches($root->children);
    }

    /**
     * Normalize branches for comparison: no empty children.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $branches): array
    {
        return array_map(function (array $branch): array {
            $children = static::normalize($branch['children'] ?? []);
            unset($branch['children']);

            return $children ? [...$branch, 'children' => $children] : $branch;
        }, array_values($branches));
    }

    /**
     * Ids of all branches in a tree.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return string[]
     */
    public static function ids(array $branches): array
    {
        $ids = [];
        foreach ($branches as $branch) {
            if (isset($branch['id'])) {
                $ids[] = (string) $branch['id'];
            }
            array_push($ids, ...static::ids($branch['children'] ?? []));
        }

        return $ids;
    }

    /**
     * All branches of a tree by id, without children.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<string, array<string, mixed>>
     */
    public static function flatten(array $branches): array
    {
        $flat = [];
        foreach ($branches as $branch) {
            $children = $branch['children'] ?? [];
            unset($branch['children']);
            if (isset($branch['id'])) {
                $flat[(string) $branch['id']] = $branch;
            }
            $flat += static::flatten($children);
        }

        return $flat;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    public static function missesIds(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (! isset($branch['id']) || static::missesIds($branch['children'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index branches by id; branches without an id get a key that can't match any source branch.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<string, array{parent: ?string, preceding: string[], data: array<string, mixed>}>  $info
     */
    protected static function index(array $branches, ?string $parent, array &$info): void
    {
        $preceding = [];
        foreach ($branches as $branch) {
            $key = isset($branch['id']) ? (string) $branch['id'] : "\0".count($info);
            $data = $branch;
            unset($data['children']);
            $info[$key] = ['parent' => $parent, 'preceding' => $preceding, 'data' => $data];
            $preceding[] = $key;
            static::index($branch['children'] ?? [], $key, $info);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function node(array $data): stdClass
    {
        return (object) ['data' => $data, 'children' => []];
    }

    /**
     * @param  stdClass[]  $nodes
     * @return array<int, array<string, mixed>>
     */
    protected static function toBranches(array $nodes): array
    {
        return array_map(
            fn (stdClass $node): array => $node->children
                ? [...$node->data, 'children' => static::toBranches($node->children)]
                : $node->data,
            $nodes
        );
    }
}
