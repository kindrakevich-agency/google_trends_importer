# Google Trends Importer

## Overview

This module fetches daily top Google Trends from their RSS feed, scrapes the content of related news articles, uses the OpenAI API to rewrite and synthesize this content into a new article, and saves it as an **unpublished** Drupal article for administrative review.

It is designed to be a robust, automated content creation assistant. It uses Drupal's Queue API to handle the heavy lifting (scraping and AI processing) in the background, preventing timeouts during cron runs.

## Features

* Fetches daily Google Trends from a configurable RSS feed.
* Saves trends and related news items to custom database tables.
* Uses a **Queue Worker** for reliable, background processing.
* Scrapes article URLs using the **Readability** algorithm to extract clean, relevant content.
* Sends the combined text of all related articles to **OpenAI** for summarization and rewriting.
* Creates a new, **unpublished** 'Article' node with the AI-generated body and the trend's primary image.
* Provides an **admin-only View** to see all imported trends and link directly to the unpublished articles for review.
* Provides a **configuration page** to set your OpenAI API Key and the Trends RSS URL.

## Requirements

* Drupal 10 or 11
* An 'Article' content type (`article`) must exist.
* The 'Article' content type must have an **Image field** with the machine name `field_image`.
* A text format with the machine name `basic_html` must exist.
* A valid **OpenAI API Key**.
* Composer to manage dependencies.

## Installation

1.  **Place the Module**
    Copy the `google_trends_importer` directory into your project's `/modules/custom` folder.

2.  **Install Composer Dependencies**
    This module declares its PHP library dependencies in its `composer.json` file. Navigate to your Drupal project's **root directory** (the one with the main `composer.json` file) and run Composer's install or update command.

    ```bash
    # From your Drupal project root
    composer install
    ```
    Or, if you need to update dependencies:
    ```bash
    composer update drupal/google_trends_importer --with-dependencies
    ```
    This will read the module's `composer.json` and automatically download `openai-php/client`, `symfony/dom-crawler`, and the other required libraries.

3.  **Enable the Module**
    Log in as an administrator, go to **Extend** (`/admin/extend`), find "Google Trends Importer", and click **Install**.

    Alternatively, use Drush:
    ```bash
    drush en google_trends_importer -y
    ```
    Enabling the module will automatically:
    * Create two new database tables (`google_trends_data` and `google_trends_news_items`).
    * Install the "Imported Trends" View (`/admin/content/imported-trends`).

## Configuration

**You must configure the module before it will work.**

1.  Go to the settings page: **Configuration > System > Google Trends Importer** (`/admin/config/system/google-trends-importer`).
2.  Enter your **OpenAI API Key**.
3.  Verify the **Google Trends RSS URL**. The default is for the US (`geo=US`). You can change this to your desired region.
4.  Click **Save configuration**.

## How It Works

The module operates in a two-step process to ensure stability.

### Step 1: Cron Run (The Fetcher)

1.  Drupal's main cron job is triggered (e.g., via `drush core-cron` or a server crontab).
2.  The `TrendsFetcher` service runs.
3.  It fetches the Google Trends RSS feed from the URL in your settings.
4.  It checks the `google_trends_data` table for any *new* trends (based on link and pub date).
5.  For each new trend, it saves the main item to `google_trends_data` and all related news items to `google_trends_news_items`.
6.  Finally, it adds the new `trend_id` to the `google_trends_processor` queue and finishes. This step is very fast.

### Step 2: Queue Processing (The Worker)

1.  Immediately after cron (or on its own schedule), Drupal's queue runner processes the `google_trends_processor` queue.
2.  The `ProcessTrend` QueueWorker picks up one `trend_id` at a time.
3.  **Scrape:** It scrapes the content from all related news URLs using the Readability algorithm.
4.  **Synthesize:** It combines all the clean text into a single prompt and sends it to OpenAI for rewriting.
5.  **Create:** It creates a new 'Article' node, setting the title, the AI-generated body, and the main trend image. This article is created as **Unpublished**.
6.  **Update:** It updates the `google_trends_data` table, marking the trend as `processed = 1` and saving the new `node_id`.

## Usage

After installation and configuration, simply let your Drupal cron job run.

To see the results, go to **Content > Imported Trends** (`/admin/content/imported-trends`).

This view will show you:
* The original Trend Title.
* The approximate traffic.
* A link to the unpublished, generated article.

You can then click "View Article" to review, edit, and publish the node like any other piece of content.