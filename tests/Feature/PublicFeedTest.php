<?php

use App\Models\Digest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('renders public rss feed by digest uuid', function () {
    config()->set('app.url', 'http://example.test');
    config()->set('app.timezone', 'UTC');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'My Digest',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
    ]);

    $today = CarbonImmutable::now('UTC');
    $yesterday = $today->subDay();

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Today Tech</title>
            <link>https://example.com/tech</link>
            <pubDate>{$today->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
        <item>
            <title>Yesterday Item</title>
            <link>https://example.com/yesterday</link>
            <pubDate>{$yesterday->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    $rss = simplexml_load_string($response->getContent(), 'SimpleXMLElement', LIBXML_NOCDATA);

    expect($rss)->not->toBeFalse();
    expect((string) $rss->channel->title)->toBe('My Digest | Daily Digest');
    expect(count($rss->channel->item))->toBe(2);

    $links = [(string) $rss->channel->item[0]->link, (string) $rss->channel->item[1]->link];
    expect($links[0].$links[1])->toContain('/feed/'.$digest->uuid.'/'.$today->toDateString());
    expect($links[0].$links[1])->toContain('/feed/'.$digest->uuid.'/'.$yesterday->toDateString());
});

test('limits rss items, publishes latest pubdate, and aggregates categories', function () {
    config()->set('app.url', 'http://example.test');
    config()->set('app.timezone', 'UTC');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'My Digest',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
        'max_days' => 1,
    ]);

    $latest = CarbonImmutable::create(2026, 2, 25, 18, 30, 0, 'UTC');
    $earlier = $latest->subHours(4);
    $yesterday = $latest->subDay();

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Latest Tech</title>
            <link>https://example.com/latest</link>
            <pubDate>{$latest->toRfc2822String()}</pubDate>
            <category>Tech</category>
            <category>AI</category>
        </item>
        <item>
            <title>Earlier News</title>
            <link>https://example.com/earlier</link>
            <pubDate>{$earlier->toRfc2822String()}</pubDate>
            <category>AI</category>
            <category>News</category>
        </item>
        <item>
            <title>Yesterday Item</title>
            <link>https://example.com/yesterday</link>
            <pubDate>{$yesterday->toRfc2822String()}</pubDate>
            <category>Archive</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid);

    $response->assertOk();

    $rss = simplexml_load_string($response->getContent(), 'SimpleXMLElement', LIBXML_NOCDATA);

    expect($rss)->not->toBeFalse();
    expect((string) $rss->channel->pubDate)->toBe($latest->toRfc2822String());
    expect(count($rss->channel->item))->toBe(1);

    $item = $rss->channel->item[0];
    $categories = array_map(
        'strval',
        (array) $item->category
    );

    expect($categories)->toEqualCanonicalizing(['Tech', 'AI', 'News']);
});

test('renders html digest for a specific date', function () {
    config()->set('app.timezone', 'UTC');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'Example Feed',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
    ]);

    $today = CarbonImmutable::now('UTC');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Today Tech</title>
            <link>https://example.com/tech</link>
            <pubDate>{$today->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid.'/'.$today->toDateString());

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $response->assertSee('Today Tech');
    $response->assertSee('<title>Example Feed | '.$today->toDateString().'</title>', false);
});

test('weekly digests return entries from the prior completed week only', function () {
    config()->set('app.url', 'http://example.test');
    config()->set('app.timezone', 'UTC');

    $now = CarbonImmutable::create(2026, 3, 9, 10, 0, 0, 'UTC');
    CarbonImmutable::setTestNow($now);

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'name' => 'Weekly Digest',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
        'is_weekly_digest' => true,
        'week_starts_on' => 'Sunday',
    ]);

    $priorWeekItem = CarbonImmutable::create(2026, 3, 7, 12, 0, 0, 'UTC');
    $currentWeekItem = CarbonImmutable::create(2026, 3, 8, 12, 0, 0, 'UTC');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Prior Week Item</title>
            <link>https://example.com/prior-week</link>
            <pubDate>{$priorWeekItem->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
        <item>
            <title>Current Week Item</title>
            <link>https://example.com/current-week</link>
            <pubDate>{$currentWeekItem->toRfc2822String()}</pubDate>
            <category>News</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid);

    $response->assertOk();
    $rss = simplexml_load_string($response->getContent(), 'SimpleXMLElement', LIBXML_NOCDATA);

    expect($rss)->not->toBeFalse();
    expect(count($rss->channel->item))->toBe(1);
    expect((string) $rss->channel->item[0]->title)->toBe('Weekly Digest | 2026-03-07');

    CarbonImmutable::setTestNow();
});

test('weekly digest rss cache is only written on the configured week start day', function () {
    config()->set('app.timezone', 'UTC');
    config()->set('digest.cache.ttl', 60);
    config()->set('digest.cache.unit', 'minutes');

    Storage::fake('local');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
        'is_weekly_digest' => true,
        'week_starts_on' => 'Sunday',
    ]);

    $priorWeekItem = CarbonImmutable::create(2026, 3, 7, 12, 0, 0, 'UTC');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Prior Week Item</title>
            <link>https://example.com/prior-week</link>
            <pubDate>{$priorWeekItem->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 8, 9, 0, 0, 'UTC'));
    $this->get('/feed/'.$digest->uuid)->assertOk();

    $cachedOnWeekStartDay = collect(Storage::disk('local')->files('digests'))
        ->contains(fn (string $path): bool => str_starts_with($path, 'digests/rss_weekly_'.$digest->uuid.'_'));

    expect($cachedOnWeekStartDay)->toBeTrue();

    Storage::disk('local')->deleteDirectory('digests');

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 9, 9, 0, 0, 'UTC'));
    $this->get('/feed/'.$digest->uuid)->assertOk();

    $cachedOnNonWeekStartDay = collect(Storage::disk('local')->files('digests'))
        ->contains(fn (string $path): bool => str_starts_with($path, 'digests/rss_weekly_'.$digest->uuid.'_'));

    expect($cachedOnNonWeekStartDay)->toBeFalse();

    CarbonImmutable::setTestNow();
});

test('paginated feeds iterate paged query until duplicate results are detected', function () {
    config()->set('app.timezone', 'UTC');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml?source=main',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
        'is_paginated_feed' => true,
    ]);

    $firstDate = CarbonImmutable::create(2026, 3, 6, 12, 0, 0, 'UTC');
    $secondDate = CarbonImmutable::create(2026, 3, 5, 12, 0, 0, 'UTC');
    $thirdDate = CarbonImmutable::create(2026, 3, 4, 12, 0, 0, 'UTC');

    $pageOneXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Page One Item</title>
            <link>https://example.com/p1</link>
            <guid>page-one</guid>
            <pubDate>{$firstDate->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    $pageTwoXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Page Two Item</title>
            <link>https://example.com/p2</link>
            <guid>page-two</guid>
            <pubDate>{$secondDate->toRfc2822String()}</pubDate>
            <category>News</category>
        </item>
        <item>
            <title>Page Three Item</title>
            <link>https://example.com/p3</link>
            <guid>page-three</guid>
            <pubDate>{$thirdDate->toRfc2822String()}</pubDate>
            <category>News</category>
        </item>
    </channel>
</rss>
XML;

    $duplicatePageXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Page One Item</title>
            <link>https://example.com/p1</link>
            <guid>page-one</guid>
            <pubDate>{$firstDate->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        'https://example.com/feed.xml?source=main&paged=1' => Http::response($pageOneXml, 200),
        'https://example.com/feed.xml?source=main&paged=2' => Http::response($pageTwoXml, 200),
        'https://example.com/feed.xml?source=main&paged=3' => Http::response($duplicatePageXml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid);

    $response->assertOk();

    $rss = simplexml_load_string($response->getContent(), 'SimpleXMLElement', LIBXML_NOCDATA);

    expect($rss)->not->toBeFalse();
    expect(count($rss->channel->item))->toBe(3);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/feed.xml?source=main&paged=1');
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/feed.xml?source=main&paged=2');
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/feed.xml?source=main&paged=3');
});

test('paginated html digests stop pagination when page contains out-of-range entries', function () {
    config()->set('app.timezone', 'UTC');

    $targetDate = CarbonImmutable::create(2026, 3, 6, 0, 0, 0, 'UTC');
    CarbonImmutable::setTestNow($targetDate->addDay());

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'timezone' => 'UTC',
        'only_prior_to_today' => false,
        'is_paginated_feed' => true,
    ]);

    $inRangeEarly = CarbonImmutable::create(2026, 3, 6, 9, 0, 0, 'UTC');
    $inRangeLate = CarbonImmutable::create(2026, 3, 6, 18, 0, 0, 'UTC');
    $outOfRangeOlder = CarbonImmutable::create(2026, 3, 5, 23, 0, 0, 'UTC');

    $pageOneXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Range Item One</title>
            <link>https://example.com/range-1</link>
            <guid>range-1</guid>
            <pubDate>{$inRangeEarly->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
    </channel>
</rss>
XML;

    $pageTwoXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Range Item Two</title>
            <link>https://example.com/range-2</link>
            <guid>range-2</guid>
            <pubDate>{$inRangeLate->toRfc2822String()}</pubDate>
            <category>Tech</category>
        </item>
        <item>
            <title>Older Item</title>
            <link>https://example.com/older</link>
            <guid>older-item</guid>
            <pubDate>{$outOfRangeOlder->toRfc2822String()}</pubDate>
            <category>Archive</category>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        'https://example.com/feed.xml?paged=1' => Http::response($pageOneXml, 200),
        'https://example.com/feed.xml?paged=2' => Http::response($pageTwoXml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid.'/'.$targetDate->toDateString());

    $response->assertOk();
    $response->assertSee('Range Item One');
    $response->assertSee('Range Item Two');
    $response->assertDontSee('Older Item');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/feed.xml?paged=1');
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/feed.xml?paged=2');

    CarbonImmutable::setTestNow();
});
