<?php

namespace Drupal\google_trends_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * Controller for the Google Trends Importer dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(Connection $database, ClientInterface $http_client) {
    $this->database = $database;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('http_client')
    );
  }

  /**
   * Fetches the current balance from OpenAI API.
   *
   * @param string $api_key
   *   The OpenAI API key.
   *
   * @return float|null
   *   The balance amount or NULL if unable to fetch.
   */
  protected function getOpenAIBalance($api_key) {
    if (empty($api_key)) {
      return NULL;
    }

    try {
      // Try to get subscription information
      $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/dashboard/billing/subscription', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'timeout' => 5,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      // If we have a hard_limit_usd, that's the total credit
      if (isset($data['hard_limit_usd'])) {
        // Now get the current usage
        $start_date = date('Y-m-01'); // First day of current month
        $end_date = date('Y-m-d'); // Today

        $usage_response = $this->httpClient->request('GET', 'https://api.openai.com/v1/dashboard/billing/usage', [
          'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
          ],
          'query' => [
            'start_date' => $start_date,
            'end_date' => $end_date,
          ],
          'timeout' => 5,
        ]);

        $usage_data = json_decode($usage_response->getBody()->getContents(), TRUE);
        $used = isset($usage_data['total_usage']) ? $usage_data['total_usage'] / 100 : 0;

        return max(0, $data['hard_limit_usd'] - $used);
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('google_trends_importer')->warning('Unable to fetch OpenAI balance: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetches the current balance from Claude/Anthropic API.
   *
   * @param string $api_key
   *   The Claude API key.
   *
   * @return float|null
   *   The balance amount or NULL if unable to fetch.
   */
  protected function getClaudeBalance($api_key) {
    if (empty($api_key)) {
      return NULL;
    }

    try {
      // Anthropic doesn't currently provide a public balance API endpoint
      // This is a placeholder for when/if they add one
      // For now, we'll return NULL and show "Not Available"

      // If Anthropic adds a balance endpoint in the future, it would look like:
      // $response = $this->httpClient->request('GET', 'https://api.anthropic.com/v1/account/balance', [
      //   'headers' => [
      //     'x-api-key' => $api_key,
      //     'anthropic-version' => '2023-06-01',
      //   ],
      //   'timeout' => 5,
      // ]);
      // $data = json_decode($response->getBody()->getContents(), TRUE);
      // return isset($data['balance']) ? $data['balance'] : NULL;

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('google_trends_importer')->warning('Unable to fetch Claude balance: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Displays the dashboard with analytics.
   *
   * @return array
   *   A render array for the dashboard page.
   */
  public function dashboard() {
    $config = $this->config('google_trends_importer.settings');

    // Get analytics data
    $total_trends = $this->database->query("SELECT COUNT(*) FROM {google_trends_data}")->fetchField();
    $processed_trends = $this->database->query("SELECT COUNT(*) FROM {google_trends_data} WHERE processed = 1")->fetchField();
    $pending_trends = $total_trends - $processed_trends;

    // Total cost
    $total_cost = $this->database->query("SELECT SUM(processing_cost) FROM {google_trends_data} WHERE processing_cost IS NOT NULL")->fetchField();
    $total_cost = $total_cost ? (float) $total_cost : 0;

    // Average cost per article
    $avg_cost = $processed_trends > 0 ? ($total_cost / $processed_trends) : 0;

    // Cost this month
    $first_day_month = strtotime('first day of this month 00:00:00');
    $cost_this_month = $this->database->query(
      "SELECT SUM(processing_cost) FROM {google_trends_data} WHERE imported_at >= :start AND processing_cost IS NOT NULL",
      [':start' => $first_day_month]
    )->fetchField();
    $cost_this_month = $cost_this_month ? (float) $cost_this_month : 0;

    // Trends this month
    $trends_this_month = $this->database->query(
      "SELECT COUNT(*) FROM {google_trends_data} WHERE imported_at >= :start",
      [':start' => $first_day_month]
    )->fetchField();

    // Recent trends (last 5)
    $recent_trends = $this->database->select('google_trends_data', 'gtd')
      ->fields('gtd', ['id', 'title', 'traffic', 'imported_at', 'processed', 'processing_cost', 'node_id'])
      ->orderBy('id', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAll();

    // Top traffic trends (top 5)
    $top_trends = $this->database->select('google_trends_data', 'gtd')
      ->fields('gtd', ['id', 'title', 'traffic', 'imported_at', 'processed', 'node_id'])
      ->orderBy('traffic', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAll();

    // Get current settings
    $ai_provider = $config->get('ai_provider') ?: 'openai';
    $ai_model = $ai_provider === 'openai' ? $config->get('openai_model') : $config->get('claude_model');
    $max_trends = $config->get('max_trends') ?: 5;
    $cron_enabled = $config->get('cron_enabled');
    $domain_id = $config->get('domain_id');

    // Get API balance for current provider
    $balance = NULL;
    if ($ai_provider === 'openai') {
      $api_key = $config->get('openai_api_key');
      $balance = $this->getOpenAIBalance($api_key);
    }
    elseif ($ai_provider === 'claude') {
      $api_key = $config->get('claude_api_key');
      $balance = $this->getClaudeBalance($api_key);
    }

    $build = [];

    // Status message
    $build['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--status']],
      'message' => [
        '#markup' => $this->t('Google Trends Importer is active. Provider: <strong>@provider</strong> (@model)', [
          '@provider' => ucfirst($ai_provider),
          '@model' => $ai_model,
        ]),
      ],
    ];

    // Statistics cards
    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['google-trends-stats']],
      '#attached' => [
        'library' => ['google_trends_importer/dashboard'],
      ],
    ];

    $build['stats']['total'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card']],
      'content' => [
        '#markup' => '<div class="stat-number">' . $total_trends . '</div><div class="stat-label">Total Trends</div>',
      ],
    ];

    $build['stats']['processed'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-success']],
      'content' => [
        '#markup' => '<div class="stat-number">' . $processed_trends . '</div><div class="stat-label">Articles Created</div>',
      ],
    ];

    $build['stats']['pending'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-warning']],
      'content' => [
        '#markup' => '<div class="stat-number">' . $pending_trends . '</div><div class="stat-label">Pending</div>',
      ],
    ];

    $build['stats']['month'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-info']],
      'content' => [
        '#markup' => '<div class="stat-number">' . $trends_this_month . '</div><div class="stat-label">This Month</div>',
      ],
    ];

    // Cost statistics
    $build['costs'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['google-trends-costs']],
    ];

    $build['costs']['total_cost'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cost-card']],
      'content' => [
        '#markup' => '<div class="cost-number">$' . number_format($total_cost, 4) . '</div><div class="cost-label">Total Cost</div>',
      ],
    ];

    $build['costs']['month_cost'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cost-card', 'cost-primary']],
      'content' => [
        '#markup' => '<div class="cost-number">$' . number_format($cost_this_month, 4) . '</div><div class="cost-label">Cost This Month</div>',
      ],
    ];

    $build['costs']['avg_cost'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cost-card']],
      'content' => [
        '#markup' => '<div class="cost-number">$' . number_format($avg_cost, 4) . '</div><div class="cost-label">Avg Cost per Article</div>',
      ],
    ];

    // API Balance card
    if ($balance !== NULL) {
      $build['costs']['balance'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cost-card', 'cost-balance']],
        'content' => [
          '#markup' => '<div class="cost-number">$' . number_format($balance, 2) . '</div><div class="cost-label">API Balance (' . ucfirst($ai_provider) . ')</div>',
        ],
      ];
    }
    else {
      $build['costs']['balance'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cost-card', 'cost-balance']],
        'content' => [
          '#markup' => '<div class="cost-number">N/A</div><div class="cost-label">API Balance (' . ucfirst($ai_provider) . ')</div>',
        ],
      ];
    }

    // Configuration overview
    $build['config_overview'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Configuration'),
      '#open' => TRUE,
    ];

    $config_items = [];
    $config_items[] = $this->t('<strong>AI Provider:</strong> @provider (@model)', [
      '@provider' => ucfirst($ai_provider),
      '@model' => $ai_model,
    ]);
    $config_items[] = $this->t('<strong>Max Trends per Cron:</strong> @max', ['@max' => $max_trends]);
    $config_items[] = $this->t('<strong>Cron Enabled:</strong> @status', ['@status' => $cron_enabled ? 'Yes' : 'No']);
    if ($domain_id) {
      $config_items[] = $this->t('<strong>Domain:</strong> @domain', ['@domain' => $domain_id]);
    }

    $build['config_overview']['list'] = [
      '#theme' => 'item_list',
      '#items' => $config_items,
    ];

    $build['config_overview']['settings_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit Settings'),
      '#url' => Url::fromRoute('google_trends_importer.settings_form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    // Recent trends table
    $build['recent'] = [
      '#type' => 'details',
      '#title' => $this->t('Recent Trends'),
      '#open' => TRUE,
    ];

    if (!empty($recent_trends)) {
      $rows = [];
      foreach ($recent_trends as $trend) {
        $row = [];
        $row[] = $trend->title;
        $row[] = $trend->traffic . 'K+';
        $row[] = date('Y-m-d H:i', $trend->imported_at);
        $row[] = $trend->processed ? $this->t('Yes') : $this->t('No');
        $row[] = $trend->processing_cost ? '$' . number_format($trend->processing_cost, 4) : 'N/A';
        if ($trend->node_id) {
          $row[] = [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('View'),
              '#url' => Url::fromRoute('entity.node.canonical', ['node' => $trend->node_id]),
            ],
          ];
        } else {
          $row[] = '';
        }
        $rows[] = $row;
      }

      $build['recent']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Title'),
          $this->t('Traffic'),
          $this->t('Imported'),
          $this->t('Processed'),
          $this->t('Cost'),
          $this->t('Article'),
        ],
        '#rows' => $rows,
      ];
    } else {
      $build['recent']['empty'] = [
        '#markup' => '<p>' . $this->t('No trends have been imported yet.') . '</p>',
      ];
    }

    // Top traffic trends
    $build['top'] = [
      '#type' => 'details',
      '#title' => $this->t('Top Traffic Trends'),
      '#open' => FALSE,
    ];

    if (!empty($top_trends)) {
      $rows = [];
      foreach ($top_trends as $trend) {
        $row = [];
        $row[] = $trend->title;
        $row[] = [
          'data' => [
            '#markup' => '<strong>' . $trend->traffic . 'K+</strong>',
          ],
        ];
        $row[] = date('Y-m-d', $trend->imported_at);
        if ($trend->node_id) {
          $row[] = [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('View'),
              '#url' => Url::fromRoute('entity.node.canonical', ['node' => $trend->node_id]),
            ],
          ];
        } else {
          $row[] = '';
        }
        $rows[] = $row;
      }

      $build['top']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Title'),
          $this->t('Traffic'),
          $this->t('Date'),
          $this->t('Article'),
        ],
        '#rows' => $rows,
      ];
    }

    // Actions
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-actions']],
    ];

    $build['actions']['fetch'] = [
      '#type' => 'link',
      '#title' => $this->t('Fetch New Trends'),
      '#url' => Url::fromRoute('google_trends_importer.manual_fetch_form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['actions']['clear'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear All Data'),
      '#url' => Url::fromRoute('google_trends_importer.clear_form'),
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];

    return $build;
  }

}
