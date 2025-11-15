<?php

namespace Drupal\google_trends_importer\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\google_trends_importer\Service\TrendsFetcher; // Use the fetcher service
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a confirmation form for manually fetching trends.
 */
class ManualFetchForm extends ConfirmFormBase {

  /**
   * The Trends Fetcher service.
   *
   * @var \Drupal\google_trends_importer\Service\TrendsFetcher
   */
  protected $trendsFetcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ManualFetchForm object.
   *
   * @param \Drupal\google_trends_importer\Service\TrendsFetcher $trends_fetcher
   * The trends fetcher service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * The messenger service.
   */
  public function __construct(TrendsFetcher $trends_fetcher, MessengerInterface $messenger) {
    $this->trendsFetcher = $trends_fetcher;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('google_trends_importer.fetcher'), // Inject the fetcher service
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_trends_importer_manual_fetch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to manually fetch new Google Trends now?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // Redirect back to the main settings page.
    return new Url('google_trends_importer.settings_form');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will check the Google Trends RSS feed for new items and add them to the processing queue. This is the same action that runs during cron (if enabled).');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Fetch Now');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Check if imports are globally enabled.
    $config = \Drupal::config('google_trends_importer.settings');
    if (!$config->get('import_enabled')) {
      $this->messenger->addWarning($this->t('Google Trends Import is currently disabled. Please enable it in the settings to fetch trends.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      // Call the fetcher service method directly.
      $this->trendsFetcher->fetchAndSaveTrends();
      // The fetcher service already logs success/no new items.
      // We can add a simple confirmation message here.
      $this->messenger->addStatus($this->t('Manual fetch process initiated. Check logs for details.'));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred during the manual fetch. Check the logs for details.'));
      $this->logger('google_trends_importer')->error('Error during manual fetch: @message', ['@message' => $e->getMessage()]);
    }

    // Redirect back to the settings page.
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}