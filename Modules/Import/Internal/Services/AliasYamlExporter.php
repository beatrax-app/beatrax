<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Symfony\Component\Yaml\Yaml;

final readonly class AliasYamlExporter
{
    public function __construct(private DatabaseManager $db) {}

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

        // The compact form — `- pattern: ...` rather than a bare dash and the
        // mapping on the next line — is the shape every exported corpus has had.
        // It stopped being the dumper's default in symfony/yaml 8.1, so naming the
        // flag is what keeps a re-export diffing clean against a file already held.
        return Yaml::dump(['entries' => $entries], inline: 4, indent: 2, flags: Yaml::DUMP_COMPACT_NESTED_MAPPING);
    }
}
