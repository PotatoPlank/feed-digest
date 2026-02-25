<?php

use App\Models\Digest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
