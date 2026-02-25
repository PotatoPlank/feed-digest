<?php

namespace App\Http\Controllers;

use App\Http\Requests\DigestStoreRequest;
use App\Http\Requests\DigestUpdateRequest;
use App\Models\Digest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class DigestController extends Controller
{
    public function index(): JsonResponse
    {
        $digests = Digest::query()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $digests->map(fn (Digest $digest): array => $this->serializeDigest($digest)),
        ]);
    }

    public function store(DigestStoreRequest $request): JsonResponse
    {
        $digest = Digest::create($request->validated());

        return response()->json($this->serializeDigest($digest), 201);
    }

    public function update(DigestUpdateRequest $request, Digest $digest): JsonResponse
    {
        $digest->fill($request->validated());
        $digest->save();
        $this->clearDigestCache($digest);

        return response()->json($this->serializeDigest($digest));
    }

    public function destroy(Digest $digest): Response
    {
        $digest->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDigest(Digest $digest): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return [
            'uuid' => $digest->uuid,
            'feed_url' => $digest->feed_url,
            'name' => $digest->name,
            'timezone' => $digest->timezone,
            'filters' => $digest->filters ?? [],
            'only_prior_to_today' => $digest->only_prior_to_today,
            'max_days' => $digest->max_days,
            'links' => [
                'rss' => $baseUrl.'/feed/'.$digest->uuid,
                'html' => $baseUrl.'/feed/'.$digest->uuid.'/{date}',
            ],
        ];
    }

    private function clearDigestCache(Digest $digest): void
    {
        $disk = Storage::disk('local');
        $paths = $disk->files('digests');

        if ($paths === []) {
            return;
        }

        $prefixes = [
            'digests/rss_'.$digest->uuid.'_',
            'digests/html_'.$digest->uuid.'_',
        ];

        $matches = array_values(array_filter(
            $paths,
            fn (string $path): bool => $this->matchesAnyPrefix($path, $prefixes)
        ));

        if ($matches !== []) {
            $disk->delete($matches);
        }
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function matchesAnyPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
