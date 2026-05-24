<?php

namespace App\Enums;

enum ProcessReadiness: string
{
    case Available   = 'available';
    case Preview     = 'preview';
    case Unavailable = 'unavailable';
}
