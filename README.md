# Google Trends Importer

## Overview

Automatically fetches daily Google Trends, scrapes related news articles with images and videos, uses OpenAI or Claude AI to generate high-quality content with AI-powered auto-tagging, and publishes articles to your Drupal site. Features intelligent media extraction, video embedding, domain assignment, cost tracking, traffic filtering, and queue-based processing for reliability.

## Features

* **Dual AI Provider Support** - Choose between OpenAI or Anthropic Claude 🆕
* **Flexible Content Type Support** - Works with any content type
* **AI-Powered Auto-Tagging** - AI selects relevant tags from your vocabulary
* **Intelligent Image Extraction** - Downloads images from article bodies, sorted by resolution
* **Video Embedding** - Extracts YouTube/Vimeo videos with automatic thumbnail generation
* **Domain Assignment** - Automatically assign articles to Drupal Domain module domains 🆕
* **Smart File Naming** - Images named using article slugs (e.g., `article-slug.jpg`, `article-slug-1.jpg`)
* **Cost Tracking** - Tracks AI API costs per article (OpenAI & Claude)
* **Traffic Filtering** - Only process trends above minimum threshold
* **Rate Limiting** - Control trends processed per cron run
* **Model Selection** - Choose from GPT-5, GPT-4o, Claude 3.5 Sonnet, Claude 3.5 Haiku, and more
* **Queue-Based Processing** - Reliable background processing
* **Content Scraping** - Uses Readability algorithm for clean content
* **Full Logging** - Debug prompts and responses in dblog

## Requirements

* Drupal 10 or 11
* PHP 8.1 or higher (PHP 8.3+ recommended)
* A content type with:
  - Image field (for article images and video thumbnails)
  - Body field with 'full_html' format enabled (for video embeds)
  - Optional: Taxonomy reference field for tags
* A taxonomy vocabulary for auto-tagging (recommended)
* Valid **OpenAI API Key** from https://platform.openai.com/api-keys OR **Claude API Key** from https://console.anthropic.com/
* Composer for dependency management
* Optional: Domain module for multi-domain support

## Installation

### 1. Install Dependencies

From your Drupal project root:

```bash
# Required dependencies (always needed)
composer require symfony/dom-crawler:"^6.4 || ^7.0"
composer require symfony/css-selector:"^6.4 || ^7.0"
composer require fivefilters/readability.php:"^3.0"

# Choose your AI provider (install at least one):

# Option A: OpenAI (GPT models)
composer require openai-php/client:"^0.3.1"

# Option B: Claude (no additional dependencies needed - uses built-in HTTP client)
# Nothing to install! Just enable the module and configure Claude API key

# Optional: For domain assignment
composer require drupal/domain
drush en domain -y
```

**Important Notes:**
- **Claude AI** uses Drupal's built-in HTTP client - no additional dependencies required
- **OpenAI** requires the `openai-php/client` library - install it if using OpenAI models
- You must install OpenAI library if using OpenAI as provider, or you'll get a fatal error
- Videos are automatically embedded in the article body HTML - no video_embed_field module needed

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

### AI Provider Selection

**AI Provider** (required): Choose between OpenAI or Anthropic Claude

### OpenAI Settings

Shows when OpenAI is selected as provider.

**API Key** (required): Get from https://platform.openai.com/api-keys

**Model** (required):
* **GPT-5** - Next generation, highest capability (~$0.10-0.30/article)
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

### Claude Settings 🆕

Shows when Claude is selected as provider.

**API Key** (required): Get from https://console.anthropic.com/

**Model** (required):
* **Claude 3.5 Sonnet** - Latest, best for most tasks (~$0.04-0.20/article) ⭐ Recommended
* **Claude 3.5 Haiku** - Fastest, most cost-effective (~$0.02-0.08/article)
* Claude 3 Opus - Most capable, highest cost (~$0.20-1.00/article)
* Claude 3 Sonnet - Balanced performance (~$0.04-0.20/article)
* Claude 3 Haiku - Fast and efficient (~$0.005-0.025/article)

**Prompt Template** (required): Same format as OpenAI - uses 3 placeholders and same separators

### Content Type Settings

* **Content Type**: Which type to create (default: Article)
* **Image Field**: Where to attach images from articles and video thumbnails (AJAX updates on content type change)
* **Tag Field**: Taxonomy field for auto-tagging (AJAX updates)

**Media Processing:**
- Automatically extracts images from article bodies
- Downloads images and names them using article slug (e.g., `slug.jpg`, `slug-1.jpg`, `slug-2.jpg`)
- Sorts images by resolution (largest first)
- Extracts YouTube/Vimeo video iframes and embeds them in article body HTML 🆕
- Downloads maximum resolution video thumbnails
- Video thumbnail placed as first image
- Videos embedded with responsive 16:9 aspect ratio
- Supports up to 10 images per article

### Taxonomy Settings

**Tag Vocabulary**: Select vocabulary for auto-tagging. The AI will receive all terms from this vocabulary and select the most relevant ones for each article.

### Domain Settings 🆕

**Domain** (optional): Assign all imported articles to a specific domain. Only shows if Domain module is enabled and domains are configured.

The module automatically sets:
- `field_domain_access` - Controls which domain can access the content
- `field_domain_source` - Indicates the primary domain for the content

Leave empty to not assign any domain.

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
- **Extracts images from article HTML bodies**
- **Sorts images by resolution (largest first)**
- **Extracts YouTube/Vimeo video embeds**
- **Downloads video thumbnails (max resolution)**
- Loads vocabulary tags
- Sends to OpenAI or Claude with complete prompt
- Calculates and stores cost
- Parses response (title, body, tags)
- **Embeds video in article body HTML if found** 🆕
- **Downloads and attaches all images with slug-based naming**
- Creates/finds taxonomy terms
- **Assigns to selected domain if configured**
- Creates published article node with all fields

## Usage

### View Imported Trends
`/admin/content/imported-trends`

Shows: Title, Traffic (K), Published, Imported, Cost, Article link

### Review AI Prompts and Responses

The module logs all prompts sent to your AI provider (OpenAI or Claude) and responses received. This helps you:
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
- **Info**: "Sending prompt to [OpenAI/Claude] for Trend ID X"
- **Debug**: Full prompt with all content, tags, and instructions
- **Debug**: Full AI response with title, body, and selected tags
- **Info**: Processing results (success, cost, node created, domain assigned)

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

**OpenAI Models:**

| Model | Per Article | 120/day | 240/day |
|-------|-------------|---------|---------|
| GPT-4o Mini | $0.003-0.01 | $0.36-1.20 | $0.72-2.40 |
| GPT-5 | $0.10-0.30 | $12-36 | $24-72 |
| O1 Mini | $0.06-0.18 | $7.20-21.60 | $14.40-43.20 |
| GPT-4o | $0.05-0.15 | $6-18 | $12-36 |
| O1 Preview | $0.30-0.90 | $36-108 | $72-216 |

**Claude Models:**

| Model | Per Article | 120/day | 240/day |
|-------|-------------|---------|---------|
| Claude 3 Haiku | $0.005-0.025 | $0.60-3.00 | $1.20-6.00 |
| Claude 3.5 Haiku | $0.02-0.08 | $2.40-9.60 | $4.80-19.20 |
| Claude 3.5 Sonnet | $0.04-0.20 | $4.80-24.00 | $9.60-48.00 |
| Claude 3 Sonnet | $0.04-0.20 | $4.80-24.00 | $9.60-48.00 |
| Claude 3 Opus | $0.20-1.00 | $24-120 | $48-240 |

### Control Costs

1. Use GPT-4o Mini (OpenAI) or Claude 3 Haiku (Claude) for best value ⭐
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
- Check AI API key is valid (OpenAI or Claude)
- Review logs for errors

**Domain not assigned**
- Verify Domain module is enabled
- Check domain is selected in settings
- Ensure content type has `field_domain_access` and `field_domain_source` fields

**Error: Class "OpenAI" not found**
- This means you selected OpenAI as provider but haven't installed the OpenAI library
- Fix: Run `composer require openai-php/client:"^0.3.1"`
- Alternative: Switch to Claude provider (no additional library needed)
- Check logs at `/admin/reports/dblog` for the error message

## Advanced Configuration

### Custom Content Types

Works with any content type:
1. Create/select your content type
2. Add image field
3. Ensure body field has 'full_html' format enabled for video embeds
4. Add taxonomy reference field (optional)
5. Configure in module settings

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

### 2.1.0 🆕
* **Claude AI support** - Choose between OpenAI or Anthropic Claude as AI provider
* **Domain module integration** - Automatically assign articles to domains
* **Video embedding in body** - Videos now embedded directly in article body HTML (no video_embed_field module needed)
* Claude 3.5 Sonnet and Claude 3.5 Haiku model support
* Separate prompt templates for OpenAI and Claude
* Cost tracking for both OpenAI and Claude
* Provider-aware logging (logs which AI provider was used)
* Responsive video embeds with 16:9 aspect ratio
* No additional dependencies required for Claude (uses HTTP client)

### 2.0.0
* AI-powered auto-tagging with vocabulary selection
* Intelligent image extraction from article bodies
* Video embedding support (YouTube/Vimeo)
* Automatic video thumbnail download
* Slug-based file naming
* Image sorting by resolution
* GPT-5 model support
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

**Quick Start:** Enable module → Install domain module (optional) → Choose AI provider (OpenAI or Claude) → Add API key → Select model → Configure image/tag/domain fields → Configure vocabulary → Save → Click "Fetch Now" → Check `/admin/content/imported-trends` → Review articles with embedded videos and images!