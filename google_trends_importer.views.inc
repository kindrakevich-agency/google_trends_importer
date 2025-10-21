<?php

/**
 * @file
 * Provides Views data for the Google Trends Importer module.
 */

/**
 * Implements hook_views_data().
 */
function google_trends_importer_views_data() {
  $data = [];

  // --- Base table: google_trends_data ---
  $data['google_trends_data']['table'] = [
    'group' => t('Google Trends'),
    'base' => [
      'field' => 'id',
      'title' => t('Imported Trends'),
      'help' => t('Stores daily Google Trends data.'),
    ],
  ];

  // Fields
  $data['google_trends_data']['id'] = [
    'title' => t('Trend ID'),
    'help' => t('The unique ID of the Google Trend item.'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
  ];

  $data['google_trends_data']['title'] = [
    'title' => t('Trend Title'),
    'help' => t('The search term (trend title).'),
    'field' => ['id' => 'standard'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
    'argument' => ['id' => 'string'],
  ];

  $data['google_trends_data']['traffic'] = [
    'title' => t('Traffic'),
    'help' => t('Approximate search traffic.'),
    'field' => ['id' => 'standard'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
  ];

  $data['google_trends_data']['pub_date'] = [
    'title' => t('Publication Date'),
    'help' => t('The publication date of the trend.'),
    'field' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
  ];

  $data['google_trends_data']['link'] = [
    'title' => t('Trend Link'),
    'help' => t('Link to the Google Trends page.'),
    'field' => ['id' => 'standard'],
  ];

  $data['google_trends_data']['snippet'] = [
    'title' => t('Snippet'),
    'help' => t('A snippet of news related to the trend.'),
    'field' => ['id' => 'standard'],
  ];

  $data['google_trends_data']['image_url'] = [
    'title' => t('Image URL'),
    'help' => t('The main image URL from the RSS feed.'),
    'field' => ['id' => 'standard'],
  ];

  $data['google_trends_data']['processed'] = [
    'title' => t('Processed'),
    'help' => t('Flag to mark as processed (0 = no, 1 = yes).'),
    'field' => ['id' => 'boolean'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'boolean'],
  ];

  $data['google_trends_data']['node_id'] = [
    'title' => t('Generated Node'),
    'help' => t('The article node created from this trend.'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'relationship' => [
      'title' => t('Generated Article'),
      'help' => t('The node created from this trend.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'id' => 'standard',
    ],
  ];

  $data['google_trends_data']['imported_at'] = [
    'title' => t('Imported At'),
    'help' => t('Timestamp when the item was imported.'),
    'field' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
  ];

  // --- Related table: google_trends_news_items ---
  $data['google_trends_news_items']['table'] = [
    'group' => t('Google Trends'),
    'base' => [
      'field' => 'id',
      'title' => t('Related News Items'),
      'help' => t('Stores related news items for each Google Trend.'),
    ],
  ];

  // Fields for news items
  $data['google_trends_news_items']['id'] = [
    'title' => t('News Item ID'),
    'field' => ['id' => 'numeric'],
    'sort' => ['id' => 'standard'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
  ];

  $data['google_trends_news_items']['trend_id'] = [
    'title' => t('Trend ID (News Item)'),
    'field' => ['id' => 'numeric'],
    'filter' => ['id' => 'numeric'],
    'argument' => ['id' => 'numeric'],
    'relationship' => [
      'title' => t('Trend'),
      'help' => t('The trend associated with this news item.'),
      'base' => 'google_trends_data',
      'base field' => 'id',
      'id' => 'standard',
    ],
  ];

  $data['google_trends_news_items']['title'] = [
    'title' => t('News Item Title'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
  ];

  $data['google_trends_news_items']['snippet'] = [
    'title' => t('Snippet'),
    'field' => ['id' => 'standard'],
  ];

  $data['google_trends_news_items']['url'] = [
    'title' => t('News Item URL'),
    'field' => ['id' => 'standard'],
  ];

  $data['google_trends_news_items']['source'] = [
    'title' => t('News Item Source'),
    'field' => ['id' => 'standard'],
    'filter' => ['id' => 'string'],
  ];

  return $data;
}
