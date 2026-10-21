<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Socle des activités (lot 2). Le lot 1 ajoutera la hiérarchie (parent morph),
     * le budget et l'import du PTBA.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('volet', 20);
            $table->string('code', 50)->nullable();
            $table->text('label');
            $table->text('description')->nullable();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->foreignId('direction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('focal_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('parent');
            $table->foreignId('parent_activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('execution_status', 20)->default('a_venir');
            $table->timestamps();

            $table->index(['exercice_id', 'direction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
