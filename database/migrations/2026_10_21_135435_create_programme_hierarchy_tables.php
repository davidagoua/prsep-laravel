<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Volet programme (cadre de résultats) : domaine → sous-domaine (indicateurs) → composante → action.
     */
    public function up(): void
    {
        Schema::create('result_domains', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->text('label');
            $table->timestamps();
        });

        Schema::create('result_subdomains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('result_domains')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->text('label');
            $table->text('expected_result')->nullable();
            $table->timestamps();
        });

        Schema::create('programme_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subdomain_id')->constrained('result_subdomains')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('label');
            $table->string('baseline')->nullable();
            $table->string('target')->nullable();
            $table->timestamps();

            $table->unique(['subdomain_id', 'position']);
        });

        Schema::create('programme_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subdomain_id')->constrained('result_subdomains')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->text('label');
            $table->timestamps();
        });

        Schema::create('programme_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('programme_components')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->text('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_actions');
        Schema::dropIfExists('programme_components');
        Schema::dropIfExists('programme_indicators');
        Schema::dropIfExists('result_subdomains');
        Schema::dropIfExists('result_domains');
    }
};
