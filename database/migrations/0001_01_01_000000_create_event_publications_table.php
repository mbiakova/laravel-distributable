<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox, in the EMITTING module's own database: the publication is written inside the
 * business transaction, so there is a single transactional write and no dual write left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_publications', function (Blueprint $table): void {
            // The row's place in the log: the only column that carries emission order.
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->string('emitter');
            $table->string('name')->index();
            $table->json('payload');
            $table->json('headers');
            $table->timestamp('emitted_at');
            $table->json('recipients');
            $table->string('stream');
            $table->timestamp('published_at')->nullable();
            $table->string('stream_id')->nullable(); // the entry's id on a transport that tracks acknowledgements
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // The only hot query: a module's pending rows, in emission order.
            $table->index(['emitter', 'published_at', 'sequence'], 'event_publications_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_publications');
    }
};
