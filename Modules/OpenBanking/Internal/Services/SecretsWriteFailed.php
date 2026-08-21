<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use RuntimeException;

/**
 * @see OpenBankingSecretsRepository
 */
final class SecretsWriteFailed extends RuntimeException {}
