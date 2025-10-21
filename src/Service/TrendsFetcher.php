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

    $this->logger->info('Starting Google Trends import...');
    $import_count = 0;

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
        $ht_children = $item->children($ht_ns_uri);

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

        // *** Use title for uniqueness check ***
        $title_string = (string) $item->title;
        $link_string = (string) $item->link; // Still save the link, just don't use it for uniqueness

        // Check if this trend already exists using title and UTC date string
        $query = $this->database->select('google_trends_data', 'gtd')
          ->fields('gtd', ['id'])
          ->condition('title', $title_string) // <-- Use title
          ->condition('pub_date', $pub_date_string_utc)
          ->range(0, 1);
        $trend_id = $query->execute()->fetchField();

        if (!$trend_id) {
          $main_fields = [
            'title' => $title_string, // <-- Use title variable
            'traffic' => (string) $ht_children->approx_traffic,
            'pub_date' => $pub_date_string_utc,
            'link' => $link_string, // <-- Still save the link
            'snippet' => (string) $item->description,
            'image_url' => (string) $ht_children->picture,
            'processed' => 0,
            'node_id' => NULL,
            'imported_at' => \Drupal::time()->getRequestTime(),
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
        $this->logger->info('Queued @count new Google Trends items for processing.', ['@count' => $import_count]);
      }
      else {
        $this->logger->info('Google Trends import ran, but found no new items.');
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to import Google Trends: @message', ['@message' => $e->getMessage()]);
    }
  }
}