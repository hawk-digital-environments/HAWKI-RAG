<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

readonly class PermissionGraphRelationship
{
    public function __construct(
        public string $resourceType,
        public string $resourceId,
        public string $relation,
        public string $subjectType,
        public string $subjectId,
        public ?string $subjectRelation = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'relation' => $this->relation,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_relation' => $this->subjectRelation,
        ];
    }

    public function key(): string
    {
        return implode('|', [
            $this->resourceType,
            $this->resourceId,
            $this->relation,
            $this->subjectType,
            $this->subjectId,
            $this->subjectRelation ?? '',
        ]);
    }
}
