@props(['tdr' => null])

@if (! $tdr)
    <flux:badge size="sm" color="zinc" variant="outline">Sans TDR</flux:badge>
@else
    <flux:badge size="sm" :color="$tdr->state->color()" {{ $attributes }}>
        {{ $tdr->state->label() }}@if ($tdr->state === \App\Enums\TdrState::InReview && $tdr->currentStep) · {{ $tdr->currentStep->label }}@endif
    </flux:badge>
@endif
