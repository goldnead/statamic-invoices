{{-- Die Utility „Rechnungsexport“.

     Wie die Liste der ausstehenden USt-IdNr.-Prüfungen ein Fragment für den
     DynamicHtmlRenderer: `<ui-*>`-Komponenten, kein eigenes JS-Bundle.

     Formulare sind hier native `<form>`-Elemente. `ui-input` reicht `name` und
     `value` an das echte Eingabefeld durch, der Zeitraum kommt also als
     gewöhnlicher GET-Parameter an und ist teilbar wie jede andere URL. Die
     Downloads sind `as="a"`: ein Inertia-Link auf eine CSV würde die Datei als
     Seitenwechsel anfordern und nichts herunterladen. --}}

<ui-heading text="{{ __('invoices::exports.title') }}" size="2xl" />

<ui-description text="{{ __('invoices::exports.intro') }}" />

@if($error)
    <ui-alert variant="error" text="{{ $error }}" class="mt-4" />
@endif

<ui-panel heading="{{ __('invoices::exports.period_heading') }}" subheading="{{ __('invoices::exports.period_current', ['period' => $period->label()]) }}" class="mt-6">
    <ui-card>
        <div class="flex flex-wrap gap-2">
            @foreach($presets as $preset)
                <ui-button size="sm" href="{{ $preset['url'] }}" text="{{ $preset['label'] }}" />
            @endforeach
        </div>

        <form method="GET" action="{{ $pageUrl }}" class="mt-4 flex flex-wrap items-end gap-3">
            <ui-field label="{{ __('invoices::exports.period_from') }}">
                <ui-input type="date" name="from" value="{{ $period->from->toDateString() }}" />
            </ui-field>
            <ui-field label="{{ __('invoices::exports.period_to') }}">
                <ui-input type="date" name="to" value="{{ $period->to->toDateString() }}" />
            </ui-field>
            <ui-button type="submit" text="{{ __('invoices::exports.period_apply') }}" />
        </form>

        @if($brandName)
            <ui-description class="mt-3" text="{{ __('invoices::exports.brand_scope', ['brand' => $brandName]) }}" />
        @endif
    </ui-card>
</ui-panel>

<ui-panel heading="{{ __('invoices::exports.report_heading') }}" subheading="{{ trans_choice('invoices::exports.report_subheading', $report->documents) }}{{ $report->creditNotes > 0 ? trim(trans_choice('invoices::exports.report_subheading_credit_notes', $report->creditNotes), ' ') : '' }}">
    @if($report->rows === [])
        {{-- Ein Leerzustand, der sagt, was er bedeutet: kein Beleg im Zeitraum,
             nicht „keine Einträge“, was sich bei einer kaputten Abfrage genauso liest. --}}
        <ui-card>
            <ui-description text="{{ __('invoices::exports.report_empty') }}" />
        </ui-card>
    @else
        <ui-card>
            {{-- Sieben Spalten Beträge passen auf ein Telefon nicht. Die Tabelle
                 scrollt dann für sich, statt die Summen abzuschneiden. --}}
            <div class="overflow-x-auto">
            <ui-table>
                <ui-table-columns>
                    <ui-table-column>{{ __('invoices::exports.report_treatment') }}</ui-table-column>
                    <ui-table-column>{{ __('invoices::exports.report_country') }}</ui-table-column>
                    <ui-table-column class="text-right">{{ __('invoices::exports.report_rate') }}</ui-table-column>
                    <ui-table-column class="text-right">{{ __('invoices::exports.report_documents') }}</ui-table-column>
                    <ui-table-column class="text-right">{{ __('invoices::exports.report_net') }}</ui-table-column>
                    <ui-table-column class="text-right">{{ __('invoices::exports.report_tax') }}</ui-table-column>
                    <ui-table-column class="text-right">{{ __('invoices::exports.report_gross') }}</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    @foreach($report->rows as $row)
                        <ui-table-row>
                            <ui-table-cell>{{ __('invoices::exports.mechanism.'.$row['mechanism']) }}</ui-table-cell>
                            <ui-table-cell>{{ $row['country'] ?? '' }}</ui-table-cell>
                            <ui-table-cell class="text-right tabular-nums whitespace-nowrap">{{ $percent($row['rate_bp']) }}</ui-table-cell>
                            <ui-table-cell class="text-right tabular-nums whitespace-nowrap">{{ $row['documents'] }}</ui-table-cell>
                            <ui-table-cell class="text-right tabular-nums whitespace-nowrap">{{ $euro($row['net']) }}</ui-table-cell>
                            <ui-table-cell class="text-right tabular-nums whitespace-nowrap">{{ $euro($row['tax']) }}</ui-table-cell>
                            <ui-table-cell class="text-right tabular-nums whitespace-nowrap">{{ $euro($row['gross']) }}</ui-table-cell>
                        </ui-table-row>
                    @endforeach
                    <ui-table-row>
                        <ui-table-cell><ui-text variant="strong" text="{{ __('invoices::exports.report_total') }}" /></ui-table-cell>
                        <ui-table-cell></ui-table-cell>
                        <ui-table-cell></ui-table-cell>
                        <ui-table-cell class="text-right tabular-nums whitespace-nowrap"><ui-text variant="strong" text="{{ $report->documents }}" /></ui-table-cell>
                        <ui-table-cell class="text-right tabular-nums whitespace-nowrap"><ui-text variant="strong" text="{{ $euro($report->totals['net']) }}" /></ui-table-cell>
                        <ui-table-cell class="text-right tabular-nums whitespace-nowrap"><ui-text variant="strong" text="{{ $euro($report->totals['tax']) }}" /></ui-table-cell>
                        <ui-table-cell class="text-right tabular-nums whitespace-nowrap"><ui-text variant="strong" text="{{ $euro($report->totals['gross']) }}" /></ui-table-cell>
                    </ui-table-row>
                </ui-table-rows>
            </ui-table>
            </div>

            @if($report->ossRows() !== [])
                <ui-description class="mt-4" text="{{ __('invoices::exports.report_oss', ['amount' => $euro($report->ossTax())]) }}" />
            @endif

            @if(collect($report->rows)->contains('mechanism', 'small_business'))
                <ui-description class="mt-2" text="{{ __('invoices::exports.report_small_business') }}" />
            @endif

            <ui-description class="mt-2" text="{{ __('invoices::exports.report_note') }}" />
        </ui-card>

        @if($report->derived > 0)
            <ui-alert variant="warning" class="mt-3" text="{{ trans_choice('invoices::exports.report_derived', $report->derived) }}" />
        @endif

        @if($report->mixesCurrencies())
            <ui-alert variant="warning" class="mt-3" text="{{ __('invoices::exports.report_currencies', ['currencies' => implode(', ', array_keys($report->currencies))]) }}" />
        @endif
    @endif
</ui-panel>

<ui-panel heading="{{ __('invoices::exports.download_heading') }}" subheading="{{ __('invoices::exports.download_subheading') }}">
    <ui-card>
        <div class="flex flex-wrap items-center gap-2">
            <ui-dropdown align="start">
                <template #trigger>
                    <ui-button variant="primary" icon="download" icon-append="chevron-down" text="{{ __('invoices::exports.download_csv') }}" />
                </template>
                <ui-dropdown-menu>
                    @foreach($csvLinks as $profile => $url)
                        <ui-dropdown-item as="a" href="{{ $url }}" download text="{{ __('invoices::exports.format_'.$profile) }}" />
                    @endforeach
                </ui-dropdown-menu>
            </ui-dropdown>

            <ui-button as="a" href="{{ $reportCsvUrl }}" download icon="download" text="{{ __('invoices::exports.download_report_csv') }}" />
        </div>
    </ui-card>
</ui-panel>

<ui-panel heading="{{ __('invoices::exports.archive_heading') }}" subheading="{{ __('invoices::exports.archive_subheading') }}">
    <ui-card>
        <form method="POST" action="{{ $archiveUrl }}">
            <ui-input type="hidden" name="_token" value="{{ csrf_token() }}" class="hidden" />
            <ui-input type="hidden" name="from" value="{{ $period->from->toDateString() }}" class="hidden" />
            <ui-input type="hidden" name="to" value="{{ $period->to->toDateString() }}" class="hidden" />
            <ui-button type="submit" icon="file-zip" text="{{ __('invoices::exports.archive_build') }}: {{ $period->label() }}" />
        </form>
    </ui-card>

    @if($archives['ready'] === [] && $archives['pending'] === [] && $archives['failed'] === [])
        <ui-card class="mt-2">
            <ui-description text="{{ __('invoices::exports.archive_none') }}" />
        </ui-card>
    @else
        <ui-card class="mt-2">
            <ui-table>
                <ui-table-rows>
                    @foreach($archives['pending'] as $name)
                        <ui-table-row>
                            <ui-table-cell>{{ $name }}</ui-table-cell>
                            <ui-table-cell><ui-badge pill color="amber" text="{{ __('invoices::exports.archive_pending') }}" /></ui-table-cell>
                            <ui-table-cell></ui-table-cell>
                            <ui-table-cell></ui-table-cell>
                        </ui-table-row>
                    @endforeach
                    @foreach($archives['failed'] as $failed)
                        <ui-table-row>
                            <ui-table-cell>{{ $failed['name'] }}</ui-table-cell>
                            <ui-table-cell><ui-badge pill color="red" text="{{ __('invoices::exports.archive_failed') }}" /></ui-table-cell>
                            <ui-table-cell colspan="2"><ui-text variant="subtle" size="sm" text="{{ $failed['reason'] }}" /></ui-table-cell>
                        </ui-table-row>
                    @endforeach
                    @foreach($archives['ready'] as $archive)
                        <ui-table-row>
                            <ui-table-cell>{{ $archive['name'] }}</ui-table-cell>
                            <ui-table-cell class="tabular-nums">{{ $size($archive['size']) }}</ui-table-cell>
                            <ui-table-cell class="tabular-nums">{{ \Illuminate\Support\Carbon::createFromTimestamp($archive['modified'], config('app.timezone'))->format('d.m.Y H:i') }}</ui-table-cell>
                            <ui-table-cell class="text-right">
                                <ui-button as="a" size="sm" href="{{ $downloadUrl($archive['name']) }}" download icon="download" text="{{ __('invoices::exports.archive_download') }}" />
                            </ui-table-cell>
                        </ui-table-row>
                    @endforeach
                </ui-table-rows>
            </ui-table>
        </ui-card>
    @endif
</ui-panel>
