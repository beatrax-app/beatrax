<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use RuntimeException;

// Thrown when OAuthSecretsRepository fails to write the on-disk JSON
// file. The message never carries the JSON payload — only the
// absolute path and a generic failure description — so credential
// material can never leak into Laravel's exception log surface.
final class SecretsWriteFailed extends RuntimeException {}
