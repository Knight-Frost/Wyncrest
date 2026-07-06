<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_notes', function (Blueprint $table) {
            $table->id();
            // contracts.id is a UUID PK (unlike listings), so this FK is UUID too.
            $table->foreignUuid('contract_id')->constrained('contracts')->cascadeOnDelete();
            // Author is always an admin — notes are internal case-file context,
            // never surfaced to the landlord or tenant.
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Newest-first lookups per contract.
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_notes');
    }
};
