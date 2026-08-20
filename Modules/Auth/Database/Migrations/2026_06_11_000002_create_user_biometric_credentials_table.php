<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// One row per enrolled device: biometric_wrap_secret is raw binary that wraps
// the data key for that device alone, and counter is the authenticator's
// signature counter, kept so a replayed assertion can be spotted.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('user_biometric_credentials', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('credential_id');
            $table->string('device_label');
            $table->binary('biometric_wrap_secret');
            $table->text('public_key_cbor')->nullable();
            $table->unsignedBigInteger('counter')->default(0);
            $table->string('platform');
            $table->unsignedTinyInteger('biometric_failed_count')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_biometric_credentials');
    }
};
