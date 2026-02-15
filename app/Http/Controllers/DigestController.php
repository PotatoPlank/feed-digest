<?php

namespace App\Http\Controllers;

use App\Http\Requests\DigestStoreRequest;
use App\Http\Requests\DigestUpdateRequest;
use App\Models\Digest;
use Illuminate\Http\JsonResponse;

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

        return response()->json($this->serializeDigest($digest));
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
            'links' => [
                'rss' => $baseUrl.'/feed/'.$digest->uuid,
                'html' => $baseUrl.'/feed/'.$digest->uuid.'/{date}',
            ],
        ];
    }
}
