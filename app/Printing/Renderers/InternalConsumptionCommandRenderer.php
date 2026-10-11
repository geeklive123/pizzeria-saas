<?php

namespace App\Printing\Renderers;

use App\Models\InternalConsumption;
use App\Printing\EscPosDocumentBuilder;
use App\Printing\ThermalDocument;
use App\Support\UiFormatter;
use DateTimeZone;

class InternalConsumptionCommandRenderer
{
    public function render(InternalConsumption $consumption, bool $reprint): ThermalDocument
    {
        $consumption->loadMissing(['createdBy', 'items']);
        $date = $consumption->confirmed_at->setTimezone(new DateTimeZone('America/La_Paz'));
        $builder = (new EscPosDocumentBuilder)
            ->alignCenter()->bold()->doubleSize()->line('*** CONSUMO INTERNO ***')->doubleSize(false)->bold(false)
            ->line($consumption->code)->line()
            ->alignLeft()->line($date->format('d/m/Y').'                    '.$date->format('H:i'))
            ->line('Motivo: '.$consumption->reason->label())
            ->line('Responsable: '.($consumption->createdBy?->name ?? '—'));

        if ($consumption->notes) {
            $builder->line('OBS GENERAL:')->line(mb_strtoupper($consumption->notes));
        }

        foreach ($consumption->items as $item) {
            $snapshot = $item->configuration_snapshot;
            $size = ($snapshot['size_key'] ?? null) === 'familiar' ? 'GRANDE' : mb_strtoupper((string) ($snapshot['size_key'] ?? $item->variant_name_snapshot));
            $builder->line(str_repeat('-', 48))->bold()
                ->line(UiFormatter::inputQuantity($item->quantity).' x PIZZA '.$size)->bold(false);
            foreach ($snapshot['sections'] ?? [] as $section) {
                $fraction = count($snapshot['sections'] ?? []) > 1 ? ($section['fraction'] ?? '').' ' : '';
                $builder->line('    '.$fraction.mb_strtoupper((string) ($section['product'] ?? '')));
            }
            foreach ($snapshot['modifiers'] ?? [] as $modifier) {
                $prefix = ($modifier['type'] ?? null) === 'remove' ? '- ' : '+ ';
                $builder->line('    '.$prefix.mb_strtoupper((string) ($modifier['name'] ?? '')));
            }
            $builder->bold()->line(($snapshot['fulfillment_type'] ?? null) === 'takeaway' ? '    PARA LLEVAR' : '    COMER AQUÍ')->bold(false);
            if ($item->notes) {
                $builder->line('OBS: '.mb_strtoupper($item->notes));
            }
        }

        return $builder->line(str_repeat('-', 48))->alignCenter()->bold()
            ->line($reprint ? 'REIMPRESION · CONSUMO INTERNO' : 'COMANDA · CONSUMO INTERNO')
            ->bold(false)->finish();
    }
}
