<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class FeedAggregator
{
    /**
     * @param  array<int, string>  $filters
     * @return array{title: string, groups: array<string, array<int, array<string, mixed>>>}
     */
    public function aggregateForDate(
        string $url,
        CarbonImmutable $date,
        string $timezone,
        array $filters = [],
        bool $onlyPriorToToday = true
    ): array {
        $xml = $this->fetchXml($url);
        $items = $this->extractItems($xml, $timezone);
        $feedTitle = $this->extractFeedTitle($xml);
        $targetDate = $date->toDateString();

        if ($onlyPriorToToday) {
            $items = $this->filterPriorToToday($items, $timezone);
        }

        $filtered = array_filter(
            $items,
            fn (array $item): bool => $item['published_at'] instanceof CarbonImmutable
                && $item['published_at']->toDateString() === $targetDate
        );

        $filterConfig = $this->parseFilters($filters);
        $filtered = $this->applyFilters($filtered, $filterConfig);

        return [
            'title' => $feedTitle,
            'groups' => $this->groupByCategory($filtered, $filterConfig),
        ];
    }

    /**
     * @param  array<int, string>  $filters
     * @return array{title: string, groupsByDate: array<string, array<string, array<int, array<string, mixed>>>>}
     */
    public function aggregateByDate(
        string $url,
        string $timezone,
        array $filters = [],
        bool $onlyPriorToToday = true
    ): array {
        $xml = $this->fetchXml($url);
        $items = $this->extractItems($xml, $timezone);
        $feedTitle = $this->extractFeedTitle($xml);
        $grouped = [];
        $filterConfig = $this->parseFilters($filters);

        if ($onlyPriorToToday) {
            $items = $this->filterPriorToToday($items, $timezone);
        }

        $items = $this->applyFilters($items, $filterConfig);

        foreach ($items as $item) {
            $publishedAt = $item['published_at'];

            if (! $publishedAt instanceof CarbonImmutable) {
                continue;
            }

            $dateKey = $publishedAt->toDateString();
            $grouped[$dateKey][] = $item;
        }

        foreach ($grouped as $date => $entries) {
            $grouped[$date] = $this->groupByCategory($entries, $filterConfig);
        }

        krsort($grouped, SORT_NATURAL);

        return [
            'title' => $feedTitle,
            'groupsByDate' => $grouped,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function filterPriorToToday(array $items, string $timezone): array
    {
        $startOfToday = CarbonImmutable::now($timezone)->startOfDay();

        return array_values(array_filter(
            $items,
            fn (array $item): bool => $item['published_at'] instanceof CarbonImmutable
                && $item['published_at']->lt($startOfToday)
        ));
    }

    private function fetchXml(string $url): SimpleXMLElement
    {
        $response = Http::timeout(10)->get($url);

        if (! $response->ok()) {
            throw new RuntimeException('Unable to fetch the feed.');
        }

        return $this->parseXml($response);
    }

    private function parseXml(Response $response): SimpleXMLElement
    {
        $body = trim($response->body());

        if ($body === '') {
            throw new RuntimeException('Feed response was empty.');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Feed XML could not be parsed.');
        }

        return $xml;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(SimpleXMLElement $xml, string $timezone): array
    {
        if (isset($xml->channel)) {
            return $this->extractRssItems($xml->channel, $timezone);
        }

        $namespaces = $xml->getNamespaces(true);
        $defaultNamespace = $namespaces[''] ?? ($namespaces['atom'] ?? null);
        $feed = $defaultNamespace ? $xml->children($defaultNamespace) : $xml;

        if (isset($feed->entry)) {
            return $this->extractAtomEntries($feed, $timezone);
        }

        if (isset($xml->entry)) {
            return $this->extractAtomEntries($xml, $timezone);
        }

        throw new RuntimeException('Unsupported feed format.');
    }

    private function extractFeedTitle(SimpleXMLElement $xml): string
    {
        if (isset($xml->channel->title)) {
            return trim((string) $xml->channel->title);
        }

        $namespaces = $xml->getNamespaces(true);
        $defaultNamespace = $namespaces[''] ?? ($namespaces['atom'] ?? null);
        $feed = $defaultNamespace ? $xml->children($defaultNamespace) : $xml;

        if (isset($feed->title)) {
            return trim((string) $feed->title);
        }

        if (isset($xml->title)) {
            return trim((string) $xml->title);
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRssItems(SimpleXMLElement $channel, string $timezone): array
    {
        $items = [];
        $namespaces = $channel->getNamespaces(true);

        foreach ($channel->item as $item) {
            $summary = trim((string) $item->description);

            if ($summary === '' && isset($namespaces['content'])) {
                $content = $item->children($namespaces['content']);
                $summary = trim((string) ($content->encoded ?? ''));
            }

            $published = trim((string) $item->pubDate);

            if ($published === '' && isset($namespaces['dc'])) {
                $dc = $item->children($namespaces['dc']);
                $published = trim((string) ($dc->date ?? ''));
            }

            $author = trim((string) $item->author);
            if ($author === '' && isset($namespaces['dc'])) {
                $dc = $item->children($namespaces['dc']);
                $author = trim((string) ($dc->creator ?? ''));
            }

            $items[] = [
                'title' => trim((string) $item->title),
                'link' => trim((string) $item->link),
                'summary' => $summary,
                'published_at' => $this->parseDate($published, $timezone),
                'categories' => $this->extractRssCategories($item),
                'author' => $author,
                'guid' => trim((string) $item->guid),
                'image' => $this->extractRssImage($item, $namespaces),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAtomEntries(SimpleXMLElement $feed, string $timezone): array
    {
        $items = [];
        $namespaces = $feed->getNamespaces(true);

        foreach ($feed->entry as $entry) {
            $summary = trim((string) $entry->summary);

            if ($summary === '') {
                $summary = trim((string) $entry->content);
            }

            $published = trim((string) $entry->updated);

            if ($published === '') {
                $published = trim((string) $entry->published);
            }

            $author = '';
            if (isset($entry->author->name)) {
                $author = trim((string) $entry->author->name);
            }

            $items[] = [
                'title' => trim((string) $entry->title),
                'link' => $this->extractAtomLink($entry),
                'summary' => $summary,
                'published_at' => $this->parseDate($published, $timezone),
                'categories' => $this->extractAtomCategories($entry),
                'author' => $author,
                'guid' => trim((string) ($entry->id ?? '')),
                'image' => $this->extractAtomImage($entry, $namespaces),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function extractRssCategories(SimpleXMLElement $item): array
    {
        $categories = [];

        foreach ($item->category as $category) {
            $value = trim((string) $category);

            if ($value !== '') {
                $categories[] = $value;
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * @return array<int, string>
     */
    private function extractAtomCategories(SimpleXMLElement $entry): array
    {
        $categories = [];

        foreach ($entry->category as $category) {
            $value = trim((string) ($category['term'] ?? $category['label'] ?? $category));

            if ($value !== '') {
                $categories[] = $value;
            }
        }

        return array_values(array_unique($categories));
    }

    private function extractAtomLink(SimpleXMLElement $entry): string
    {
        $fallback = trim((string) ($entry->link['href'] ?? ''));

        foreach ($entry->link as $link) {
            $rel = trim((string) ($link['rel'] ?? ''));
            $href = trim((string) ($link['href'] ?? ''));

            if ($href === '') {
                continue;
            }

            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }
        }

        return $fallback;
    }

    private function extractRssImage(SimpleXMLElement $item, array $namespaces): ?string
    {
        if (isset($item->enclosure)) {
            $url = trim((string) ($item->enclosure['url'] ?? ''));
            $type = trim((string) ($item->enclosure['type'] ?? ''));

            if ($url !== '' && (str_starts_with($type, 'image/') || $type === '')) {
                return $url;
            }
        }

        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);

            if (isset($media->content)) {
                $url = trim((string) ($media->content['url'] ?? ''));
                $type = trim((string) ($media->content['type'] ?? ''));

                if ($url !== '' && (str_starts_with($type, 'image/') || $type === '')) {
                    return $url;
                }
            }

            if (isset($media->thumbnail)) {
                $url = trim((string) ($media->thumbnail['url'] ?? ''));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function extractAtomImage(SimpleXMLElement $entry, array $namespaces): ?string
    {
        if (isset($namespaces['media'])) {
            $media = $entry->children($namespaces['media']);

            if (isset($media->content)) {
                $url = trim((string) ($media->content['url'] ?? ''));
                $type = trim((string) ($media->content['type'] ?? ''));

                if ($url !== '' && (str_starts_with($type, 'image/') || $type === '')) {
                    return $url;
                }
            }

            if (isset($media->thumbnail)) {
                $url = trim((string) ($media->thumbnail['url'] ?? ''));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filterConfig
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByCategory(array $items, array $filterConfig): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $publishedAt = $item['published_at'];

            if (! $publishedAt instanceof CarbonImmutable) {
                continue;
            }

            $categories = $item['categories'];
            $primaryCategory = $categories[0] ?? 'Uncategorized';
            $summary = $this->applyContentRemovals($item['summary'], $filterConfig);

            $responseItem = [
                'title' => $item['title'],
                'link' => $item['link'],
                'summary' => $summary,
                'published_at' => $publishedAt->toIso8601String(),
                'categories' => $item['categories'],
                'author' => $item['author'],
                'guid' => $item['guid'],
                'image' => $item['image'],
            ];

            $grouped[$primaryCategory][] = $responseItem;
        }

        foreach ($grouped as $category => $entries) {
            usort($entries, fn (array $left, array $right): int => strcmp(
                $right['published_at'],
                $left['published_at']
            ));

            $grouped[$category] = $entries;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        return $grouped;
    }

    /**
     * @param  array<int, string>  $filters
     * @return array<string, mixed>
     */
    private function parseFilters(array $filters): array
    {
        $config = [
            'includeCategories' => [],
            'excludeCategories' => [],
            'includeAuthors' => [],
            'excludeAuthors' => [],
            'includeSummary' => [],
            'excludeSummary' => [],
            'includeSummaryRegex' => [],
            'excludeSummaryRegex' => [],
            'removeText' => [],
            'removeRegex' => [],
        ];

        foreach ($filters as $filter) {
            if (! is_string($filter)) {
                continue;
            }

            $filter = trim($filter);

            if ($filter === '') {
                continue;
            }

            if (preg_match('/^([+-])\\s*#(?:"([^"]+)"|(.+))$/', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[2] ?? $matches[3] ?? '');
                $key = $matches[1] === '+' ? 'includeCategories' : 'excludeCategories';
                if ($value !== '') {
                    $config[$key][] = $value;
                }

                continue;
            }

            if (preg_match('/^([+-])\\s*author:(?:"([^"]+)"|(.+))$/i', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[2] ?? $matches[3] ?? '');
                $key = $matches[1] === '+' ? 'includeAuthors' : 'excludeAuthors';
                if ($value !== '') {
                    $config[$key][] = $value;
                }

                continue;
            }

            if (preg_match('/^([+-])\\s*summary-regex:(?:"(.+)"|(.+))$/i', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[2] ?? $matches[3] ?? '');
                $key = $matches[1] === '+' ? 'includeSummaryRegex' : 'excludeSummaryRegex';
                if ($value !== '') {
                    $config[$key][] = $value;
                }

                continue;
            }

            if (preg_match('/^([+-])\\s*summary:(?:"([^"]+)"|(.+))$/i', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[2] ?? $matches[3] ?? '');
                $key = $matches[1] === '+' ? 'includeSummary' : 'excludeSummary';
                if ($value !== '') {
                    $config[$key][] = $value;
                }

                continue;
            }

            if (preg_match('/^remove-regex:(?:"(.+)"|(.+))$/i', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[1] ?? $matches[2] ?? '');
                if ($value !== '') {
                    $config['removeRegex'][] = $value;
                }

                continue;
            }

            if (preg_match('/^remove:(?:"([^"]+)"|(.+))$/i', $filter, $matches)) {
                $value = $this->trimFilterValue($matches[1] ?? $matches[2] ?? '');
                if ($value !== '') {
                    $config['removeText'][] = $value;
                }

                continue;
            }
        }

        return $config;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filterConfig
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $items, array $filterConfig): array
    {
        $includeCategories = $this->normalizeFilterValues($filterConfig['includeCategories'] ?? []);
        $excludeCategories = $this->normalizeFilterValues($filterConfig['excludeCategories'] ?? []);
        $includeAuthors = $this->normalizeFilterValues($filterConfig['includeAuthors'] ?? []);
        $excludeAuthors = $this->normalizeFilterValues($filterConfig['excludeAuthors'] ?? []);
        $includeSummary = $this->normalizeFilterValues($filterConfig['includeSummary'] ?? []);
        $excludeSummary = $this->normalizeFilterValues($filterConfig['excludeSummary'] ?? []);
        $includeSummaryRegex = $filterConfig['includeSummaryRegex'] ?? [];
        $excludeSummaryRegex = $filterConfig['excludeSummaryRegex'] ?? [];

        $requireCategoryMatch = $includeCategories !== [];
        $requireAuthorMatch = $includeAuthors !== [];
        $requireSummaryMatch = $includeSummary !== [] || $includeSummaryRegex !== [];

        $filtered = [];

        foreach ($items as $item) {
            $categories = array_map(
                fn (string $value): string => mb_strtolower($value),
                $item['categories'] ?? []
            );
            $author = mb_strtolower((string) ($item['author'] ?? ''));
            $summary = (string) ($item['summary'] ?? '');
            $summaryLower = mb_strtolower($summary);

            if ($requireCategoryMatch && ! $this->matchesAny($categories, $includeCategories)) {
                continue;
            }

            if ($excludeCategories !== [] && $this->matchesAny($categories, $excludeCategories)) {
                continue;
            }

            if ($requireAuthorMatch && ! $this->matchesTextAny($author, $includeAuthors)) {
                continue;
            }

            if ($excludeAuthors !== [] && $this->matchesTextAny($author, $excludeAuthors)) {
                continue;
            }

            if ($requireSummaryMatch && ! $this->matchesSummary($summaryLower, $includeSummary, $includeSummaryRegex)) {
                continue;
            }

            if ($this->matchesSummary($summaryLower, $excludeSummary, $excludeSummaryRegex)) {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalizeFilterValues(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value): string => mb_strtolower(trim($value)),
            $values
        ), fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<int, string>  $haystack
     * @param  array<int, string>  $needles
     */
    private function matchesAny(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            foreach ($haystack as $value) {
                if (str_contains($value, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matchesTextAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $summaryTerms
     * @param  array<int, string>  $summaryRegex
     */
    private function matchesSummary(string $summaryLower, array $summaryTerms, array $summaryRegex): bool
    {
        if ($this->matchesTextAny($summaryLower, $summaryTerms)) {
            return true;
        }

        foreach ($summaryRegex as $pattern) {
            if ($this->matchesRegex($summaryLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesRegex(string $text, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        return @preg_match('~'.$pattern.'~i', $text) === 1;
    }

    private function applyContentRemovals(string $summary, array $filterConfig): string
    {
        $result = $summary;

        foreach (($filterConfig['removeText'] ?? []) as $value) {
            $result = str_ireplace((string) $value, '', $result);
        }

        foreach (($filterConfig['removeRegex'] ?? []) as $pattern) {
            $result = (string) @preg_replace('~'.$pattern.'~i', '', $result);
        }

        return $result;
    }

    private function trimFilterValue(string $value): string
    {
        return trim($value);
    }

    private function parseDate(string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
