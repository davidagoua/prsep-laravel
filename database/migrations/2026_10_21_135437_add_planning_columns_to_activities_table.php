<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Clé de rapprochement à l'import : le code s'il existe, sinon le parent et le libellé.
            $table->string('import_key', 191)->nullable()->after('code');
            $table->foreignId('category_id')->nullable()->after('focal_user_id')->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('ugp_id')->nullable()->after('category_id')->constrained('ugps')->nullOnDelete();
            $table->foreignId('funding_source_id')->nullable()->after('ugp_id')->constrained('funding_sources')->nullOnDelete();
            $table->string('activity_type', 100)->nullable()->after('funding_source_id');
            $table->string('localisation')->nullable()->after('activity_type');
            $table->string('partner')->nullable()->after('localisation');
            $table->string('procurement_method', 150)->nullable()->after('partner');
            $table->string('ild_reference', 50)->nullable()->after('procurement_method');
            $table->string('ddp_programme', 100)->nullable();
            $table->string('ddp_nature', 100)->nullable();
            $table->string('ddp_action', 100)->nullable();
            $table->string('ddp_activite', 100)->nullable();
            $table->string('ddp_ligne', 100)->nullable();
            $table->string('ppm_ref', 100)->nullable();
            // Mois d'exécution prévus (1 à 12), tels que cochés dans le chronogramme.
            $table->json('schedule_months')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['exercice_id', 'import_key']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['exercice_id', 'import_key']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('ugp_id');
            $table->dropConstrainedForeignId('funding_source_id');
            $table->dropColumn([
                'import_key', 'activity_type', 'localisation', 'partner', 'procurement_method', 'ild_reference',
                'ddp_programme', 'ddp_nature', 'ddp_action', 'ddp_activite', 'ddp_ligne', 'ppm_ref', 'schedule_months', 'position',
            ]);
        });
    }
};
