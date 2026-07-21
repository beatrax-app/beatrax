<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use RuntimeException;

/**
 * @see OpenBankingSecretsRepository
 */
final class SecretsWriteFailed extends RuntimeException {}
