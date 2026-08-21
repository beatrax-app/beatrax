<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// The UNIQUE (user_id, sha256) constraint on the table prevents
// re-importing the same physical file.
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $source_format
 * @property string $raw_file_path
 * @property string $sha256
 * @property CarbonImmutable $uploaded_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int $inserted_count
 * @property int $duplicate_count
 * @property int $enriched_count
 * @property int $error_count
 * @property array<array-key, mixed>|null $row_issues
 * @property string $status
 */
final class ImportRun extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_format',
        'raw_file_path',
        'sha256',
        'uploaded_at',
        'confirmed_at',
        'inserted_count',
        'duplicate_count',
        'enriched_count',
        'error_count',
        'row_issues',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'inserted_count' => 'integer',
            'duplicate_count' => 'integer',
            'enriched_count' => 'integer',
            'error_count' => 'integer',
            'row_issues' => 'array',
        ];
    }
}
