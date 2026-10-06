<?php

namespace App\Identity;

use App\Identity\Brands\BrandAssessment;
use App\Identity\Evidence\ReconciliationResult;

final readonly class IdentityResult
{
    public function __construct(
        public ReconciliationResult $evidence,
        public BrandAssessment $brand,
        public EntityClassification $classification,
    ) {}
}
