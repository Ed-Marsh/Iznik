<?php

namespace Tests\Unit\Services;

use App\Services\AiImageMatcherService;
use App\Services\Embedding\EmbedderContract;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiImageMatcherServiceTest extends TestCase
{
    /** @var array<string,list<float>> name => vector the fake embedder returns */
    private array $vectors = [];

    private int $embedCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('ai_image_aliases')->delete();
        DB::table('ai_images_embeddings')->delete();
        DB::table('messages_attachments')->delete();
        DB::table('ai_images')->delete();
    }

    private function matcher(bool $fail = false): AiImageMatcherService
    {
        $test = $this;

        return new AiImageMatcherService(new class($test, $fail) implements EmbedderContract
        {
            public function __construct(private $test, private bool $fail) {}

            public function embed(array $texts): array|false
            {
                return $this->test->fakeEmbed($texts, $this->fail);
            }
        });
    }

    public function fakeEmbed(array $texts, bool $fail): array|false
    {
        $this->embedCalls++;
        if ($fail) {
            return false;
        }
        $out = [];
        foreach ($texts as $id => $text) {
            if (isset($this->vectors[$text])) {
                $out[$id] = $this->vectors[$text];
            }
        }

        return $out;
    }

    /** A unit vector whose cosine with axis(0) is $cos. */
    private function vec(float $cos, int $axis = 1): array
    {
        $v = array_fill(0, 256, 0.0);
        $v[0] = $cos;
        $v[$axis] = sqrt(max(0.0, 1 - $cos * $cos));

        return $v;
    }

    /** An active picture that a post has used, with its name's vector. */
    private function picture(string $name, array $vec, int $usage = 1): int
    {
        $uid = 'freegletusd-'.md5($name);
        $id = DB::table('ai_images')->insertGetId([
            'name' => $name, 'externaluid' => $uid, 'status' => 'active', 'usage_count' => $usage,
        ]);
        $msgid = $this->createTestMessage($this->createTestUser(), $this->createTestGroup())->id;
        DB::table('messages_attachments')->insert([
            'msgid' => $msgid, 'externaluid' => $uid, 'externalmods' => json_encode(['ai' => true]), 'contenttype' => 'image/jpeg',
        ]);
        $this->vectors[$name] = $vec;

        return $id;
    }

    public function test_a_variant_of_an_existing_name_reuses_its_picture_and_is_remembered(): void
    {
        $id = $this->picture('Cat litter', $this->vec(1.0));

        $found = $this->matcher()->matchMany(['3 bags of cat litter']);

        $this->assertSame(['3 bags of cat litter' => 'freegletusd-'.md5('Cat litter')], $found);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => '3 bags of cat litter', 'aiimageid' => $id, 'method' => 'key']);
    }

    public function test_a_close_name_with_the_same_thing_reuses_the_picture(): void
    {
        $id = $this->picture('Sofa bed', $this->vec(1.0));
        $this->vectors['Sofa bed that folds'] = $this->vec(0.87);

        $found = $this->matcher()->matchMany(['Sofa bed that folds']);

        $this->assertArrayHasKey('Sofa bed that folds', $found);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'Sofa bed that folds', 'aiimageid' => $id, 'method' => 'embedding']);
    }

    public function test_a_close_name_for_a_different_thing_is_generated_instead(): void
    {
        // Close in meaning, but a shredder is not shredded paper.
        $this->picture('Shredded paper', $this->vec(1.0));
        $this->vectors['Paper shredder'] = $this->vec(0.9);

        $this->assertSame([], $this->matcher()->matchMany(['Paper shredder']));
        $this->assertDatabaseCount('ai_image_aliases', 0);
    }

    public function test_a_very_close_name_matches_whatever_its_wording(): void
    {
        $this->picture('Laptop', $this->vec(1.0));
        $this->vectors['Lap top or computer'] = $this->vec(0.95);

        $this->assertArrayHasKey('Lap top or computer', $this->matcher()->matchMany(['Lap top or computer']));
    }

    public function test_a_distant_name_is_generated_instead(): void
    {
        $this->picture('Sofa', $this->vec(1.0));
        $this->vectors['Sofa cushions'] = $this->vec(0.7);

        $this->assertSame([], $this->matcher()->matchMany(['Sofa cushions']));
    }

    public function test_an_alias_answers_without_embedding(): void
    {
        $id = $this->picture('Freezer', $this->vec(1.0));
        DB::table('ai_image_aliases')->insert(['name' => 'Freazer', 'aiimageid' => $id, 'method' => 'embedding', 'score' => 0.9]);

        $found = $this->matcher()->matchMany(['freazer']);

        $this->assertSame(['freazer' => 'freegletusd-'.md5('Freezer')], $found);
        $this->assertSame(0, $this->embedCalls);
    }

    public function test_a_picture_that_is_not_active_is_never_reused(): void
    {
        $id = $this->picture('Cat litter', $this->vec(1.0));
        DB::table('ai_images')->where('id', $id)->update(['status' => 'rejected']);
        DB::table('ai_image_aliases')->insert(['name' => 'Kitty litter', 'aiimageid' => $id, 'method' => 'embedding']);

        $this->assertSame([], $this->matcher()->matchMany(['Kitty litter', 'Cat litters']));
    }

    public function test_a_job_illustration_is_not_offered_to_a_post(): void
    {
        // Job pictures share ai_images but no post uses them, so they never join the library.
        DB::table('ai_images')->insert(['name' => 'Delivery Driver', 'externaluid' => 'freegletusd-job', 'status' => 'active']);
        $this->vectors['Delivery Driver'] = $this->vec(1.0);
        $this->vectors['Delivery driver'] = $this->vec(1.0);

        $this->assertSame([], $this->matcher()->matchMany(['Delivery driver']));
        $this->assertDatabaseCount('ai_images_embeddings', 0);
    }

    public function test_library_names_are_embedded_once(): void
    {
        $this->picture('Sofa', $this->vec(1.0));
        $this->vectors['Settee'] = $this->vec(0.5);

        $this->matcher()->matchMany(['Settee']);
        $this->assertDatabaseCount('ai_images_embeddings', 1);

        $calls = $this->embedCalls;
        $this->matcher()->matchMany(['Settee']);
        // Second run: the library is already embedded, so only the query is.
        $this->assertSame($calls + 1, $this->embedCalls);
    }

    public function test_when_embedding_fails_nothing_matches_and_nothing_is_stored(): void
    {
        $this->picture('Sofa', $this->vec(1.0));

        $this->assertSame([], $this->matcher(fail: true)->matchMany(['Settee']));
        $this->assertDatabaseCount('ai_image_aliases', 0);
    }

    public function test_a_dry_run_stores_nothing(): void
    {
        $this->picture('Cat litter', $this->vec(1.0));
        // Embedded already, as a dry run does not embed the library.
        DB::table('ai_images_embeddings')->insert([
            'aiimageid' => DB::table('ai_images')->value('id'), 'embedding' => pack('g*', ...$this->vec(1.0)),
        ]);

        $found = $this->matcher()->matchMany(['Cat litters'], dryRun: true);

        $this->assertArrayHasKey('Cat litters', $found);
        $this->assertDatabaseCount('ai_image_aliases', 0);
    }
}
