<?php

namespace Drupal\google_trends_importer\Service;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Service to fetch trends and add them to the processing queue.
 */
class TrendsFetcher {

  protected $httpClient;
  protected $database;
  protected $logger;
  protected $queue;
  protected $config;

  public function __construct(ClientInterface $http_client, Connection $database, LoggerChannelFactoryInterface $logger_factory, QueueFactory $queue_factory, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->logger = $logger_factory->get('google_trends_importer');
    $this->config = $config_factory->get('google_trends_importer.settings');
    $this->queue = $queue_factory->get('google_trends_processor');
  }

  public function fetchAndSaveTrends() {
    $trends_url = $this->config->get('trends_url');
    if (empty($trends_url)) {
      $this->logger->warning('Google Trends RSS URL is not configured. Aborting import.');
      return;
    }

    $min_traffic = $this->config->get('min_traffic');
    $max_trends = $this->config->get('max_trends') ?: 5;
    
    $this->logger->info('Starting Google Trends import (max @max trends)...', ['@max' => $max_trends]);
    $import_count = 0;
    $skipped_count = 0;

    try {
      $response = $this->httpClient->request('GET', $trends_url);
      $xml_string = (string) $response->getBody();
      $xml = simplexml_load_string($xml_string, 'SimpleXMLElement', LIBXML_NOCDATA);

      if ($xml === FALSE) {
        $this->logger->error('Failed to parse Google Trends RSS feed.');
        return;
      }

      $namespaces = $xml->getNamespaces(TRUE);
      $ht_ns_uri = $namespaces['ht'] ?? null;

      if (!$ht_ns_uri) {
        $this->logger->error('Could not find the "ht" namespace in the RSS feed.');
        return;
      }

      if (!isset($xml->channel->item)) {
        $this->logger->warning('No <item> elements found in the RSS feed channel.');
        return;
      }

      foreach ($xml->channel->item as $item) {
        // Check if we've reached the maximum
        if ($import_count >= $max_trends) {
          $this->logger->info('Reached maximum trends limit (@max). Stopping import.', ['@max' => $max_trends]);
          break;
        }

        $ht_children = $item->children($ht_ns_uri);

        // Parse traffic as integer
        $traffic_string = (string) $ht_children->approx_traffic;
        $traffic_int = $this->parseTrafficToInt($traffic_string);

        // Check minimum traffic threshold
        if ($min_traffic && $traffic_int < $min_traffic) {
          $skipped_count++;
          continue;
        }

        try {
          $pubDate = new DrupalDateTime((string) $item->pubDate);
          $pubDate->setTimezone(new \DateTimeZone('UTC'));
          $pub_date_string_utc = $pubDate->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
        } catch (\Exception $e) {
          $this->logger->error('Failed to parse pubDate "@date" for item "@title": @message. Skipping item.', [
            '@date' => (string) $item->pubDate,
            '@title' => (string) $item->title,
            '@message' => $e->getMessage(),
          ]);
          continue;
        }

        $title_string = (string) $item->title;
        $link_string = (string) $item->link;

        // Check if this trend already exists
        $query = $this->database->select('google_trends_data', 'gtd')
          ->fields('gtd', ['id'])
          ->condition('title', $title_string)
          ->condition('pub_date', $pub_date_string_utc)
          ->range(0, 1);
        $trend_id = $query->execute()->fetchField();

        if (!$trend_id) {
          $main_fields = [
            'title' => $title_string,
            'traffic' => $traffic_int,
            'pub_date' => $pub_date_string_utc,
            'link' => $link_string,
            'snippet' => (string) $item->description,
            'image_url' => (string) $ht_children->picture,
            'processed' => 0,
            'node_id' => NULL,
            'imported_at' => \Drupal::time()->getRequestTime(),
            'processing_cost' => NULL,
          ];

          $trend_id = $this->database->insert('google_trends_data')
            ->fields($main_fields)
            ->execute();

          foreach ($ht_children->news_item as $news_item) {
            $news_item_children = $news_item->children($ht_ns_uri);
            $news_fields = [
              'trend_id' => $trend_id,
              'title' => (string) $news_item_children->news_item_title,
              'snippet' => (string) $news_item_children->news_item_snippet,
              'url' => (string) $news_item_children->news_item_url,
              'source' => (string) $news_item_children->news_item_source,
            ];
            $this->database->insert('google_trends_news_items')
              ->fields($news_fields)
              ->execute();
          }

          $this->queue->createItem($trend_id);
          $import_count++;
        }
      }

      if ($import_count > 0) {
        $message = 'Queued @count new Google Trends items for processing.';
        if ($skipped_count > 0) {
          $message .= ' Skipped @skipped items due to minimum traffic threshold.';
        }
        $this->logger->info($message, ['@count' => $import_count, '@skipped' => $skipped_count]);
      }
      else {
        $message = 'Google Trends import ran, but found no new items.';
        if ($skipped_count > 0) {
          $message .= ' @skipped items were skipped due to minimum traffic threshold.';
        }
        $this->logger->info($message, ['@skipped' => $skipped_count]);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to import Google Trends: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Parse traffic string to integer.
   *
   * @param string $traffic_string
   *   Traffic string like "100K+" or "2M+".
   *
   * @return int
   *   Traffic in thousands.
   */
  protected function parseTrafficToInt($traffic_string) {
    // Remove any whitespace
    $traffic_string = trim($traffic_string);
    
    // Extract numeric part and multiplier
    if (preg_match('/^([0-9.]+)([KMB])?.*$/i', $traffic_string, $matches)) {
      $number = floatval($matches[1]);
      $multiplier = isset($matches[2]) ? strtoupper($matches[2]) : '';
      
      switch ($multiplier) {
        case 'M':
          return (int) ($number * 1000); // Convert millions to thousands
        case 'B':
          return (int) ($number * 1000000); // Convert billions to thousands
        case 'K':
        default:
          return (int) $number; // Already in thousands
      }
    }
    
    return 0;
  }
}