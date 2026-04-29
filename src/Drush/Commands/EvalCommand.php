<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Drush\Commands;

use Drupal\dkan_drupal_ai_query\Eval\EvalRunner;
use Drupal\dkan_drupal_ai_query\Eval\GoldenCaseLoader;
use Drupal\dkan_drupal_ai_query\Eval\RunReporter;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the dkan_drupal_ai_query eval harness.
 */
class EvalCommand extends DrushCommands {

  protected const DEFAULT_PROVIDER = 'anthropic';
  protected const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

  public function __construct(
    protected GoldenCaseLoader $loader,
    protected EvalRunner $runner,
    protected RunReporter $reporter,
    protected string $modulePath,
  ) {
    parent::__construct();
  }

  /**
   * Build the command instance from the service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dkan_drupal_ai_query.eval.loader'),
      $container->get('dkan_drupal_ai_query.eval.runner'),
      $container->get('dkan_drupal_ai_query.eval.reporter'),
      $container->get('extension.list.module')->getPath('dkan_drupal_ai_query'),
    );
  }

  /**
   * Run the dkan_drupal_ai_query eval harness against the golden set.
   */
  #[CLI\Command(name: 'dkan-aiq:eval', aliases: ['dkan-aiq-eval'])]
  #[CLI\Option(name: 'set', description: 'Path to the golden set YAML.')]
  #[CLI\Option(name: 'case', description: 'Run only the case with this id.')]
  #[CLI\Option(name: 'provider', description: 'AI provider id.')]
  #[CLI\Option(name: 'model', description: 'AI model id.')]
  #[CLI\Option(name: 'output-dir', description: 'Where to write run-{label}.jsonl and run-{label}.md.')]
  #[CLI\Option(name: 'label', description: 'Label for the run filename.')]
  #[CLI\Option(name: 'no-cache-clear', description: 'Skip drupal_flush_all_caches() between cases.')]
  #[CLI\Option(name: 'sleep-seconds', description: 'Pause this many seconds between cases to dodge LLM rate limits.')]
  #[CLI\Usage(name: 'drush dkan-aiq:eval', description: 'Run the full golden set and write reports.')]
  #[CLI\Usage(name: 'drush dkan-aiq:eval --case=crime_count_total', description: 'Run a single case by id.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function eval(
    array $options = [
      'set' => self::OPT,
      'case' => self::OPT,
      'provider' => self::OPT,
      'model' => self::OPT,
      'output-dir' => self::OPT,
      'label' => self::OPT,
      'no-cache-clear' => FALSE,
      'sleep-seconds' => self::OPT,
    ],
  ): int {
    $setPath = !empty($options['set'])
      ? (string) $options['set']
      : $this->modulePath . '/tests/eval/golden_set.yml';

    $providerId = !empty($options['provider']) ? (string) $options['provider'] : self::DEFAULT_PROVIDER;
    $modelId = !empty($options['model']) ? (string) $options['model'] : self::DEFAULT_MODEL;
    $outputDir = !empty($options['output-dir'])
      ? (string) $options['output-dir']
      : 'sites/default/files/private/ai-eval';
    $runLabel = !empty($options['label']) ? (string) $options['label'] : gmdate('Ymd-His');
    $clearCaches = empty($options['no-cache-clear']);
    $sleepSeconds = isset($options['sleep-seconds']) ? max(0, (int) $options['sleep-seconds']) : 0;

    try {
      $cases = $this->loader->load($setPath);
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    if (!empty($options['case'])) {
      $needle = (string) $options['case'];
      $cases = array_values(array_filter($cases, fn($c) => $c->id === $needle));
      if (!$cases) {
        $this->logger()->error("No case with id '{$needle}' in {$setPath}");
        return self::EXIT_FAILURE;
      }
    }

    $this->output()->writeln(sprintf(
      '<info>Running %d case(s) against %s/%s</info>',
      count($cases),
      $providerId,
      $modelId,
    ));

    $progress = function (int $i, int $total, $case, $result): void {
      $marker = match ($result->outcome) {
        'pass' => '<info>PASS</info>',
        'fail' => '<comment>FAIL</comment>',
        default => '<error>ERROR</error>',
      };
      $cat = $result->failureCategory ? " [{$result->failureCategory}]" : '';
      $this->output()->writeln(sprintf(
        '  [%d/%d] %s %s%s (%dms)',
        $i,
        $total,
        $marker,
        $case->id,
        $cat,
        $result->durationMs,
      ));
    };

    $results = $this->runner->run($cases, $providerId, $modelId, $clearCaches, $sleepSeconds, $progress);

    $paths = $this->reporter->write($results, $outputDir, $runLabel);
    $summary = $this->reporter->summarize($results);

    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      '<info>Pass rate: %d/%d (%.1f%%). DSL limitation rate: %.1f%%</info>',
      $summary['pass'],
      $summary['total'],
      $summary['pass_rate'] * 100,
      $summary['dsl_limitation_rate'] * 100,
    ));
    $this->output()->writeln("JSONL: {$paths['jsonl']}");
    $this->output()->writeln("Markdown: {$paths['markdown']}");

    return $summary['fail'] > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
