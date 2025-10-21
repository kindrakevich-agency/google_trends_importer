<?php

namespace Drupal\google_trends_importer\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerFactoryInterface;
use OpenAI\Client as OpenAiClient;
use FiveFilters\Readability\Readability;
use FiveFilters\Readability\Configuration;

/**
 * Processes a Trend Item from the queue.
 *
 * @QueueWorker(
 * id = "google_trends_processor",
 * title = @Translation("Google Trends Processor"),
 * cron = {"time" = 60}
 * )
 */
class ProcessTrend extends QueueWorkerBase {

  protected $database;
  protected $openAiClient;
  protected $httpClient;
  protected $logger;
  protected $entityTypeManager;
  protected $fileRepository;
  protected $fileSystem;

  /**
   * Constructs a new ProcessTrend object.
   */
  public function __construct(Connection $database, OpenAiClient $openAiClient, ClientInterface $httpClient, LoggerFactoryInterface $logger_factory, EntityTypeManagerInterface $entityTypeManager, FileRepositoryInterface $fileRepository, ConfigFactoryInterface $configFactory) {
    $this->database = $database;
    $this->openAiClient = $openAiClient;
    $this->httpClient = $httpClient;
    $this->logger = $logger_factory->get('google_trends_importer');
    $this->entityTypeManager = $entityTypeManager;
    $this->fileRepository = $fileRepository;
    $this->fileSystem = 'public://';
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // $data is the $trend_id we added to the queue.
    $trend_id = $data;

    try {
      // 1. Get Trend and News Items from our tables.
      $trend = $this->database->select('google_trends_data', 'gtd')
        ->fields('gtd')
        ->condition('id', $trend_id)
        ->execute()
        ->fetch();

      if (!$trend) {
        throw new \Exception(sprintf('Trend ID %d not found.', $trend_id));
      }

      $news_items = $this->database->select('google_trends_news_items', 'gtn')
        ->fields('gtn')
        ->condition('trend_id', $trend_id)
        ->execute()
        ->fetchAll();

      if (empty($news_items)) {
        throw new \Exception(sprintf('No news items found for Trend ID %d.', $trend_id));
      }

      // 2. Scrape text from each URL using Readability.
      $all_scraped_text = '';
      foreach ($news_items as $item) {
        $all_scraped_text .= $this->scrapeUrlWithReadability($item->url) . "\n\n---\n\n";
      }

      if (empty(trim($all_scraped_text))) {
        throw new \Exception(sprintf('Readability scraping returned no text for Trend ID %d.', $trend_id));
      }

      // 3. Send to OpenAI for rewrite.
      $this->logger->info(sprintf('Sending Trend ID %d (%s) to OpenAI.', $trend->id, $trend->title));
      $prompt = sprintf(
        "You are an expert news editor. The following text snippets are from multiple news articles about the search trend '%s'.
        Please synthesize all this information into a single, well-written, and engaging news article.
        The article should be in HTML format.
        It should have a clear structure, flow logically, and be easy to read.
        Do not include a title in your response, just the body text.
        
        News Snippets:
        %s",
        $trend->title,
        $all_scraped_text
      );

      $result = $this->openAiClient->chat()->create([
        'model' => 'gpt-3.5-turbo', // Or 'gpt-4'
        'messages' => [
          ['role' => 'user', 'content' => $prompt],
        ],
      ]);

      $article_body = $result->choices[0]->message->content;

      // 4. Create the Drupal Article Node.
      $this->logger->info(sprintf('Creating article for Trend ID %d.', $trend->id));
      $this->createArticleNode($trend, $article_body);

      // 5. Mark as processed.
      $this->database->update('google_trends_data')
        ->fields(['processed' => 1])
        ->condition('id', $trend_id)
        ->execute();

      $this->logger->info(sprintf('Successfully processed and created article for Trend ID %d.', $trend->id));

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process Trend ID @id: @message', [
        '@id' => $trend_id,
        '@message' => $e->getMessage(),
      ]);
      // Re-throw exception so the queue item is not deleted and can be re-tried.
      throw $e;
    }
  }

  /**
   * Helper function to scrape text from a URL using Readability.
   */
  private function scrapeUrlWithReadability($url) {
    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
      $html = (string) $response->getBody();

      $config = new Configuration();
      $config->setFixRelativeURLs(true);
      $config->setOriginalURL($url);

      $readability = new Readability($config);
      $readability->parse($html);
      
      if (!$readability->isReadable()) {
        $this->logger->warning('Readability could not find content for URL @url', ['@url' => $url]);
        return '';
      }

      // Return the title and the clean text content.
      return $readability->getTitle() . "\n\n" . $readability->getTextContent();

    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to scrape URL @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return ''; // Return empty string on failure
    }
  }

  /**
   * Helper function to create the node and save the node ID.
   */
  private function createArticleNode($trend, $body_text) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    // 1. Handle the image.
    $file_id = NULL;
    if (!empty($trend->image_url)) {
      try {
        $file_data = file_get_contents($trend->image_url);
        if ($file_data) {
          $filename = 'trend_image_' . $trend->id . '.jpg';
          $destination = $this->fileSystem . '/' . $filename;
          
          $file = $this->fileRepository->writeData($file_data, $destination, FILE_EXISTS_REPLACE);
          $file_id = $file->id();
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to download image for Trend ID @id: @message', [
          '@id' => $trend->id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // 2. Create the node.
    $node = $node_storage->create([
      'type' => 'article',
      'title' => $trend->title,
      'status' => 0, // 0 = Unpublished
      'body' => [
        'value' => $body_text,
        'format' => 'basic_html',
      ],
    ]);

    // 3. Attach image if we have one.
    if ($file_id) {
      // Assumes 'field_image' exists on the 'article' content type
      $node->field_image->target_id = $file_id;
      $node->field_image->alt = $trend->title;
    }

    $node->save();

    // 4. Update our database table with the new Node ID.
    $this->database->update('google_trends_data')
      ->fields(['node_id' => $node->id()])
      ->condition('id', $trend->id)
      ->execute();
  }
}