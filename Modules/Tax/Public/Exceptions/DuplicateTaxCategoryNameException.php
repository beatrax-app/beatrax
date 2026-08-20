<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Exceptions;

use RuntimeException;

// Thrown by TaxCategoryWriter::add and ::rename when the target name
// already belongs to another tax_deduction_categories row for the user —
// surfaces a friendly message before the unique(user_id, name) constraint
// would raise an uncaught QueryException.
final class DuplicateTaxCategoryNameException extends RuntimeException {}
