<?php

namespace Drupal\google_trends_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Google Trends Importer settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  // ... getFormId(), getEditableConfigNames() remain the same ...
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
      // *** UPDATED DESCRIPTION ***
      '#description' => $this->t('Template for generating article title and body. Use %s for the original trend title (first) and the combined news snippets (second). Instruct the AI to separate the generated title and body with "---TITLE_SEPARATOR---".'),
      '#rows' => 20, // Increased rows slightly
      '#default_value' => $config->get('openai_prompt'),
      '#required' => TRUE,
    ];


    return parent::buildForm($form, $form_state);
  }

  // ... submitForm() remains the same ...
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('google_trends_importer.settings')
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('trends_url', $form_state->getValue('trends_url'))
      // Save the prompt value
      ->set('openai_prompt', $form_state->getValue('openai_prompt'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}