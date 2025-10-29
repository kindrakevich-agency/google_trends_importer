# Google Trends Importer

## Overview

Automatically fetches daily Google Trends, scrapes related news articles with images and videos, uses OpenAI to generate high-quality content with AI-powered auto-tagging, and publishes articles to your Drupal site. Features intelligent media extraction, video embedding, cost tracking, traffic filtering, and queue-based processing for reliability.

## Features

* **Flexible Content Type Support** - Works with any content type
* **AI-Powered Auto-Tagging** - ChatGPT selects relevant tags from your vocabulary
* **Intelligent Image Extraction** - Downloads images from article bodies, sorted by resolution
* **Video Embedding** - Extracts YouTube/Vimeo videos with automatic thumbnail generation
* **Smart File Naming** - Images named using article slugs (e.g., `article-slug.jpg`, `article-slug-1.jpg`)
* **Cost Tracking** - Tracks OpenAI API costs per article
* **Traffic Filtering** - Only process trends above minimum threshold
* **Rate Limiting** - Control trends processed per cron run
* **Model Selection** - Choose from GPT-5, GPT-4o, O1, and more
* **Queue-Based Processing** - Reliable background processing
* **Content Scraping** - Uses Readability algorithm for clean content
* **Full Logging** - Debug prompts and responses in dblog

## Requirements

* Drupal 10 or 11
* PHP 8.1 or higher (PHP 8.3+ recommended)
* A content type with:
  - Image field (for article images and video thumbnails)
  - Optional: Video embed field (video_embed_field module)
  - Optional: Taxonomy reference field for tags
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

# Optional: For video embedding
composer require drupal/video_embed_field
drush en video_embed_field -y
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
* **GPT-5** - Next generation, highest capability (~$0.10-0.30/article) 🆕
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
* **Image Field**: Where to attach images from articles and video thumbnails (AJAX updates on content type change)
* **Video Field**: Video embed field for YouTube/Vimeo videos (requires video_embed_field module) 🆕
* **Tag Field**: Taxonomy field for auto-tagging (AJAX updates)

**Media Processing:**
- Automatically extracts images from article bodies
- Downloads images and names them using article slug (e.g., `slug.jpg`, `slug-1.jpg`, `slug-2.jpg`)
- Sorts images by resolution (largest first)
- Extracts YouTube/Vimeo video iframes
- Downloads maximum resolution video thumbnails
- Video thumbnail placed as first image
- Supports up to 10 images per article

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
- **Extracts images from article HTML bodies** 🆕
- **Sorts images by resolution (largest first)** 🆕
- **Extracts YouTube/Vimeo video embeds** 🆕
- **Downloads video thumbnails (max resolution)** 🆕
- Loads vocabulary tags
- Sends to OpenAI with complete prompt
- Calculates and stores cost
- Parses response (title, body, tags)
- **Downloads and attaches all images with slug-based naming** 🆕
- **Attaches video embed if found** 🆕
- Creates/finds taxonomy terms
- Creates published article node with all fields

## Usage

### View Imported Trends
`/admin/content/imported-trends`

Shows: Title, Traffic (K), Published, Imported, Cost, Article link

### Review AI Prompts and Responses

The module logs all prompts sent to OpenAI and responses received. This helps you:
- Verify what's being sent to the AI
- Debug issues with generated content
- Refine your prompt template
- Audit AI interactions

**View logs:**
1. Go to `/admin/reports/dblog`
2. Filter by "google_trends_importer"
3. Look for entries marked "Debug" - these contain full prompts/responses
4. Click "View" on any entry to see the complete prompt or response

**Via Drush:**
```bash
# View recent logs
drush watchdog:show --type=google_trends_importer

# View only debug logs (prompts/responses)
drush watchdog:show --type=google_trends_importer --severity=Debug

# Search for specific trend
drush watchdog:show --type=google_trends_importer | grep "Trend ID 123"
```

**Log entries include:**
- **Info**: "Sending prompt to OpenAI for Trend ID X"
- **Debug**: Full prompt with all content, tags, and instructions
- **Debug**: Full AI response with title, body, and selected tags
- **Info**: Processing results (success, cost, node created)

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
| GPT-5 | $0.10-0.30 | $12-36 | $24-72 |
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
* **Intelligent image extraction from article bodies** 🆕
* **Video embedding support (YouTube/Vimeo)** 🆕
* **Automatic video thumbnail download** 🆕
* **Slug-based file naming** 🆕
* **Image sorting by resolution** 🆕
* **GPT-5 model support** 🆕
* Cost tracking per article
* Traffic filtering (int field, min threshold)
* Max trends limit per run
* O1 Preview and O1 Mini model support
* Flexible content type configuration
* Unified prompt template
* Fixed Views with proper date handling
* Full prompt/response logging
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

**Quick Start:** Enable module → Install video_embed_field (optional) → Add API key → Select model → Configure image/video/tag fields → Configure vocabulary → Save → Click "Fetch Now" → Check `/admin/content/imported-trends` → Review articles with images and videos!