<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Symfony\Component\Yaml\Yaml;

final class AliasYamlExporter
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function export(User $user): string
    {
        $rows = $this->db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['pattern', 'friendly_name']);

        $entries = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $pattern = isset($row->pattern) && is_string($row->pattern) ? $row->pattern : '';
            $friendly = isset($row->friendly_name) && is_string($row->friendly_name) ? $row->friendly_name : '';
            $entries[] = [
                'pattern' => $pattern,
                'name' => $friendly,
                'category' => null,
                'region' => null,
                'contributor' => 'user',
            ];
        }

        return Yaml::dump(['entries' => $entries], inline: 4, indent: 2);
    }
}
