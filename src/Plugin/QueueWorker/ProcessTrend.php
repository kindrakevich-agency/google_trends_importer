<?php

namespace Drupal\google_trends_importer\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use OpenAI\Client; // Use direct class name
use FiveFilters\Readability\Readability;
use FiveFilters\Readability\Configuration;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Processes a Trend Item from the queue.
 *
 * @QueueWorker(
 * id = "google_trends_processor",
 * title = @Translation("Google Trends Processor"),
 * cron = {"time" = 60}
 * )
 */
class ProcessTrend extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected $database;
  protected $openAiClient;
  protected $httpClient;
  protected $logger;
  protected $entityTypeManager;
  protected $fileRepository;
  protected $fileSystem;

  /**
   * Constructs a new ProcessTrend object.
   * (Removed ConfigFactoryInterface from constructor signature)
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Connection $database,
    Client $openAiClient,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entityTypeManager,
    FileRepositoryInterface $fileRepository
    // ConfigFactoryInterface $configFactory - Removed
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Get config factory to read settings
    $config_factory = $container->get('config.factory');
    $config = $config_factory->get('google_trends_importer.settings');
    $api_key = $config->get('openai_api_key');

    // ** Instantiate the OpenAI Client directly here **
    // Check if API key is set before creating client
    $openai_client = null;
    if (!empty($api_key)) {
       try {
           // Use the static factory method
           $openai_client = \OpenAI::client($api_key);
       } catch (\Exception $e) {
           // Log error if client creation fails, but allow worker creation
           $container->get('logger.factory')->get('google_trends_importer')
               ->error('Failed to create OpenAI client: @message', ['@message' => $e->getMessage()]);
       }
    } else {
        $container->get('logger.factory')->get('google_trends_importer')
            ->warning('OpenAI API Key is not configured. OpenAI functionality will be disabled.');
    }


    // Pass the created client (or null if key missing/error) to the constructor
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $openai_client, // Pass the newly created client object (or null)
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('file.repository')
      // $container->get('config.factory') - No longer needed in constructor
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $trend_id = $data;

    // ** Add check for missing OpenAI client **
    if (!$this->openAiClient) {
        $this->logger->error('OpenAI client is not available (API Key missing or invalid?). Cannot process Trend ID @id.', ['@id' => $trend_id]);
        // Optionally, you could mark as processed here if you don't want retries
        // $this->database->update('google_trends_data')...
        // throw new \Exception('OpenAI client not available.'); // Or throw to retry later
        return; // Stop processing this item if client is missing
    }


    try {
      // 1. Get Trend
      $trend = $this->database->select('google_trends_data', 'gtd')
        ->fields('gtd')
        ->condition('id', $trend_id)
        ->execute()
        ->fetch();

      if (!$trend) {
        $this->logger->error('Trend ID @id not found in google_trends_data table. Skipping.', ['@id' => $trend_id]);
        return;
      }

      // 2. Get News Items
      $news_items = $this->database->select('google_trends_news_items', 'gtn')
        ->fields('gtn')
        ->condition('trend_id', $trend_id)
        ->execute()
        ->fetchAll();

      if (empty($news_items)) {
        $this->logger->warning('No news items found for Trend ID @id. Marking as processed without creating article.', ['@id' => $trend_id]);
        $this->database->update('google_trends_data')
          ->fields(['processed' => 1])
          ->condition('id', $trend_id)
          ->execute();
        return;
      }

      // 3. Scrape text
      $all_scraped_text = '';
      foreach ($news_items as $item) {
        $all_scraped_text .= $this->scrapeUrlWithReadability($item->url) . "\n\n---\n\n";
      }

      if (empty(trim($all_scraped_text))) {
        $this->logger->warning('Readability scraping returned no text for Trend ID @id. Marking as processed without creating article.', ['@id' => $trend_id]);
         $this->database->update('google_trends_data')
          ->fields(['processed' => 1])
          ->condition('id', $trend_id)
          ->execute();
        return;
      }

      // 4. Send to OpenAI
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
        'model' => 'gpt-3.5-turbo',
        'messages' => [
          ['role' => 'user', 'content' => $prompt],
        ],
      ]);

      $article_body = $result->choices[0]->message->content;

      // 5. Create Node
      $this->logger->info(sprintf('Creating article for Trend ID %d.', $trend->id));
      $this->createArticleNode($trend, $article_body);

      // 6. Mark as processed
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
      throw new SuspendQueueException($e->getMessage(), $e->getCode(), $e);
    }
  }

  // ... (scrapeUrlWithReadability and createArticleNode methods remain the same) ...
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
          // Ensure directory exists
          $directory = dirname($this->fileSystem . '/google_trends/'); // Added a subdirectory
          \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
          
          $destination = $this->fileSystem . '/google_trends/' . $filename; // Use subdirectory
          
          $file = $this->fileRepository->writeData($file_data, $destination, FILE_EXISTS_REPLACE);
          if ($file) {
             $file_id = $file->id();
          } else {
             $this->logger->error('Failed to save file for Trend ID @id to @dest', [
               '@id' => $trend->id,
               '@dest' => $destination,
             ]);
          }
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to download or save image for Trend ID @id: @message', [
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
      // Check if the field exists before trying to set it.
      if ($node->hasField('field_image')) {
         $node->field_image->target_id = $file_id;
         $node->field_image->alt = $trend->title;
      } else {
         $this->logger->warning('Node type "article" is missing the "field_image" field. Cannot attach image for Trend ID @id.', ['@id' => $trend->id]);
      }
    }

    $node->save();

    // 4. Update our database table with the new Node ID.
    // This happens BEFORE marking as processed.
    $this->database->update('google_trends_data')
      ->fields(['node_id' => $node->id()])
      ->condition('id', $trend->id)
      ->execute();
  }
}