<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use RuntimeException;

/**
 * Thrown when {@see OpenBankingSecretsRepository} fails to write the
 * on-disk chmod-600 JSON file. The message never carries the JSON
 * payload — only the absolute path and a generic failure description —
 * so the RSA private key / session tokens can never leak into
 * Laravel's exception log surface (D-06/T-19-03-03).
 */
final class SecretsWriteFailed extends RuntimeException {}
