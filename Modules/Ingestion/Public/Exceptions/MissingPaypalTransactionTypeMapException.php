<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

/**
 * Thrown when a PayPal CSV event type is mapped as a `parent` action in
 * `PaypalCsvEventTypeMap::MAP` but has no corresponding entry in
 * `TRANSACTION_TYPE`. The two tables MUST stay in lock-step: every
 * parent-action event type must have a `Transaction::TYPES` value.
 *
 * This is a code-internal inconsistency (a forgotten map entry), not a
 * user-data issue, so it carries its own type to distinguish it from
 * `UnknownPaypalEventTypeException` (which signals "PayPal shipped a
 * new event type we have not mapped yet").
 *
 * Extends `UnknownPaypalEventTypeException` so legacy call sites that
 * catch the supertype keep working; new call sites can catch this
 * narrower type to surface the missing-mapping case as a hard error
 * during development.
 */
final class MissingPaypalTransactionTypeMapException extends UnknownPaypalEventTypeException {}
