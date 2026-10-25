<?php

namespace App\Services;

use App\Services\Embedding\EmbedderContract;
use App\Services\Embedding\NodeEmbedder;
use App\Support\ItemMatchKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find a picture we already have for an item name we have not seen, so the post reuses it
 * instead of a new one being generated. A match is kept as an alias, so the same name
 * matches at once next time, and the post form (apiv2) shows it straight away.
 *
 * In order: an alias; the same ItemMatchKey ("3 bags of cat litter" = "Cat litter"); then the
 * nearest name by embedding, if close enough. The thresholds come from a blind-judged replay
 * of three months of production names: docs/developers/reference/ai-item-images.md.
 */
class AiImageMatcherService
{
    /** Nearest name whose head noun agrees ("Sofa bed that folds" -> "Sofa bed"). */
    public const HEAD_THRESHOLD = 0.85;

    /** Nearest name regardless of head noun - only when very close ("Lap top or computer" -> "Laptop"). */
    public const ANY_THRESHOLD = 0.92;

    /** Library names embedded per run, so a backfill is spread over runs. */
    private const EMBED_PER_RUN = 500;

    private EmbedderContract $embedder;

    /** @var array<string,int>|null ItemMatchKey => ai_images.id */
    private ?array $keys = null;

    /** @var list<array{id:int,head:?string,vec:string}>|null vec is the packed float32 embedding */
    private ?array $library = null;

    public function __construct(?EmbedderContract $embedder = null)
    {
        $this->embedder = $embedder ?? new NodeEmbedder('search_query');
    }

    /**
     * @param  list<string>  $names  item names with no picture of their own
     * @return array<string,string> name => externaluid of the picture to reuse
     */
    public function matchMany(array $names, bool $dryRun = false): array
    {
        $names = array_values(array_unique(array_filter($names, fn ($n) => $n !== '')));
        if (! $names) {
            return [];
        }

        $found = $this->aliases($names);
        $left = array_values(array_filter($names, fn ($n) => ! isset($found[$n])));
        if (! $left) {
            return $found;
        }

        $this->loadLibrary($dryRun);

        $toEmbed = [];
        foreach ($left as $i => $name) {
            $id = $this->keys[ItemMatchKey::key($name)] ?? null;
            if ($id) {
                $found[$name] = $this->recordAlias($name, $id, 'key', null, $dryRun);
            } else {
                $toEmbed["q$i"] = $name;
            }
        }

        if ($toEmbed && $this->library) {
            $vectors = $this->embedder->embed($toEmbed);
            if ($vectors === false) {
                // Not matching is safe: the names are generated as they always were.
                Log::warning('AiImageMatcher: embedding failed, generating instead');

                return array_filter($found);
            }
            foreach ($toEmbed as $qid => $name) {
                $match = isset($vectors[$qid]) ? $this->nearest($vectors[$qid], ItemMatchKey::head($name)) : null;
                if ($match) {
                    $found[$name] = $this->recordAlias($name, $match[0], 'embedding', $match[1], $dryRun);
                }
            }
        }

        return array_filter($found);
    }

    /**
     * @param  list<string>  $names
     * @return array<string,string>
     */
    private function aliases(array $names): array
    {
        $rows = DB::table('ai_image_aliases')
            ->join('ai_images', 'ai_images.id', '=', 'ai_image_aliases.aiimageid')
            ->whereIn('ai_image_aliases.name', $names)
            ->where('ai_images.status', 'active')
            ->whereNotNull('ai_images.externaluid')
            ->get(['ai_image_aliases.name', 'ai_images.externaluid']);

        // The collation matches "Sofas" to a stored "sofas"; give the caller back its own spelling.
        $byLower = [];
        foreach ($rows as $r) {
            $byLower[mb_strtolower($r->name)] = $r->externaluid;
        }
        $out = [];
        foreach ($names as $n) {
            if (isset($byLower[mb_strtolower($n)])) {
                $out[$n] = $byLower[mb_strtolower($n)];
            }
        }

        return $out;
    }

    /** @return array{0:int,1:float}|null */
    private function nearest(array $q, ?string $head): ?array
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($this->library as $entry) {
            $v = unpack('g*', $entry['vec']);
            $s = 0.0;
            for ($k = 0; $k < EmbeddingService::EMBEDDING_DIM; $k++) {
                $s += $q[$k] * $v[$k + 1];
            }
            if ($s <= $bestScore) {
                continue;
            }
            $agrees = $s >= self::HEAD_THRESHOLD && ItemMatchKey::headsAgree($head, $entry['head']);
            if ($agrees || $s >= self::ANY_THRESHOLD) {
                $best = $entry['id'];
                $bestScore = $s;
            }
        }

        return $best ? [$best, $bestScore] : null;
    }

    private function recordAlias(string $name, int $aiImageId, string $method, ?float $score, bool $dryRun): string
    {
        if (! $dryRun) {
            DB::table('ai_image_aliases')->insertOrIgnore([
                'name' => mb_substr($name, 0, 255),
                'aiimageid' => $aiImageId,
                'method' => $method,
                'score' => $score,
            ]);
        }
        Log::info("AiImageMatcher: '{$name}' reuses image {$aiImageId} ({$method}".($score ? ' '.round($score, 3) : '').')');

        return (string) DB::table('ai_images')->where('id', $aiImageId)->value('externaluid');
    }

    /**
     * The library is every active picture a post has used. Pictures without an embedding yet
     * get one here, a few hundred a run, so new pictures join it and a backfill drains.
     */
    private function loadLibrary(bool $dryRun): void
    {
        if ($this->library !== null) {
            return;
        }

        if (! $dryRun) {
            $this->embedMissing();
        }

        // Keys come from every active picture a post has used, embedded or not, so an exact-key
        // match still works while the embedder is down. usage_count counts post attachments
        // only, which leaves out job illustrations.
        $this->keys = [];
        $named = DB::table('ai_images')
            ->where('status', 'active')
            ->whereNotNull('externaluid')
            ->where('usage_count', '>', 0)
            ->orderByDesc('usage_count')
            ->get(['id', 'name']);
        foreach ($named as $r) {
            // Most-used first, so a key shared by several pictures takes the best-known one.
            $this->keys[ItemMatchKey::key($r->name)] ??= (int) $r->id;
        }

        $this->library = [];
        $rows = DB::table('ai_images')
            ->join('ai_images_embeddings', 'ai_images_embeddings.aiimageid', '=', 'ai_images.id')
            ->where('ai_images.status', 'active')
            ->whereNotNull('ai_images.externaluid')
            ->orderByDesc('ai_images.usage_count')
            ->get(['ai_images.id', 'ai_images.name', 'ai_images_embeddings.embedding']);

        foreach ($rows as $r) {
            $this->library[] = [
                'id' => (int) $r->id,
                'head' => ItemMatchKey::head($r->name),
                // Kept packed: 28k unpacked vectors would be over 100MB of PHP floats.
                'vec' => $r->embedding,
            ];
        }
    }

    private function embedMissing(): void
    {
        // Only pictures a post has used: job illustrations share ai_images, and a post for a
        // "Driver" should not be offered the HGV driver job's picture.
        $rows = DB::table('ai_images')
            ->leftJoin('ai_images_embeddings', 'ai_images_embeddings.aiimageid', '=', 'ai_images.id')
            ->whereNull('ai_images_embeddings.id')
            ->where('ai_images.status', 'active')
            ->whereNotNull('ai_images.externaluid')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('messages_attachments')
                ->whereColumn('messages_attachments.externaluid', 'ai_images.externaluid'))
            ->orderByDesc('ai_images.id')
            ->limit(self::EMBED_PER_RUN)
            ->get(['ai_images.id', 'ai_images.name']);
        if ($rows->isEmpty()) {
            return;
        }

        $texts = [];
        foreach ($rows as $r) {
            $texts[(string) $r->id] = $r->name;
        }
        $vectors = $this->embedder->embed($texts);
        if ($vectors === false) {
            Log::warning('AiImageMatcher: could not embed library names this run');

            return;
        }

        $insert = [];
        foreach ($vectors as $id => $vec) {
            if (count($vec) === EmbeddingService::EMBEDDING_DIM) {
                $insert[] = ['aiimageid' => (int) $id, 'embedding' => pack('g*', ...$vec)];
            }
        }
        if ($insert) {
            DB::table('ai_images_embeddings')->insertOrIgnore($insert);
        }
    }
}
