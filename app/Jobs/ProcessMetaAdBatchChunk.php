<?php

namespace App\Jobs;

use App\Services\Meta\MetaAdBatchProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMetaAdBatchChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'default';

    public int $timeout = 1800;

    public int $tries = 50;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $batchId,
        public readonly array $itemIds
    ) {}

    public function handle(MetaAdBatchProcessor $processor): void
    {
        $processor->processChunk($this->batchId, $this->itemIds);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('meta-ad-batch:'.$this->batchId))
                ->releaseAfter(120)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function failed(Throwable $exception): void
    {
        app(MetaAdBatchProcessor::class)->failChunkItems(
            $this->batchId,
            $this->itemIds,
            'Erro inesperado no processamento do chunk: '.$exception->getMessage()
        );
    }
}
