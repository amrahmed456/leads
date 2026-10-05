<?php

namespace App\Discovery\Data;

use App\Discovery\RecordOutcome;
use App\Models\Business;

final readonly class RecordedBusiness
{
    public function __construct(
        public Business $business,
        public RecordOutcome $outcome,
    ) {}
}
