<?php

namespace App\Support;

/**
 * Reduce an item name to what its picture shows, so a variant can reuse a picture we already
 * have instead of generating another: "3 bags of cat litter" and "Cat Litter" are one picture,
 * "White double bed" and "Double Bed" are one picture.
 *
 * key() is an exact-match key. head() is the thing itself - the last word before the first
 * "for/with/and/..." - which the nearest-name match must agree on, so "ladder for bunk beds"
 * never gets the bunk bed picture and "paper shredder" never gets "shredded paper".
 *
 * Benchmarked against production names: docs/developers/reference/ai-item-images.md.
 */
final class ItemMatchKey
{
    /** Words that change little or nothing in a rough illustration of the item. */
    private const DROP = [
        'a', 'an', 'the', 'some', 'any', 'few', 'several', 'various', 'assorted', 'mixed', 'lots', 'lot',
        'x', 'anything', 'etc', 'bits', 'stuff',
        'new', 'brand', 'used', 'unused', 'old', 'vintage', 'retro', 'antique', 'working', 'broken',
        'good', 'great', 'excellent', 'condition', 'free', 'spare', 'nice', 'lovely', 'clean', 'little',
        'small', 'large', 'big', 'medium', 'mini', 'huge', 'tiny', 'extra', 'xl', 'xxl', 'giant',
        'black', 'white', 'grey', 'gray', 'red', 'blue', 'green', 'yellow', 'pink', 'purple', 'orange',
        'brown', 'cream', 'beige', 'silver', 'gold', 'navy', 'teal', 'oak', 'pine', 'colour', 'coloured', 'size', 'sizes', 'sized',
    ];

    /**
     * "Bag of cat litter" is cat litter, but "Coin bags" is bags: a container word is only
     * dropped when "of" follows it.
     */
    private const CONTAINER = [
        'pair', 'pairs', 'set', 'sets', 'box', 'boxes', 'bag', 'bags', 'bundle', 'bundles', 'selection',
        'collection', 'load', 'loads', 'pack', 'packs', 'job', 'amount', 'quantity', 'lots', 'lot', 'couple',
    ];

    /** Trash Nothing's "OFFERED:"/"REQUESTED:" and the ways a wanted post starts. */
    private const LEAD = '/^(?:(?:offered|requested|offer|wanted|wanting|request|need|needed|looking for|seeking|wtd)\b[\s:]*)+/';

    /** Nouns that only exist in the plural: singularising them makes a different word. */
    private const PLURAL_ONLY = [
        'glasses', 'scissors', 'trousers', 'jeans', 'clothes', 'pants', 'shorts', 'tights', 'leggings',
        'pyjamas', 'pajamas', 'binoculars', 'tongs', 'pliers', 'tweezers', 'headphones', 'earphones',
        'goggles', 'drawers', 'stairs', 'news', 'series', 'species', 'chess', 'dregs', 'overalls',
        'dungarees', 'knickers', 'boxers', 'sunglasses', 'spectacles', 'secateurs', 'shears', 'lees',
    ];

    private const IRREGULAR = [
        'knives' => 'knife', 'shelves' => 'shelf', 'leaves' => 'leaf', 'loaves' => 'loaf', 'wolves' => 'wolf',
        'halves' => 'half', 'children' => 'child', 'mice' => 'mouse', 'geese' => 'goose', 'feet' => 'foot',
        'teeth' => 'tooth', 'men' => 'man', 'women' => 'woman', 'people' => 'person', 'dice' => 'die',
        'scarves' => 'scarf', 'hooves' => 'hoof',
    ];

    /** Where the thing itself ends and what it is for, or comes with, begins. */
    private const SPLIT = ['for', 'with', 'and', 'or', 'to', 'from', 'in', 'on', 'plus', 'inc', 'including', 'without', 'that', 'which', 'suitable', 'if'];

    public static function key(string $name): string
    {
        return implode(' ', self::keyTokens($name));
    }

    public static function head(string $name): ?string
    {
        $toks = self::keyTokens($name);
        $cut = null;
        foreach ($toks as $i => $w) {
            if (in_array($w, self::SPLIT, true)) {
                $cut = $i;
                break;
            }
        }
        $first = $cut ? array_slice($toks, 0, $cut) : $toks;

        return $first ? end($first) : null;
    }

    /**
     * Two heads name the same thing: equal, or a typo apart ("freazer"/"freezer"). A short word
     * gets no slack, so "took" is not "book".
     */
    public static function headsAgree(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $shorter = min(strlen($a), strlen($b));
        $cap = $shorter >= 8 ? 2 : ($shorter >= 4 ? 1 : 0);

        return $cap > 0 && $a[0] === $b[0] && self::editDistance($a, $b, $cap) <= $cap;
    }

    /** @return list<string> */
    public static function keyTokens(string $name): array
    {
        $all = self::tokens($name);
        $kept = [];
        foreach (self::stripPhrasing($all) as $w) {
            // A lone letter is a size ("M/L") or a split possessive, never the item.
            if (strlen($w) < 2 || preg_match('/^\d+$|^\d+x$|^x\d+$/', $w) || in_array($w, self::DROP, true)) {
                continue;
            }
            $kept[] = self::singular($w);
        }

        return $kept ?: array_map([self::class, 'singular'], $all);
    }

    /** @return list<string> */
    private static function tokens(string $name): array
    {
        $s = mb_strtolower($name);
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
        }
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace("/['\x{2018}\x{2019}`]/u", '', $s) ?? $s;
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);

        return $s === '' ? [] : explode(' ', $s);
    }

    /**
     * @param  list<string>  $toks
     * @return list<string>
     */
    private static function stripPhrasing(array $toks): array
    {
        $s = trim(preg_replace(self::LEAD, '', implode(' ', $toks)) ?? '');
        $t = $s === '' ? [] : explode(' ', $s);
        $out = [];
        for ($i = 0; $i < count($t); $i++) {
            if (in_array($t[$i], self::CONTAINER, true) && ($t[$i + 1] ?? null) === 'of') {
                $i++;

                continue;
            }
            $out[] = $t[$i];
        }

        return $out;
    }

    private static function singular(string $w): string
    {
        if (strlen($w) <= 3 || in_array($w, self::PLURAL_ONLY, true)) {
            return $w;
        }
        if (isset(self::IRREGULAR[$w])) {
            return self::IRREGULAR[$w];
        }
        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            return substr($w, 0, -3).'y';
        }
        if (preg_match('/(ss|us|is|ous)$/', $w)) {
            return $w;
        }
        if (preg_match('/(ches|shes|xes|sses|zzes)$/', $w)) {
            return substr($w, 0, -2);
        }
        if (str_ends_with($w, 's')) {
            return substr($w, 0, -1);
        }

        return $w;
    }

    /** Damerau-Levenshtein (optimal string alignment), giving up once past $cap. */
    private static function editDistance(string $a, string $b, int $cap): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > $cap) {
            return $cap + 1;
        }
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            $rowMin = PHP_INT_MAX;
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
                $rowMin = min($rowMin, $d[$i][$j]);
            }
            if ($rowMin > $cap) {
                return $cap + 1;
            }
        }

        return $d[$la][$lb];
    }
}
