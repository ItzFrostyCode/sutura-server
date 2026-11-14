<?php

namespace App\Casts;

use App\Support\ServiceQuestions;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes a service's questions (custom_fields) or list columns (roster_fields) in the one shape
 * every screen expects (App\Support\ServiceQuestions). An empty list is stored as null, as before.
 */
class ServiceQuestionsCast implements CastsAttributes
{
    public function __construct(private readonly string $kind = 'questions') {}

    private function allowed(): array
    {
        return $this->kind === 'roster' ? ServiceQuestions::ROSTER_TYPES : ServiceQuestions::TYPES;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $list = ServiceQuestions::normalize($value, $this->allowed());

        return $list === [] ? null : $list;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $list = ServiceQuestions::normalize($value, $this->allowed());

        return $list === [] ? null : json_encode($list, JSON_UNESCAPED_UNICODE);
    }
}
