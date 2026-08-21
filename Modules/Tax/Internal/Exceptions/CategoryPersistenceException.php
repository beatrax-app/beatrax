<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Exceptions;

use RuntimeException;

// Thrown by TaxCategoryWriter::add when a freshly inserted
// tax_deduction_categories row cannot be read back for its id. It extends
// RuntimeException so the existing UI-layer catches (TaxSettingsSection,
// HandlesTaxTagging) still degrade gracefully rather than 500.
final class CategoryPersistenceException extends RuntimeException {}
