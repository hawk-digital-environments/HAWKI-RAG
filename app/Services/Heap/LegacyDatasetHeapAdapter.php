<?php
declare(strict_types=1);

namespace App\Services\Heap;

use App\Models\Dataset;
use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class LegacyDatasetHeapAdapter
{
    public function toHeap(Dataset $dataset): Heap
    {
        $heap = new Heap();
        $heap->setRawAttributes($dataset->getAttributes(), true);
        $heap->exists = $dataset->exists;

        return $heap;
    }

    public function toDataset(Heap $heap): Dataset
    {
        $dataset = new Dataset();
        $dataset->setRawAttributes($heap->getAttributes(), true);
        $dataset->exists = $heap->exists;

        return $dataset;
    }
}
