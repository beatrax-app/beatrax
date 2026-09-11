<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Internal\Console\Probes\OwnerBoundaryProbe;

uses(RefreshDatabase::class);

// Measured against a copy of a paired install: 61 references where both sides
// carry an owner, none of them crossing. The merge gates that keep it that way
// judged ids the peer minted until those were translated first.

function ownerBoundaryUser(DatabaseManager $db, string $tag): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'boundary-'.$tag.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function ownerBoundaryCategory(DatabaseManager $db, int $userId, string $slug): int
{
    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => ucfirst($slug),
        'slug' => 'boundary-'.$slug.'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->check = new OwnerBoundaryProbe($db);
});

it('reads a real number of owner-scoped references, so a clean answer means something', function (): void {
    // Rows that actually join, or "ok" is the answer an empty table gives and
    // says nothing about the comparison that produced it.
    foreach (['one', 'two'] as $tag) {
        $userId = ownerBoundaryUser($this->db, $tag);
        $parent = ownerBoundaryCategory($this->db, $userId, $tag.'-parent');
        $child = ownerBoundaryCategory($this->db, $userId, $tag.'-child');
        $this->db->connection()->table('categories')->where('id', $child)->update(['parent_id' => $parent]);
    }

    expect($this->check->run()->severity)->toBe('ok');

    // The floor is the positive control: "nothing crosses" and "nothing was
    // read" produce the same sentence otherwise.
    $count = (int) $this->check->run()->message;

    expect($count)->toBeGreaterThan(20);
});

it('names a row that points at another reader\'s row', function (): void {
    $mine = ownerBoundaryUser($this->db, 'mine');
    $theirs = ownerBoundaryUser($this->db, 'theirs');

    $theirCategory = ownerBoundaryCategory($this->db, $theirs, 'theirs');
    $myCategory = ownerBoundaryCategory($this->db, $mine, 'mine');

    // The shape a create-path gate refuses on arrival, reached here the way it
    // would be reached by a gate that read the id before it was translated.
    $this->db->connection()->table('categories')
        ->where('id', $myCategory)
        ->update(['parent_id' => $theirCategory]);

    expect($this->check->run()->severity)->toBe('warning')
        ->and($this->check->run()->message)->toContain('categories.parent_id -> categories (1)');
});

it('stays quiet about a reference whose parent is shared rather than owned', function (): void {
    $mine = ownerBoundaryUser($this->db, 'shared');

    // A global category carries a null user_id, so it is nobody's row to
    // cross into and the comparison must not read it as a disagreement.
    $global = (int) $this->db->connection()->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Global',
        'slug' => 'boundary-global-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $myCategory = ownerBoundaryCategory($this->db, $mine, 'child');

    $this->db->connection()->table('categories')->where('id', $myCategory)->update(['parent_id' => $global]);

    expect($this->check->run()->severity)->toBe('ok');
});
