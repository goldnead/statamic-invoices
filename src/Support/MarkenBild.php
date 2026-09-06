<?php

namespace Goldnead\Invoices\Support;

use Goldnead\BrandContext\Support\BrandIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Wie die Marke aussieht — soweit dieses Paket es wissen kann.
 *
 * Die Werte gehoeren `statamic-brand-context`
 * ({@see BrandIdentity}). Das Paket ist hier
 * eine **optionale** Abhaengigkeit: eine Rechnung darf nicht daran scheitern,
 * dass es fehlt, und auch nicht daran, dass eine aeltere Fassung installiert
 * ist, die diese Klasse noch nicht kennt.
 *
 * Deshalb wird die Klasse ueber einen **String** aufgeloest und mit
 * `class_exists` geprueft, nie direkt benannt — dasselbe Muster, mit dem
 * payments seine Rechnungs-Bruecke einhaengt. Fehlt die Gegenseite, gibt es die
 * Vorgaben: ein lesbares Dokument ohne Logo, kein leerer Kasten und kein
 * Fehler.
 *
 * (Das `use` oben hat Pint fuer den `{@see}`-Verweis ergaenzt. Es ist zur
 * Laufzeit folgenlos — ein `use` ist ein Alias und laedt nichts. Wer die Klasse
 * irgendwann wirklich per Typ benennt, macht daraus eine Pflichtabhaengigkeit.)
 *
 * **Nichts hier laedt etwas nach.** Das Logo ist ein Pfad auf der Platte, die
 * Schrift eine Systemliste, die Farben sind Werte. Der Grund steht im Kopf von
 * `resources/views/invoice.blade.php` und gilt unveraendert: eine Rechnung, die
 * von einem CDN abhaengt, ist in fuenf Jahren eine Rechnung ohne Layout, und
 * aufbewahren muss man sie zehn.
 */
final class MarkenBild
{
    private const IDENTITY = 'Goldnead\\BrandContext\\Support\\BrandIdentity';

    private const BRAND = 'Goldnead\\BrandContext\\Models\\Brand';

    /**
     * Die Erscheinung der Marke, an der diese Rechnung haengt.
     *
     * `$brandId` ist `0` auf jeder Installation mit einer einzigen Marke, und
     * das ist der Normalfall — dann gilt die Standardmarke.
     *
     * @return array<string, mixed>
     */
    public static function fuer(int|string|null $brandId = null): array
    {
        $identityClass = self::IDENTITY;

        if (! class_exists($identityClass)) {
            return self::vorgabe();
        }

        $brand = null;
        $brandClass = self::BRAND;

        try {
            if ($brandId !== null && (int) $brandId > 0 && class_exists($brandClass)) {
                $brand = $brandClass::find((int) $brandId);
            }

            $identitaet = $identityClass::for($brand);
        } catch (\Throwable $e) {
            // brand-context ist da, aber nicht benutzbar — die haeufigste
            // Fassung davon ist „Klassen installiert, Migrationen nie
            // gelaufen": dann wirft schon `Brand::default()` an einer
            // fehlenden Tabelle.
            //
            // **Eine Rechnung darf daran nicht scheitern.** Sie ist ein
            // Pflichtdokument, und ein fehlendes Logo ist kein Grund, sie nicht
            // auszustellen. Also Vorgaben.
            //
            // Aber nicht stumm: wer sein Branding eingetragen hat und es nicht
            // sieht, soll den Grund im Log finden und nicht raten muessen.
            // Einmal je Aufruf, `warning` und nicht `error` — es ist ein
            // Schoenheitsfehler, kein Ausfall.
            Log::warning('statamic-invoices: die Marke war nicht lesbar, die Rechnung nimmt die Vorgaben.', [
                'brand_id' => $brandId,
                'grund' => $e->getMessage(),
            ]);

            return self::vorgabe();
        }

        return [
            'name' => $identitaet->name(),
            'ink' => $identitaet->ink(),
            'accent' => $identitaet->accent(),
            'paper' => $identitaet->paper(),
            'muted' => $identitaet->muted(),
            'font' => $identitaet->fontStack(),
            'logo' => $identitaet->logoPath(),
            'logoSvg' => $identitaet->logoSvg(),
        ];
    }

    /**
     * Ohne brand-context: lesbar, aber ohne Marke.
     *
     * Die Farben sind bewusst neutral und **nicht** die von adriangoldner.dev.
     * Wer dieses Addon einzeln kauft, soll keine fremde Marke in seiner
     * Rechnung finden — und ein Dokument in Schwarz auf Weiss ist kein Mangel,
     * sondern eine Rechnung.
     *
     * @return array<string, mixed>
     */
    public static function vorgabe(): array
    {
        return [
            'name' => '',
            'ink' => '#1a1a1a',
            'accent' => '#1a1a1a',
            'paper' => '#ffffff',
            'muted' => '#666666',
            'font' => '-apple-system, "Segoe UI", Roboto, Helvetica, Arial, "DejaVu Sans", sans-serif',
            'logo' => null,
            'logoSvg' => null,
        ];
    }
}
