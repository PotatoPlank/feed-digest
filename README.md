# Daily Feed Digest

Daily Feed Digest turns an RSS or Atom feed into daily digests. It exposes a public RSS feed (one item per day), HTML digests for specific dates, and a small authenticated API to manage digest definitions.

## Disclaimer

The majority of this codebase was written with AI assistance and is provided as-is without any guarantees. While I may update it in the future, I do not intend to maintain it and will not be responsible for any damages.

## Features

- Aggregate RSS or Atom feeds into daily digests grouped by category.
- Public RSS output with one item per day.
- HTML view for a specific date with a clean digest layout.
- Token-protected API for managing digests.
- Optional filter rules for categories, authors, and summaries.
- Cached outputs with configurable TTL.

## Requirements

- PHP 8.4+
- Composer
- Node.js + npm
- SQLite (default) or another supported database

## Setup

1. Install dependencies:

```bash
composer install
npm install
```

2. Configure environment:

```bash
cp .env.example .env
php artisan key:generate
```

3. Update `.env` with at least:

- `APP_URL` (used to build public feed links)
- `APP_NAME` (used as a fallback title)
- `FEED_API_TOKEN` (required for API access)

4. Run migrations and build assets:

```bash
php artisan migrate
npm run build
```

Alternatively, you can run the scripted setup:

```bash
composer run setup
```

## Running Locally

```bash
composer run dev
```

This runs the PHP server, queue listener, Laravel Pail logs, and Vite concurrently. If you want to run them separately:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
npm run dev
```

## API Authentication

All `/api` routes require the `FEED_API_TOKEN` value. Provide it via:

- `Authorization: Bearer <token>` header, or
- `?token=<token>` query parameter

## API Endpoints

### List digests

`GET /api/digests`

### Create a digest

`POST /api/digests`

Payload:

```json
{
  "feed_url": "https://example.com/feed.xml",
  "name": "My Digest",
  "timezone": "UTC",
  "filters": ["+#\"gaming\"", "-author:\"bob smith\""]
}
```

### Update a digest

`PUT /api/digests/{uuid}`

Payload fields are the same as `POST /api/digests`, but all are optional.

## Public Feeds

- `GET /feed/{uuid}` returns an RSS feed (one item per day).
- `GET /feed/{uuid}/{YYYY-MM-DD}` returns an HTML digest for the specified date.

Optional query parameter:

- `name` overrides the digest title in the RSS channel and HTML page.

## Filter Rules

Filters are case-insensitive and applied in order when aggregating items.

- `+#"Category"` include categories that contain the value
- `-#"Category"` exclude categories that contain the value
- `+author:"Name"` include authors that contain the value
- `-author:"Name"` exclude authors that contain the value
- `+summary:"text"` include summaries containing the text
- `-summary:"text"` exclude summaries containing the text
- `+summary-regex:"pattern"` include summaries matching the regex
- `-summary-regex:"pattern"` exclude summaries matching the regex
- `remove:"text"` remove text from summaries
- `remove-regex:"pattern"` remove regex matches from summaries

Examples:

```text
+#"gaming"
-author:"bob smith"
-summary-regex:"banned"
remove:"Thank you for reading this post."
remove-regex:"Secret Regex Example"
```

## Caching

Digest outputs are cached in `storage/app/digests` using the TTL defined in `config/digest.php`.

- Set `cache.ttl` to `0` to disable caching.
- Supported units: `minutes`, `hours`, `days`.

## Testing

```bash
php artisan test
```
