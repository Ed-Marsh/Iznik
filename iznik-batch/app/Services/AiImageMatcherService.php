<?php

namespace App\Services;

use App\Support\ItemMatchKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find the picture for an item name we have no picture for. ItemCleanerService says which item
 * the name means; every item has at most one picture, found by its ItemMatchKey. So "Aluminium
 * walking frame" and "Walking frame wanted" share the "walking frame" picture, and only an item
 * we have never drawn is generated - drawn from the item, so the next variant finds it too.
 *
 * Both lookups are kept in ai_image_aliases: the post's wording ('item'), which the Go API reads
 * at compose time, and the item key ('itemkey'). Replayed against every post picture since
 * December 2025, the share of new names needing a picture falls month on month, from 61% to
 * 21%: docs/developers/reference/ai-item-images.md.
 */
class AiImageMatcherService
{
    public function __construct(private ItemCleanerService $cleaner) {}

    /**
     * @param  list<string>  $names  item names with no picture of their own
     * @param  array<string,string>  $known  name => item already worked out (messages_cleaned_items), so
     *                                       only the rest go to the cleaner
     * @return array{reuse: array<string,string>, items: array<string,string>}
     *                                                                         reuse: name => externaluid of a picture to use now;
     *                                                                         items: name => the item to draw for a name that needs a new picture.
     *                                                                         A name in neither is generated from its own wording, as before.
     */
    public function matchMany(array $names, bool $dryRun = false, array $known = []): array
    {
        $names = array_values(array_unique(array_filter($names, fn ($n) => $n !== '')));
        $reuse = $names ? $this->aliasLookup($names) : [];
        $items = [];

        $left = array_values(array_filter($names, fn ($n) => ! isset($reuse[$n])));
        $cleaned = array_intersect_key($known, array_flip($left))
            + $this->cleaner->clean(array_values(array_filter($left, fn ($n) => ! isset($known[$n]))));
        foreach ($cleaned as $name => $item) {
            if ($item === ItemCleanerService::NONE) {
                continue;
            }
            $key = ItemMatchKey::key($item);
            if ($key === '') {
                continue;
            }
            $found = $this->aliasLookup([$key])[$key] ?? null;
            if ($found) {
                $reuse[$name] = $found;
                if (! $dryRun) {
                    $this->alias($name, $this->imageId($found), 'item');
                }
                Log::info("AiImageMatcher: '{$name}' is a '{$item}', reusing its picture");
            } elseif ($this->drawable($item)) {
                $items[$name] = $item;
            }
        }

        return ['reuse' => $reuse, 'items' => $items];
    }

    /**
     * A picture was just drawn for $item on behalf of a post called $name: point both at it,
     * so the next variant of the item, and the next post with this wording, find it.
     */
    public function recordGenerated(string $name, string $item, string $externalUid): void
    {
        $id = $this->imageId($externalUid);
        if (! $id) {
            return;
        }
        $this->alias(ItemMatchKey::key($item), $id, 'itemkey');
        if (mb_strtolower($name) !== mb_strtolower($item)) {
            $this->alias($name, $id, 'item');
        }
    }

    /**
     * Give existing pictures their item key, most-used first so a key shared by several
     * pictures goes to the best-known one. Returns how many names were cleaned.
     */
    public function backfill(int $offset, int $limit): int
    {
        $rows = DB::table('ai_images')
            ->where('status', 'active')
            ->whereNotNull('externaluid')
            ->where('usage_count', '>', 0)
            ->orderByDesc('usage_count')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name']);

        $items = $this->cleaner->clean($rows->pluck('name')->all());
        foreach ($rows as $r) {
            $item = $items[$r->name] ?? null;
            if ($item && $item !== ItemCleanerService::NONE) {
                $this->alias(ItemMatchKey::key($item), (int) $r->id, 'itemkey');
            }
        }

        return $items ? $rows->count() : 0;
    }

    /**
     * Pictures for names, through an alias or a picture of that very name, active only.
     *
     * @param  list<string>  $names
     * @return array<string,string> name => externaluid
     */
    private function aliasLookup(array $names): array
    {
        $viaAlias = DB::table('ai_image_aliases')
            ->join('ai_images', 'ai_images.id', '=', 'ai_image_aliases.aiimageid')
            ->whereIn('ai_image_aliases.name', $names)
            ->where('ai_images.status', 'active')
            ->whereNotNull('ai_images.externaluid')
            ->pluck('ai_images.externaluid', 'ai_image_aliases.name');
        $direct = DB::table('ai_images')
            ->whereIn('name', $names)
            ->where('status', 'active')
            ->whereNotNull('externaluid')
            ->pluck('externaluid', 'name');

        // The collation matches "Sofas" to a stored "sofas"; give the caller back its own spelling.
        $byLower = [];
        foreach ([$viaAlias, $direct] as $found) {
            foreach ($found as $n => $uid) {
                $byLower[mb_strtolower($n)] ??= $uid;
            }
        }
        $out = [];
        foreach ($names as $n) {
            if (isset($byLower[mb_strtolower($n)])) {
                $out[$n] = $byLower[mb_strtolower($n)];
            }
        }

        return $out;
    }

    /**
     * An item can be drawn under its own name unless a picture of that name exists and is
     * rejected, regenerating or suppressed: drawing over it would leave the new picture with
     * that status. Those fall back to the post's own wording, as before.
     */
    private function drawable(string $item): bool
    {
        return ! DB::table('ai_images')->where('name', $item)->where('status', '!=', 'active')->exists();
    }

    private function imageId(string $externalUid): ?int
    {
        $id = DB::table('ai_images')->where('externaluid', $externalUid)->value('id');

        return $id ? (int) $id : null;
    }

    private function alias(string $name, ?int $aiImageId, string $method): void
    {
        if ($aiImageId && $name !== '') {
            DB::table('ai_image_aliases')->insertOrIgnore([
                'name' => mb_substr($name, 0, 255), 'aiimageid' => $aiImageId, 'method' => $method,
            ]);
        }
    }
}
