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
 *   id = "google_trends_processor",
 *   title = @Translation("Google Trends Processor"),
 *   cron = {"time" = 60}
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
    $tags_separator = '---TAGS_SEPARATOR---';

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

      // Get available tags from vocabulary
      $config = $this->configFactory->get('google_trends_importer.settings');
      $available_tags = $this->getAvailableTags($config);
      $tags_list = !empty($available_tags) ? implode(', ', $available_tags) : 'No tags available';

      $this->logger->info(sprintf('Sending Trend ID %d (%s) to OpenAI.', $trend->id, $trend->title));
      
      $prompt_template = $config->get('openai_prompt');
      $model_name = $config->get('openai_model') ?: 'gpt-4o-mini';

      if (empty($prompt_template)) {
        $this->logger->error('OpenAI Prompt Template is not configured. Cannot process Trend ID @id.', ['@id' => $trend_id]);
        throw new \Exception('OpenAI Prompt Template is empty.');
      }

      // Build the prompt with trend title, content, and tags
      $prompt = sprintf($prompt_template, $trend->title, $all_scraped_text, $tags_list);

      $start_time = microtime(true);
      
      $result = $this->openAiClient->chat()->create([
        'model' => $model_name,
        'messages' => [
          ['role' => 'user', 'content' => $prompt],
        ],
      ]);
      
      $end_time = microtime(true);
      $processing_cost = $this->calculateCost($result, $model_name);

      $full_response = $result->choices[0]->message->content;

      // Parse response for title, body, and tags
      $parsed = $this->parseResponse($full_response, $separator, $tags_separator, $trend->title);

      $this->logger->info(sprintf('Creating article for Trend ID %d with generated title: %s', $trend->id, $parsed['title']));
      
      $node_id = $this->createArticleNode($trend, $parsed['title'], $parsed['body'], $parsed['tags'], $config);

      // Update trend record with processing info
      $this->database->update('google_trends_data')
        ->fields([
          'processed' => 1,
          'node_id' => $node_id,
          'processing_cost' => $processing_cost,
        ])
        ->condition('id', $trend_id)
        ->execute();

      $this->logger->info(sprintf('Successfully processed and created article for Trend ID %d. Cost: $%s', $trend->id, number_format($processing_cost, 6)));

    } catch (\Exception $e) {
      $this->logger->error('Failed to process Trend ID @id: @message', [
        '@id' => $trend_id,
        '@message' => $e->getMessage(),
      ]);
      throw new SuspendQueueException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Get available tags from the configured vocabulary.
   */
  protected function getAvailableTags($config) {
    $tags = [];
    $vocab_id = $config->get('tag_vocabulary');
    
    if (empty($vocab_id)) {
      return $tags;
    }

    try {
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadTree($vocab_id, 0, NULL, TRUE);

      foreach ($terms as $term) {
        $tags[] = $term->getName();
      }
    } catch (\Exception $e) {
      $this->logger->warning('Failed to load tags from vocabulary @vocab: @message', [
        '@vocab' => $vocab_id,
        '@message' => $e->getMessage(),
      ]);
    }

    return $tags;
  }

  /**
   * Build the prompt with tags if available.
   */
  protected function buildPrompt($template, $title, $content, $tags) {
    $tags_instruction = '';
    
    if (!empty($tags)) {
      $tags_list = implode(', ', $tags);
      $tags_instruction_template = $this->configFactory->get('google_trends_importer.settings')->get('tags_instruction');
      
      if (!empty($tags_instruction_template)) {
        $tags_instruction = sprintf($tags_instruction_template, $tags_list);
      }
    }

    // Add tags instruction to the template
    $enhanced_template = $template . $tags_instruction;
    
    return sprintf($enhanced_template, $title, $content);
  }

  /**
   * Parse the OpenAI response.
   */
  protected function parseResponse($full_response, $separator, $tags_separator, $fallback_title) {
    $result = [
      'title' => $fallback_title,
      'body' => '',
      'tags' => [],
    ];

    // First split by title separator
    $parts = explode($separator, $full_response, 2);
    
    if (count($parts) === 2) {
      $result['title'] = trim($parts[0]);
      $remaining = $parts[1];
      
      // Check for tags separator
      if (strpos($remaining, $tags_separator) !== FALSE) {
        $body_tags_parts = explode($tags_separator, $remaining, 2);
        $result['body'] = trim($body_tags_parts[0]);
        
        if (isset($body_tags_parts[1])) {
          $tags_string = trim($body_tags_parts[1]);
          $tags_array = array_map('trim', explode(',', $tags_string));
          $result['tags'] = array_filter($tags_array);
        }
      } else {
        $result['body'] = trim($remaining);
      }
    } else {
      $this->logger->warning('OpenAI response did not contain expected separators. Using full response as body.');
      $result['body'] = trim($full_response);
    }

    return $result;
  }

  /**
   * Calculate the cost of the OpenAI API call.
   */
  protected function calculateCost($result, $model_name) {
    // Pricing per 1M tokens (as of 2024)
    $pricing = [
      'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
      'gpt-4o-mini' => ['input' => 0.150, 'output' => 0.600],
      'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
      'gpt-4' => ['input' => 30.00, 'output' => 60.00],
      'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
    ];

    if (!isset($pricing[$model_name])) {
      return 0;
    }

    $input_tokens = $result->usage->promptTokens ?? 0;
    $output_tokens = $result->usage->completionTokens ?? 0;

    $input_cost = ($input_tokens / 1000000) * $pricing[$model_name]['input'];
    $output_cost = ($output_tokens / 1000000) * $pricing[$model_name]['output'];

    return $input_cost + $output_cost;
  }

  /**
   * Helper function to scrape text from a URL using Readability.
   */
  private function scrapeUrlWithReadability($url) {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 15,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]
      ]);
      $html = (string) $response->getBody();

      if (empty(trim($html))) {
        $this->logger->warning('Received empty HTML body for URL @url', ['@url' => $url]);
        return '';
      }

      $cleaned_html = '';
      $dom = new DOMDocument();
      libxml_use_internal_errors(true);

      $load_success = $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
      $errors = libxml_get_errors();
      libxml_clear_errors();
      libxml_use_internal_errors(false);

      if (!$load_success || !empty($errors)) {
        $error_messages = [];
        foreach ($errors as $error) {
          $error_messages[] = trim($error->message) . ' (Line: ' . $error->line . ')';
        }
        $this->logger->warning('DOMDocument encountered HTML parsing errors for URL @url: @errors', [
          '@url' => $url,
          '@errors' => implode('; ', $error_messages)
        ]);
        
        if (!$load_success) {
          $this->logger->error('DOMDocument completely failed to load HTML for URL @url. Cannot clean.', ['@url' => $url]);
          $cleaned_html = $html;
        }
      }

      if ($load_success) {
        $xpath = new DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments) {
          for ($i = $comments->length - 1; $i >= 0; $i--) {
            $comment = $comments->item($i);
            if ($comment->parentNode) {
              $comment->parentNode->removeChild($comment);
            }
          }
        }
        $cleaned_html = $dom->saveHTML();
      } else {
        $cleaned_html = $html;
      }

      if (empty(trim($cleaned_html))) {
        $this->logger->warning('HTML became empty after cleaning for URL @url', ['@url' => $url]);
        return '';
      }

      $config = new Configuration();
      $config->setFixRelativeURLs(true);
      $config->setOriginalURL($url);

      $readability = new Readability($config);
      $readability->parse($cleaned_html);

      $htmlContent = $readability->getContent();
      if (empty(trim(strip_tags($htmlContent))) && strlen($htmlContent) < 100) {
        $this->logger->warning('Readability could not extract meaningful content for URL @url', ['@url' => $url]);
        return '';
      }

      $plainTextContent = strip_tags($htmlContent);
      $plainTextContent = html_entity_decode($plainTextContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $plainTextContent = preg_replace('/\s+/', ' ', trim($plainTextContent));

      if (empty($plainTextContent)) {
        $this->logger->warning('Readability content became empty after stripping tags for URL @url', ['@url' => $url]);
        return '';
      }

      $title = $readability->getTitle();
      if (empty($title)) {
        $this->logger->info('Readability did not find a title for URL @url', ['@url' => $url]);
        if ($load_success) {
          $titleNodes = $xpath->query('//title');
          if ($titleNodes && $titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->nodeValue);
          }
        }
        if (empty($title)) $title = 'Untitled Article';
      }

      return $title . "\n\n" . $plainTextContent;

    } catch (\GuzzleHttp\Exception\RequestException $e) {
      $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
      $this->logger->warning('HTTP request failed for URL @url (Status: @status): @message', [
        '@url' => $url,
        '@status' => $statusCode,
        '@message' => $e->getMessage(),
      ]);
      return '';
    } catch (\Exception $e) {
      $this->logger->warning('Failed to scrape or parse URL @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Helper function to create the node and attach tags.
   */
  private function createArticleNode($trend, $generated_title, $body_text, $tags, $config) {
    $content_type = $config->get('content_type') ?: 'article';
    $image_field = $config->get('image_field') ?: 'field_image';
    $tag_field = $config->get('tag_field');
    
    $node_storage = $this->entityTypeManager->getStorage('node');
    $file_system = \Drupal::service('file_system');

    // Handle image
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

    // Convert pub_date to timestamp
    $created_timestamp = strtotime($trend->pub_date);
    if ($created_timestamp === FALSE) {
      $this->logger->warning('Failed to parse pub_date "@date" for Trend ID @id. Using current time.', [
        '@date' => $trend->pub_date,
        '@id' => $trend->id,
      ]);
      $created_timestamp = \Drupal::time()->getRequestTime();
    }

    // Create node
    $node = $node_storage->create([
      'type' => $content_type,
      'title' => $generated_title,
      'status' => 1,
      'body' => [
        'value' => $body_text,
        'format' => 'full_html',
      ],
      'created' => $created_timestamp,
    ]);

    // Attach image if field exists
    $alt_text = !empty($generated_title) ? $generated_title : $trend->title;
    if ($file_id && $node->hasField($image_field)) {
      $node->set($image_field, [
        'target_id' => $file_id,
        'alt' => $alt_text,
      ]);
    }

    // Attach tags if configured and available
    if (!empty($tag_field) && !empty($tags) && $node->hasField($tag_field)) {
      $tag_ids = $this->getOrCreateTagIds($tags, $config);
      if (!empty($tag_ids)) {
        $node->set($tag_field, $tag_ids);
        $this->logger->info('Attached @count tags to article for Trend ID @id', [
          '@count' => count($tag_ids),
          '@id' => $trend->id,
        ]);
      }
    }

    $node->save();
    return $node->id();
  }

  /**
   * Get or create taxonomy term IDs for the given tag names.
   */
  protected function getOrCreateTagIds($tag_names, $config) {
    $tag_ids = [];
    $vocab_id = $config->get('tag_vocabulary');
    
    if (empty($vocab_id)) {
      return $tag_ids;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach ($tag_names as $tag_name) {
      // Try to find existing term
      $terms = $term_storage->loadByProperties([
        'name' => $tag_name,
        'vid' => $vocab_id,
      ]);

      if (!empty($terms)) {
        $term = reset($terms);
        $tag_ids[] = ['target_id' => $term->id()];
      } else {
        // Create new term if it doesn't exist
        try {
          $term = $term_storage->create([
            'name' => $tag_name,
            'vid' => $vocab_id,
          ]);
          $term->save();
          $tag_ids[] = ['target_id' => $term->id()];
          $this->logger->info('Created new tag: @tag', ['@tag' => $tag_name]);
        } catch (\Exception $e) {
          $this->logger->warning('Failed to create tag "@tag": @message', [
            '@tag' => $tag_name,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return $tag_ids;
  }
}