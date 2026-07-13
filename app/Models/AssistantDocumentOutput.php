<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Compatibility alias for the managed document output model.
 */
class AssistantDocumentOutput extends ManagedDocumentOutput
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(AssistantDocument::class, 'assistant_document_id', 'assistant_document_id');
    }
}
