<?php

namespace App\Imports\Ptba;

use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Exercice;
use App\Models\ExpenseCategory;
use App\Models\FundingSource;
use App\Models\ProgrammeAction;
use App\Models\ProgrammeComponent;
use App\Models\ProjectComponent;
use App\Models\ProjectIndicator;
use App\Models\ProjectSubcomponent;
use App\Models\ResultDomain;
use App\Models\ResultSubdomain;
use App\Models\Ugp;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Étape « valider » : enregistre le PTBA analysé, tout ou rien.
 * Réimportation : niveaux et activités sont mis à jour par leur code (ou clé de rapprochement),
 * les lignes budgétaires des activités importées sont remplacées, les autres activités ne sont pas touchées.
 */
class PtbaImporter
{
    /** @var array<string, int> identifiants des nœuds enregistrés */
    private array $nodeIds = [];

    /**
     * @param  callable(int): void|null  $progress  pourcentage d'avancement
     * @return array{created: int, updated: int, lines: int}
     */
    public function import(ParsedPtba $parsed, Exercice $exercice, ?callable $progress = null): array
    {
        return DB::transaction(function () use ($parsed, $exercice, $progress) {
            $this->nodeIds = [];
            foreach ($parsed->nodes as $key => $node) {
                $this->nodeIds[$key] = $this->saveNode($node);
            }

            $directions = new DirectionResolver;
            $volet = Volet::from($parsed->volet);
            $stats = ['created' => 0, 'updated' => 0, 'lines' => 0];
            $ids = [];
            $total = max(1, count($parsed->activities));
            $done = 0;

            foreach ($parsed->activities as $key => $data) {
                $parentType = str_starts_with($data['parent'], 'project_indicator') ? ProjectIndicator::class : ProgrammeAction::class;

                $activity = Activity::firstOrNew(['exercice_id' => $exercice->id, 'import_key' => $key]);
                $stats[$activity->exists ? 'updated' : 'created']++;

                $activity->fill([
                    'volet' => $volet,
                    'code' => $data['code'],
                    'label' => $data['label'],
                    'parent_type' => $parentType,
                    'parent_id' => $this->nodeIds[$data['parent']],
                    'parent_activity_id' => $data['parent_activity'] ? ($ids[$data['parent_activity']] ?? null) : null,
                    'direction_id' => $directions->resolve($data['direction'] ?? null) ?? $activity->direction_id,
                    'starts_on' => $data['starts_on'] ?? null,
                    'ends_on' => $data['ends_on'] ?? null,
                    'schedule_months' => $data['months'] ?: null,
                    'category_id' => ExpenseCategory::resolve($data['category'] ?? null)?->id,
                    'ugp_id' => Ugp::resolve($data['ugp'] ?? null)?->id,
                    'funding_source_id' => FundingSource::resolve($data['funding_source'] ?? null)?->id,
                    'activity_type' => $data['activity_type'] ?? null,
                    'localisation' => $data['localisation'] ?? null,
                    'partner' => $data['partner'] ?? null,
                    'procurement_method' => $data['procurement_method'] ?? null,
                    'ild_reference' => $data['ild_reference'] ?? null,
                    'ddp_programme' => $data['ddp_programme'] ?? null,
                    'ddp_nature' => $data['ddp_nature'] ?? null,
                    'ddp_action' => $data['ddp_action'] ?? null,
                    'ddp_activite' => $data['ddp_activite'] ?? null,
                    'ddp_ligne' => $data['ddp_ligne'] ?? null,
                    'ppm_ref' => $data['ppm_ref'] ?? null,
                    'position' => $data['position'],
                ])->save();
                $ids[$key] = $activity->id;

                $activity->budgetLines()->where('exercice_id', $exercice->id)->delete();
                foreach ($data['lines'] as $position => $line) {
                    $activity->budgetLines()->create([
                        'exercice_id' => $exercice->id,
                        'label' => $line['label'],
                        'unit_id' => Unit::resolve($line['unit'])?->id,
                        'quantity' => $line['quantity'],
                        'frequency' => $line['frequency'],
                        'duration' => $line['duration'],
                        'unit_cost' => (int) round($line['unit_cost']),
                        'economic_nature' => $line['economic_nature'],
                        'position' => $position,
                    ]);
                    $stats['lines']++;
                }

                if ($progress && (++$done % 25 === 0 || $done === $total)) {
                    $progress((int) floor($done / $total * 100));
                }
            }

            return $stats;
        });
    }

    private function saveNode(array $node): int
    {
        $parentId = $node['parent'] ? $this->nodeIds[$node['parent']] : null;
        $extra = $node['extra'];

        return match ($node['type']) {
            'project_component' => ProjectComponent::updateOrCreate(['code' => $node['code']], [
                'label' => $node['label'], 'position' => $node['position'],
            ])->id,
            'project_subcomponent' => ProjectSubcomponent::updateOrCreate(['code' => $node['code']], [
                'component_id' => $parentId, 'label' => $node['label'], 'sigle' => $extra['sigle'] ?? null, 'position' => $node['position'],
            ])->id,
            'project_indicator' => ProjectIndicator::updateOrCreate(['code' => $node['code']], [
                'subcomponent_id' => $parentId, 'label' => $node['label'], 'type' => $extra['type'] ?? ProjectIndicator::ILD, 'position' => $node['position'],
            ])->id,
            'result_domain' => ResultDomain::updateOrCreate(['code' => $node['code']], ['label' => $node['label']])->id,
            'result_subdomain' => $this->saveSubdomain($node, $parentId),
            'programme_component' => ProgrammeComponent::updateOrCreate(['code' => $node['code']], [
                'subdomain_id' => $parentId, 'label' => $node['label'],
            ])->id,
            'programme_action' => ProgrammeAction::updateOrCreate(['code' => $node['code']], [
                'component_id' => $parentId, 'label' => $node['label'],
            ])->id,
        };
    }

    private function saveSubdomain(array $node, int $domainId): int
    {
        $subdomain = ResultSubdomain::updateOrCreate(['code' => $node['code']], [
            'domain_id' => $domainId,
            'label' => $node['label'],
            'expected_result' => $node['extra']['expected_result'] ?? null,
        ]);

        if (! empty($node['extra']['indicators'])) {
            $subdomain->indicators()->delete();
            foreach ($node['extra']['indicators'] as $position => $indicator) {
                $subdomain->indicators()->create($indicator + ['position' => $position + 1]);
            }
        }

        return $subdomain->id;
    }
}
