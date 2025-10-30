<?php

namespace Drupal\google_trends_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Google Trends Importer settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_trends_importer_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['google_trends_importer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('google_trends_importer.settings');

    // AI Provider Selection
    $form['ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#description' => $this->t('Select which AI provider to use for article generation.'),
      '#options' => [
        'openai' => 'OpenAI (ChatGPT)',
        'claude' => 'Anthropic Claude',
      ],
      '#default_value' => $config->get('ai_provider') ?: 'openai',
      '#required' => TRUE,
    ];

    // OpenAI Settings
    $form['openai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI Settings'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="ai_provider"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['openai_settings']['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#description' => $this->t('Enter your API key from OpenAI.'),
      '#default_value' => $config->get('openai_api_key'),
      '#maxlength' => 255,
    ];

    $form['openai_settings']['openai_model'] = [
      '#type' => 'select',
      '#title' => $this->t('OpenAI Model'),
      '#description' => $this->t('Select the OpenAI model to use for content generation.'),
      '#options' => [
        'gpt-5' => 'GPT-5 (Next generation, highest capability)',
        'gpt-4o' => 'GPT-4o (Most capable, higher cost)',
        'gpt-4o-mini' => 'GPT-4o Mini (Balanced performance and cost)',
        'o1-preview' => 'O1 Preview (Advanced reasoning, highest cost)',
        'o1-mini' => 'O1 Mini (Fast reasoning, moderate cost)',
        'gpt-4-turbo' => 'GPT-4 Turbo (Previous generation, powerful)',
        'gpt-4' => 'GPT-4 (Legacy, slower)',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Fastest, lowest cost)',
      ],
      '#default_value' => $config->get('openai_model') ?: 'gpt-4o-mini',
      '#required' => TRUE,
    ];

    $form['openai_settings']['openai_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OpenAI Prompt Template'),
      '#description' => $this->t('Template for generating article title, body, and selecting tags. Use %s for placeholders: 1) trend title, 2) news content, 3) available tags (when vocabulary is selected). Include separators: ---TITLE_SEPARATOR--- (between title and body) and ---TAGS_SEPARATOR--- (between body and tags).'),
      '#rows' => 25,
      '#default_value' => $config->get('openai_prompt'),
      '#required' => TRUE,
    ];

    // Claude Settings
    $form['claude_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Claude Settings'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="ai_provider"]' => ['value' => 'claude'],
        ],
      ],
    ];

    $form['claude_settings']['claude_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Claude API Key'),
      '#description' => $this->t('Enter your API key from Anthropic.'),
      '#default_value' => $config->get('claude_api_key'),
      '#maxlength' => 255,
    ];

    $form['claude_settings']['claude_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Claude Model'),
      '#description' => $this->t('Select the Claude model to use for content generation.'),
      '#options' => [
        'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Latest, best for most tasks)',
        'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (Fastest, most cost-effective)',
        'claude-3-opus-20240229' => 'Claude 3 Opus (Most capable, highest cost)',
        'claude-3-sonnet-20240229' => 'Claude 3 Sonnet (Balanced)',
        'claude-3-haiku-20240307' => 'Claude 3 Haiku (Fast and efficient)',
      ],
      '#default_value' => $config->get('claude_model') ?: 'claude-3-5-sonnet-20241022',
      '#required' => TRUE,
    ];

    $form['claude_settings']['claude_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Claude Prompt Template'),
      '#description' => $this->t('Template for generating article title, body, and selecting tags. Use %s for placeholders: 1) trend title, 2) news content, 3) available tags (when vocabulary is selected). Include separators: ---TITLE_SEPARATOR--- (between title and body) and ---TAGS_SEPARATOR--- (between body and tags).'),
      '#rows' => 25,
      '#default_value' => $config->get('claude_prompt'),
      '#required' => TRUE,
    ];

    // Content Type Settings
    $form['content_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Type Settings'),
      '#open' => TRUE,
    ];

    // Get all content types
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $type) {
      $content_type_options[$type->id()] = $type->label();
    }

    $form['content_settings']['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t('Select the content type for imported articles.'),
      '#options' => $content_type_options,
      '#default_value' => $config->get('content_type') ?: 'article',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateFieldOptions',
        'wrapper' => 'field-options-wrapper',
      ],
    ];

    $form['content_settings']['field_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'field-options-wrapper'],
    ];

    $selected_content_type = $form_state->getValue('content_type') ?: $config->get('content_type') ?: 'article';
    $field_options = $this->getFieldOptions($selected_content_type);

    $form['content_settings']['field_wrapper']['image_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Field'),
      '#description' => $this->t('Select the image field for the content type. Images from articles will be downloaded and attached here.'),
      '#options' => $field_options['image'],
      '#default_value' => $config->get('image_field') ?: 'field_image',
      '#empty_option' => $this->t('- None -'),
    ];

    $form['content_settings']['field_wrapper']['video_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Video Embed Field'),
      '#description' => $this->t('Select the video embed field (video_embed_field type). YouTube/Vimeo videos from articles will be embedded here.'),
      '#options' => $field_options['video_embed'],
      '#default_value' => $config->get('video_field'),
      '#empty_option' => $this->t('- None -'),
    ];

    $form['content_settings']['field_wrapper']['tag_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Tag Field'),
      '#description' => $this->t('Select the taxonomy reference field for tags.'),
      '#options' => $field_options['taxonomy'],
      '#default_value' => $config->get('tag_field'),
      '#empty_option' => $this->t('- None -'),
    ];

    // Vocabulary Settings
    $form['taxonomy_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Taxonomy Settings'),
      '#open' => TRUE,
    ];

    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $vocab_options = [];
    foreach ($vocabularies as $vocab) {
      $vocab_options[$vocab->id()] = $vocab->label();
    }

    $form['taxonomy_settings']['tag_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Tag Vocabulary'),
      '#description' => $this->t('Select the vocabulary to use for auto-tagging articles. Available tags will be sent to ChatGPT for automatic selection.'),
      '#options' => $vocab_options,
      '#default_value' => $config->get('tag_vocabulary'),
      '#empty_option' => $this->t('- None -'),
    ];

    // Trends Feed Settings
    $form['feed_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Trends Feed Settings'),
      '#open' => TRUE,
    ];

    $form['feed_settings']['trends_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Google Trends RSS URL'),
      '#description' => $this->t('The RSS feed URL for daily trends. e.g., https://trends.google.com/trending/rss?geo=US'),
      '#default_value' => $config->get('trends_url') ?: 'https://trends.google.com/trending/rss?geo=US',
      '#maxlength' => 2048,
    ];

    $form['feed_settings']['min_traffic'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Traffic'),
      '#description' => $this->t('Only process trends with at least this many searches. Leave empty to process all trends. Enter the number without "K" or "+" (e.g., 100 for 100K+ searches).'),
      '#default_value' => $config->get('min_traffic'),
      '#min' => 0,
      '#step' => 1,
    ];

    $form['feed_settings']['max_trends'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Trends to Parse at Once'),
      '#description' => $this->t('Limit how many trends are fetched and queued in a single cron run. This helps prevent timeouts and manages API costs.'),
      '#default_value' => $config->get('max_trends') ?: 5,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['feed_settings']['cron_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Fetching via Cron'),
      '#description' => $this->t('If checked, new trends will be automatically fetched and queued when Drupal\'s cron runs.'),
      '#default_value' => $config->get('cron_enabled'),
    ];

    // Action buttons
    $form['actions']['manual_fetch'] = [
      '#type' => 'link',
      '#title' => $this->t('Manually Fetch New Trends Now'),
      '#url' => Url::fromRoute('google_trends_importer.manual_fetch_form'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#weight' => 10,
    ];

    $form['actions']['clear_data'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear Imported Data'),
      '#url' => Url::fromRoute('google_trends_importer.clear_form'),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#weight' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback to update field options based on selected content type.
   */
  public function updateFieldOptions(array &$form, FormStateInterface $form_state) {
    return $form['content_settings']['field_wrapper'];
  }

  /**
   * Get field options for a content type.
   */
  protected function getFieldOptions($content_type) {
    $options = [
      'image' => [],
      'taxonomy' => [],
      'video_embed' => [],
    ];

    try {
      $field_definitions = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties(['entity_type' => 'node', 'bundle' => $content_type]);

      foreach ($field_definitions as $field) {
        $field_type = $field->getType();
        $field_name = $field->getName();
        $field_label = $field->getLabel();

        if ($field_type === 'image') {
          $options['image'][$field_name] = $field_label;
        }
        elseif ($field_type === 'entity_reference' && $field->getSetting('target_type') === 'taxonomy_term') {
          $options['taxonomy'][$field_name] = $field_label;
        }
        elseif ($field_type === 'video_embed_field') {
          $options['video_embed'][$field_name] = $field_label;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger('google_trends_importer')->error('Error loading field options: @message', ['@message' => $e->getMessage()]);
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('google_trends_importer.settings')
      ->set('ai_provider', $form_state->getValue('ai_provider'))
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('openai_model', $form_state->getValue('openai_model'))
      ->set('openai_prompt', $form_state->getValue('openai_prompt'))
      ->set('claude_api_key', $form_state->getValue('claude_api_key'))
      ->set('claude_model', $form_state->getValue('claude_model'))
      ->set('claude_prompt', $form_state->getValue('claude_prompt'))
      ->set('content_type', $form_state->getValue('content_type'))
      ->set('image_field', $form_state->getValue('image_field'))
      ->set('video_field', $form_state->getValue('video_field'))
      ->set('tag_field', $form_state->getValue('tag_field'))
      ->set('tag_vocabulary', $form_state->getValue('tag_vocabulary'))
      ->set('trends_url', $form_state->getValue('trends_url'))
      ->set('min_traffic', $form_state->getValue('min_traffic'))
      ->set('max_trends', $form_state->getValue('max_trends'))
      ->set('cron_enabled', (bool) $form_state->getValue('cron_enabled'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}