<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Rag\Values\FilterExpression;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

#[Singleton]
readonly class DocumentFilterEvaluator
{
    public function apply(Builder $query, FilterExpression $expression): Builder
    {
        if ($expression->isEmpty()) {
            return $query;
        }

        return $query->where(fn (Builder $builder): Builder => $this->applyNode($builder, $expression));
    }

    private function applyNode(Builder $query, FilterExpression $node): Builder
    {
        if ($node->isLeaf()) {
            return $this->applyLeaf($query, (string) $node->field, $node->value);
        }

        return match ($node->operator) {
            'OR' => $query->where(function (Builder $builder) use ($node): void {
                foreach ($node->children as $index => $child) {
                    if ($index === 0) {
                        $builder->where(fn (Builder $nested): Builder => $this->applyNode($nested, $child));
                        continue;
                    }

                    $builder->orWhere(fn (Builder $nested): Builder => $this->applyNode($nested, $child));
                }
            }),
            'NOT' => $query->whereNot(fn (Builder $builder): Builder => $this->applyNode($builder, $node->children[0])),
            default => $query->where(function (Builder $builder) use ($node): void {
                foreach ($node->children as $child) {
                    $builder->where(fn (Builder $nested): Builder => $this->applyNode($nested, $child));
                }
            }),
        };
    }

    private function applyLeaf(Builder $query, string $field, mixed $value): Builder
    {
        if (is_array($value)) {
            return $query->where(function (Builder $builder) use ($field, $value): void {
                foreach ($value as $index => $candidate) {
                    if ($index === 0) {
                        $this->applyLeafCondition($builder, $field, $candidate);
                        continue;
                    }

                    $builder->orWhere(fn (Builder $nested) => $this->applyLeafCondition($nested, $field, $candidate));
                }
            });
        }

        return $this->applyLeafCondition($query, $field, $value);
    }

    private function applyLeafCondition(Builder $query, string $field, mixed $value): Builder
    {
        return match ($field) {
            'heap' => $query->where('documents.dataset_id', (string) $value),
            'document_id' => $query->where('documents.id', (string) $value),
            'owner_app' => $query->where('heaps.owner_application_id', (string) $value),
            'visibility' => $query->where('heaps.visibility', (string) $value),
            'protected' => $query->where('heaps.protected', (bool) $value),
            default => $query->where(function (Builder $builder) use ($field, $value): void {
                $documentPath = $this->jsonPath('documents.metadata_json', $field);
                $heapPath = $this->jsonPath('heaps.metadata_json', $field);
                $builder->where($documentPath, $value)
                    ->orWhere($heapPath, $value);
            }),
        };
    }

    private function jsonPath(string $column, string $field): string
    {
        return $column.'->'.str_replace('.', '->', $field);
    }
}
