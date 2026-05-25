<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Serialises a user's `merchant_aliases` table into a YAML document
 * shaped like the bundled community-corpus file (`entries:` list-of-
 * mappings). The output is offered as a downloadable `aliases.yaml`
 * from Settings → Aliases so a power user can:
 *
 *   - back the table up outside the SQLite database,
 *   - move it between machines, or
 *   - propose entries upstream for inclusion in the community corpus
 *     (the schema matches the corpus loader on purpose).
 *
 * Schema (one entry per `merchant_aliases` row):
 *   pattern:      string  — the user's first-seen raw description
 *   name:         string  — the friendly name the user chose
 *   category:     null    — categories are not user-aliased; placeholder
 *                           kept so the file remains shape-compatible
 *                           with the corpus loader
 *   region:       null    — same shape-compatibility rationale
 *   contributor:  "user"  — disambiguates user-exported rows from
 *                           bundled corpus rows in any downstream tool
 *
 * The service is stateless and pure-read (`MerchantAlias::query` is the
 * only side-effect), so it is bound as a singleton in the provider.
 */
final class AliasYamlExporter
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Builds the YAML document. The query is explicitly user-scoped;
     * cross-user leakage is structurally impossible because the
     * caller always provides the resolved `User` model from the
     * authenticated `CurrentUser` contract.
     *
     * Uses the raw query builder (via the injected `DatabaseManager`)
     * rather than the Eloquent Builder so the project's
     * phpstan-strict-rules `staticMethod.dynamicCall` rule doesn't
     * flag the `->orderBy()` chain — same convention as the dashboard
     * queries under `Modules/Ledger/Public/Services/` and
     * `MerchantNameResolver`.
     */
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
