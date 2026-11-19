<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum TerminalProjectionTemporalEvidence: string
{
    case Active = 'active';
    case Terminal = 'terminal';
    case NotFound = 'not_found_requires_retention_approval';
    case Unavailable = 'unavailable';
    case MissingIdentity = 'missing_identity';
    case IdentityConflict = 'identity_conflict';
    case Unknown = 'unknown';
}
