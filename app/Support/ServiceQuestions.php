<?php

namespace App\Support;

/**
 * The one shape of a service's questions (custom_fields, asked once per order) and its per-person list columns
 * (roster_fields): {id, label, type, required, options}. Every reader (online order, booking, walk-in job form,
 * service editor) gets exactly this, whatever older data or an older editor stored.
 *
 * Why: questions were saved in an older shape too ({name, type: dropdown|short_text}). Screens that only knew the new
 * one lost the React key (no id) or showed a text box instead of the choices (dropdown is not "select").
 * Normalising here, on the model, fixes every screen at once and keeps it fixed for anything saved later.
 */
final class ServiceQuestions
{
    /** Types a question may have. */
    public const TYPES = ['text', 'number', 'select', 'radio', 'checkbox'];

    /** Types a per-person list column may have (one cell per person: no radio or checkbox). */
    public const ROSTER_TYPES = ['text', 'number', 'select'];

    /** Older or looser names for the same type. */
    private const ALIASES = [
        'short_text' => 'text', 'long_text' => 'text', 'textarea' => 'text', 'paragraph' => 'text', 'string' => 'text',
        'numeric' => 'number', 'integer' => 'number',
        'dropdown' => 'select', 'single_choice' => 'select', 'choice' => 'select',
        'boolean' => 'checkbox', 'yes_no' => 'checkbox', 'toggle' => 'checkbox',
    ];

    /**
     * @param  mixed  $fields  a list (array, or the JSON text of one); anything else is an empty list
     * @param  list<string>  $allowed  self::TYPES or self::ROSTER_TYPES
     * @return list<array{id:string,label:string,type:string,required:bool,options?:list<string>}>
     */
    public static function normalize(mixed $fields, array $allowed = self::TYPES): array
    {
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        $ids = [];
        foreach (array_values($fields) as $i => $f) {
            if (! is_array($f)) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? $f['name'] ?? ''));
            $options = self::options($f['options'] ?? null);
            // A choice with no choices yet stays a choice: the publish check tells the owner to add them
            // (Service::publishProblems), and the order screens ask it as typed text until then.
            $type = self::type((string) ($f['type'] ?? ''), $options, $allowed);

            $id = self::id($f, $label, $i);
            for ($n = 2, $base = $id; in_array($id, $ids, true); $n++) {
                $id = "{$base}-{$n}";
            }
            $ids[] = $id;

            $q = ['id' => $id, 'label' => $label, 'type' => $type, 'required' => filter_var($f['required'] ?? false, FILTER_VALIDATE_BOOL)];
            if (in_array($type, ['select', 'radio'], true)) {
                $q['options'] = $options;
            }
            $out[] = $q;
        }

        return $out;
    }

    private static function type(string $raw, array $options, array $allowed): string
    {
        $t = strtolower(trim($raw));
        $t = self::ALIASES[$t] ?? $t;
        if (! in_array($t, $allowed, true)) {
            // A per-person column cannot be a radio or checkbox: a list of answers becomes a choice, a yes/no a Yes/No choice.
            $t = $options !== [] && in_array('select', $allowed, true) ? 'select' : 'text';
        }

        return $t;
    }

    /** @return list<string> */
    private static function options(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }
        $seen = [];
        foreach ($raw as $o) {
            $o = trim(is_scalar($o) ? (string) $o : '');
            if ($o !== '' && ! in_array(mb_strtolower($o), array_map('mb_strtolower', $seen), true)) {
                $seen[] = mb_substr($o, 0, 100);
            }
        }

        return $seen;
    }

    /** A stable id: the stored one, else the old `name`, else one made from the label (never random, so it does not change per read). */
    private static function id(array $f, string $label, int $i): string
    {
        foreach (['id', 'name'] as $key) {
            $v = trim(is_scalar($f[$key] ?? null) ? (string) $f[$key] : '');
            if ($v !== '') {
                return mb_substr($v, 0, 64);
            }
        }

        return $label !== '' ? 'q_'.substr(md5(mb_strtolower($label)), 0, 8) : "q_{$i}";
    }
}
