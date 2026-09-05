{{-- Der Bildschirm „USt-IdNr.: Prüfung ausstehend“, als Statamic-Utility.

     Diese Datei erweitert bewusst kein Core-Layout: das Control Panel von Statamic 6
     hat keine Blade-Seiten mehr, und eine Utility mit `->view()` schickt das Fragment
     durch den DynamicHtmlRenderer, in dem die `<ui-*>`-Komponenten leben. Damit sieht
     die Liste aus wie der Rest des CP, ohne dass dieses Addon ein eigenes JS-Bundle
     mitschleppt — und sie lässt sich in der Testsuite dieses Pakets rendern, weil sie
     kein Layout und keine Statamic-Installation um sich herum braucht. --}}

<ui-heading text="USt-IdNr.: Prüfung ausstehend" size="2xl" />

<ui-description text="Rechnungen, bei denen der Bestätigungsdienst nicht erreichbar war. Der Kauf ist zustande gekommen, die Rechnung trägt den Vermerk „Bestätigung ausstehend“, und die Prüfung steht noch aus. Nachprüfen: php artisan invoices:recheck-vat-ids" />

@if($total === 0)
    {{-- Ein Leerzustand, der sagt was er bedeutet. „Keine Einträge“ liest sich
         identisch, ob nichts offen ist oder die Abfrage kaputt ist. --}}
    <ui-panel>
        <ui-card>
            <ui-description text="Nichts offen. Jede USt-IdNr., die auf einer Rechnung steht, wurde bestätigt oder musste nicht geprüft werden." />
        </ui-card>
    </ui-panel>
@else
    <ui-panel>
        <ui-card>
            <ui-table>
                <ui-table-columns>
                    <ui-table-column>Rechnung</ui-table-column>
                    <ui-table-column>Datum</ui-table-column>
                    <ui-table-column>Empfänger</ui-table-column>
                    <ui-table-column>USt-IdNr.</ui-table-column>
                    <ui-table-column>Zone</ui-table-column>
                    <ui-table-column>Zuletzt nachgeprüft</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    @foreach($invoices as $invoice)
                        @php($letzte = $invoice->vatIdChecks->first())
                        <ui-table-row>
                            <ui-table-cell>{{ $invoice->number }}</ui-table-cell>
                            <ui-table-cell>{{ $invoice->issued_at?->format('d.m.Y') }}</ui-table-cell>
                            <ui-table-cell>{{ $invoice->buyer_name }}</ui-table-cell>
                            <ui-table-cell>{{ $invoice->buyer_vat_id }}</ui-table-cell>
                            <ui-table-cell>{{ $invoice->zone()?->label() ?? '—' }}</ui-table-cell>
                            <ui-table-cell>
                                @if($letzte === null)
                                    <ui-badge text="noch nicht" />
                                @elseif($letzte->contradicts($invoice))
                                    {{-- Die einzige Zeile, die eine Entscheidung braucht, und
                                         deshalb die einzige, die sich abhebt. --}}
                                    <ui-badge color="red" text="ungültig, bitte ansehen" />
                                    {{ $letzte->checked_at?->format('d.m.Y H:i') }}
                                @else
                                    <ui-badge text="{{ $letzte->verdict()?->value ?? 'unklar' }}" />
                                    {{ $letzte->checked_at?->format('d.m.Y H:i') }}
                                @endif
                            </ui-table-cell>
                        </ui-table-row>
                    @endforeach
                </ui-table-rows>
            </ui-table>
        </ui-card>
    </ui-panel>

    @if($invoices->hasPages())
        {{-- Ohne diese Zeile endet die Liste bei fünfzig, während oben eine höhere
             Zahl steht: nichts sieht kaputt aus, und der Rest ist unerreichbar. --}}
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
@endif
