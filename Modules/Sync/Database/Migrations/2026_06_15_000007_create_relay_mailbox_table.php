<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('relay_mailbox', static function (Blueprint $table): void {
            $table->id();
            // Device ids are the only routing keys here, and there is
            // deliberately no user_id column: a relay operator with full
            // database access must be able to learn who talks to whom, and
            // nothing else. The blob is never decrypted on this side.
            $table->string('sender_did');
            $table->string('recipient_did');
            $table->binary('blob');
            $table->text('created_at');
            $table->text('delivered_at')->nullable();
            // GC cutoff: seven days once delivered, thirty if never drained.
            $table->text('expires_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        // Raw SQL because Blueprint cannot express a partial index. Indexing
        // only pending rows keeps the drain query off the delivered backlog.
        $connection->statement(
            'CREATE INDEX relay_mailbox_pending_idx ON relay_mailbox (recipient_did, delivered_at)'
            .' WHERE delivered_at IS NULL'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('relay_mailbox');
    }
};
