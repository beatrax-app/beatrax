<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

use Modules\Core\Public\Support\Lang;

// Which of the palette's three rails a hit came from. The value reaches the
// client as a class suffix and a label, so it is the contract between the
// registry and the row template; the rails carry the reader's words for it.
enum PaletteSource: string
{
    case View = 'view';

    case DevView = 'dev-view';

    case Dev = 'dev';

    case Action = 'action';

    public function label(): string
    {
        return Lang::get(match ($this) {
            self::View => 'dev::palette.rail_view',
            self::DevView, self::Dev => 'dev::palette.rail_dev',
            self::Action => 'dev::palette.rail_action',
        });
    }
}
