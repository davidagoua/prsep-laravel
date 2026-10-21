<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tdrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('state', 30)->default('draft');
            // Circuit figé à la première soumission : une modification du circuit ne touche pas les dossiers en cours.
            $table->foreignId('circuit_id')->nullable()->constrained('workflow_circuits')->restrictOnDelete();
            $table->foreignId('current_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('returned_from_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->boolean('coordination_granted')->nullable();
            $table->boolean('injunction')->nullable();
            $table->text('lessons')->nullable();
            $table->text('risks')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('step_entered_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'current_step_id']);
        });

        Schema::create('tdr_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tdr_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('tdr');
            $table->unsignedSmallInteger('version');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tdr_id', 'kind', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tdr_documents');
        Schema::dropIfExists('tdrs');
    }
};
