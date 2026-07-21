<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

// Currencies are global — they do not belong to a single user — so
// this model intentionally skips the BelongsToUser trait.
/**
 * @property string $code
 * @property string $name
 * @property int $minor_unit
 */
final class Currency extends Model
{
    /** @var string */
    protected $primaryKey = 'code';

    /** @var string */
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'minor_unit'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'minor_unit' => 'integer',
        ];
    }
}
