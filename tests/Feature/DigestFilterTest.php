<?php

use App\Models\Digest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('applies category, author, summary filters, and content removals', function () {
    config()->set('app.timezone', 'UTC');

    $digest = Digest::factory()->create([
        'feed_url' => 'https://example.com/feed.xml',
        'timezone' => 'UTC',
        'filters' => [
            '+#"gaming"',
            '-author:"jim jones"',
            '-summary-regex:"banned"',
            'remove:"ICE"',
            'remove-regex:"Secret"',
        ],
    ]);

    $today = CarbonImmutable::now('UTC');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Example Feed</title>
        <item>
            <title>Allowed Item</title>
            <link>https://example.com/allowed</link>
            <pubDate>{$today->toRfc2822String()}</pubDate>
            <category>Gaming</category>
            <author>Timmy</author>
            <description>ICE People Secret</description>
        </item>
        <item>
            <title>Excluded Author</title>
            <link>https://example.com/author</link>
            <pubDate>{$today->toRfc2822String()}</pubDate>
            <category>Gaming</category>
            <author>Jim Jones</author>
            <description>Gaming update</description>
        </item>
        <item>
            <title>Excluded Category</title>
            <link>https://example.com/category</link>
            <pubDate>{$today->toRfc2822String()}</pubDate>
            <category>Tech</category>
            <author>Timmy</author>
            <description>Tech update</description>
        </item>
    </channel>
</rss>
XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $response = $this->get('/feed/'.$digest->uuid.'/'.$today->toDateString());

    $response->assertOk();
    $response->assertSee('Allowed Item');
    $response->assertDontSee('Excluded Author');
    $response->assertDontSee('Excluded Category');
    $response->assertDontSee('ICE');
    $response->assertDontSee('Secret');
    $response->assertSee('People');
});
