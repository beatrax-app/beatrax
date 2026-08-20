<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

// A code-internal map inconsistency: a 'parent' event type with no TRANSACTION_TYPE entry.
// Distinct from the supertype's "PayPal shipped an unmapped event type" user-data signal.
final class MissingPaypalTransactionTypeMapException extends UnknownPaypalEventTypeException {}
