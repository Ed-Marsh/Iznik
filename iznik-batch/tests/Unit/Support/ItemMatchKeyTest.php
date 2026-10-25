<?php

namespace Tests\Unit\Support;

use App\Support\ItemMatchKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ItemMatchKeyTest extends TestCase
{
    public static function sameKey(): array
    {
        return [
            'quantity and container' => ['3 bags of cat litter', 'Cat litter'],
            'colour' => ['White double bed', 'Double Bed'],
            'condition' => ['Used tv', 'Tv'],
            'plural' => ['Photo frame', 'Photo frames'],
            'punctuation and case' => ["Child's Bike Stabilisers", 'Child bike stabilisers'],
            'size and dimensions' => ['2x 4\'6" mattresses', 'Mattress'],
            'trash nothing prefix' => ['REQUESTED: Rice cooker', 'Rice cooker'],
            'wanted phrasing' => ['Looking for a slow cooker', 'Slow cooker'],
            'ies plural' => ['Baby bodies', 'Baby body'],
            'irregular plural' => ['Kitchen knives', 'Kitchen knife'],
        ];
    }

    #[DataProvider('sameKey')]
    public function test_variants_share_a_key(string $a, string $b): void
    {
        $this->assertSame(ItemMatchKey::key($b), ItemMatchKey::key($a));
    }

    public static function differentKey(): array
    {
        return [
            // The bag is the item here; only "bag of ..." is a container.
            'container as the item' => ['Coin bags', 'Old coins'],
            // Plural-only nouns are not singularised into another word.
            'plural-only noun' => ['Glasses', 'Glass'],
            'drawers' => ['Chest of drawers', 'Chest of drawer'],
        ];
    }

    #[DataProvider('differentKey')]
    public function test_different_things_keep_different_keys(string $a, string $b): void
    {
        $this->assertNotSame(ItemMatchKey::key($b), ItemMatchKey::key($a));
    }

    public function test_a_name_of_only_dropped_words_keeps_its_words(): void
    {
        $this->assertSame('free', ItemMatchKey::key('Free'));
    }

    public static function heads(): array
    {
        return [
            ['Ladder for bunk beds', 'ladder'],
            ['Paper shredder', 'shredder'],
            ['Shredded paper', 'paper'],
            ['Sofa bed that folds', 'bed'],
            ['Bunk bed for kids, scooter', 'bed'],
            ['Grey velvet 3 seater sofas', 'sofa'],
        ];
    }

    #[DataProvider('heads')]
    public function test_head_is_the_thing_itself(string $name, string $head): void
    {
        $this->assertSame($head, ItemMatchKey::head($name));
    }

    public function test_heads_agree_across_a_typo_but_not_a_different_short_word(): void
    {
        $this->assertTrue(ItemMatchKey::headsAgree('freazer', 'freezer'));
        $this->assertTrue(ItemMatchKey::headsAgree('radiator', 'rediator'));
        $this->assertFalse(ItemMatchKey::headsAgree('took', 'book'));
        $this->assertFalse(ItemMatchKey::headsAgree('shredder', 'paper'));
        $this->assertFalse(ItemMatchKey::headsAgree(null, 'sofa'));
    }
}
