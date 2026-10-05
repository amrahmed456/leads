<?php

namespace App\Discovery;

enum RecordOutcome: string
{
    /** A new business was created. */
    case Created = 'created';

    /** A new provider record was linked to an existing, matching business. */
    case Merged = 'merged';

    /** The provider record was already known; its business was refreshed. */
    case Updated = 'updated';
}
