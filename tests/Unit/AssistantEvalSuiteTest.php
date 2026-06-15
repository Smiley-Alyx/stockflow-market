<?php

namespace Tests\Unit;

use App\Domains\Assistant\Evaluation\AssistantEvalSuite;
use PHPUnit\Framework\TestCase;

class AssistantEvalSuiteTest extends TestCase
{
    public function test_dataset_defines_allowed_and_forbidden_recommendations(): void
    {
        foreach ($this->suite()->cases() as $case) {
            $catalogIds = array_column($case['catalog_products'], 'id');

            $this->assertNotSame('', $case['query']);
            $this->assertSame([], array_diff($case['allowed_product_ids'], $catalogIds));
            $this->assertSame([], array_intersect($case['allowed_product_ids'], $case['forbidden_product_ids']));
        }
    }

    public function test_baseline_is_grounded_relevant_and_has_no_hallucinated_cards(): void
    {
        $report = $this->suite()->evaluate($this->baseline());

        $this->assertTrue($report['summary']['passed']);
        $this->assertSame(4, $report['summary']['passed_cases']);
        $this->assertSame([
            'grounding' => 1.0,
            'relevance' => 1.0,
            'forbidden_recommendations' => 1.0,
            'no_hallucinated_cards' => 1.0,
        ], $report['summary']['rates']);
    }

    public function test_evaluator_detects_quality_and_safety_failures(): void
    {
        $report = $this->suite()->evaluate($this->regressedRun());
        $cases = collect($report['cases'])->keyBy('case_id');

        $this->assertFalse($report['summary']['passed']);
        $this->assertFalse($cases['blue-speaker-budget']['checks']['grounding']);
        $this->assertFalse($cases['compact-office-speaker']['checks']['relevance']);
        $this->assertFalse($cases['compact-office-speaker']['checks']['forbidden_recommendations']);
        $this->assertFalse($cases['speaker-comparison']['checks']['no_hallucinated_cards']);
    }

    public function test_forbidden_textual_product_mention_is_rejected_without_a_card(): void
    {
        $run = $this->baseline();
        $run['results'][0]['mentioned_product_ids'] = [101, 102];

        $case = collect($this->suite()->evaluate($run)['cases'])->keyBy('case_id')['blue-speaker-budget'];

        $this->assertTrue($case['checks']['grounding']);
        $this->assertFalse($case['checks']['forbidden_recommendations']);
        $this->assertTrue($case['checks']['no_hallucinated_cards']);
    }

    public function test_comparison_reports_regressions_between_provider_model_and_prompt(): void
    {
        $comparison = $this->suite()->compare($this->regressedRun(), $this->baseline());

        $this->assertFalse($comparison['passed']);
        $this->assertSame('reference', $comparison['baseline']['provider']);
        $this->assertSame('candidate-provider', $comparison['candidate']['provider']);
        $this->assertSame('candidate-model', $comparison['candidate']['model']);
        $this->assertSame('v2', $comparison['candidate']['prompt_version']);
        $this->assertContains('grounding', $comparison['regressions']);
        $this->assertContains('relevance', $comparison['regressions']);
        $this->assertContains('forbidden_recommendations', $comparison['regressions']);
        $this->assertContains('no_hallucinated_cards', $comparison['regressions']);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseline(): array
    {
        return $this->json('tests/evals/assistant/runs/baseline.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function regressedRun(): array
    {
        $run = $this->baseline();
        $run['metadata'] = [
            'provider' => 'candidate-provider',
            'model' => 'candidate-model',
            'prompt_version' => 'v2',
        ];
        $run['results'][0]['searched_product_ids'] = [999];
        $run['results'][0]['selected_product_ids'] = [999];
        $run['results'][0]['mentioned_product_ids'] = [999];
        $run['results'][0]['product_card_ids'] = [999];
        $run['results'][1]['searched_product_ids'] = [201];
        $run['results'][1]['selected_product_ids'] = [201];
        $run['results'][1]['mentioned_product_ids'] = [201];
        $run['results'][1]['product_card_ids'] = [201];
        $run['results'][2]['product_card_ids'] = [999];

        return $run;
    }

    private function suite(): AssistantEvalSuite
    {
        return new AssistantEvalSuite($this->path('tests/evals/assistant/cases.json'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $relativePath): array
    {
        return json_decode(
            (string) file_get_contents($this->path($relativePath)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function path(string $relativePath): string
    {
        return dirname(__DIR__, 2).'/'.$relativePath;
    }
}
