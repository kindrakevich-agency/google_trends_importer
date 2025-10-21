<?php

namespace Drupal\google_trends_importer\Plugin\QueueWorker;

// ... (keep all existing 'use' statements and class definition) ...
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use OpenAI\Client;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\File\FileSystemInterface;


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

    // ... (properties, __construct, create methods remain the same) ...
    protected $database;
    protected $openAiClient;
    protected $httpClient;
    protected $logger;
    protected $entityTypeManager;
    protected $fileRepository;
    protected $fileSystem;
    protected $configFactory; // Added this property back

   /**
    * Constructs a new ProcessTrend object.
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
      FileRepositoryInterface $fileRepository,
      ConfigFactoryInterface $configFactory // Added ConfigFactory back
    ) {
      parent::__construct($configuration, $plugin_id, $plugin_definition);
      $this->database = $database;
      $this->openAiClient = $openAiClient;
      $this->httpClient = $httpClient;
      $this->logger = $logger_factory->get('google_trends_importer');
      $this->entityTypeManager = $entityTypeManager;
      $this->fileRepository = $fileRepository;
      $this->configFactory = $configFactory; // Store ConfigFactory
      $this->fileSystem = 'public://';
    }

   /**
    * {@inheritdoc}
    */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      $config_factory = $container->get('config.factory');
      $config = $config_factory->get('google_trends_importer.settings');
      $api_key = $config->get('openai_api_key');

      $openai_client = null;
      if (!empty($api_key)) {
         try {
             $openai_client = \OpenAI::client($api_key);
         } catch (\Exception $e) {
             $container->get('logger.factory')->get('google_trends_importer')
                 ->error('Failed to create OpenAI client: @message', ['@message' => $e->getMessage()]);
         }
      } else {
          $container->get('logger.factory')->get('google_trends_importer')
              ->warning('OpenAI API Key is not configured. OpenAI functionality will be disabled.');
      }

      return new static(
        $configuration,
        $plugin_id,
        $plugin_definition,
        $container->get('database'),
        $openai_client,
        $container->get('http_client'),
        $container->get('logger.factory'),
        $container->get('entity_type.manager'),
        $container->get('file.repository'),
        $config_factory // Pass ConfigFactory
      );
    }


   /**
    * {@inheritdoc}
    */
    public function processItem($data) {
      $trend_id = $data;

      if (!$this->openAiClient) {
          $this->logger->error('OpenAI client is not available (API Key missing or invalid?). Cannot process Trend ID @id.', ['@id' => $trend_id]);
          return;
      }

      try {
        // ... (Steps 1, 2, 3 remain the same) ...
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

        // Get the prompt template from configuration
        $config = $this->configFactory->get('google_trends_importer.settings');
        $prompt_template = $config->get('openai_prompt'); // <-- Use configFactory property

        if (empty($prompt_template)) {
            $this->logger->error('OpenAI Prompt Template is not configured. Cannot process Trend ID @id.', ['@id' => $trend_id]);
             throw new \Exception('OpenAI Prompt Template is empty.');
        }

        // *** USE $prompt_template VARIABLE HERE ***
        $prompt = sprintf(
           $prompt_template, // <-- Use the variable read from config
           $trend->title,
           $all_scraped_text
        );
        // *** End of correction ***

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

            $htmlContent = $readability->getContent();
            if (empty(trim(strip_tags($htmlContent)))) {
                $this->logger->warning('Readability could not extract meaningful content for URL @url', ['@url' => $url]);
                return '';
            }

            $plainTextContent = strip_tags($htmlContent);
            $plainTextContent = html_entity_decode($plainTextContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plainTextContent = preg_replace('/\s+/', ' ', trim($plainTextContent));

            return $readability->getTitle() . "\n\n" . $plainTextContent;

        } catch (\Exception $e) {
            $this->logger->warning('Failed to scrape URL @url: @message', [
                '@url' => $url,
                '@message' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Helper function to create the node and save the node ID.
     */
    private function createArticleNode($trend, $body_text) {
        $node_storage = $this->entityTypeManager->getStorage('node');
        $file_system = \Drupal::service('file_system');

        $file_id = NULL;
        if (!empty($trend->image_url)) {
            try {
                $file_data = @file_get_contents($trend->image_url);
                if ($file_data === FALSE) {
                   $error = error_get_last();
                   $this->logger->warning('Failed to download image for Trend ID @id from @url: @message', [
                      '@id' => $trend->id,
                      '@url' => $trend->image_url,
                      '@message' => $error['message'] ?? 'Unknown error',
                   ]);
                } elseif (!empty($file_data)) {
                    $filename = 'trend_image_' . $trend->id . '.jpg';
                    $directory = $this->fileSystem . '/google_trends/';
                    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

                    $destination = $directory . $filename;

                    $file = $this->fileRepository->writeData($file_data, $destination, FileSystemInterface::EXISTS_REPLACE);
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
                $this->logger->warning('Exception during image download/save for Trend ID @id: @message', [
                    '@id' => $trend->id,
                    '@message' => $e->getMessage(),
                ]);
            }
        }

        $node = $node_storage->create([
            'type' => 'article',
            'title' => $trend->title,
            'status' => 0,
            'body' => [
                'value' => $body_text,
                'format' => 'basic_html',
            ],
        ]);

        if ($file_id) {
            if ($node->hasField('field_image')) {
                $node->field_image->target_id = $file_id;
                $node->field_image->alt = $trend->title;
            } else {
                $this->logger->warning('Node type "article" is missing the "field_image" field. Cannot attach image for Trend ID @id.', ['@id' => $trend->id]);
            }
        }

        $node->save();

        $this->database->update('google_trends_data')
            ->fields(['node_id' => $node->id()])
            ->condition('id', $trend->id)
            ->execute();
    }
}