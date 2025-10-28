# Google Trends Importer

## Overview

Automatically fetches daily Google Trends, scrapes related news articles, uses OpenAI to generate high-quality content with AI-powered auto-tagging, and publishes articles to your Drupal site. Features cost tracking, traffic filtering, flexible content types, and queue-based processing for reliability.

## Requirements

* Drupal 10 or 11
* PHP 8.1 or higher (PHP 8.3+ recommended)
* A content type with an image field and optional taxonomy reference field
* A taxonomy vocabulary for auto-tagging (recommended)
* Valid **OpenAI API Key** from https://platform.openai.com/api-keys
* Composer for dependency management

## Installation

### 1. Install Dependencies

From your Drupal project root:

```bash
composer require openai-php/client:"^0.3.1"
composer require symfony/dom-crawler:"^6.4 || ^7.0"
composer require symfony/css-selector:"^6.4 || ^7.0"
composer require fivefilters/readability.php:"^3.0"
```

### 2. Enable Module

```bash
# Via Drush
drush en google_trends_importer -y

# Or via UI: /admin/extend
```

### 3. Run Updates (If Upgrading)

```bash
drush updb -y
drush cr
```

### 4. Update Views (If Upgrading from v1.x)

Choose one method:

```bash
# Method A: Command line (fastest)
drush config:import --partial --source=modules/custom/google_trends_importer/config/install

# Method B: Delete and recreate
drush config:delete views.view.imported_trends
drush pm:uninstall google_trends_importer -y && drush pm:enable google_trends_importer -y
```

**Method C: Manual UI Update** (if commands fail):
1. Go to `/admin/structure/views`
2. Edit "Imported Trends"
3. Update Traffic field → Type: Numeric, Suffix: "K+"
4. Add fields: Published (pub_date), Imported (imported_at), Cost (processing_cost with $ prefix)
5. Enable sorting on all fields
6. Save

Verify at `/admin/content/imported-trends`: Traffic shows "100K+", dates formatted, cost shows "$0.0050"

## Configuration

Go to: `/admin/config/system/google-trends-importer`

### OpenAI Settings

**API Key** (required): Get from https://platform.openai.com/api-keys

**Model** (required):
* **GPT-4o Mini** - Best balance (~$0.003-0.01/article) ⭐ Recommended
* GPT-4o - Highest quality (~$0.05-0.15/article)
* O1 Mini - Fast reasoning (~$0.06-0.18/article)
* O1 Preview - Advanced reasoning (~$0.30-0.90/article)
* GPT-4 Turbo - Previous gen (~$0.10-0.30/article)
* GPT-3.5 Turbo - Cheapest (~$0.01-0.03/article)

**Prompt Template** (required): Uses 3 placeholders:
1. `%s` - Trend title
2. `%s` - Article content
3. `%s` - Available tags

Must include separators: `---TITLE_SEPARATOR---` and `---TAGS_SEPARATOR---`

### Content Type Settings

* **Content Type**: Which type to create (default: Article)
* **Image Field**: Where to attach images (AJAX updates on content type change)
* **Tag Field**: Taxonomy field for auto-tagging (AJAX updates)

### Taxonomy Settings

**Tag Vocabulary**: Select vocabulary for auto-tagging. ChatGPT will receive all terms from this vocabulary and select the most relevant ones for each article.

### Feed Settings

* **RSS URL**: Default `https://trends.google.com/trending/rss?geo=US` (change `geo=` for other regions)
* **Minimum Traffic**: Only process trends above this threshold (in thousands, e.g., 100 = 100K+)
* **Maximum Trends**: Limit per cron run (default: 5, prevents timeouts)
* **Enable Cron**: Auto-fetch on cron runs

## How It Works

**Step 1: Fetcher** (runs on cron)
- Fetches Google Trends RSS feed
- Parses traffic to integers
- Filters by minimum traffic
- Stops at max_trends limit
- Saves to database and queues for processing

**Step 2: Queue Worker** (background)
- Scrapes article content using Readability
- Loads vocabulary tags
- Sends to OpenAI with complete prompt
- Calculates and stores cost
- Parses response (title, body, tags)
- Downloads image
- Creates/finds taxonomy terms
- Creates published article node with all fields

## Usage

### View Imported Trends
`/admin/content/imported-trends`

Shows: Title, Traffic (K), Published, Imported, Cost, Article link

### Monitor Costs

```bash
# Total cost
drush sqlq "SELECT SUM(processing_cost) FROM google_trends_data WHERE processed = 1"

# Last 24 hours
drush sqlq "SELECT SUM(processing_cost) FROM google_trends_data WHERE imported_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))"
```

### Manual Processing

```bash
# Fetch trends now (via settings page button or):
drush queue:run google_trends_processor
```

## Cost Management

### Estimates

| Model | Per Article | 120/day | 240/day |
|-------|-------------|---------|---------|
| GPT-4o Mini | $0.003-0.01 | $0.36-1.20 | $0.72-2.40 |
| O1 Mini | $0.06-0.18 | $7.20-21.60 | $14.40-43.20 |
| GPT-4o | $0.05-0.15 | $6-18 | $12-36 |
| O1 Preview | $0.30-0.90 | $36-108 | $72-216 |

### Control Costs

1. Use GPT-4o Mini (recommended)
2. Set `max_trends` to 5 or less
3. Set `min_traffic` to filter low-value trends
4. Monitor costs in view for first week
5. Adjust model based on quality needs

## Troubleshooting

**No trends importing**
- Check cron is running: `drush core-cron`
- Lower or remove min_traffic threshold
- Check logs: `/admin/reports/dblog`

**Tags not attaching**
- Verify vocabulary is selected
- Check tag field is configured
- Ensure content type has taxonomy field

**High costs**
- Switch to GPT-4o Mini
- Reduce max_trends
- Increase min_traffic

**Queue stuck**
- Run manually: `drush queue:run google_trends_processor`
- Check OpenAI API key is valid
- Review logs for errors

## Advanced Configuration

### Custom Content Types

Works with any content type:
1. Create/select your content type
2. Add image field
3. Add taxonomy reference field
4. Configure in module settings

### Custom Prompts

Edit prompt template using 3 placeholders (in order):

```
You are [role]. Generate article about: '%s'

Source content: %s
Available tags: %s

Requirements:
- [Your requirements]
- Select 3-5 relevant tags

Separators:
---TITLE_SEPARATOR---
---TAGS_SEPARATOR---
```

## Upgrading from v1.x

1. Backup database
2. Replace module files
3. Run: `drush updb -y && drush cr`
4. Update Views (see Installation section)
5. Update prompt template to include 3rd `%s` for tags
6. Configure: max_trends, vocabulary, tag field

## API Reference

**Services:**
- `google_trends_importer.fetcher` - Fetches trends from RSS

**Queue Workers:**
- `google_trends_processor` - Processes individual trends

**Database Tables:**
- `google_trends_data` - Main trends with traffic (int), cost (numeric)
- `google_trends_news_items` - Related news articles

## Version History

### 2.0.0
* AI-powered auto-tagging with vocabulary selection
* Cost tracking per article
* Traffic filtering (int field, min threshold)
* Max trends limit per run
* O1 Preview and O1 Mini model support
* Flexible content type configuration
* Unified prompt template
* Fixed Views with all fields
* Comprehensive documentation

### 1.0.0
* Initial release

## Support

Check Drupal logs: `/admin/reports/dblog`
Test manually: Settings page → "Fetch Now" button
Queue status: `drush queue:list`

## License

GPL-2.0-or-later

---

**Quick Start:** Enable module → Add API key → Select model → Configure vocabulary → Save → Click "Fetch Now" → Check `/admin/content/imported-trends`