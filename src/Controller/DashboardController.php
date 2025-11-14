<?php

namespace Drupal\google_trends_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

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
   * Constructs a DashboardController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
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
