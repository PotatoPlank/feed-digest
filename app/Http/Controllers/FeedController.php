<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedDigestRequest;
use App\Models\Digest;
use App\Services\FeedAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FeedController extends Controller
{
    public function rss(FeedDigestRequest $request, Digest $digest, FeedAggregator $aggregator): Response|JsonResponse
    {
        $timezone = $digest->timezone ?: config('app.timezone');
        $nameOverride = trim($request->string('name')->toString());
        $cachePath = $this->buildRssCachePath($digest, $nameOverride);

        if ($cachePath !== null && $this->isCacheFresh($cachePath)) {
            return response(file_get_contents($cachePath) ?: '', 200, [
                'Content-Type' => 'application/rss+xml; charset=UTF-8',
            ]);
        }

        try {
            $result = $aggregator->aggregateByDate(
                $digest->feed_url,
                $timezone,
                $digest->filters ?? []
            );
            $groupsByDate = $result['groupsByDate'];
            $feedTitle = $nameOverride !== '' ? $nameOverride : ($digest->name ?: $result['title']);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $rss = $this->buildRssDigest($digest, $feedTitle ?? '', $nameOverride, $groupsByDate);

        if ($cachePath !== null && $this->hasEntriesByDate($groupsByDate)) {
            $this->writeCache($cachePath, $rss);
        }

        return response($rss, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }

    public function html(
        FeedDigestRequest $request,
        Digest $digest,
        FeedAggregator $aggregator
    ): Response|JsonResponse {
        $timezone = $digest->timezone ?: config('app.timezone');
        $dateInput = $request->input('date');

        if (!is_string($dateInput) || $dateInput === '') {
            return response()->json([
                'message' => 'A valid date is required.',
            ], 422);
        }

        $date = CarbonImmutable::parse($dateInput, $timezone);
        $nameOverride = trim($request->string('name')->toString());
        $cachePath = $this->buildHtmlCachePath($digest, $date, $nameOverride);

        if ($cachePath !== null && $this->isCacheFresh($cachePath)) {
            return response(file_get_contents($cachePath) ?: '', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        try {
            $result = $aggregator->aggregateForDate(
                $digest->feed_url,
                $date,
                $timezone,
                $digest->filters ?? []
            );
            $feedTitle = $nameOverride !== '' ? $nameOverride : ($digest->name ?: $result['title']);
            $baseTitle = $feedTitle !== '' ? $feedTitle : (string) config('app.name', 'Daily Feed Aggregator');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $html = $this->buildHtmlPage(
            sprintf('%s | %s', $baseTitle, $date->toDateString()),
            $this->buildDigestHtml($result['groups'])
        );

        if ($cachePath !== null && $this->hasEntries($result['groups'])) {
            $this->writeCache($cachePath, $html);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, array<string, array<int, array<string, mixed>>>>  $groupsByDate
     */
    private function buildRssDigest(
        Digest $digest,
        string $feedTitle,
        string $nameOverride,
        array $groupsByDate
    ): string {
        $appName = (string) config('app.name', 'Daily Feed Aggregator');
        $baseTitle = $feedTitle !== '' ? $feedTitle : $appName;

        $channelTitle = $this->escapeXml($baseTitle.' | Daily Digest');
        $channelLink = $this->escapeXml($this->buildFeedLink($digest, $nameOverride));
        $channelDescription = $this->escapeXml('Daily feed digest');
        $lastBuild = CarbonImmutable::now(config('app.timezone'))->toRfc2822String();
        $itemsXml = $this->buildRssItems($groupsByDate, $digest, $baseTitle, $nameOverride);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>{$channelTitle}</title>
        <link>{$channelLink}</link>
        <description>{$channelDescription}</description>
        <lastBuildDate>{$lastBuild}</lastBuildDate>
        {$itemsXml}
    </channel>
</rss>
XML;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     */
    private function buildDigestHtml(array $groups): string
    {
        $html = '<div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace; font-size: 14px; line-height: 1.6; background: #0b0f14; color: #d1d5db; padding: 20px; border-radius: 8px;">';

        if ($groups === []) {
            $html .= '<p style="margin: 0;">No entries for this date.</p></div>';

            return $html;
        }

        foreach ($groups as $category => $entries) {
            $html .= '<h2 style="font-size: 15px; margin: 18px 0 8px; color: #7dd3fc; text-transform: uppercase; letter-spacing: 0.08em;">'.$this->escapeHtml($category).'</h2>';
            $html .= '<ul style="list-style: none; padding-left: 0; margin: 0 0 18px;">';

            foreach ($entries as $entry) {
                $title = $this->escapeHtml((string) ($entry['title'] ?? ''));
                $link = $this->escapeHtml((string) ($entry['link'] ?? ''));
                $summary = (string) ($entry['summary'] ?? '');
                $author = trim((string) ($entry['author'] ?? ''));
                $publishedAt = trim((string) ($entry['published_at'] ?? ''));
                $categories = $entry['categories'] ?? [];
                $image = trim((string) ($entry['image'] ?? ''));

                $metaParts = [];
                if ($publishedAt !== '') {
                    $metaParts[] = $this->escapeHtml($publishedAt);
                }
                if ($author !== '') {
                    $metaParts[] = 'by '.$this->escapeHtml($author);
                }
                if (is_array($categories) && $categories !== []) {
                    $categoryList = array_map(fn ($value) => $this->escapeHtml((string) $value), $categories);
                    $metaParts[] = 'categories: '.implode(', ', $categoryList);
                }

                $html .= '<li style="margin-bottom: 16px; padding: 12px 14px; border: 1px solid #1f2937; border-radius: 6px; background: #0f1720;">';
                $html .= '<div><span style="color: #60a5fa; margin-right: 8px;">🔗</span><a href="'.$link.'" style="color: #a5b4fc; text-decoration: none; font-weight: 600;">'.$title.'</a></div>';

                if ($image !== '') {
                    $html .= '<div style="margin: 10px 0;"><img src="'.$this->escapeHtml($image).'" alt="" style="max-width: 25rem; width: 100%; height: auto; border-radius: 4px; border: 1px solid #1f2937;" /></div>';
                }

                if ($summary !== '') {
                    $html .= '<div style="margin: 8px 0; color: #e5e7eb;">'.$this->styleSummaryHtml($summary).'</div>';
                }

                if ($metaParts !== []) {
                    $html .= '<div style="color: #94a3b8; font-size: 12px;">'.implode(' · ', $metaParts).'</div>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '</div>';

        return $html;
    }

    private function buildHtmlPage(string $title, string $bodyHtml): string
    {
        $pageTitle = $this->escapeHtml($title);

        return <<<HTML
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$pageTitle}</title>
    </head>
    <body style="margin: 0; background: #0b0f14; color: #d1d5db;">
        {$bodyHtml}
    </body>
</html>
HTML;
    }

    private function styleSummaryHtml(string $html): string
    {
        $style = 'max-width: 25rem; width: 100%; height: auto; border-radius: 4px; border: 1px solid #1f2937;';
        $linkStyle = 'color: #9ca3af; text-decoration: underline;';

        $html = (string) preg_replace_callback('/<img\\b[^>]*>/i', function (array $matches) use ($style): string {
            $tag = $matches[0];

            if (preg_match('/\\bstyle=(["\'])(.*?)\\1/i', $tag, $styleMatch)) {
                $existing = rtrim($styleMatch[2]);
                $existing = $existing === '' ? $style : $existing.'; '.$style;
                $replacement = 'style='.$styleMatch[1].$existing.$styleMatch[1];

                return (string) preg_replace('/\\bstyle=(["\'])(.*?)\\1/i', $replacement, $tag, 1);
            }

            return (string) preg_replace('/<img\\b/i', '<img style="'.$style.'"', $tag, 1);
        }, $html);

        return (string) preg_replace_callback('/<a\\b[^>]*>/i', function (array $matches) use ($linkStyle): string {
            $tag = $matches[0];

            if (preg_match('/\\bstyle=(["\'])(.*?)\\1/i', $tag, $styleMatch)) {
                $existing = rtrim($styleMatch[2]);
                $existing = $existing === '' ? $linkStyle : $existing.'; '.$linkStyle;
                $replacement = 'style='.$styleMatch[1].$existing.$styleMatch[1];

                return (string) preg_replace('/\\bstyle=(["\'])(.*?)\\1/i', $replacement, $tag, 1);
            }

            return (string) preg_replace('/<a\\b/i', '<a style="'.$linkStyle.'"', $tag, 1);
        }, $html);
    }

    /**
     * @param  array<string, array<string, array<int, array<string, mixed>>>>  $groupsByDate
     */
    private function buildRssItems(
        array $groupsByDate,
        Digest $digest,
        string $feedTitle,
        string $nameOverride
    ): string {
        $items = [];

        foreach ($groupsByDate as $date => $groups) {
            $dateInstance = CarbonImmutable::createFromFormat('Y-m-d', $date, config('app.timezone'));

            if (!$dateInstance instanceof CarbonImmutable) {
                continue;
            }

            $itemTitle = sprintf('%s | %s', $feedTitle, $date);
            $guid = sprintf('%s:%s', $digest->uuid, $date);
            $html = $this->buildDigestHtml($groups);
            $link = $this->buildDateLink($digest, $date, $nameOverride);

            $items[] = sprintf(
                '<item><title>%s</title><link>%s</link><guid isPermaLink="false">%s</guid><pubDate>%s</pubDate><description><![CDATA[%s]]></description></item>',
                $this->escapeXml($itemTitle),
                $this->escapeXml($link),
                $this->escapeXml($guid),
                $dateInstance->toRfc2822String(),
                $html
            );
        }

        return implode("\n        ", $items);
    }

    private function buildDateLink(Digest $digest, string $date, string $nameOverride): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $queryParams = [
            'name' => $nameOverride,
        ];

        if ($nameOverride === '') {
            unset($queryParams['name']);
        }

        $query = http_build_query($queryParams);
        $path = $baseUrl.'/feed/'.$digest->uuid.'/'.$date;

        return $query === '' ? $path : $path.'?'.$query;
    }

    private function buildFeedLink(Digest $digest, string $nameOverride): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $query = $nameOverride !== '' ? '?'.http_build_query(['name' => $nameOverride]) : '';

        return $baseUrl.'/feed/'.$digest->uuid.$query;
    }

    /**
     * @param  array<string, array<string, array<int, array<string, mixed>>>>  $groupsByDate
     */
    private function hasEntriesByDate(array $groupsByDate): bool
    {
        foreach ($groupsByDate as $groups) {
            if ($this->hasEntries($groups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     */
    private function hasEntries(array $groups): bool
    {
        foreach ($groups as $entries) {
            if (count($entries) > 0) {
                return true;
            }
        }

        return false;
    }

    private function buildRssCachePath(Digest $digest, string $nameOverride): ?string
    {
        return $this->buildCachePath(
            sprintf(
                'rss_%s_%s_%s.xml',
                $digest->uuid,
                $digest->updated_at?->timestamp ?? 0,
                $this->hashCacheKey($nameOverride)
            )
        );
    }

    private function buildHtmlCachePath(Digest $digest, CarbonImmutable $date, string $nameOverride): ?string
    {
        return $this->buildCachePath(
            sprintf(
                'html_%s_%s_%s_%s.html',
                $digest->uuid,
                $date->toDateString(),
                $digest->updated_at?->timestamp ?? 0,
                $this->hashCacheKey($nameOverride)
            )
        );
    }

    private function hashCacheKey(string $value): string
    {
        return sha1($value);
    }

    private function buildCachePath(string $filename): ?string
    {
        if ($this->cacheTtlSeconds() <= 0) {
            return null;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('digests');

        return $disk->path('digests/'.$filename);
    }

    private function isCacheFresh(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $ttl = $this->cacheTtlSeconds();

        if ($ttl <= 0) {
            return false;
        }

        return filemtime($path) >= (time() - $ttl);
    }

    private function writeCache(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    private function cacheTtlSeconds(): int
    {
        $value = (int) config('digest.cache.ttl', 0);
        $unit = (string) config('digest.cache.unit', 'minutes');

        if ($value <= 0) {
            return 0;
        }

        return match (strtolower($unit)) {
            'minute', 'minutes' => $value * 60,
            'hour', 'hours' => $value * 3600,
            'day', 'days' => $value * 86400,
            default => $value * 60,
        };
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
