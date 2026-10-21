<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Volet projet (financement sur résultats) : composante → sous-composante → indicateur (ILD ou hors ILD).
     * La hiérarchie est pluriannuelle : elle ne dépend pas de l'exercice.
     */
    public function up(): void
    {
        Schema::create('project_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->text('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('project_subcomponents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('project_components')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('sigle', 30)->nullable();
            $table->text('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('project_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcomponent_id')->constrained('project_subcomponents')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('type', 10)->default('ILD');
            $table->text('label');
            $table->string('baseline')->nullable();
            $table->string('target')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_indicators');
        Schema::dropIfExists('project_subcomponents');
        Schema::dropIfExists('project_components');
    }
};
