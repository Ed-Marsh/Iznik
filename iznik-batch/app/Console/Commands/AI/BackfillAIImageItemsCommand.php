<?php

namespace App\Console\Commands\AI;

use App\Services\AiImageMatcherService;
use App\Services\ItemCleanerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Give the pictures we already have an item key, so a new post for the same item reuses one
 * instead of a new picture being drawn. Pictures drawn from now on get theirs when drawn.
 *
 * Works through the used pictures most-used first, a few hundred names a run, and remembers how
 * far it got. Once through, a run costs one count. docs/developers/reference/ai-item-images.md.
 */
#[AsCommand(name: 'ai:images:backfill-items')]
class BackfillAIImageItemsCommand extends Command
{
    protected $signature = 'ai:images:backfill-items {--limit=500 : Names to clean this run}';

    protected $description = 'Give existing AI item pictures the item key new posts are matched on';

    private const OFFSET_KEY = 'ai.images_item_backfill_offset';

    public function handle(AiImageMatcherService $matcher, ItemCleanerService $cleaner): int
    {
        if (! $cleaner->isConfigured()) {
            $this->warn('No Gemini key: nothing to do.');

            return self::SUCCESS;
        }

        $offset = (int) (DB::table('config')->where('key', self::OFFSET_KEY)->value('value') ?? 0);
        $limit = max(1, (int) $this->option('limit'));
        $done = 0;

        while ($done < $limit) {
            $chunk = min(ItemCleanerService::BATCH, $limit - $done);
            $cleaned = $matcher->backfill($offset, $chunk);
            if ($cleaned === 0) {
                // Finished, or the cleaner failed: either way stop, and keep the offset so a
                // failed chunk is retried next run rather than skipped.
                break;
            }
            $offset += $cleaned;
            $done += $cleaned;
            DB::table('config')->upsert(['key' => self::OFFSET_KEY, 'value' => (string) $offset], ['key'], ['value']);
        }

        $this->info("Cleaned {$done} picture names, {$offset} so far.");

        return self::SUCCESS;
    }
}
