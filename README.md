### 2.0.0
* Added flexible content type support
* Added AI-powered auto-tagging
* Added cost tracking per article
* Added traffic filtering
* Added max trends limit
* Added O1 Preview and O1 Mini model support
* Added model selector dropdown
* Unified prompt template (removed separate tags_instruction)
* Fixed Views configuration
* Changed traffic to integer field
* Update# Google Trends Importer

## Overview

This module fetches daily top Google Trends from their RSS feed, scrapes the content of related news articles, uses the OpenAI API to rewrite and synthesize this content into a new article with AI-powered auto-tagging, and saves it as a **published** Drupal node for review.

It is designed to be a robust, automated content creation assistant with cost tracking, flexible content type configuration, and intelligent tag selection. It uses Drupal's Queue API to handle the heavy lifting (scraping and AI processing) in the background, preventing timeouts during cron runs.

## Features

* **Flexible Content Type Support** - Works with any content type, not just 'Article'
* **AI-Powered Auto-Tagging** - ChatGPT automatically selects relevant tags from your vocabulary
* **Cost Tracking** - Tracks OpenAI API costs per article for budget management
* **Traffic Filtering** - Only process trends above a minimum traffic threshold
* **Rate Limiting** - Control how many trends are processed per cron run
* **Model Selection** - Choose from GPT-4o, GPT-4o Mini, GPT-4 Turbo, GPT-3.5 Turbo
* **Queue-Based Processing** - Reliable background processing using Drupal's Queue API
* **Content Scraping** - Uses Readability algorithm to extract clean article content
* **Image Management** - Automatically downloads and attaches trend images
* **Admin View** - Comprehensive view showing all imported trends with cost and traffic data
* **Customizable Prompts** - Fully customizable OpenAI prompts and tag selection instructions

## Requirements

* Drupal 10 or 11
* PHP 7.4 or higher
* A content type with:
  - An image field (e.g., `field_image`)
  - Optional: A taxonomy reference field for tags
* A taxonomy vocabulary for auto-tagging (optional but recommended)
* A text format (e.g., `full_html` or `basic_html`)
* A valid **OpenAI API Key** from https://platform.openai.com/api-keys
* Composer to manage dependencies

## Installation

### 1. Place the Module
Copy the `google_trends_importer` directory into your project's `/modules/custom` folder.

### 2. Install Composer Dependencies
This module requires several external PHP libraries. Navigate to your Drupal project's **root directory** (the one with the main `composer.json` file) and run the following commands one by one:

```bash
# From your Drupal project root
composer require openai-php/client:"^0.3.1"
composer require symfony/dom-crawler:"^6.4 || ^7.0"
composer require symfony/css-selector:"^6.4 || ^7.0"
composer require fivefilters/readability.php:"^3.0"
```

This will download the necessary libraries and update your project's main `composer.json` and `composer.lock` files.

### 3. Enable the Module
Log in as an administrator, go to **Extend** (`/admin/extend`), find "Google Trends Importer", and click **Install**.

Alternatively, use Drush:
```bash
drush en google_trends_importer -y
```

Enabling the module will automatically:
* Create two new database tables (`google_trends_data` and `google_trends_news_items`)
* Install the "Imported Trends" View (`/admin/content/imported-trends`)

### 4. Run Database Updates (If Upgrading)
If you're upgrading from an older version:
```bash
drush updb -y
drush cr
```

### 5. Update Views Configuration (If Upgrading from v1.x)
The Views configuration has been significantly updated. You have two options:

#### Option A: Automatic Update (Recommended)
```bash
# Import the updated view configuration
drush config:import --partial --source=modules/custom/google_trends_importer/config/install

# Or specifically import just the view
drush cim --partial views.view.imported_trends
```

#### Option B: Manual Update via UI
1. Go to **Structure > Views** (`/admin/structure/views`)
2. Find "Imported Trends" view and click **Edit**
3. Update the **Traffic** field:
   - Click on "Traffic" field
   - Change "Type" to "Numeric"
   - Set "Suffix" to "K+"
   - Under "Format settings" enable "Use thousands separator"
4. Add **Published Date** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Publication Date"
   - Select it and configure as "Date" format (short)
5. Add **Imported At** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Imported At"
   - Select it and configure as "Date" format (short)
6. Add **Processing Cost** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Processing Cost"
   - Select it and configure as "Numeric"
   - Set "Prefix" to "$"
   - Set "Precision" to 4
   - Enable "Use decimal point"
7. Click "Save" to apply changes

#### Option C: Delete and Reinstall View
If you encounter issues:
```bash
# Delete the old view
drush config:delete views.view.imported_trends

# Reinstall the module (this will recreate the view)
drush pm:uninstall google_trends_importer -y
drush pm:enable google_trends_importer -y

# Note: This will NOT delete your data, only the view configuration
```

## Configuration

**You must configure the module before it will work.**

Go to: **Configuration > System > Google Trends Importer** (`/admin/config/system/google-trends-importer`)

### OpenAI Settings

#### API Key (Required)
Enter your OpenAI API key from https://platform.openai.com/api-keys

#### Model Selection (Required)
Choose which OpenAI model to use:
* **GPT-4o** - Most capable, higher cost (~$0.05-0.15 per article)
* **GPT-4o Mini** - Balanced performance and cost (~$0.003-0.01 per article) **[Recommended]**
* **O1 Preview** - Advanced reasoning for complex topics (~$0.30-0.90 per article)
* **O1 Mini** - Fast reasoning, good for analytical content (~$0.06-0.18 per article)
* **GPT-4 Turbo** - Previous generation, powerful (~$0.10-0.30 per article)
* **GPT-4** - Legacy, slower (~$0.30-0.60 per article)
* **GPT-3.5 Turbo** - Fastest, lowest cost (~$0.01-0.03 per article)

#### Prompt Template (Required)
The main instructions for ChatGPT. Use `%s` placeholders for:
1. First `%s` - The trend title
2. Second `%s` - The combined news article content
3. Third `%s` - Available tags (when vocabulary is configured)

Include separators:
* `---TITLE_SEPARATOR---` (between title and body)
* `---TAGS_SEPARATOR---` (between body and tags)

### Content Type Settings

#### Content Type (Required)
Select which content type to use for generated articles (e.g., "Article", "Blog Post", etc.)

#### Image Field
Select the image field for your content type. This field will be populated with the trend's main image.

#### Tag Field
Select the taxonomy reference field for auto-tagging. This should reference your tag vocabulary.

### Taxonomy Settings

#### Tag Vocabulary
Select a taxonomy vocabulary for auto-tagging. ChatGPT will select the most relevant tags from this vocabulary based on the article content.

**How it works:**
1. All terms from the selected vocabulary are sent to ChatGPT (as the third %s placeholder in the prompt)
2. ChatGPT analyzes the article and selects relevant tags
3. Selected tags are automatically attached to the article
4. If a selected tag doesn't exist, it's created automatically

### Feed Settings

#### Google Trends RSS URL (Required)
The RSS feed URL for daily trends. Default: `https://trends.google.com/trending/rss?geo=US`

You can change the `geo` parameter for different regions:
* US: `geo=US`
* UK: `geo=GB`
* India: `geo=IN`
* Global: Remove the `geo` parameter

#### Minimum Traffic (Optional)
Only process trends with at least this many searches (in thousands). For example:
* `100` = Only trends with 100K+ searches
* Leave empty to process all trends

This helps focus on popular trends and reduce API costs.

#### Maximum Trends to Parse at Once (Required)
Default: **5**

Limits how many trends are fetched and queued in a single cron run. This:
* Prevents timeouts on slow servers
* Controls API costs per hour
* Allows gradual processing

**Examples:**
* `5` trends/hour × 24 hours = 120 articles/day
* `10` trends/hour × 24 hours = 240 articles/day

#### Enable Automatic Fetching via Cron
Check this to automatically fetch new trends when Drupal's cron runs. Uncheck to disable automatic processing (you can still fetch manually).

### Action Buttons

#### Manually Fetch New Trends Now
Click this button to immediately fetch new trends without waiting for cron. Useful for:
* Testing the configuration
* Getting started quickly
* Manually triggering updates

#### Clear Imported Data
Permanently deletes all data from the trends tables and clears the processing queue. **Use with caution** - this cannot be undone.

## How It Works

The module operates in a two-step process to ensure stability and prevent timeouts.

### Step 1: Cron Run (The Fetcher)

1. Drupal's main cron job is triggered (e.g., via `drush core-cron` or a server crontab)
2. The `TrendsFetcher` service runs
3. It fetches the Google Trends RSS feed from the configured URL
4. For each trend item:
   - Parses traffic (e.g., "100K+" → integer 100)
   - Checks if traffic meets minimum threshold (if configured)
   - Checks if we've reached the maximum trends limit
   - Verifies the trend doesn't already exist (by title and pub date)
5. For each new trend:
   - Saves the main item to `google_trends_data`
   - Saves all related news items to `google_trends_news_items`
   - Adds the `trend_id` to the `google_trends_processor` queue
6. Stops processing when max_trends limit is reached
7. Logs summary (items imported, items skipped, limit reached)

This step is very fast (seconds) and won't timeout.

### Step 2: Queue Processing (The Worker)

1. Immediately after cron (or on its own schedule), Drupal's queue runner processes the `google_trends_processor` queue
2. The `ProcessTrend` QueueWorker picks up one `trend_id` at a time
3. **Scrape:** It scrapes the content from all related news URLs using the Readability algorithm to extract clean text
4. **Load Tags:** If a vocabulary is configured, it loads all available taxonomy terms
5. **Build Prompt:** Combines the trend title, scraped content, and available tags into a prompt using your templates
6. **Send to OpenAI:** Sends the prompt to OpenAI using the selected model
7. **Calculate Cost:** Tracks input/output tokens and calculates the API cost
8. **Parse Response:** Extracts the title, HTML body, and selected tags using the separators
9. **Download Image:** Downloads the trend's main image and saves it to Drupal
10. **Create Tags:** For each selected tag, finds the existing term or creates a new one
11. **Create Article:** Creates a new node with:
    - Content type from settings
    - Generated title
    - Generated HTML body
    - Attached image
    - Attached tags (if configured)
    - Publication date matching the trend's pub_date
    - Published status (status = 1)
12. **Update Database:** Marks the trend as processed and stores the node_id and processing_cost

Each trend is processed independently, so if one fails, others continue.

## Usage

After installation and configuration, the module runs automatically via cron.

### Viewing Imported Trends

Go to: **Content > Imported Trends** (`/admin/content/imported-trends`)

This view shows:
* **Trend Title** - The original search trend topic
* **Traffic (K)** - Approximate searches in thousands (e.g., "100K+")
* **Published** - When the trend was published by Google
* **Imported** - When the trend was imported to your site
* **Cost** - How much it cost to process with OpenAI (e.g., "$0.0050")
* **Article** - Link to view the generated article

The view is sortable by all columns and shows 25 items per page.

### Reviewing Articles

Click "View" next to any trend to see the generated article. You can:
* Edit the content if needed
* Add additional tags manually
* Change the featured image
* Unpublish if the quality isn't good
* Delete if unwanted

### Monitoring Costs

The view shows individual costs per article. To see total costs:

```bash
# Total cost for all processed trends
drush sqlq "SELECT SUM(processing_cost) as total FROM google_trends_data WHERE processed = 1"

# Cost in the last 24 hours
drush sqlq "SELECT SUM(processing_cost) as total FROM google_trends_data WHERE imported_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))"
```

### Manual Processing

To manually fetch trends (without waiting for cron):
1. Go to the settings page
2. Click "Manually Fetch New Trends Now"
3. Confirm the action

To manually process the queue:
```bash
drush queue:run google_trends_processor
```

## Cost Management

### Estimating Costs

| Model | Cost per Article | 120 articles/day | 240 articles/day |
|-------|------------------|------------------|------------------|
| GPT-4o Mini | $0.003-0.01 | $0.36-1.20/day | $0.72-2.40/day |
| GPT-3.5 Turbo | $0.01-0.03 | $1.20-3.60/day | $2.40-7.20/day |
| GPT-4o | $0.05-0.15 | $6-18/day | $12-36/day |
| O1 Mini | $0.06-0.18 | $7.20-21.60/day | $14.40-43.20/day |
| GPT-4 Turbo | $0.10-0.30 | $12-36/day | $24-72/day |
| O1 Preview | $0.30-0.90 | $36-108/day | $72-216/day |

**Recommendation:** Start with GPT-4o Mini and monitor quality. Upgrade to GPT-4o for better quality, or O1 models for complex/analytical topics.

### Controlling Costs

1. **Use GPT-4o Mini** - Best balance of quality and cost
2. **Set max_trends** - Limit articles per run (default: 5)
3. **Set min_traffic** - Only process popular trends
4. **Monitor the view** - Check costs daily for the first week
5. **Adjust cron frequency** - Run less often if needed

### Cost Tracking

Costs are automatically tracked in the database and visible in the admin view. The calculation includes:
* Input tokens (prompt size) × input price
* Output tokens (generated content) × output price
* Based on actual usage returned by OpenAI

## Troubleshooting

### No Trends Being Imported

**Check:**
1. Is cron running? `drush core-cron`
2. Is "Enable Cron Processing" checked in settings?
3. Is min_traffic set too high? (Lower it or remove it)
4. Check logs: `/admin/reports/dblog` filter by "google_trends_importer"

### Tags Not Attaching

**Check:**
1. Is a vocabulary selected in settings?
2. Does the content type have a taxonomy reference field?
3. Is the tag field selected in settings?
4. Does the tags_instruction contain the `%s` placeholder?
5. Check logs for tag creation errors

### Articles Have Poor Quality

**Try:**
1. Switch to a better model (GPT-4o Mini → GPT-4o)
2. Adjust the prompt template to be more specific
3. Increase min_traffic to focus on major news
4. Review and edit the tags_instruction

### High API Costs

**Solutions:**
1. Switch to GPT-4o Mini or GPT-3.5 Turbo
2. Reduce max_trends (from 5 to 2-3)
3. Increase min_traffic threshold
4. Run cron less frequently

### Queue Not Processing

**Check:**
1. Run queue manually: `drush queue:run google_trends_processor`
2. Check for errors: `drush watchdog:show --type=google_trends_importer`
3. Verify OpenAI API key is valid
4. Check queue status: `drush queue:list`

### Images Not Downloading

**Check:**
1. Is the image field configured in settings?
2. Does the content type have an image field?
3. Are file permissions correct on `public://google_trends`?
4. Check logs for download errors

## Advanced Configuration

### Custom Content Types

The module works with any content type. To use a custom type:

1. Create your content type (e.g., "News Article")
2. Add an image field (e.g., `field_featured_image`)
3. Add a taxonomy reference field (e.g., `field_topics`)
4. In module settings:
   - Select your content type
   - Select your image field
   - Select your taxonomy field

### Custom Prompts

Edit the **Prompt Template** to change how articles are generated. The template uses three `%s` placeholders:

```
You are a [your role]. Your task is to [your task].

Topic: '%s'
Source content: %s
Available tags: %s

Requirements:
- [Your requirement 1]
- [Your requirement 2]
- Select 3-5 relevant tags from the available tags list

Separators:
- Title/Body: ---TITLE_SEPARATOR---
- Body/Tags: ---TAGS_SEPARATOR---
```

**Important:** Always include all three %s placeholders in order: (1) trend title, (2) content, (3) tags list.

### Multiple Regions

To import trends from multiple regions, install the module multiple times with different machine names, or run separate cron jobs with different RSS URLs.

## Maintenance

### Regular Tasks

1. **Review articles weekly** - Check quality and make adjustments
2. **Monitor costs** - Keep track of API usage
3. **Curate vocabulary** - Add/remove tags as needed
4. **Clean old trends** - Optionally archive or delete old records

### Database Cleanup

To remove old processed trends (example: older than 30 days):

```sql
DELETE gtni FROM google_trends_news_items gtni
INNER JOIN google_trends_data gtd ON gtni.trend_id = gtd.id
WHERE gtd.imported_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));

DELETE FROM google_trends_data 
WHERE imported_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));
```

**Note:** This only removes the trend records, not the generated articles.

## Upgrading

### From Version 1.x to 2.x

1. Back up your database
2. Replace all module files
3. Run updates: `drush updb -y`
4. Clear cache: `drush cr`
5. **Update the Views configuration** (see below)
6. Review new settings at `/admin/config/system/google-trends-importer`
7. Configure new features:
   - Set max_trends (default: 5)
   - Update prompt template to include third %s for tags
   - Select tag vocabulary and field

The update hook will automatically:
* Convert traffic from varchar to int
* Add processing_cost field
* Add traffic index

### Updating the Views Configuration

The "Imported Trends" view has significant improvements in v2.0. You must update it after upgrading:

#### Quick Update (Command Line)
```bash
# Export current configuration
drush config:export

# Import the new view from the module
drush config:import --partial --source=modules/custom/google_trends_importer/config/install

# Or import just the view
drush cim --partial views.view.imported_trends

# Clear cache
drush cr
```

#### Manual Update (UI Method)

If the command-line method doesn't work, update manually:

1. **Go to Views**: `/admin/structure/views`
2. **Edit "Imported Trends"**: Click Edit next to the view
3. **Update Traffic Field**:
   - Click on "Traffic" in the Fields section
   - Change "Type" dropdown to "Numeric"
   - Under "Format settings":
     - Set Suffix: `K+`
     - Check "Use thousands separator"
     - Uncheck "Set precision"
   - Click Apply
4. **Add Published Date Field**:
   - Click "Add" button in Fields section
   - Search for: `google_trends_data: Publication Date`
   - Select it and click "Apply"
   - Choose Format: "Date" → "Short format"
   - Set Label: "Published"
   - Click Apply
5. **Add Imported At Field**:
   - Click "Add" in Fields
   - Search for: `google_trends_data: Imported At`
   - Select and configure as Date (short format)
   - Set Label: "Imported"
   - Click Apply
6. **Add Processing Cost Field**:
   - Click "Add" in Fields
   - Search for: `google_trends_data: Processing Cost`
   - Select and click Apply
   - Choose "Numeric" format
   - Under "Format settings":
     - Prefix: `### 2.0.0
* Added flexible content type support
* Added AI-powered auto-tagging
* Added cost tracking per article
* Added traffic filtering
* Added max trends limit
* Added O1 Preview and O1 Mini model support
* Added model selector dropdown
* Unified prompt template (removed separate tags_instruction)
* Fixed Views configuration
* Changed traffic to integer field
* Update# Google Trends Importer

## Overview

This module fetches daily top Google Trends from their RSS feed, scrapes the content of related news articles, uses the OpenAI API to rewrite and synthesize this content into a new article with AI-powered auto-tagging, and saves it as a **published** Drupal node for review.

It is designed to be a robust, automated content creation assistant with cost tracking, flexible content type configuration, and intelligent tag selection. It uses Drupal's Queue API to handle the heavy lifting (scraping and AI processing) in the background, preventing timeouts during cron runs.

## Features

* **Flexible Content Type Support** - Works with any content type, not just 'Article'
* **AI-Powered Auto-Tagging** - ChatGPT automatically selects relevant tags from your vocabulary
* **Cost Tracking** - Tracks OpenAI API costs per article for budget management
* **Traffic Filtering** - Only process trends above a minimum traffic threshold
* **Rate Limiting** - Control how many trends are processed per cron run
* **Model Selection** - Choose from GPT-4o, GPT-4o Mini, GPT-4 Turbo, GPT-3.5 Turbo
* **Queue-Based Processing** - Reliable background processing using Drupal's Queue API
* **Content Scraping** - Uses Readability algorithm to extract clean article content
* **Image Management** - Automatically downloads and attaches trend images
* **Admin View** - Comprehensive view showing all imported trends with cost and traffic data
* **Customizable Prompts** - Fully customizable OpenAI prompts and tag selection instructions

## Requirements

* Drupal 10 or 11
* PHP 7.4 or higher
* A content type with:
  - An image field (e.g., `field_image`)
  - Optional: A taxonomy reference field for tags
* A taxonomy vocabulary for auto-tagging (optional but recommended)
* A text format (e.g., `full_html` or `basic_html`)
* A valid **OpenAI API Key** from https://platform.openai.com/api-keys
* Composer to manage dependencies

## Installation

### 1. Place the Module
Copy the `google_trends_importer` directory into your project's `/modules/custom` folder.

### 2. Install Composer Dependencies
This module requires several external PHP libraries. Navigate to your Drupal project's **root directory** (the one with the main `composer.json` file) and run the following commands one by one:

```bash
# From your Drupal project root
composer require openai-php/client:"^0.3.1"
composer require symfony/dom-crawler:"^6.4 || ^7.0"
composer require symfony/css-selector:"^6.4 || ^7.0"
composer require fivefilters/readability.php:"^3.0"
```

This will download the necessary libraries and update your project's main `composer.json` and `composer.lock` files.

### 3. Enable the Module
Log in as an administrator, go to **Extend** (`/admin/extend`), find "Google Trends Importer", and click **Install**.

Alternatively, use Drush:
```bash
drush en google_trends_importer -y
```

Enabling the module will automatically:
* Create two new database tables (`google_trends_data` and `google_trends_news_items`)
* Install the "Imported Trends" View (`/admin/content/imported-trends`)

### 4. Run Database Updates (If Upgrading)
If you're upgrading from an older version:
```bash
drush updb -y
drush cr
```

### 5. Update Views Configuration (If Upgrading from v1.x)
The Views configuration has been significantly updated. You have two options:

#### Option A: Automatic Update (Recommended)
```bash
# Import the updated view configuration
drush config:import --partial --source=modules/custom/google_trends_importer/config/install

# Or specifically import just the view
drush cim --partial views.view.imported_trends
```

#### Option B: Manual Update via UI
1. Go to **Structure > Views** (`/admin/structure/views`)
2. Find "Imported Trends" view and click **Edit**
3. Update the **Traffic** field:
   - Click on "Traffic" field
   - Change "Type" to "Numeric"
   - Set "Suffix" to "K+"
   - Under "Format settings" enable "Use thousands separator"
4. Add **Published Date** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Publication Date"
   - Select it and configure as "Date" format (short)
5. Add **Imported At** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Imported At"
   - Select it and configure as "Date" format (short)
6. Add **Processing Cost** field:
   - Click "Add" under Fields
   - Search for "google_trends_data: Processing Cost"
   - Select it and configure as "Numeric"
   - Set "Prefix" to "$"
   - Set "Precision" to 4
   - Enable "Use decimal point"
7. Click "Save" to apply changes

#### Option C: Delete and Reinstall View
If you encounter issues:
```bash
# Delete the old view
drush config:delete views.view.imported_trends

# Reinstall the module (this will recreate the view)
drush pm:uninstall google_trends_importer -y
drush pm:enable google_trends_importer -y

# Note: This will NOT delete your data, only the view configuration
```

## Configuration

**You must configure the module before it will work.**

Go to: **Configuration > System > Google Trends Importer** (`/admin/config/system/google-trends-importer`)

### OpenAI Settings

#### API Key (Required)
Enter your OpenAI API key from https://platform.openai.com/api-keys

#### Model Selection (Required)
Choose which OpenAI model to use:
* **GPT-4o** - Most capable, higher cost (~$0.05-0.15 per article)
* **GPT-4o Mini** - Balanced performance and cost (~$0.003-0.01 per article) **[Recommended]**
* **O1 Preview** - Advanced reasoning for complex topics (~$0.30-0.90 per article)
* **O1 Mini** - Fast reasoning, good for analytical content (~$0.06-0.18 per article)
* **GPT-4 Turbo** - Previous generation, powerful (~$0.10-0.30 per article)
* **GPT-4** - Legacy, slower (~$0.30-0.60 per article)
* **GPT-3.5 Turbo** - Fastest, lowest cost (~$0.01-0.03 per article)

#### Prompt Template (Required)
The main instructions for ChatGPT. Use `%s` placeholders for:
1. First `%s` - The trend title
2. Second `%s` - The combined news article content
3. Third `%s` - Available tags (when vocabulary is configured)

Include separators:
* `---TITLE_SEPARATOR---` (between title and body)
* `---TAGS_SEPARATOR---` (between body and tags)

### Content Type Settings

#### Content Type (Required)
Select which content type to use for generated articles (e.g., "Article", "Blog Post", etc.)

#### Image Field
Select the image field for your content type. This field will be populated with the trend's main image.

#### Tag Field
Select the taxonomy reference field for auto-tagging. This should reference your tag vocabulary.

### Taxonomy Settings

#### Tag Vocabulary
Select a taxonomy vocabulary for auto-tagging. ChatGPT will select the most relevant tags from this vocabulary based on the article content.

**How it works:**
1. All terms from the selected vocabulary are sent to ChatGPT (as the third %s placeholder in the prompt)
2. ChatGPT analyzes the article and selects relevant tags
3. Selected tags are automatically attached to the article
4. If a selected tag doesn't exist, it's created automatically

### Feed Settings

#### Google Trends RSS URL (Required)
The RSS feed URL for daily trends. Default: `https://trends.google.com/trending/rss?geo=US`

You can change the `geo` parameter for different regions:
* US: `geo=US`
* UK: `geo=GB`
* India: `geo=IN`
* Global: Remove the `geo` parameter

#### Minimum Traffic (Optional)
Only process trends with at least this many searches (in thousands). For example:
* `100` = Only trends with 100K+ searches
* Leave empty to process all trends

This helps focus on popular trends and reduce API costs.

#### Maximum Trends to Parse at Once (Required)
Default: **5**

Limits how many trends are fetched and queued in a single cron run. This:
* Prevents timeouts on slow servers
* Controls API costs per hour
* Allows gradual processing

**Examples:**
* `5` trends/hour × 24 hours = 120 articles/day
* `10` trends/hour × 24 hours = 240 articles/day

#### Enable Automatic Fetching via Cron
Check this to automatically fetch new trends when Drupal's cron runs. Uncheck to disable automatic processing (you can still fetch manually).

### Action Buttons

#### Manually Fetch New Trends Now
Click this button to immediately fetch new trends without waiting for cron. Useful for:
* Testing the configuration
* Getting started quickly
* Manually triggering updates

#### Clear Imported Data
Permanently deletes all data from the trends tables and clears the processing queue. **Use with caution** - this cannot be undone.

## How It Works

The module operates in a two-step process to ensure stability and prevent timeouts.

### Step 1: Cron Run (The Fetcher)

1. Drupal's main cron job is triggered (e.g., via `drush core-cron` or a server crontab)
2. The `TrendsFetcher` service runs
3. It fetches the Google Trends RSS feed from the configured URL
4. For each trend item:
   - Parses traffic (e.g., "100K+" → integer 100)
   - Checks if traffic meets minimum threshold (if configured)
   - Checks if we've reached the maximum trends limit
   - Verifies the trend doesn't already exist (by title and pub date)
5. For each new trend:
   - Saves the main item to `google_trends_data`
   - Saves all related news items to `google_trends_news_items`
   - Adds the `trend_id` to the `google_trends_processor` queue
6. Stops processing when max_trends limit is reached
7. Logs summary (items imported, items skipped, limit reached)

This step is very fast (seconds) and won't timeout.

### Step 2: Queue Processing (The Worker)

1. Immediately after cron (or on its own schedule), Drupal's queue runner processes the `google_trends_processor` queue
2. The `ProcessTrend` QueueWorker picks up one `trend_id` at a time
3. **Scrape:** It scrapes the content from all related news URLs using the Readability algorithm to extract clean text
4. **Load Tags:** If a vocabulary is configured, it loads all available taxonomy terms
5. **Build Prompt:** Combines the trend title, scraped content, and available tags into a prompt using your templates
6. **Send to OpenAI:** Sends the prompt to OpenAI using the selected model
7. **Calculate Cost:** Tracks input/output tokens and calculates the API cost
8. **Parse Response:** Extracts the title, HTML body, and selected tags using the separators
9. **Download Image:** Downloads the trend's main image and saves it to Drupal
10. **Create Tags:** For each selected tag, finds the existing term or creates a new one
11. **Create Article:** Creates a new node with:
    - Content type from settings
    - Generated title
    - Generated HTML body
    - Attached image
    - Attached tags (if configured)
    - Publication date matching the trend's pub_date
    - Published status (status = 1)
12. **Update Database:** Marks the trend as processed and stores the node_id and processing_cost

Each trend is processed independently, so if one fails, others continue.

## Usage

After installation and configuration, the module runs automatically via cron.

### Viewing Imported Trends

Go to: **Content > Imported Trends** (`/admin/content/imported-trends`)

This view shows:
* **Trend Title** - The original search trend topic
* **Traffic (K)** - Approximate searches in thousands (e.g., "100K+")
* **Published** - When the trend was published by Google
* **Imported** - When the trend was imported to your site
* **Cost** - How much it cost to process with OpenAI (e.g., "$0.0050")
* **Article** - Link to view the generated article

The view is sortable by all columns and shows 25 items per page.

### Reviewing Articles

Click "View" next to any trend to see the generated article. You can:
* Edit the content if needed
* Add additional tags manually
* Change the featured image
* Unpublish if the quality isn't good
* Delete if unwanted

### Monitoring Costs

The view shows individual costs per article. To see total costs:

```bash
# Total cost for all processed trends
drush sqlq "SELECT SUM(processing_cost) as total FROM google_trends_data WHERE processed = 1"

# Cost in the last 24 hours
drush sqlq "SELECT SUM(processing_cost) as total FROM google_trends_data WHERE imported_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))"
```

### Manual Processing

To manually fetch trends (without waiting for cron):
1. Go to the settings page
2. Click "Manually Fetch New Trends Now"
3. Confirm the action

To manually process the queue:
```bash
drush queue:run google_trends_processor
```

## Cost Management

### Estimating Costs

| Model | Cost per Article | 120 articles/day | 240 articles/day |
|-------|------------------|------------------|------------------|
| GPT-4o Mini | $0.003-0.01 | $0.36-1.20/day | $0.72-2.40/day |
| GPT-3.5 Turbo | $0.01-0.03 | $1.20-3.60/day | $2.40-7.20/day |
| GPT-4o | $0.05-0.15 | $6-18/day | $12-36/day |
| O1 Mini | $0.06-0.18 | $7.20-21.60/day | $14.40-43.20/day |
| GPT-4 Turbo | $0.10-0.30 | $12-36/day | $24-72/day |
| O1 Preview | $0.30-0.90 | $36-108/day | $72-216/day |

**Recommendation:** Start with GPT-4o Mini and monitor quality. Upgrade to GPT-4o for better quality, or O1 models for complex/analytical topics.

### Controlling Costs

1. **Use GPT-4o Mini** - Best balance of quality and cost
2. **Set max_trends** - Limit articles per run (default: 5)
3. **Set min_traffic** - Only process popular trends
4. **Monitor the view** - Check costs daily for the first week
5. **Adjust cron frequency** - Run less often if needed

### Cost Tracking

Costs are automatically tracked in the database and visible in the admin view. The calculation includes:
* Input tokens (prompt size) × input price
* Output tokens (generated content) × output price
* Based on actual usage returned by OpenAI

## Troubleshooting

### No Trends Being Imported

**Check:**
1. Is cron running? `drush core-cron`
2. Is "Enable Cron Processing" checked in settings?
3. Is min_traffic set too high? (Lower it or remove it)
4. Check logs: `/admin/reports/dblog` filter by "google_trends_importer"

### Tags Not Attaching

**Check:**
1. Is a vocabulary selected in settings?
2. Does the content type have a taxonomy reference field?
3. Is the tag field selected in settings?
4. Does the tags_instruction contain the `%s` placeholder?
5. Check logs for tag creation errors

### Articles Have Poor Quality

**Try:**
1. Switch to a better model (GPT-4o Mini → GPT-4o)
2. Adjust the prompt template to be more specific
3. Increase min_traffic to focus on major news
4. Review and edit the tags_instruction

### High API Costs

**Solutions:**
1. Switch to GPT-4o Mini or GPT-3.5 Turbo
2. Reduce max_trends (from 5 to 2-3)
3. Increase min_traffic threshold
4. Run cron less frequently

### Queue Not Processing

**Check:**
1. Run queue manually: `drush queue:run google_trends_processor`
2. Check for errors: `drush watchdog:show --type=google_trends_importer`
3. Verify OpenAI API key is valid
4. Check queue status: `drush queue:list`

### Images Not Downloading

**Check:**
1. Is the image field configured in settings?
2. Does the content type have an image field?
3. Are file permissions correct on `public://google_trends`?
4. Check logs for download errors

## Advanced Configuration

### Custom Content Types

The module works with any content type. To use a custom type:

1. Create your content type (e.g., "News Article")
2. Add an image field (e.g., `field_featured_image`)
3. Add a taxonomy reference field (e.g., `field_topics`)
4. In module settings:
   - Select your content type
   - Select your image field
   - Select your taxonomy field

### Custom Prompts

Edit the **Prompt Template** to change how articles are generated. The template uses three `%s` placeholders:

```
You are a [your role]. Your task is to [your task].

Topic: '%s'
Source content: %s
Available tags: %s

Requirements:
- [Your requirement 1]
- [Your requirement 2]
- Select 3-5 relevant tags from the available tags list

Separators:
- Title/Body: ---TITLE_SEPARATOR---
- Body/Tags: ---TAGS_SEPARATOR---
```

**Important:** Always include all three %s placeholders in order: (1) trend title, (2) content, (3) tags list.

### Multiple Regions

To import trends from multiple regions, install the module multiple times with different machine names, or run separate cron jobs with different RSS URLs.

## Maintenance

### Regular Tasks

1. **Review articles weekly** - Check quality and make adjustments
2. **Monitor costs** - Keep track of API usage
3. **Curate vocabulary** - Add/remove tags as needed
4. **Clean old trends** - Optionally archive or delete old records

### Database Cleanup

To remove old processed trends (example: older than 30 days):

```sql
DELETE gtni FROM google_trends_news_items gtni
INNER JOIN google_trends_data gtd ON gtni.trend_id = gtd.id
WHERE gtd.imported_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));

DELETE FROM google_trends_data 
WHERE imported_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));
```

**Note:** This only removes the trend records, not the generated articles.


     - Set precision: `4`
     - Decimal: `.`
     - Check "Use thousands separator"
   - Set Label: "Cost"
   - Click Apply
7. **Rearrange Fields** (drag to reorder):
   - Trend Title
   - Traffic (K)
   - Published
   - Imported
   - Cost
   - Article (View link)
8. **Enable Sorting** for each field:
   - Click on each field
   - Check "Sortable"
   - Click Apply
9. **Save the View**: Click "Save" button

#### Nuclear Option (Complete Reinstall)

If you have major issues, you can delete and reinstall:

```bash
# WARNING: This preserves your data but resets view customizations

# Delete the view configuration
drush config:delete views.view.imported_trends

# Reinstall just the view from module defaults
drush pm:uninstall google_trends_importer -y
drush pm:enable google_trends_importer -y

# Your trend data remains intact in the database
```

#### Verify the Update

After updating, check `/admin/content/imported-trends` to verify:
- ✅ Traffic shows as numbers with "K+" (e.g., "100K+")
- ✅ Published date appears in short format
- ✅ Imported date appears in short format
- ✅ Cost appears with dollar sign (e.g., "$0.0050")
- ✅ All columns are sortable (except Article link)

If fields are missing or incorrectly formatted, repeat the manual update steps.

## API Reference

### Services

#### google_trends_importer.fetcher
Service class: `TrendsFetcher`

Fetches trends from Google RSS feed and queues them for processing.

Methods:
* `fetchAndSaveTrends()` - Main method called by cron

### Queue Workers

#### google_trends_processor
Plugin ID: `google_trends_processor`
Plugin class: `ProcessTrend`

Processes individual trends from the queue.

### Database Tables

#### google_trends_data
Stores main trend information and processing status.

#### google_trends_news_items
Stores related news articles for each trend.

## Contributing

Found a bug? Have a feature request? Please create an issue with:
* Drupal version
* PHP version
* Module version
* Steps to reproduce
* Error messages from logs

## License

This module is licensed under the GPL-2.0-or-later license.

## Credits

* Uses OpenAI API for content generation
* Uses Readability.php for content extraction
* Built on Drupal's Queue API
* Inspired by Google Trends RSS feeds

## Support

For issues and questions:
1. Check this README
2. Review Drupal logs: `/admin/reports/dblog`
3. Verify configuration: `/admin/config/system/google-trends-importer`
4. Test manually using the "Fetch Now" button
5. Check queue status: `drush queue:list`

## Version History

### 2.0.0
* Added flexible content type support
* Added AI-powered auto-tagging
* Added cost tracking per article
* Added traffic filtering
* Added max trends limit
* Added O1 Preview and O1 Mini model support
* Added model selector dropdown
* Unified prompt template (removed separate tags_instruction)
* Fixed Views configuration with all fields properly formatted
* Changed traffic to integer field
* Updated prompt templates
* Added comprehensive documentation
* Added Views update instructions

### 1.0.0
* Initial release
* Basic trend importing
* OpenAI integration
* Queue-based processing