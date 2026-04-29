<?php

namespace App\Jobs;

use App\Models\MetaAdBatch;
use App\Services\Meta\MetaAdBatchProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMetaAdBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $batchId
    ) {}

    public function handle(MetaAdBatchProcessor $processor): void
    {
        $batch = MetaAdBatch::with('user')->find($this->batchId);
        if (! $batch) {
            return;
        }

        foreach ($processor->prepareBatch($batch) as $itemIds) {
            ProcessMetaAdBatchChunk::dispatch($batch->id, $itemIds)
                ->onQueue(ProcessMetaAdBatchChunk::QUEUE);
        }
    }

    public function failed(Throwable $exception): void
    {
        $batch = MetaAdBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        if ($batch->status === 'failed' && filled($batch->error_message)) {
            return;
        }

        app(MetaAdBatchProcessor::class)->markBatchFailed($batch, 'Erro inesperado no processamento: '.$exception->getMessage(), [
            'batch_id' => $this->batchId,
        ]);
    }
}
