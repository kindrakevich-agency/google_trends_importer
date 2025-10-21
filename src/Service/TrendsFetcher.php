<?php

namespace Drupal\google_trends_importer\Service;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service to fetch trends and add them to the processing queue.
 */
class TrendsFetcher {

  protected $httpClient;
  protected $database;
  protected $logger;
  protected $queue;
  protected $config;

  /**
   * Constructs a new TrendsFetcher object.
   */
  public function __construct(ClientInterface $http_client, Connection $database, LoggerFactoryInterface $logger_factory, QueueFactory $queue_factory, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->logger = $logger_factory->get('google_trends_importer');
    $this->queue = $queue_factory->get('google_trends_processor');
    $this->config = $config_factory->get('google_trends_importer.settings');
  }

  /**
   * Fetches trends, saves them, and queues new ones for processing.
   */
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

      if ($xml === FALSE || !isset($xml->channel->item)) {
        $this->logger->error('Failed to parse Google Trends RSS feed.');
        return;
      }

      foreach ($xml->channel->item as $item) {
        $ht_namespaces = $item->children('https://trends.google.com/trends/trendingsearches/daily');
        
        $pubDate = new \DateTime((string) $item->pubDate);
        $pub_date_string = $pubDate->format('Y-m-d H:i:s');
        $link_string = (string) $item->link;

        // Check if this trend already exists.
        $query = $this->database->select('google_trends_data', 'gtd')
          ->fields('gtd', ['id'])
          ->condition('link', $link_string)
          ->condition('pub_date', $pub_date_string)
          ->range(0, 1);
        $trend_id = $query->execute()->fetchField();

        if (!$trend_id) {
          // This is a new trend. Insert it.
          $main_fields = [
            'title' => (string) $item->title,
            'traffic' => (string) $ht_namespaces->approx_traffic,
            'pub_date' => $pub_date_string,
            'link' => $link_string,
            'snippet' => (string) $item->description,
            'image_url' => (string) $ht_namespaces->picture, // Get the image URL
            'processed' => 0, // Mark as unprocessed
            'node_id' => NULL,
            'imported_at' => \Drupal::time()->getRequestTime(),
          ];

          $trend_id = $this->database->insert('google_trends_data')
            ->fields($main_fields)
            ->execute();

          // Save all related news items.
          foreach ($ht_namespaces->news_item as $news_item) {
            $news_fields = [
              'trend_id' => $trend_id,
              'title' => (string) $news_item->news_item_title,
              'snippet' => (string) $news_item->news_item_snippet,
              'url' => (string) $news_item->news_item_url,
              'source' => (string) $news_item->news_item_source,
            ];
            $this->database->insert('google_trends_news_items')
              ->fields($news_fields)
              ->execute();
          }

          // Add the new trend ID to the queue for processing.
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