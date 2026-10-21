<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Circuits de validation paramétrables et versionnés (un circuit actif par objet et par volet).
        Schema::create('workflow_circuits', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 20);
            $table->string('volet', 20)->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subject', 'volet', 'version']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained('workflow_circuits')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('label');
            $table->string('role', 50);
            $table->string('scope', 20)->default('global');
            $table->boolean('gives_opinion')->default(false);
            $table->timestamps();

            $table->unique(['circuit_id', 'position']);
        });

        // Historique des transitions, commun à tous les objets soumis à un circuit.
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('action', 30);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->foreignId('from_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('to_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_circuits');
    }
};
