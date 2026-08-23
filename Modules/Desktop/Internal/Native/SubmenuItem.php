<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Native\Desktop\Contracts\MenuItem;

// A top-level menu that owns its entries. NativePHP's own items cannot express
// one: a role item has its submenu stripped by the shell, and a label item is
// typed `normal`, which Electron renders as a dead entry rather than a menu.
// See .docs/features/desktop/architecture.md — "Submenus never hang off a role".
final class SubmenuItem implements MenuItem
{
    /** @var list<MenuItem> */
    private readonly array $items;

    public function __construct(private readonly string $label, MenuItem ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'submenu',
            'label' => $this->label,
            'submenu' => array_map(
                static fn (MenuItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
