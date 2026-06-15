<?php

declare(strict_types=1);

use App\Domains\Assistant\Evaluation\AssistantEvalSuite;

require dirname(__DIR__).'/vendor/autoload.php';

$candidatePath = $argv[1] ?? dirname(__DIR__).'/tests/evals/assistant/runs/baseline.json';
$baselinePath = $argv[2] ?? null;
$suite = new AssistantEvalSuite(dirname(__DIR__).'/tests/evals/assistant/cases.json');
$candidate = readEvalJson($candidatePath);
$report = $suite->evaluate($candidate);

printMetadata('Candidate', $report['metadata']);
printSummary($report['summary']);

foreach ($report['cases'] as $case) {
    if (! $case['passed']) {
        $failedChecks = array_keys(array_filter($case['checks'], fn (bool $passed): bool => ! $passed));
        fwrite(STDERR, "- {$case['case_id']}: ".implode(', ', $failedChecks)."\n");
    }
}

$passed = $report['summary']['passed'];

if (is_string($baselinePath) && $baselinePath !== '') {
    $comparison = $suite->compare($candidate, readEvalJson($baselinePath));

    printMetadata('Baseline', $comparison['baseline']);
    echo "Metric deltas:\n";

    foreach ($comparison['deltas'] as $metric => $delta) {
        printf("- %s: %+.4f\n", $metric, $delta);
    }

    if ($comparison['regressions'] !== []) {
        fwrite(STDERR, 'Regressions: '.implode(', ', $comparison['regressions'])."\n");
    }

    $passed = $passed && $comparison['passed'];
}

exit($passed ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function readEvalJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read assistant eval run: {$path}");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Assistant eval run must contain a JSON object: {$path}");
    }

    return $decoded;
}

/**
 * @param  array<string, string>  $metadata
 */
function printMetadata(string $label, array $metadata): void
{
    echo "{$label}: provider={$metadata['provider']} model={$metadata['model']} prompt={$metadata['prompt_version']}\n";
}

/**
 * @param  array<string, mixed>  $summary
 */
function printSummary(array $summary): void
{
    echo "Passed cases: {$summary['passed_cases']}/{$summary['total_cases']}\n";

    foreach ($summary['rates'] as $metric => $rate) {
        printf("- %s: %.4f\n", $metric, $rate);
    }
}
