<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consumption guard, in the CONSUMING module's own database — the mirror of the outbox.
 * The mark and the handler's own writes share one transaction, so a replay is a no-op and a
 * handler that fails leaves nothing behind. Spring Modulith folds both into a single table
 * because its listeners share one datasource; a database per module makes that impossible, and
 * the two-table version is the one that survives the network.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_consumptions', function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->string('handler');
            $table->timestamp('consumed_at');

            $table->primary(['event_id', 'handler']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_consumptions');
    }
};
