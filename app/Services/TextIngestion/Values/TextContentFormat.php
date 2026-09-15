<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

enum TextContentFormat: string
{
    case PlainText = 'plain_text';
    case Markdown = 'markdown';
}
