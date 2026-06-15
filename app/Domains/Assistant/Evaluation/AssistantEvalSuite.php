<?php

namespace App\Domains\Assistant\Evaluation;

use InvalidArgumentException;

class AssistantEvalSuite
{
    public function __construct(
        private readonly string $casesPath = '',
    ) {}

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function evaluate(array $run): array
    {
        $metadata = $this->metadata($run);
        $results = collect($run['results'] ?? [])
            ->filter(fn (mixed $result): bool => is_array($result))
            ->keyBy(fn (array $result): string => (string) ($result['case_id'] ?? ''));
        $evaluated = [];

        foreach ($this->cases() as $case) {
            $result = $results->get($case['id'], []);
            $complete = $this->completeResult($result);
            $catalogIds = $this->productIds($case['catalog_products']);
            $allowedIds = $this->ids($case['allowed_product_ids']);
            $forbiddenIds = $this->ids($case['forbidden_product_ids']);
            $searchedIds = $this->ids($result['searched_product_ids'] ?? []);
            $selectedIds = $this->ids($result['selected_product_ids'] ?? []);
            $mentionedIds = $this->ids($result['mentioned_product_ids'] ?? []);
            $cardIds = $this->ids($result['product_card_ids'] ?? []);
            $recommendedIds = array_values(array_unique([...$selectedIds, ...$mentionedIds]));

            $checks = [
                'grounding' => $complete
                    && $this->subset($searchedIds, $catalogIds)
                    && $this->subset($selectedIds, $searchedIds)
                    && $this->subset($mentionedIds, $searchedIds),
                'relevance' => $complete && $this->relevant($selectedIds, $allowedIds),
                'forbidden_recommendations' => $complete && array_intersect($recommendedIds, $forbiddenIds) === [],
                'no_hallucinated_cards' => $complete && $cardIds === $selectedIds && $this->subset($cardIds, $searchedIds),
            ];

            $evaluated[] = [
                'case_id' => $case['id'],
                'query' => $case['query'],
                'checks' => $checks,
                'passed' => ! in_array(false, $checks, true),
            ];
        }

        return [
            'metadata' => $metadata,
            'cases' => $evaluated,
            'summary' => $this->summary($evaluated),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    public function compare(array $candidate, array $baseline): array
    {
        $candidateReport = $this->evaluate($candidate);
        $baselineReport = $this->evaluate($baseline);
        $deltas = [];
        $regressions = [];

        foreach ($candidateReport['summary']['rates'] as $metric => $rate) {
            $delta = round($rate - $baselineReport['summary']['rates'][$metric], 4);
            $deltas[$metric] = $delta;

            if ($delta < 0) {
                $regressions[] = $metric;
            }
        }

        return [
            'baseline' => $baselineReport['metadata'],
            'candidate' => $candidateReport['metadata'],
            'deltas' => $deltas,
            'regressions' => $regressions,
            'passed' => $candidateReport['summary']['passed'] && $regressions === [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cases(): array
    {
        $dataset = $this->readJson($this->casesPath !== '' ? $this->casesPath : base_path('tests/evals/assistant/cases.json'));
        $cases = $dataset['cases'] ?? null;

        if (($dataset['version'] ?? null) !== 1 || ! is_array($cases)) {
            throw new InvalidArgumentException('Assistant eval dataset must contain version 1 cases.');
        }

        return array_values(array_map(function (mixed $case): array {
            if (! is_array($case)
                || ! is_string($case['id'] ?? null)
                || ! is_string($case['query'] ?? null)
                || ! is_array($case['catalog_products'] ?? null)
                || ! is_array($case['allowed_product_ids'] ?? null)
                || ! is_array($case['forbidden_product_ids'] ?? null)) {
                throw new InvalidArgumentException('Assistant eval case has an invalid format.');
            }

            return $case;
        }, $cases));
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function summary(array $cases): array
    {
        $total = count($cases);
        $metrics = ['grounding', 'relevance', 'forbidden_recommendations', 'no_hallucinated_cards'];
        $rates = [];

        foreach ($metrics as $metric) {
            $passed = count(array_filter($cases, fn (array $case): bool => $case['checks'][$metric]));
            $rates[$metric] = $total === 0 ? 0.0 : round($passed / $total, 4);
        }

        return [
            'passed_cases' => count(array_filter($cases, fn (array $case): bool => $case['passed'])),
            'total_cases' => $total,
            'rates' => $rates,
            'passed' => $total > 0 && ! in_array(false, array_column($cases, 'passed'), true),
        ];
    }

    /**
     * @param  list<int>  $selected
     * @param  list<int>  $allowed
     */
    private function relevant(array $selected, array $allowed): bool
    {
        return $allowed === []
            ? $selected === []
            : $selected !== [] && $this->subset($selected, $allowed);
    }

    /**
     * @param  list<int>  $values
     * @param  list<int>  $allowed
     */
    private function subset(array $values, array $allowed): bool
    {
        return array_diff($values, $allowed) === [];
    }

    private function completeResult(mixed $result): bool
    {
        return is_array($result)
            && is_string($result['message'] ?? null)
            && is_array($result['searched_product_ids'] ?? null)
            && is_array($result['selected_product_ids'] ?? null)
            && is_array($result['mentioned_product_ids'] ?? null)
            && is_array($result['product_card_ids'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<int>
     */
    private function productIds(array $products): array
    {
        return $this->ids(array_column($products, 'id'));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ids = array_map('intval', array_filter($values, fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value)));
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{provider: string, model: string, prompt_version: string}
     */
    private function metadata(array $run): array
    {
        $metadata = $run['metadata'] ?? null;

        if (! is_array($metadata)
            || ! is_string($metadata['provider'] ?? null)
            || ! is_string($metadata['model'] ?? null)
            || ! is_string($metadata['prompt_version'] ?? null)) {
            throw new InvalidArgumentException('Assistant eval run must identify provider, model, and prompt_version.');
        }

        return [
            'provider' => $metadata['provider'],
            'model' => $metadata['model'],
            'prompt_version' => $metadata['prompt_version'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read assistant eval JSON: {$path}");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Assistant eval JSON must contain an object: {$path}");
        }

        return $decoded;
    }
}
