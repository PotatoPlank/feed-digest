<?php

use App\Models\Digest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('digest endpoints require a token', function () {
    config()->set('services.feed.token', 'test-token');

    $response = $this->getJson('/api/digests');

    $response->assertStatus(401);
});

test('creates a digest and returns its uuid', function () {
    config()->set('services.feed.token', 'test-token');

    $payload = [
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'Example Digest',
        'timezone' => 'UTC',
        'filters' => ['+#"gaming"', '-author:"jim jones"'],
    ];

    $response = $this->postJson('/api/digests', $payload, [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertCreated();

    $uuid = $response->json('uuid');

    expect($uuid)->not->toBeEmpty();
    $this->assertDatabaseHas('digests', [
        'uuid' => $uuid,
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'Example Digest',
        'timezone' => 'UTC',
    ]);
});

test('requires a unique feed url or name when creating a digest', function () {
    config()->set('services.feed.token', 'test-token');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'Existing Digest',
    ]);

    $duplicateResponse = $this->postJson('/api/digests', [
        'feed_url' => $digest->feed_url,
        'name' => $digest->name,
    ], [
        'Authorization' => 'Bearer test-token',
    ]);

    $duplicateResponse->assertUnprocessable();
    $duplicateResponse->assertJsonValidationErrors(['name']);

    $urlOnlyResponse = $this->postJson('/api/digests', [
        'feed_url' => $digest->feed_url,
    ], [
        'Authorization' => 'Bearer test-token',
    ]);

    $urlOnlyResponse->assertUnprocessable();
    $urlOnlyResponse->assertJsonValidationErrors(['feed_url']);

    $uniqueNameResponse = $this->postJson('/api/digests', [
        'feed_url' => $digest->feed_url,
        'name' => 'Different Digest',
    ], [
        'Authorization' => 'Bearer test-token',
    ]);

    $uniqueNameResponse->assertCreated();
});

test('allows duplicate names when the feed url is unique', function () {
    config()->set('services.feed.token', 'test-token');

    $digest = Digest::factory()->create([
        'name' => 'Repeated Name',
    ]);

    $response = $this->postJson('/api/digests', [
        'feed_url' => 'https://example.com/another.xml',
        'name' => $digest->name,
    ], [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertCreated();
});

test('updates a digest', function () {
    config()->set('services.feed.token', 'test-token');

    $digest = Digest::factory()->create([
        'name' => 'Old Name',
    ]);

    $response = $this->putJson('/api/digests/'.$digest->uuid, [
        'name' => 'New Name',
    ], [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('digests', [
        'uuid' => $digest->uuid,
        'name' => 'New Name',
    ]);
});

test('deletes a digest', function () {
    config()->set('services.feed.token', 'test-token');

    $digest = Digest::factory()->create();

    $response = $this->deleteJson('/api/digests/'.$digest->uuid, [], [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertNoContent();
    $this->assertDatabaseMissing('digests', [
        'uuid' => $digest->uuid,
    ]);
});

test('lists available digests with links', function () {
    config()->set('services.feed.token', 'test-token');
    config()->set('app.url', 'http://example.test');

    $first = Digest::factory()->create();
    $second = Digest::factory()->create();

    $response = $this->getJson('/api/digests', [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertOk();

    $data = collect($response->json('data'));

    expect($data->pluck('uuid')->all())->toContain($first->uuid, $second->uuid);
    expect($data->pluck('links.rss')->all())->toContain(
        'http://example.test/feed/'.$first->uuid,
        'http://example.test/feed/'.$second->uuid
    );
});
