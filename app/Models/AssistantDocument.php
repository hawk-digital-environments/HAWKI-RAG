<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Compatibility alias for the managed document model.
 */
class AssistantDocument extends ManagedDocument
{
    public function outputs(): HasMany
    {
        return $this->hasMany(AssistantDocumentOutput::class, 'assistant_document_id', 'assistant_document_id');
    }
}
