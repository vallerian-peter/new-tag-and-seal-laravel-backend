<?php

namespace App\Services\Ledger;

use RuntimeException;

/** A write to a legacy log table whose family has been retired to the ledger. */
class LegacyRetiredException extends RuntimeException
{
    public function __construct(public readonly string $table, public readonly string $family)
    {
        parent::__construct("The $family records in $table are read-only; they are now kept in the event ledger. Update the app to record them.");
    }
}
