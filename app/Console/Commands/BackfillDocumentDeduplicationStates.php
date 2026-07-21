<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\DocumentDeduplicationBackfillService;
use App\Services\Pipeline\Values\DocumentDeduplicationBackfillReport;
use Illuminate\Console\Command;

class BackfillDocumentDeduplicationStates extends Command
{
    protected $signature = 'pipeline:deduplication-backfill
        {--apply : Persist verified historical states; omitted means dry-run}
        {--dataset= : Restrict managed documents to one dataset ID}
        {--scope= : Restrict records to one Qdrant collection}
        {--chunk=200 : Number of candidates processed per batch (1-1000)}';

    protected $description = 'Dry-run or seed fail-closed historical document deduplication state.';

    public function handle(DocumentDeduplicationBackfillService $backfill): int
    {
        $chunkSize = (int) $this->option('chunk');
        if ($chunkSize < 1 || $chunkSize > 1000) {
            $this->error('The --chunk value must be between 1 and 1000.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line($apply
            ? 'Applying verified historical document deduplication state.'
            : 'Dry-run only; no deduplication state will be written.');

        $report = $backfill->run(
            apply: $apply,
            datasetId: $this->optionalString($this->option('dataset')),
            scopeKey: $this->optionalString($this->option('scope')),
            chunkSize: $chunkSize,
        );

        $this->renderReport($report);

        if ($report->conflicts > 0) {
            $this->warn('Existing non-matching states were preserved. Review the conflict count before any manual action.');
        }

        if ($report->unverifiedRegistryRecords > 0) {
            $this->warn('Unverified page-registry records were not seeded; they will establish byte-level state on a future pipeline run.');
        }

        if (! $apply && $report->wouldSeed > 0) {
            $this->info('Dry-run passed. Re-run with --apply to seed only the verified candidates.');
        } elseif ($apply) {
            $this->info('Backfill apply completed without overwriting any existing state.');
        }

        return self::SUCCESS;
    }

    private function renderReport(DocumentDeduplicationBackfillReport $report): void
    {
        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            ['Managed documents examined', $report->managedDocumentsExamined],
            ['Verified managed candidates', $report->verifiedCandidates],
            ['Would seed (dry-run)', $report->wouldSeed],
            ['Seeded', $report->seeded],
            ['Already seeded', $report->alreadySeeded],
            ['Existing conflicts preserved', $report->conflicts],
            ['Managed documents deferred', $report->managedDocumentsDeferred],
            ['Unverified registry records deferred', $report->unverifiedRegistryRecords],
        ]);

        if ($report->skipReasons !== []) {
            $this->newLine();
            $this->line('Deferred/conflict reasons:');
            $this->table(
                ['Reason', 'Count'],
                collect($report->skipReasons)
                    ->sortKeys()
                    ->map(static fn (int $count, string $reason): array => [$reason, $count])
                    ->values()
                    ->all(),
            );
        }
    }

    private function optionalString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }
}
