<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

// Signals a code-internal map inconsistency (a parent-action event type
// with no matching TRANSACTION_TYPE entry), distinct from the parent
// type's "PayPal shipped an unmapped event type" user-data signal.
// Extends that supertype so legacy catch sites keep working.
final class MissingPaypalTransactionTypeMapException extends UnknownPaypalEventTypeException {}
