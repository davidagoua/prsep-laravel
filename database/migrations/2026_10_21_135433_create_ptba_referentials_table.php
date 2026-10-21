<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Référentiels simples du PTBA, alimentés par l'import et modifiables par l'admin. */
    public function up(): void
    {
        foreach (['expense_categories', 'units', 'ugps', 'funding_sources'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('label')->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['funding_sources', 'ugps', 'units', 'expense_categories'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
