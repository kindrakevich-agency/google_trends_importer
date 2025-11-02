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
use GuzzleHttp\Exception\RequestException;

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

  /**
   * OpenAI models with pricing per 1M tokens.
   */
  const OPENAI_MODELS = [
    'gpt-5' => ['input' => 5.00, 'output' => 15.00, 'label' => 'GPT-5 (Next generation, highest capability)'],
    'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'label' => 'GPT-4o (Most capable, higher cost)'],
    'gpt-4o-mini' => ['input' => 0.150, 'output' => 0.600, 'label' => 'GPT-4o Mini (Balanced performance and cost)'],
    'o1-preview' => ['input' => 15.00, 'output' => 60.00, 'label' => 'O1 Preview (Advanced reasoning, highest cost)'],
    'o1-mini' => ['input' => 3.00, 'output' => 12.00, 'label' => 'O1 Mini (Fast reasoning, moderate cost)'],
    'o1' => ['input' => 15.00, 'output' => 60.00, 'label' => 'O1 (Advanced reasoning)'],
    'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00, 'label' => 'GPT-4 Turbo (Previous generation, powerful)'],
    'gpt-4-turbo-preview' => ['input' => 10.00, 'output' => 30.00, 'label' => 'GPT-4 Turbo Preview'],
    'gpt-4-0125-preview' => ['input' => 10.00, 'output' => 30.00, 'label' => 'GPT-4 0125 Preview'],
    'gpt-4-1106-preview' => ['input' => 10.00, 'output' => 30.00, 'label' => 'GPT-4 1106 Preview'],
    'gpt-4' => ['input' => 30.00, 'output' => 60.00, 'label' => 'GPT-4 (Legacy, slower)'],
    'gpt-4-0613' => ['input' => 30.00, 'output' => 60.00, 'label' => 'GPT-4 0613'],
    'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50, 'label' => 'GPT-3.5 Turbo (Fastest, lowest cost)'],
    'gpt-3.5-turbo-0125' => ['input' => 0.50, 'output' => 1.50, 'label' => 'GPT-3.5 Turbo 0125'],
    'gpt-3.5-turbo-1106' => ['input' => 1.00, 'output' => 2.00, 'label' => 'GPT-3.5 Turbo 1106'],
  ];

  /**
   * Claude models with pricing per 1M tokens.
   */
  const CLAUDE_MODELS = [
    'claude-3-5-sonnet-20241022' => ['input' => 3.00, 'output' => 15.00, 'label' => 'Claude 3.5 Sonnet (Latest)'],
    'claude-3-5-haiku-20241022' => ['input' => 0.80, 'output' => 4.00, 'label' => 'Claude 3.5 Haiku (Fast)'],
    'claude-3-opus-20240229' => ['input' => 15.00, 'output' => 75.00, 'label' => 'Claude 3 Opus'],
    'claude-3-sonnet-20240229' => ['input' => 3.00, 'output' => 15.00, 'label' => 'Claude 3 Sonnet'],
    'claude-3-haiku-20240307' => ['input' => 0.25, 'output' => 1.25, 'label' => 'Claude 3 Haiku'],
  ];

  protected $database;
  protected $openAiClient;
  protected $claudeApiKey;
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
    Client $openAiClient = NULL,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entityTypeManager,
    FileRepositoryInterface $fileRepository,
    ConfigFactoryInterface $configFactory,
    $claudeApiKey = NULL
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    $this->openAiClient = $openAiClient;
    $this->claudeApiKey = $claudeApiKey;
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
    $ai_provider = $config->get('ai_provider') ?: 'openai';

    $openai_client = null;
    $claude_api_key = null;

    if ($ai_provider === 'openai') {
      // Check if OpenAI library is installed
      if (!class_exists('\OpenAI')) {
        $container->get('logger.factory')->get('google_trends_importer')
          ->error('OpenAI library is not installed. Please run: composer require openai-php/client');
        return new static(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $container->get('database'),
          null,
          $container->get('http_client'),
          $container->get('logger.factory'),
          $container->get('entity_type.manager'),
          $container->get('file.repository'),
          $config_factory,
          null
        );
      }

      $api_key = $config->get('openai_api_key');
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
    } elseif ($ai_provider === 'claude') {
      $claude_api_key = $config->get('claude_api_key');
      if (empty($claude_api_key)) {
        $container->get('logger.factory')->get('google_trends_importer')
          ->warning('Claude API Key is not configured. Claude functionality will be disabled.');
      }
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
      $config_factory,
      $claude_api_key
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $trend_id = $data;
    $separator = '---TITLE_SEPARATOR---';
    $tags_separator = '---TAGS_SEPARATOR---';

    // Get configuration
    $config = $this->configFactory->get('google_trends_importer.settings');
    $ai_provider = $config->get('ai_provider') ?: 'openai';

    // Check if AI client is available
    if ($ai_provider === 'openai' && !$this->openAiClient) {
      $this->logger->error('OpenAI client is not available (API Key missing or invalid?). Cannot process Trend ID @id.', ['@id' => $trend_id]);
      return;
    }
    if ($ai_provider === 'claude' && empty($this->claudeApiKey)) {
      $this->logger->error('Claude API key is not available. Cannot process Trend ID @id.', ['@id' => $trend_id]);
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
      $available_tags = $this->getAvailableTags($config);
      $tags_list = !empty($available_tags) ? implode(', ', $available_tags) : 'No tags available';

      // Get prompt template and model based on provider
      if ($ai_provider === 'claude') {
        $prompt_template = $config->get('claude_prompt');
        $model_name = $config->get('claude_model') ?: 'claude-3-5-sonnet-20241022';
        $provider_label = 'Claude';
      } else {
        $prompt_template = $config->get('openai_prompt');
        $model_name = $config->get('openai_model') ?: 'gpt-4o-mini';
        $provider_label = 'OpenAI';
      }

      if (empty($prompt_template)) {
        $this->logger->error('@provider Prompt Template is not configured. Cannot process Trend ID @id.', [
          '@provider' => $provider_label,
          '@id' => $trend_id,
        ]);
        throw new \Exception($provider_label . ' Prompt Template is empty.');
      }

      $this->logger->info(sprintf('Sending Trend ID %d (%s) to %s.', $trend->id, $trend->title, $provider_label));

      // Build the prompt with trend title, content, and tags
      $prompt = sprintf($prompt_template, $trend->title, $all_scraped_text, $tags_list);

      // Log the full prompt to dblog for review
      $this->logger->info('Sending prompt to @provider for Trend ID @id (@title). Full prompt logged below:', [
        '@provider' => $provider_label,
        '@id' => $trend->id,
        '@title' => $trend->title,
      ]);

      // Log full prompt in a separate entry for better readability
      $this->logger->debug('Full @provider Prompt for Trend ID @id:<br><pre>@prompt</pre>', [
        '@provider' => $provider_label,
        '@id' => $trend->id,
        '@prompt' => $prompt,
      ]);

      $start_time = microtime(true);

      // Call the appropriate AI provider
      if ($ai_provider === 'claude') {
        $result = $this->callClaudeApi($prompt, $model_name);
        $full_response = $result['content'];
        $processing_cost = $this->calculateClaudeCost($result, $model_name);
      } else {
        $result = $this->openAiClient->chat()->create([
          'model' => $model_name,
          'messages' => [
            ['role' => 'user', 'content' => $prompt],
          ],
        ]);
        $full_response = $result->choices[0]->message->content;
        $processing_cost = $this->calculateCost($result, $model_name);
      }

      $end_time = microtime(true);

      // Log the full AI response
      $this->logger->debug('Full @provider Response for Trend ID @id:<br><pre>@response</pre>', [
        '@provider' => $provider_label,
        '@id' => $trend->id,
        '@response' => $full_response,
      ]);

      // Parse response for title, body, and tags
      $parsed = $this->parseResponse($full_response, $separator, $tags_separator, $trend->title);

      $this->logger->info(sprintf('Creating article for Trend ID %d with generated title: %s', $trend->id, $parsed['title']));
      
      // Extract images and videos from article URLs
      $media = $this->extractMediaFromArticles($news_items);
      $this->logger->info('Extracted @images images and @video video for Trend ID @id', [
        '@images' => count($media['images']),
        '@video' => $media['video'] ? '1' : '0',
        '@id' => $trend->id,
      ]);
      
      $node_id = $this->createArticleNode($trend, $parsed['title'], $parsed['body'], $parsed['tags'], $config, $media);

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
    if (!isset(self::OPENAI_MODELS[$model_name])) {
      $this->logger->warning('Unknown model @model for cost calculation', ['@model' => $model_name]);
      return 0;
    }

    $pricing = self::OPENAI_MODELS[$model_name];
    $input_tokens = $result->usage->promptTokens ?? 0;
    $output_tokens = $result->usage->completionTokens ?? 0;

    $input_cost = ($input_tokens / 1000000) * $pricing['input'];
    $output_cost = ($output_tokens / 1000000) * $pricing['output'];

    return $input_cost + $output_cost;
  }

  /**
   * Call Claude API.
   */
  protected function callClaudeApi($prompt, $model_name) {
    try {
      $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
        'headers' => [
          'x-api-key' => $this->claudeApiKey,
          'anthropic-version' => '2023-06-01',
          'content-type' => 'application/json',
        ],
        'json' => [
          'model' => $model_name,
          'max_tokens' => 4096,
          'messages' => [
            [
              'role' => 'user',
              'content' => $prompt,
            ],
          ],
        ],
        'timeout' => 120,
      ]);

      $body = json_decode((string) $response->getBody(), TRUE);

      if (!isset($body['content'][0]['text'])) {
        throw new \Exception('Invalid response from Claude API');
      }

      return [
        'content' => $body['content'][0]['text'],
        'usage' => $body['usage'] ?? [],
      ];
    } catch (RequestException $e) {
      $this->logger->error('Claude API request failed: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Calculate the cost of the Claude API call.
   */
  protected function calculateClaudeCost($result, $model_name) {
    if (!isset(self::CLAUDE_MODELS[$model_name])) {
      $this->logger->warning('Unknown Claude model @model for cost calculation', ['@model' => $model_name]);
      return 0;
    }

    $pricing = self::CLAUDE_MODELS[$model_name];
    $input_tokens = $result['usage']['input_tokens'] ?? 0;
    $output_tokens = $result['usage']['output_tokens'] ?? 0;

    $input_cost = ($input_tokens / 1000000) * $pricing['input'];
    $output_cost = ($output_tokens / 1000000) * $pricing['output'];

    return $input_cost + $output_cost;
  }

  /**
   * Get available OpenAI models for settings form.
   *
   * @return array
   *   Array of model_id => label.
   */
  public static function getAvailableModels() {
    $models = [];
    foreach (self::OPENAI_MODELS as $model_id => $model_data) {
      $models[$model_id] = $model_data['label'];
    }
    return $models;
  }

  /**
   * Extract images and videos from scraped HTML content using Readability.
   *
   * @param array $news_items
   *   Array of news items with URLs.
   *
   * @return array
   *   Array with 'images' and 'video' keys.
   */
  protected function extractMediaFromArticles($news_items) {
    $all_images = [];
    $video_url = null;

    foreach ($news_items as $item) {
      try {
        $response = $this->httpClient->request('GET', $item->url, [
          'timeout' => 15,
          'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
          ]
        ]);
        $html = (string) $response->getBody();

        if (empty($html)) {
          continue;
        }

        // Use Readability to extract images
        $config = new Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL($item->url);

        $readability = new Readability($config);
        
        // Suppress HTML5 parsing warnings
        libxml_use_internal_errors(true);
        $readability->parse($html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        
        // Get images from Readability
        $readabilityImages = $readability->getImages();
        
        if (!empty($readabilityImages)) {
          foreach ($readabilityImages as $img_url) {
            // Readability returns absolute URLs
            $all_images[] = [
              'url' => $img_url,
              'width' => 0,
              'height' => 0,
              'alt' => '',
            ];
          }
        }

        // Extract video (YouTube/Vimeo iframes) - only first one
        if (!$video_url) {
          $dom = new DOMDocument();
          libxml_use_internal_errors(true);
          $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
          libxml_clear_errors();
          libxml_use_internal_errors(false);
          
          $xpath = new DOMXPath($dom);
          $iframe_nodes = $xpath->query('//iframe[contains(@src, "youtube.com") or contains(@src, "youtu.be") or contains(@src, "vimeo.com")]');
          if ($iframe_nodes->length > 0) {
            $video_url = $iframe_nodes->item(0)->getAttribute('src');
            
            // Clean up embed URL
            if (strpos($video_url, '//') === 0) {
              $video_url = 'https:' . $video_url;
            }
          }
        }

      } catch (\Exception $e) {
        $this->logger->warning('Failed to extract media from URL @url: @message', [
          '@url' => $item->url,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Remove duplicates
    $unique_images = [];
    $seen_urls = [];
    foreach ($all_images as $img) {
      if (!in_array($img['url'], $seen_urls)) {
        $unique_images[] = $img;
        $seen_urls[] = $img['url'];
      }
    }

    // Try to get image dimensions for better sorting
    foreach ($unique_images as &$img) {
      if ($img['width'] == 0 || $img['height'] == 0) {
        // Try to get dimensions from actual image
        try {
          $size = @getimagesize($img['url']);
          if ($size !== FALSE) {
            $img['width'] = $size[0];
            $img['height'] = $size[1];
          }
        } catch (\Exception $e) {
          // Ignore, keep 0x0
        }
      }
    }

    // Sort by resolution (largest first)
    usort($unique_images, function($a, $b) {
      $area_a = $a['width'] * $a['height'];
      $area_b = $b['width'] * $b['height'];
      
      if ($area_a == 0 && $area_b == 0) {
        return 0;
      }
      if ($area_a == 0) {
        return 1;
      }
      if ($area_b == 0) {
        return -1;
      }
      
      return $area_b - $area_a; // Descending order
    });

    return [
      'images' => $unique_images,
      'video' => $video_url,
    ];
  }

  /**
   * Get video thumbnail URL from YouTube or Vimeo.
   *
   * @param string $video_url
   *   The video embed URL.
   *
   * @return string|null
   *   Thumbnail URL or null.
   */
  protected function getVideoThumbnail($video_url) {
    if (empty($video_url)) {
      return null;
    }

    // YouTube
    if (preg_match('/youtube\.com\/embed\/([^?&]+)/', $video_url, $matches)) {
      $video_id = $matches[1];
      // Try maxresdefault first (1280x720), fallback to hqdefault (480x360)
      $maxres_url = "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg";
      $hq_url = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
      
      // Check if maxres exists
      try {
        $response = $this->httpClient->request('HEAD', $maxres_url, ['timeout' => 5]);
        if ($response->getStatusCode() === 200) {
          return $maxres_url;
        }
      } catch (\Exception $e) {
        // Fallback to hq
      }
      
      return $hq_url;
    }
    
    // YouTube short URL
    if (preg_match('/youtu\.be\/([^?&]+)/', $video_url, $matches)) {
      $video_id = $matches[1];
      return "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg";
    }

    // Vimeo
    if (preg_match('/vimeo\.com\/video\/(\d+)/', $video_url, $matches)) {
      $video_id = $matches[1];
      try {
        $api_url = "https://vimeo.com/api/v2/video/{$video_id}.json";
        $response = $this->httpClient->request('GET', $api_url, ['timeout' => 10]);
        $data = json_decode((string) $response->getBody(), TRUE);
        if (isset($data[0]['thumbnail_large'])) {
          return $data[0]['thumbnail_large'];
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to get Vimeo thumbnail: @message', ['@message' => $e->getMessage()]);
      }
    }

    return null;
  }

  /**
   * Generate slug from title.
   *
   * @param string $title
   *   The title to slugify.
   *
   * @return string
   *   The slug.
   */
  protected function generateSlug($title) {
    // Transliterate first (handles Cyrillic, special chars, etc.)
    $transliterated = \Drupal::transliteration()->transliterate($title, 'en');
    
    // Convert to lowercase
    $slug = strtolower($transliterated);
    
    // Replace spaces and special characters with hyphens
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    
    // Remove leading/trailing hyphens
    $slug = trim($slug, '-');
    
    // Limit length
    $slug = substr($slug, 0, 100);
    
    return $slug;
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

      // Use Readability directly without pre-cleaning
      $config = new Configuration();
      $config->setFixRelativeURLs(true);
      $config->setOriginalURL($url);

      $readability = new Readability($config);
      
      // Parse HTML - Readability handles cleaning internally
      // Suppress libxml errors as they're mostly about HTML5 tags
      libxml_use_internal_errors(true);
      $readability->parse($html);
      libxml_clear_errors();
      libxml_use_internal_errors(false);

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
        $title = 'Untitled Article';
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
   * Helper function to create the node and attach tags, images, and video.
   */
  private function createArticleNode($trend, $generated_title, $body_text, $tags, $config, $media) {
    $content_type = $config->get('content_type') ?: 'article';
    $image_field = $config->get('image_field') ?: 'field_image';
    $tag_field = $config->get('tag_field');

    $node_storage = $this->entityTypeManager->getStorage('node');
    $file_system = \Drupal::service('file_system');

    // Generate slug for filenames
    $slug = $this->generateSlug($generated_title);

    // Embed video in body if present
    if (!empty($media['video'])) {
      $video_url = $media['video'];
      // Ensure the video URL is suitable for embedding
      if (strpos($video_url, 'youtube.com') !== FALSE || strpos($video_url, 'youtu.be') !== FALSE || strpos($video_url, 'vimeo.com') !== FALSE) {
        $video_embed = '<div class="embedded-video" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%; margin: 20px 0;">';
        $video_embed .= '<iframe src="' . htmlspecialchars($video_url, ENT_QUOTES, 'UTF-8') . '" ';
        $video_embed .= 'style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" ';
        $video_embed .= 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
        $video_embed .= '</div>';

        // Add video embed at the beginning of the body
        $body_text = $video_embed . "\n\n" . $body_text;

        $this->logger->info('Embedded video @url in article body for Trend ID @id', [
          '@url' => $video_url,
          '@id' => $trend->id,
        ]);
      }
    }

    $file_ids = [];
    
    // Download images from articles
    if (!empty($media['images']) && !empty($image_field)) {
      $directory = 'public://google_trends';
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      
      $image_index = 0;
      foreach ($media['images'] as $image_data) {
        try {
          $image_content = @file_get_contents($image_data['url']);
          if ($image_content === FALSE || empty($image_content)) {
            continue;
          }
          
          // Determine file extension
          $extension = 'jpg';
          if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $image_data['url'], $ext_match)) {
            $extension = strtolower($ext_match[1]);
            if ($extension === 'jpeg') {
              $extension = 'jpg';
            }
          }
          
          // Generate filename
          $filename = $image_index === 0 ? "{$slug}.{$extension}" : "{$slug}-{$image_index}.{$extension}";
          $destination = $directory . '/' . $filename;
          
          $file = $this->fileRepository->writeData($image_content, $destination, FileSystemInterface::EXISTS_REPLACE);
          if ($file) {
            $file_ids[] = [
              'target_id' => $file->id(),
              'alt' => $image_data['alt'] ?: $generated_title,
            ];
            $image_index++;
            
            $this->logger->info('Downloaded image @filename for Trend ID @id', [
              '@filename' => $filename,
              '@id' => $trend->id,
            ]);
          }
        } catch (\Exception $e) {
          $this->logger->warning('Failed to download image from @url: @message', [
            '@url' => $image_data['url'],
            '@message' => $e->getMessage(),
          ]);
        }
        
        // Limit to reasonable number of images
        if ($image_index >= 10) {
          break;
        }
      }
    }
    
    // If no images from articles, try the trend main image
    if (empty($file_ids) && !empty($trend->image_url) && !empty($image_field)) {
      try {
        $file_data = @file_get_contents($trend->image_url);
        if ($file_data !== FALSE && !empty($file_data)) {
          $directory = 'public://google_trends';
          $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
          
          $filename = "{$slug}.jpg";
          $destination = $directory . '/' . $filename;
          
          $file = $this->fileRepository->writeData($file_data, $destination, FileSystemInterface::EXISTS_REPLACE);
          if ($file) {
            $file_ids[] = [
              'target_id' => $file->id(),
              'alt' => $generated_title,
            ];
            $this->logger->info('Downloaded trend main image as @filename', ['@filename' => $filename]);
          }
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to download trend main image: @message', ['@message' => $e->getMessage()]);
      }
    }
    
    // Download video thumbnail if video exists
    if (!empty($media['video']) && !empty($image_field)) {
      $thumbnail_url = $this->getVideoThumbnail($media['video']);
      if ($thumbnail_url) {
        try {
          $thumbnail_data = @file_get_contents($thumbnail_url);
          if ($thumbnail_data !== FALSE && !empty($thumbnail_data)) {
            $directory = 'public://google_trends';
            $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
            
            // Add video thumbnail as first image
            $filename = "{$slug}-video-thumb.jpg";
            $destination = $directory . '/' . $filename;
            
            $file = $this->fileRepository->writeData($thumbnail_data, $destination, FileSystemInterface::EXISTS_REPLACE);
            if ($file) {
              // Prepend video thumbnail to beginning of array
              array_unshift($file_ids, [
                'target_id' => $file->id(),
                'alt' => $generated_title . ' - Video',
              ]);
              $this->logger->info('Downloaded video thumbnail as @filename', ['@filename' => $filename]);
            }
          }
        } catch (\Exception $e) {
          $this->logger->warning('Failed to download video thumbnail: @message', ['@message' => $e->getMessage()]);
        }
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

    // Attach images if field exists
    if (!empty($file_ids) && $node->hasField($image_field)) {
      $node->set($image_field, $file_ids);
      $this->logger->info('Attached @count images to article for Trend ID @id', [
        '@count' => count($file_ids),
        '@id' => $trend->id,
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

    // Assign domain if configured and Domain module is enabled
    $domain_id = $config->get('domain_id');
    if (!empty($domain_id) && \Drupal::moduleHandler()->moduleExists('domain')) {
      if ($node->hasField('field_domain_access')) {
        $node->set('field_domain_access', [$domain_id]);
        $this->logger->info('Assigned domain @domain to article for Trend ID @id', [
          '@domain' => $domain_id,
          '@id' => $trend->id,
        ]);
      }
      // Set domain source if the field exists and skip_domain_source is not checked
      $skip_domain_source = $config->get('skip_domain_source');
      if (!$skip_domain_source && $node->hasField('field_domain_source')) {
        $node->set('field_domain_source', $domain_id);
        $this->logger->info('Set domain source @domain for Trend ID @id', [
          '@domain' => $domain_id,
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