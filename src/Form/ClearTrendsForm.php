<?php

namespace Drupal\google_trends_importer\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a confirmation form for clearing imported trends data.
 */
class ClearTrendsForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ClearTrendsForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * The database connection.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * The messenger service.
   */
  public function __construct(Connection $database, MessengerInterface $messenger) {
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_trends_importer_clear_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to clear all imported Google Trends data?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // Redirect back to the main settings page if cancelled.
    return new Url('google_trends_importer.settings_form');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will permanently delete all data from the %data_table and %news_table tables. This cannot be undone.', [
      '%data_table' => 'google_trends_data',
      '%news_table' => 'google_trends_news_items',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clear Data');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // You could add checkboxes here if you wanted separate clearing options.
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // Use truncate for efficiency, it's faster than DELETE FROM.
      $this->database->truncate('google_trends_news_items')->execute();
      $this->database->truncate('google_trends_data')->execute();

      // Also clear the processing queue, as the trend IDs no longer exist.
      $queue = \Drupal::queue('google_trends_processor');
      $queue->deleteQueue();

      $this->messenger->addStatus($this->t('All imported Google Trends data and queued items have been cleared.'));
      $this->logger('google_trends_importer')->notice('Cleared all Google Trends data tables and queue.');
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred while clearing the data. Check the logs for details.'));
      $this->logger('google_trends_importer')->error('Error clearing Google Trends data: @message', ['@message' => $e->getMessage()]);
    }

    // Redirect back to the settings page after clearing.
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}