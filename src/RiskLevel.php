<?php

declare(strict_types=1);

namespace MonitorHQ;

enum RiskLevel: string
{
    case High = 'high';
    case Standard = 'standard';
    case Low = 'low';
}
