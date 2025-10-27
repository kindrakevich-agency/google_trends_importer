<?php

namespace Drupal\google_trends_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure Google Trends Importer settings for this site.
 */
class SettingsForm extends ConfigFormBase {

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

    $form['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#description' => $this->t('Enter your API key from OpenAI.'),
      '#default_value' => $config->get('openai_api_key'),
      '#maxlength' => 255,
    ];

    // Add textfield for OpenAI model
    $form['openai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI Model Name'),
      '#description' => $this->t('Enter the model name to use (e.g., gpt-3.5-turbo, gpt-4, gpt-4-turbo). Check OpenAI documentation for available models.'),
      '#default_value' => $config->get('openai_model') ?: 'gpt-3.5-turbo',
      '#required' => TRUE,
    ];

    $form['trends_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Google Trends RSS URL'),
      '#description' => $this->t('The RSS feed URL for daily trends. e.g., https://trends.google.com/trending/rss?geo=US'),
      '#default_value' => $config->get('trends_url') ?: 'https://trends.google.com/trending/rss?geo=US',
      '#maxlength' => 2048,
    ];

    $form['openai_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OpenAI Prompt Template'),
      '#description' => $this->t('Template for generating article title and body. Use %s for the original trend title (first) and the combined news snippets (second). Instruct the AI to separate the generated title and body with "---TITLE_SEPARATOR---".'),
      '#rows' => 20,
      '#default_value' => $config->get('openai_prompt'),
      '#required' => TRUE,
    ];

    $form['cron_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Fetching via Cron'),
      '#description' => $this->t('If checked, new trends will be automatically fetched and queued when Drupal\'s cron runs.'),
      '#default_value' => $config->get('cron_enabled'),
    ];

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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('google_trends_importer.settings')
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('openai_model', $form_state->getValue('openai_model')) // Save the model
      ->set('trends_url', $form_state->getValue('trends_url'))
      ->set('openai_prompt', $form_state->getValue('openai_prompt'))
      ->set('cron_enabled', (bool) $form_state->getValue('cron_enabled'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}