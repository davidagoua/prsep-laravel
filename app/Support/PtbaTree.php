<?php

namespace App\Support;

use App\Enums\Volet;
use App\Models\Activity;
use App\Models\ProgrammeAction;
use App\Models\ProjectIndicator;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Arborescence du PTBA d'un volet pour un exercice, avec les montants cumulés à chaque niveau.
 * Seules les branches contenant des activités visibles par l'utilisateur sont conservées.
 */
class PtbaTree
{
    /** Montants additionnés de l'activité vers la racine. */
    public const METRICS = ['planned'];

    /**
     * @param  array{direction?: ?int, ild?: ?string, search?: ?string}  $filters
     * @return array{roots: list<array>, totals: array<string, int>, count: int}
     */
    public function build(Volet $volet, int $exerciceId, User $user, array $filters = [], ?callable $withMetrics = null): array
    {
        $activities = Activity::query()
            ->visibleTo($user)
            ->where('exercice_id', $exerciceId)
            ->where('volet', $volet)
            ->whereNotNull('parent_id')
            ->when($filters['direction'] ?? null, fn ($q, $id) => $q->where('direction_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->whereLike('label', "%{$s}%")->orWhereLike('code', "%{$s}%")))
            ->when($volet === Volet::Projet && ($filters['ild'] ?? null), fn ($q) => $q->whereHasMorph('parent', [ProjectIndicator::class], fn ($q) => $q->where('type', $filters['ild'])))
            ->withSum(['budgetLines as planned' => fn ($q) => $q->where('exercice_id', $exerciceId)], 'amount')
            ->with(['direction:id,code', 'tdr.currentStep'])
            ->orderBy('position')
            ->orderBy('id');

        // Pas de when() : il exécuterait la fermeture passée comme condition.
        if ($withMetrics) {
            $withMetrics($activities, $exerciceId);
        }
        $activities = $activities->get();

        $metrics = $withMetrics ? ['planned', 'engaged', 'paid'] : self::METRICS;
        $leaves = $this->activityNodes($activities, $metrics);

        $roots = $volet === Volet::Projet
            ? $this->projectRoots($activities, $leaves, $metrics)
            : $this->programmeRoots($activities, $leaves, $metrics);

        $totals = [];
        foreach ($metrics as $metric) {
            $totals[$metric] = collect($roots)->sum("metrics.{$metric}");
        }

        return ['roots' => $roots, 'totals' => $totals, 'count' => $activities->count()];
    }

    /** @return Collection<int, array> nœuds d'activités (sous-activités imbriquées) groupés par parent */
    private function activityNodes(Collection $activities, array $metrics): Collection
    {
        $nodes = $activities->mapWithKeys(fn (Activity $a) => [$a->id => [
            'type' => 'activity',
            'key' => 'a'.$a->id,
            'code' => $a->code,
            'label' => $a->label,
            'activity' => $a,
            'metrics' => collect($metrics)->mapWithKeys(fn ($m) => [$m => (int) ($a->{$m} ?? 0)])->all(),
            'children' => [],
        ]]);

        // Sous-activités sous leur activité parente (si elle est dans le même périmètre).
        $nested = $nodes->all();
        foreach ($activities->whereNotNull('parent_activity_id')->sortByDesc('position') as $a) {
            if (isset($nested[$a->parent_activity_id])) {
                array_unshift($nested[$a->parent_activity_id]['children'], $nested[$a->id]);
                foreach ($metrics as $m) {
                    $nested[$a->parent_activity_id]['metrics'][$m] += $nested[$a->id]['metrics'][$m];
                }
                unset($nested[$a->id]);
            }
        }

        return collect($nested)->groupBy(fn ($node) => $node['activity']->parent_type.'#'.$node['activity']->parent_id);
    }

    private function projectRoots(Collection $activities, Collection $leaves, array $metrics): array
    {
        $indicators = ProjectIndicator::with('subcomponent.component')
            ->whereIn('id', $activities->where('parent_type', ProjectIndicator::class)->pluck('parent_id')->unique())
            ->get();

        return $this->assemble($indicators, $metrics, [
            fn (ProjectIndicator $i) => $i->subcomponent->component,
            fn (ProjectIndicator $i) => $i->subcomponent,
        ], fn (ProjectIndicator $i) => [
            'type' => $i->isIld() ? 'ild' : 'hors_ild',
            'children' => $leaves[ProjectIndicator::class.'#'.$i->id]?->values()->all() ?? [],
        ], ['component', 'subcomponent']);
    }

    private function programmeRoots(Collection $activities, Collection $leaves, array $metrics): array
    {
        $actions = ProgrammeAction::with('component.subdomain.domain')
            ->whereIn('id', $activities->where('parent_type', ProgrammeAction::class)->pluck('parent_id')->unique())
            ->get();

        return $this->assemble($actions, $metrics, [
            fn (ProgrammeAction $a) => $a->component->subdomain->domain,
            fn (ProgrammeAction $a) => $a->component->subdomain,
            fn (ProgrammeAction $a) => $a->component,
        ], fn (ProgrammeAction $a) => [
            'type' => 'action',
            'children' => $leaves[ProgrammeAction::class.'#'.$a->id]?->values()->all() ?? [],
        ], ['domain', 'subdomain', 'component']);
    }

    /**
     * Construit les niveaux supérieurs à partir des parents directs des activités.
     *
     * @param  list<callable>  $ancestors  du niveau le plus haut au plus bas
     * @param  list<string>  $types
     */
    private function assemble(Collection $parents, array $metrics, array $ancestors, callable $leaf, array $types): array
    {
        $tree = [];
        foreach ($parents as $parent) {
            $ref = &$tree;
            foreach ($ancestors as $depth => $resolve) {
                $model = $resolve($parent);
                $key = $types[$depth].$model->id;
                $ref[$key] ??= [
                    'type' => $types[$depth], 'key' => $key, 'code' => $model->code ?? $model->sigle ?? null,
                    'label' => $model->label, 'sort' => $model->position ?? $model->code, 'children' => [],
                ];
                $ref = &$ref[$key]['children'];
            }
            $ref['p'.$parent->id] = ['key' => get_class($parent).$parent->id, 'code' => $parent->code, 'label' => $parent->label,
                'sort' => $parent->position ?? $parent->code] + $leaf($parent);
            unset($ref);
        }

        return $this->finalize($tree, $metrics);
    }

    /** Trie, convertit en listes et cumule les montants de bas en haut. */
    private function finalize(array $nodes, array $metrics): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'activity') {
                $node['children'] = $this->finalize($node['children'], $metrics);
                $node['metrics'] = [];
                foreach ($metrics as $m) {
                    $node['metrics'][$m] = collect($node['children'])->sum("metrics.{$m}");
                }
                $node['count'] = collect($node['children'])->sum(fn ($c) => $c['type'] === 'activity' ? 1 : ($c['count'] ?? 0));
            }
            $result[] = $node;
        }

        usort($result, fn ($a, $b) => strnatcmp((string) ($a['sort'] ?? ''), (string) ($b['sort'] ?? '')));

        return $result;
    }
}
