<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class SystemNewsController extends Controller
{
    private const PER_REPO_LIMIT = 15;
    private const TOTAL_LIMIT = 20;

    /**
     * The Home dashboard's "System News & Updates" tab used to be a
     * hardcoded array describing features that don't exist in this app
     * (rental configs, Lalamove/Toktok/Grab Express shipping — all
     * explicitly out of SUTURA's approved scope) — actively misleading, not
     * just stale. Reading real commit history from both repos gives the
     * shop owner an actually-true changelog instead.
     */
    public function index(): JsonResponse
    {
        // Cache a plain array, not the Collection object itself — a cached
        // Collection is serialized via PHP's native serialize() and can come
        // back as __PHP_Incomplete_Class (json_encodes to an object, not an
        // array, crashing the frontend's items.map()) if class autoloading
        // shifts between the cache write and read, e.g. a composer update
        // regenerating the autoloader mid-TTL. Plain arrays don't have this
        // failure mode.
        $items = Cache::remember('system-news-feed', now()->addMinutes(15), function () {
            return collect([
                ['path' => base_path(), 'label' => 'Backend'],
                ['path' => base_path('../sutura-client'), 'label' => 'Frontend'],
            ])
                ->flatMap(fn ($repo) => $this->commitsFrom($repo['path'], $repo['label']))
                ->sortByDesc('date')
                ->take(self::TOTAL_LIMIT)
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    private function commitsFrom(string $path, string $label): array
    {
        if (!is_dir($path . '/.git')) {
            return [];
        }

        // %x1f (unit separator) between fields, %x1e (record separator)
        // between commits — commit subjects can contain almost any other
        // character, so a control character not otherwise typeable is the
        // only genuinely safe delimiter. tformat (not format) terminates
        // each record instead of separating them — format: still inserts
        // git's own default "\n" between entries on top of a custom
        // separator, leaving a stray leading newline on every hash after
        // the first; tformat doesn't.
        $result = Process::path($path)
            ->run(['git', 'log', '-n', (string) self::PER_REPO_LIMIT, '--pretty=tformat:%H%x1f%aI%x1f%s%x1e']);

        if (!$result->successful()) {
            return [];
        }

        return collect(explode("\x1e", trim($result->output())))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(function ($line) use ($label) {
                [$hash, $date, $subject] = array_pad(explode("\x1f", $line), 3, null);

                return [
                    'hash' => substr((string) $hash, 0, 7),
                    'date' => $date,
                    'subject' => $subject,
                    'source' => $label,
                ];
            })
            ->all();
    }
}
