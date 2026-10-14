<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Reads what a customer typed — "fix my pants", "add a logo to my school uniform", "make a barong tagalog",
 * "barong" — as: what work (make / alter & fix / customize), which tasks (capabilities) and which garments,
 * and turns that into a match against what shops have actually set up (the service group, its ticked
 * capabilities, its garments or designs). Nothing is guessed from a service's free-text name.
 *
 * The keyword lists follow the adviser task (2026-10-14, §5): everyday English, Tagalog and Bisaya. The Bisaya
 * words are a first list and should be checked by the team.
 */
final class SearchIntent
{
    public const MAKE = 'make';

    public const ALTER = 'alter';

    public const CUSTOMIZE = 'customize';

    /** Checked in this order when a search names more than one kind of work ("sew a patch" = customize). */
    private const INTENT_ORDER = [self::CUSTOMIZE, self::ALTER, self::MAKE];

    private const INTENT_WORDS = [
        self::CUSTOMIZE => [
            'embroider', 'embroidery', 'burda', 'magpaburda', 'add a logo', 'add logo', 'logo', 'put a badge', 'badge',
            'patch', 'nameplate', 'initials', 'monogram', 'callado',
        ],
        self::ALTER => [
            'fix', 'repair', 'alter', 'alteration', 'shorten', 'putol', 'paliit', 'paliitan', 'paluwag', 'pahaba', 'baston',
            'hem', 'zipper', 'punit', 'butas', 'mend', 'ayos', 'ipaayos', 'resize', 'taper', 'tahi punit',
            // Bisaya (to be checked): ayo / ipaayo = fix, ayuhon = to be fixed, gamyan / pagamyon = make smaller.
            'ayo', 'ipaayo', 'ayuhon', 'gamyan', 'pagamyon',
        ],
        self::MAKE => [
            'make', 'making', 'custom', 'custom made', 'patahi', 'magpatahi', 'made to measure', 'bespoke', 'tailor', 'tahi', 'sew',
            'dressmaking', 'tahion',
        ],
    ];

    /** A single word that names a task, for the kind of work it belongs to. */
    private const CAPABILITY_WORDS = [
        'zipper' => ['zipper_replacement'],
        'hem' => ['hemming_length_adjustment'], 'shorten' => ['hemming_length_adjustment'], 'putol' => ['hemming_length_adjustment'],
        'pahaba' => ['hemming_length_adjustment'], 'length' => ['hemming_length_adjustment'],
        'waist' => ['waist_seat_adjustment'], 'bewang' => ['waist_seat_adjustment'], 'baywang' => ['waist_seat_adjustment'], 'paliit' => ['waist_seat_adjustment', 'tapering_resizing'], 'paluwag' => ['waist_seat_adjustment'],
        'baston' => ['tapering_resizing'], 'taper' => ['tapering_resizing'], 'resize' => ['tapering_resizing'], 'paliitan' => ['tapering_resizing'],
        'sleeve' => ['sleeve_adjustment'], 'manggas' => ['sleeve_adjustment'],
        'button' => ['button_fastener_replacement'], 'butones' => ['button_fastener_replacement'],
        'punit' => ['garment_repair_mending'], 'butas' => ['garment_repair_mending'], 'mend' => ['garment_repair_mending'], 'tear' => ['garment_repair_mending'],
        'logo' => ['logo_embroidery', 'garment_embroidery', 'uniform_embroidery'], 'seal' => ['logo_embroidery', 'uniform_embroidery'],
        'name' => ['name_rank_embroidery', 'uniform_embroidery'], 'nameplate' => ['name_rank_embroidery', 'uniform_embroidery'],
        'rank' => ['name_rank_embroidery'],
        'badge' => ['badge_patch_attachment', 'uniform_embroidery'], 'patch' => ['badge_patch_attachment', 'uniform_embroidery'],
        'initials' => ['monogram_callado'], 'monogram' => ['monogram_callado', 'garment_embroidery'], 'callado' => ['monogram_callado'],
        'beadwork' => ['garment_embroidery'],
    ];

    /** Words that carry no meaning for the match (English, Tagalog, Bisaya). */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'my', 'our', 'your', 'to', 'on', 'in', 'of', 'for', 'at', 'with', 'and', 'or', 'please', 'pls',
        'i', 'me', 'want', 'need', 'can', 'some', 'ng', 'sa', 'ko', 'ang', 'mga', 'na', 'ni', 'po', 'pa', 'ipa', 'akin',
        'nako', 'akong', 'ug', 'og',
    ];

    /** A word that is only a describing word in these garment names ("Dress Shirt" is a shirt, "Scrub Suit" a uniform). */
    private const NOT_NAMED_BY = [
        'dress' => ['dress_shirt', 'dress_pants'],
        'suit' => ['medical_uniform'],
    ];

    /** Optional embroidery a garment-making service can tick. */
    private const OPTIONAL_EMBROIDERY = ['garment_embroidery', 'uniform_embroidery'];

    /**
     * @return array{intent: ?string, capabilities: list<string>, garments: list<string>}|null null when the search
     *                                                                                         names no work and no garment
     */
    public static function parse(?string $query): ?array
    {
        $words = CanonicalTaxonomy::wordStems((string) $query);
        if ($words === []) {
            return null;
        }
        $left = $words;
        $take = function (array $phraseWords) use (&$left): bool {
            if ($phraseWords === [] || array_diff($phraseWords, $left)) {
                return false;
            }
            foreach ($phraseWords as $w) {
                unset($left[array_search($w, $left, true)]);
            }

            return true;
        };

        // 1. Garments named in two or more words first ("school uniform", "barong tagalog", "custom gown").
        $garments = [];
        foreach (self::garmentPhrases() as [$phrase, $slugs]) {
            if (count($phrase) > 1 && $take($phrase)) {
                $garments = [...$garments, ...$slugs];
            }
        }

        // 2. The kind of work, longest phrase first ("tahi punit" before "tahi").
        $found = [];
        $phrases = [];
        foreach (self::INTENT_WORDS as $intent => $list) {
            foreach ($list as $p) {
                $phrases[] = [$intent, CanonicalTaxonomy::wordStems($p)];
            }
        }
        usort($phrases, fn ($a, $b) => count($b[1]) <=> count($a[1]));
        $before = $left;
        foreach ($phrases as [$intent, $phrase]) {
            if ($take($phrase)) {
                $found[] = $intent;
            }
        }
        $intent = collect(self::INTENT_ORDER)->first(fn ($i) => in_array($i, $found, true));

        // 3. Tasks, only those belonging to that kind of work.
        $allowed = match ($intent) {
            self::ALTER => CanonicalTaxonomy::capabilitiesOf('alterations_repairs'),
            self::CUSTOMIZE => [...CanonicalTaxonomy::capabilitiesOf('garment_customization'), ...self::OPTIONAL_EMBROIDERY],
            default => [],
        };
        $capabilities = [];
        if ($allowed !== []) {
            $group = $intent === self::ALTER ? 'alterations_repairs' : 'garment_customization';
            $capabilities = CanonicalTaxonomy::capabilitiesMentionedIn($group, (string) $query);
            foreach ($before as $w) {
                $capabilities = [...$capabilities, ...(self::CAPABILITY_WORDS[$w] ?? [])];
            }
            foreach ($left as $i => $w) {
                if (isset(self::CAPABILITY_WORDS[$w])) {
                    $capabilities = [...$capabilities, ...self::CAPABILITY_WORDS[$w]];
                    unset($left[$i]);
                }
            }
            $capabilities = array_values(array_intersect($allowed, array_unique($capabilities)));
        }

        // 4. Garments named in one word: a garment's own word ("tuxedo", "jersey"), an alias ("slacks", "maong")
        //    or a category ("barong", "pants", "gown").
        $stop = array_flip(self::STOP_WORDS);
        foreach ($left as $w) {
            if (! isset($stop[$w])) {
                $garments = [...$garments, ...(self::singleWordGarments()[$w] ?? [])];
            }
        }
        $garments = array_values(array_unique($garments));

        return $intent === null && $garments === []
            ? null
            : ['intent' => $intent, 'capabilities' => $capabilities, 'garments' => $garments];
    }

    /**
     * Limit a services query to those that do what was searched. Alterations / customization with no garments
     * ticked work on any garment. A garment matches by the service's ticked garments or one of its listed designs.
     *
     * @param  array{intent: ?string, capabilities: list<string>, garments: list<string>}  $p
     */
    public static function constrainServices(Builder $q, array $p): void
    {
        $q->where('services.is_active', true);
        $caps = $p['capabilities'];

        if ($p['intent'] === self::ALTER) {
            $q->where('services.service_category', 'alterations_repairs');
            if ($caps !== []) {
                $q->where(fn ($c) => self::anyJson($c, 'services.capabilities', $caps));
            }
        } elseif ($p['intent'] === self::CUSTOMIZE) {
            $own = array_values(array_intersect($caps, CanonicalTaxonomy::capabilitiesOf('garment_customization')));
            $q->where(function ($c) use ($own) {
                $c->where(function ($g) use ($own) {
                    $g->where('services.service_category', 'garment_customization');
                    if ($own !== []) {
                        $g->where(fn ($x) => self::anyJson($x, 'services.capabilities', $own));
                    }
                })->orWhere(function ($g) {
                    $g->whereIn('services.service_category', ['custom_tailoring', 'uniform_production'])
                        ->where(fn ($x) => self::anyJson($x, 'services.capabilities', self::OPTIONAL_EMBROIDERY));
                });
            });
        } elseif ($p['intent'] === self::MAKE) {
            $q->whereIn('services.service_category', ['custom_tailoring', 'uniform_production']);
        }

        if ($p['garments'] !== []) {
            $garments = $p['garments'];
            // "Any garment" (nothing ticked): an alteration service answers every garment search — the customer
            // should see that a shop can alter or fix it. An embroidery service only when embroidery was asked
            // for, so a shop that only embroiders does not show up for "tuxedo".
            $anyGarment = $p['intent'] === self::CUSTOMIZE ? ['alterations_repairs', 'garment_customization'] : ['alterations_repairs'];
            $q->where(function ($g) use ($garments, $anyGarment) {
                self::anyJson($g, 'services.garment_types', $garments);
                $g->orWhereHas('catalogItems', fn ($ci) => $ci->listed()->ofGarments($garments))
                    ->orWhere(fn ($any) => $any->whereIn('services.service_category', $anyGarment)
                        ->where(fn ($e) => $e->whereNull('services.garment_types')->orWhereJsonLength('services.garment_types', 0)));
            });
        }
    }

    /** True when designs (not only services) are an answer: no work named, or "make". */
    public static function designsAnswer(array $p): bool
    {
        return $p['garments'] !== [] && ($p['intent'] === null || $p['intent'] === self::MAKE);
    }

    private static function anyJson($q, string $column, array $values): void
    {
        $q->where(function ($w) use ($column, $values) {
            foreach ($values as $v) {
                $w->orWhereJsonContains($column, $v);
            }
        });
    }

    /** @return list<array{0: list<string>, 1: list<string>}> [phrase stems, garments] for labels, aliases, category aliases */
    private static function garmentPhrases(): array
    {
        static $phrases = null;
        if ($phrases === null) {
            $phrases = [];
            foreach (CanonicalTaxonomy::GARMENTS as $slug => $g) {
                // "Custom Gown / Bridal Gown" is two names, not one phrase.
                foreach ([...explode('/', $g['label']), ...$g['aliases']] as $p) {
                    $phrases[] = [array_values(array_unique(CanonicalTaxonomy::wordStems($p))), [$slug]];
                }
            }
            foreach (CanonicalTaxonomy::GARMENT_CATEGORIES as $cat => $c) {
                foreach ($c['aliases'] as $p) {
                    $phrases[] = [array_values(array_unique(CanonicalTaxonomy::wordStems($p))), CanonicalTaxonomy::garmentsInCategory($cat)];
                }
            }
            usort($phrases, fn ($a, $b) => count($b[0]) <=> count($a[0]));
        }

        return $phrases;
    }

    /** @return array<string, list<string>> one word => garments it names */
    private static function singleWordGarments(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $generic = array_flip(['custom', 'set', 'and', 't', 'other', 'wear']);
            foreach (CanonicalTaxonomy::GARMENTS as $slug => $g) {
                foreach (CanonicalTaxonomy::wordStems($g['label']) as $w) {
                    if (! isset($generic[$w])) {
                        $map[$w][] = $slug;
                    }
                }
                foreach ($g['aliases'] as $a) {
                    $stems = CanonicalTaxonomy::wordStems($a);
                    if (count($stems) === 1) {
                        $map[$stems[0]][] = $slug;
                    }
                }
            }
            foreach (CanonicalTaxonomy::GARMENT_CATEGORIES as $cat => $c) {
                foreach ($c['aliases'] as $a) {
                    $stems = CanonicalTaxonomy::wordStems($a);
                    if (count($stems) === 1) {
                        $map[$stems[0]] = [...($map[$stems[0]] ?? []), ...CanonicalTaxonomy::garmentsInCategory($cat)];
                    }
                }
            }
            foreach (self::NOT_NAMED_BY as $word => $not) {
                if (isset($map[$word])) {
                    $map[$word] = array_diff($map[$word], $not);
                }
            }
            $map = array_map(fn ($s) => array_values(array_unique($s)), $map);
        }

        return $map;
    }
}
