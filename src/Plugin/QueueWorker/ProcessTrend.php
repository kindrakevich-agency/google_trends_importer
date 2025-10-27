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
use DOMDocument;
use DOMXPath;

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

    // ... (properties, __construct, create, processItem, scrapeUrlWithReadability methods remain the same) ...
    protected $database;
    protected $openAiClient;
    protected $httpClient;
    protected $logger;
    protected $entityTypeManager;
    protected $fileRepository;
    protected $fileSystem;
    protected $configFactory;

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
      ConfigFactoryInterface $configFactory
    ) {
      parent::__construct($configuration, $plugin_id, $plugin_definition);
      $this->database = $database;
      $this->openAiClient = $openAiClient;
      $this->httpClient = $httpClient;
      $this->logger = $logger_factory->get('google_trends_importer');
      $this->entityTypeManager = $entityTypeManager;
      $this->fileRepository = $fileRepository;
      $this->configFactory = $configFactory;
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
        $config_factory
      );
    }

   /**
    * {@inheritdoc}
    */
    public function processItem($data) {
        $trend_id = $data;
        $separator = '---TITLE_SEPARATOR---';

        if (!$this->openAiClient) {
            $this->logger->error('OpenAI client is not available (API Key missing or invalid?). Cannot process Trend ID @id.', ['@id' => $trend_id]);
            return;
        }

        try {
            $trend = $this->database->select('google_trends_data', 'gtd')
              ->fields('gtd')
              ->condition('id', $trend_id)
              ->execute()
              ->fetch();

            if (!$trend) {
              $this->logger->error('Trend ID @id not found in google_trends_data table. Skipping.', ['@id' => $trend_id]);
              return;
            }

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

            $this->logger->info(sprintf('Sending Trend ID %d (%s) to OpenAI.', $trend->id, $trend->title));
            $config = $this->configFactory->get('google_trends_importer.settings');
            $prompt_template = $config->get('openai_prompt');
            $model_name = $config->get('openai_model') ?: 'gpt-3.5-turbo';

            if (empty($prompt_template)) {
                $this->logger->error('OpenAI Prompt Template is not configured. Cannot process Trend ID @id.', ['@id' => $trend_id]);
                throw new \Exception('OpenAI Prompt Template is empty.');
            }

            $prompt = sprintf(
                $prompt_template,
                $trend->title,
                $all_scraped_text
            );
            
            \Drupal::logger('send_to_open_ai_proceed')->notice('<pre><code>' . print_r($prompt, TRUE) . '</code></pre>' );

            $result = $this->openAiClient->chat()->create([
                'model' => $model_name,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            
            \Drupal::logger('send_to_open_ai_end')->notice('<pre><code>' . print_r($result, TRUE) . '</code></pre>' );

            $full_response = $result->choices[0]->message->content;

            $parts = explode($separator, $full_response, 2);

            if (count($parts) !== 2) {
                $this->logger->error('OpenAI response for Trend ID @id did not contain the expected separator "@sep". Using original trend title.', [
                    '@id' => $trend_id,
                    '@sep' => $separator
                ]);
                $generated_title = $trend->title;
                $article_body = trim($full_response);
            } else {
                $generated_title = trim($parts[0]);
                $article_body = trim($parts[1]);
            }

            $this->logger->info(sprintf('Creating article for Trend ID %d with generated title: %s', $trend->id, $generated_title));
            $this->createArticleNode($trend, $generated_title, $article_body);

            $this->database->update('google_trends_data')
                ->fields(['processed' => 1])
                ->condition('id', $trend_id)
                ->execute();

            $this->logger->info(sprintf('Successfully processed and created article for Trend ID %d.', $trend->id));

        } catch (\Exception $e) {
            $this->logger->error('Failed to process Trend ID @id: @message', [
                '@id' => $trend_id,
                '@message' => $e->getMessage(),
            ]);
            throw new SuspendQueueException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Helper function to scrape text from a URL using Readability.
     */
    private function scrapeUrlWithReadability($url) {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15, // Increased timeout slightly
                // Add a basic User-Agent to look less like a default bot
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                ]
            ]);
            $html = (string) $response->getBody();

            // Proceed only if HTML is not empty
            if (empty(trim($html))) {
                $this->logger->warning('Received empty HTML body for URL @url', ['@url' => $url]);
                return '';
            }

            // *** START DOMDocument Cleaning ***
            $cleaned_html = '';
            // Create DOMDocument object and load HTML, suppressing errors for invalid HTML
            $dom = new DOMDocument();
            libxml_use_internal_errors(true); // Enable user error handling

            // Prepend meta charset tag to help parser, load HTML
            // Note: Using loadHTML() implies HTML structure; NOIMPLIED/NODEFDTD might be too strict
            $load_success = $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
            $errors = libxml_get_errors(); // Get parsing errors
            libxml_clear_errors();
            libxml_use_internal_errors(false); // Restore default error handling

            if (!$load_success || !empty($errors)) {
                $error_messages = [];
                foreach ($errors as $error) {
                    $error_messages[] = trim($error->message) . ' (Line: ' . $error->line . ')';
                }
                $this->logger->warning('DOMDocument encountered HTML parsing errors for URL @url: @errors', [
                    '@url' => $url,
                    '@errors' => implode('; ', $error_messages)
                ]);
                // Decide if we should proceed even with errors. Let's try.
                // If loadHTML failed completely ($load_success = false), we might need to stop.
                 if (!$load_success) {
                     $this->logger->error('DOMDocument completely failed to load HTML for URL @url. Cannot clean.', ['@url' => $url]);
                     // Fallback: try Readability on the original (potentially broken) HTML
                     $cleaned_html = $html;
                 }
            }

             if ($load_success) { // Only clean if DOM loaded successfully
                // Create XPath object
                $xpath = new DOMXPath($dom);
                // Find all comment nodes
                $comments = $xpath->query('//comment()');
                // Remove each comment node
                if ($comments) {
                   for ($i = $comments->length - 1; $i >= 0; $i--) {
                       $comment = $comments->item($i);
                       if ($comment->parentNode) { // Check parent exists
                          $comment->parentNode->removeChild($comment);
                       }
                   }
                }
                // Get the cleaned HTML string back
                $cleaned_html = $dom->saveHTML();
             } else {
                 // If DOM failed to load, use original HTML for Readability as a last resort
                 $cleaned_html = $html;
             }
            // *** END DOMDocument Cleaning ***

            if (empty(trim($cleaned_html))) {
                $this->logger->warning('HTML became empty after cleaning for URL @url', ['@url' => $url]);
                return '';
            }

            $config = new Configuration();
            $config->setFixRelativeURLs(true);
            $config->setOriginalURL($url);
            // Optionally: Try disabling some cleaning steps if issues persist
            // $config->setCleanConditionally(false);
            // $config->setRemoveEmptyNodes(false);


            $readability = new Readability($config);
            // Parse the CLEANED (or original if cleaning failed) HTML
            $readability->parse($cleaned_html);


            $htmlContent = $readability->getContent();
            // Check based on stripped tags AND raw HTML content length as a fallback
            if (empty(trim(strip_tags($htmlContent))) && strlen($htmlContent) < 100) {
                $this->logger->warning('Readability could not extract meaningful content (empty after strip_tags) for URL @url', ['@url' => $url]);
                return '';
            }

            // Use the HTML content if available, otherwise fallback to plain text extraction
            $plainTextContent = strip_tags($htmlContent);
            $plainTextContent = html_entity_decode($plainTextContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plainTextContent = preg_replace('/\s+/', ' ', trim($plainTextContent));

             // Also check if plain text became empty after stripping/cleaning
             if (empty($plainTextContent)) {
                 $this->logger->warning('Readability content became empty after stripping tags for URL @url', ['@url' => $url]);
                 return '';
             }

            $title = $readability->getTitle();
            if (empty($title)) {
                $this->logger->info('Readability did not find a title for URL @url', ['@url' => $url]);
                // Try to get title from DOM as fallback
                 if ($load_success) {
                     $titleNodes = $xpath->query('//title');
                     if ($titleNodes && $titleNodes->length > 0) {
                         $title = trim($titleNodes->item(0)->nodeValue);
                     }
                 }
                 if (empty($title)) $title = 'Untitled Article'; // Final fallback
            }


            return $title . "\n\n" . $plainTextContent;

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Handle HTTP client errors specifically (e.g., 404, 403, timeout)
             $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
             $this->logger->warning('HTTP request failed for URL @url (Status: @status): @message', [
                 '@url' => $url,
                 '@status' => $statusCode,
                 '@message' => $e->getMessage(),
             ]);
             return ''; // Return empty on HTTP failure
        } catch (\Exception $e) {
            // Catch broader exceptions during processing
            $this->logger->warning('Failed to scrape or parse URL @url: @message', [
                '@url' => $url,
                '@message' => $e->getMessage(),
            ]);
            return '';
        }
    }


    /**
     * Helper function to create the node and save the node ID.
     */
    private function createArticleNode($trend, $generated_title, $body_text) {
        $node_storage = $this->entityTypeManager->getStorage('node');
        $file_system = \Drupal::service('file_system');

        // ... (Image handling code remains the same) ...
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

                    $directory = 'public://google_trends';
                    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

                    $destination = $directory . '/' . $filename;

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


        // *** Convert pub_date string to timestamp ***
        $created_timestamp = strtotime($trend->pub_date);
        // Fallback to current time if conversion fails
        if ($created_timestamp === FALSE) {
            $this->logger->warning('Failed to parse pub_date "@date" for Trend ID @id. Using current time for node creation.', [
                '@date' => $trend->pub_date,
                '@id' => $trend->id,
            ]);
            $created_timestamp = \Drupal::time()->getRequestTime();
        }
        // *** End conversion ***

        $node = $node_storage->create([
            'type' => 'article',
            'title' => $generated_title,
            'status' => 1,
            'body' => [
                'value' => $body_text,
                'format' => 'full_html',
            ],
            // *** Set the 'created' timestamp ***
            'created' => $created_timestamp,
        ]);

        $alt_text = !empty($generated_title) ? $generated_title : $trend->title;
        if ($file_id) {
            if ($node->hasField('field_image')) {
                $node->field_image->target_id = $file_id;
                $node->field_image->alt = $alt_text;
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