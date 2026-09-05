# Changelog

## 2.0.0 — 2026-09-05

**Zwei Verhaltensänderungen, die eine bestehende Installation treffen — deshalb die Hauptversion.**
Beide stehen unten, mit dem Weg zurück. Wer EU-B2B-Kunden hat, liest zuerst den Abschnitt „eine
formal geprüfte Nummer stellt nicht mehr steuerfrei" und lässt danach einmal
`php artisan invoices:pending-invoices` laufen: dort steht, welche bezahlte Zahlung ohne Beleg
geblieben ist. Als Minor getaggt hätte diese Änderung sich als Logzeile bemerkbar gemacht, und
zwar erst nachdem das Geld geflossen war.

Drei Steuerzonen, eine echte USt-IdNr.-Prüfung und ein Tor vor dem Checkout. Anlass ist der
Verkauf der Addon-Suite ins Ausland: an Unternehmen, überwiegend außerhalb Deutschlands, über
die eigene Kette. Genau diese Konstellation traf die Stellen, an denen das Addon weniger konnte,
als eine Auslandsrechnung braucht.

### Die USt-IdNr. wird geprüft, nicht nur angesehen

Bisher wurde die Nummer gegen ein Muster gehalten, und die Rechnung schrieb ehrlich dazu, dass
nur die Form geprüft wurde. Jetzt fragt `Verification\ViesVerifier` den Bestätigungsdienst der
EU. Liegt die eigene Nummer in `tax.merchant_vat_id`, ist die Abfrage eine qualifizierte, und die
Antwort trägt ein Aktenzeichen, das sich Jahre später zitieren lässt.

Das Ergebnis wird **an der Rechnung eingefroren**: Urteil, Zeitpunkt, Dienst und Aktenzeichen in
vier neuen Spalten. Nachgesehen wird nie beim Rendern — die Antwort von heute ist nicht die, auf
die sich der Verkäufer damals gestützt hat.

**Ein Ausfall ist kein Urteil.** Timeout, HTTP 500, kaputter Körper, oder VIES' eigenes
`MS_UNAVAILABLE` innerhalb einer 200 — alles davon endet als `pending`, nie als `invalid`. Der
Kauf kommt zustande, die Rechnung sagt „USt-IdNr. angegeben, Bestätigung ausstehend / VAT ID
provided, verification pending", und die Nachprüfung steht in der neuen Utility im Control Panel.
Damit bleibt die Regel vom 25.08. heil: eine Rechnung fällt nicht mit einem fremden Server.
`invoices:recheck-vat-ids` fragt später noch einmal und schreibt das Ergebnis in eine eigene
Tabelle — nie in die Rechnung, die sich nicht ändert.

### Drei Zonen statt siebenundzwanzig Ländersätzen

`Support\TaxZone`: `de`, `eu-b2b`, `third-country-b2b`. Mehr braucht ein Verkäufer nicht, der nur
an Unternehmen verkauft — eine Leistung an ein Unternehmen im Ausland wird beim Empfänger
besteuert (§ 3a Abs. 2 UStG), es fällt in allen drei Fällen keine deutsche Steuer an, und was
sich unterscheidet, ist der Satz auf dem Beleg. Die Ländersätze bleiben deshalb draußen.

### Verhaltensänderung: § 19 verdeckt Auslands-B2B nicht mehr

Bisher beantwortete der Kleinunternehmer-Zweig auch den grenzüberschreitenden B2B-Fall mit
„keine Umsatzsteuer nach § 19 UStG" und einer Warnung. Das ist die falsche Pflichtangabe: § 19
ist eine Inlandsregel, und für die Leistung an ein Unternehmen im Ausland liegt der Ort beim
Empfänger. § 14a Abs. 1 UStG will dort „Steuerschuldnerschaft des Leistungsempfängers" und beide
USt-IdNrn. auf dem Dokument. Herleitung: `TASKS/suite-steuer-selbsteinschaetzung-2026-09-05.md`,
Frage (b).

Der neue Weg greift **nur, wenn jemand die Unternehmereigenschaft tatsächlich festgestellt hat**:
eine bestätigte (oder wegen Ausfalls ausstehende) USt-IdNr. innerhalb der EU, eine Erklärung des
Käufers außerhalb. Eine bloß formal geprüfte Nummer ändert nichts — der alte Weg antwortet weiter
wie bisher.

Die Sätze für die beiden Auslandszonen stehen jetzt zweisprachig auf dem Beleg: die
vorgeschriebene deutsche Formulierung, gefolgt von der englischen aus `tax.texts_en`.

### Verhaltensänderung: eine formal geprüfte Nummer stellt nicht mehr steuerfrei

Bisher genügte es, dass eine USt-IdNr. zu einem Muster passte, damit eine EU-Lieferung
steuerfrei gestellt wurde — mit der vorgeschriebenen § 14a-Formulierung auf dem Beleg und einem
Hinweis in den internen Notizen, dass nur die Form geprüft wurde. Das ist die Behauptung einer
Prüfung, die nie stattgefunden hat, und der Beleg sieht dabei völlig unauffällig aus.

Jetzt kommt in diesem Fall kein Satz heraus, sondern `undetermined` mit dem Code
`vat_id_unconfirmed` — die Rechnung wird nicht geschrieben, und der Fall landet dort, wo dieses
Addon jeden ungeklärten Fall hinlegt: bei einem Menschen. Wer bewusst mit einer reinen
Formatprüfung leben will, setzt `tax.vat_id_check.enabled` auf `false`; dann verhält sich alles
wie vorher, samt der alten Notiz. Die Entscheidung ist damit ausdrücklich statt voreingestellt.

Die betroffenen Unit-Tests übergeben den Prüfstand jetzt sichtbar (`VatIdStatus::Valid`). Dass
sie ihn vorher weggelassen haben und trotzdem grün waren, war genau das Problem.

### Das Tor vor dem Checkout

`Support\BuyerAdmission` beantwortet, ob ein Käufer kaufen darf und in welcher Zone. Abgewiesen
wird: ohne Land, ohne Firmenname, EU ohne bestätigte USt-IdNr., Drittland ohne Bestätigung der
unternehmerischen Nutzung. Zwei Oberflächen:

- Die Middleware `invoices.business-buyer`, die ein Host auf seine eigene Checkout-Route legt.
  Sie ist die Durchsetzung und setzt das eingefrorene Ergebnis selbst in die Anfrage, damit ein
  Client kein „bestätigt" unterschieben kann.
- `POST /!/invoices/buyer-check`, damit das Formular die Antwort schon kennt, während der Käufer
  tippt, statt nach dem Start der Zahlung.

Ein Ausfall des Prüfdienstes weist niemanden ab.

### Control Panel

Neue Utility „USt-IdNr.-Prüfungen": die Rechnungen, deren Nummer beim Kauf nicht bestätigt werden
konnte, mit dem, was eine spätere Nachfrage ergeben hat. Der Bildschirm zeigt und handelt nicht —
was aus einer Nummer folgt, die eine Woche später ungültig ist, ist eine Entscheidung, und
§ 6a Abs. 4 UStG schützt das Vertrauen auf die Auskunft vom Kauftag.

Die Liste enthält nicht nur die ausstehenden Prüfungen, sondern auch die Belege, auf denen eine
Nummer steht und **nie** jemand gefragt hat: ältere Rechnungen, oder eine Zahlung, die den
Schreiber am Checkout vorbei erreicht hat. Sonst wäre das eine Klasse von Rechnungen, die keine
Auswertung zählen kann, weil keine von ihr weiß. Bildschirm und `invoices:recheck-vat-ids` lesen
dieselbe Definition (`Invoice::scopeAwaitingVatIdConfirmation`), damit die Liste nichts zeigt, was
das Kommando nie anfasst, und umgekehrt.

`tax.business_only.enabled` tut jetzt, was der Kommentar daneben immer versprach: auf `false` hört
das Tor auf, Verbraucher abzuweisen. Vorher las das Tor nur `require_company`, und der Schalter war
wirkungslos, ohne das zu sagen.

### Der Beleg nennt die Firma

Das Tor verlangt einen Firmennamen, und der kam bisher nicht weiter als bis zum Tor: auf der
Rechnung stand, wer das Formular ausgefüllt hat. § 14 Abs. 4 Nr. 1 UStG will den Leistungsempfänger
genannt haben, und das ist bei einem Geschäftskauf die Firma — sonst kann die Buchhaltung des
Käufers den Beleg nicht gegen sein Unternehmen buchen, was der Grund war, die USt-IdNr. überhaupt
anzugeben. Die Person geht nicht verloren, sie steht als `meta.buyer_contact` am Beleg.

### Gefragt wird nur, wo die Antwort etwas entscheidet

Inlands- und Drittlandsnummern gehen nicht mehr an VIES. Bei einem deutschen Käufer entscheidet die
Bestätigung steuerlich nichts, kostet aber im Ausfall etwas Echtes: die Rechnung trüge `pending` und
stünde dauerhaft auf der Prüfliste. Und VIES kennt nur EU-Nummern — eine US-Steuernummer dorthin zu
schicken heißt, eine Antwort auf eine Frage zu bekommen, die niemand gestellt hat. Beide Nummern
stehen weiter auf dem Beleg, jetzt mit dem Vermerk „USt-IdNr. angegeben, nicht bestätigt".

### Neue Konfiguration

`tax.vat_id_check` (`enabled`, `service`, `timeout`, `cache_hours`), `tax.business_only`
(`enabled`, `require_company`), `tax.texts_en`. Migration
`2026_09_05_090000_add_vat_id_verification_to_invoices` legt die vier Spalten plus `tax_zone` auf
`invoices` an und die Tabelle `invoice_vat_id_checks`.

### Aufräumen am Rand

Routen werden jetzt aus `boot()` registriert statt aus `bootAddon()`. Letzteres läuft aus einem
`Statamic::booted()`-Callback, und eine dort angemeldete Route steht in der Sammlung, ohne je zu
greifen — sie funktionierte nur, weil in einer App etwas anderes zuerst registriert.

## 1.3.1 — 2026-09-05

Kein Verhalten geändert. Das Addon schreibt die Rechnungen, mit denen die Suite verkauft wird, und
war das einzige der Familie ohne Lizenzdatei und ohne CI.

### Lizenz

`LICENSE.md` liegt jetzt bei, wortgleich mit statamic-payments und den übrigen Geschwistern
(proprietäre Lizenz, Copyright Adrian Goldner). `composer.json` sagte schon `proprietary`; die
Datei fehlte.

### CI

`.github/workflows/tests.yml` nach dem Vorbild der Familie: Matrix PHP 8.2 bis 8.4 × Laravel ^12
und ^13 × prefer-lowest und prefer-stable (PHP 8.2 mit Laravel 13 ausgeschlossen, das verlangt
^8.3), dazu Pint als Prüfung, addon-lint als Gate und PHPStan. Der Testlauf ist `vendor/bin/pest`,
nicht phpunit: die Steuerregeln sind in Pest-Syntax geschrieben, und Pest lässt PHPUnit-Klassen
nur mitlaufen, wenn es selbst der Runner ist. Kein `dist`-Job, es gibt kein Control-Panel-Bundle.

### Werkzeug

- **Larastan** in `require-dev`, `phpstan.neon` auf Level 5 über `src/`. Die Insights-Metriken
  extenden eine Klasse aus einem Addon, das nur `suggest` ist; die Stand-ins unter `tests/Fakes/`
  werden deshalb gescannt, nicht analysiert. Die Baseline trägt zwei Einträge, beide `view-string`
  auf `invoices::…`-Views: Larastan prüft, ob die View existiert, und kennt den Addon-Namespace im
  Analyse-Kontext nicht. `InvoiceCounter` hat jetzt die `@property`-Zeilen, die PHPStan brauchte.
- **Pint** nimmt `tests/Fakes/insights-contracts.php` aus. Die Datei ist eine byte-getreue Kopie
  der Signaturen aus statamic-insights; ein Formatierer darauf würde genau die Eigenschaft
  zerstören, die ihren Wert ausmacht.
- **`.gitattributes`** mit `export-ignore` für Tests, CI und Werkzeugkonfiguration; eine Site, die
  das Addon installiert, lädt sie nicht mehr mit.
- **`addon-lint.json`** waivt zwei Regeln mit Begründung: `release.readme` (die Regel sucht
  Überschriftenwörter wie „Usage"; die README erklärt die Nutzung unter „The number", „The PDF",
  „Sending it to the buyer") und `testing.addon-testcase` (die Testbasis ist absichtlich von Hand
  gebaut, siehe `tests/TestCase.php`; der Umbau auf `AddonTestCase` ist ein eigener Schritt).
  Lint-Score 79 → 100.

### Tests gegen statamic-payments 1.17

Zwei Tests setzten stillschweigend payments 1.11 voraus, den Stand des lokalen `vendor/`. Gegen
1.17.1, was `^1.14` heute auflöst, fielen sie:

- `InsightsMetricsTest` verglich die **gesamte** Metrik-Registry mit den vier eigenen Einträgen.
  payments trägt seit 1.14 selbst Metriken ein. Jetzt werden nur die `invoices.`-Handles verglichen.
- `TheBrandComesFromThePaymentTest` prüfte eine Installation, deren `payments`-Tabelle noch keine
  `brand_id`-Spalte hat. Diesen Zustand schließt `^1.14` seit 048fde2 aus (die Spalte kam mit 1.13),
  und payments 1.17 setzt sie beim Anlegen selbst; der Test bewies nur noch, dass man in eine
  gelöschte Spalte nicht schreiben kann. Entfernt, mit Vermerk an der Stelle.

## 1.3.0 — 2026-09-02

### Die Rechnungs-Mail steht im Kommunikationsprotokoll der Zahlung

`InvoiceDelivery::send()` trägt eine zugestellte Rechnung über `PaymentLog::mail($paymentId,
'invoice', $to, $subject, 'sent', ['invoice' => $number])` in `payment_communications` von
statamic-payments ein (ab dessen 1.16, Detailseite der Zahlung). Per `class_exists` auf die
Fassade; ein älteres payments ohne sie bleibt unberührt, und ein Fehler beim Schreiben bricht dort
nie die Zustellung.

### Neu: § 19 warnt beim Verbraucher im EU-Ausland

`TaxRules` setzte bei aktiver Kleinunternehmerregelung alles auf 0 % und warnte nur im B2B-Fall,
also wenn eine USt-IdNr vorlag. Für einen Verbraucher in einem anderen Mitgliedstaat kam nichts —
dabei ist das der Fall, in dem trotz § 19 Steuer im Käuferland anfallen kann: Bei digitalen
Leistungen liegt der Leistungsort beim Käufer (§ 3a Abs. 5 UStG, bei Waren § 3c), sobald der
EU-weite B2C-Umsatz die 10.000-€-Schwelle übersteigt. Die deutsche Befreiung reicht dann nur über
die EU-Kleinunternehmerregelung (§ 19a UStG, seit 01.01.2025, „EX"-Nummer) dorthin; ohne sie ist
Umsatzsteuer des Käuferlandes fällig (OSS). Unterhalb der Schwelle bleibt der Leistungsort in
Deutschland und § 19 greift wie gehabt.

Zwei neue Schlüssel unter `tax.small_business`, beide nicht berechnet, weil beide Tatsachen über
das Jahr sind und nicht über die Zeile: `eu_threshold_mode` (`'below'`, Standard, oder `'above'`)
und `eu_scheme` (`false`, Standard). Über der Schwelle ohne EU-Regelung trägt das Ergebnis eine
Warnung im `notes`-Feld, derselbe Weg wie beim B2B-Fall. Mit EU-Regelung nennt `tax_reason` die
§ 19a-Befreiung (`texts.small_business_eu`, `legal_bases.small_business_eu`) statt § 19. Ein
unbekannter Wert für `eu_threshold_mode` wirft, wie ein unbekannter Schlüssel.

Das ist die Lesart des Gesetzes, mit der das Addon arbeitet, keine Steuerberatung; sie ist noch
nicht steuerlich geprüft.

### Behoben: die Hinweise des Steuerrechners erreichten niemanden

`TaxResult::notes` trug sie von Anfang an, und der `InvoiceWriter` ließ sie fallen: er übernahm
Grund und Mechanismus und sonst nichts. Die B2B-Warnung unter § 19 stand damit seit 1.0.0 auf
keinem Weg, den ein Mensch sieht. Jetzt schreibt der Writer je Hinweis
`Log::warning('invoices: tax note', ['payment' => …, 'product' => …, 'note' => …])`, bevor er
entscheidet, ob er schreibt; legt sie an der Rechnung unter `meta.tax_notes` ab (Liste aus
`product` und `note`), von wo die Gutschrift sie mitnimmt; und `invoices:pending` gibt sie je
Zahlung unter der Tabelle aus, mit und ohne `--write`. Neu dafür: `InvoiceWriter::taxNotesFor()`.
Auf dem Dokument stehen sie nicht, sie sind für die Prüfung, nicht für den Käufer.

## 1.2.1 — 2026-08-29

### Angehoben: `statamic-payments ^1.14`

Die Marke der Rechnung kommt jetzt von der Zahlung, und die Spalte `brand_id` gibt es dort erst
seit 1.14. Mit einer älteren Fassung liefe der Code zwar durch — er fiele auf die Standardmarke
zurück, mit einer Log-Warnung —, aber genau das ist der Fehler, den 1.2.0 behebt. Eine Anforderung,
die den behobenen Zustand wieder zulässt, wäre keine.

Eigene Patch-Version, weil 1.2.0 zu diesem Zeitpunkt bereits veröffentlicht war. Ein
veröffentlichter Tag wird nicht verschoben.

## 1.2.0 — 2026-08-29

### Neu: vier Zahlen in Insights

Ausgestellte Dokumente, netto, brutto und Umsatzsteuer, aufteilbar nach Art, Käuferland und
Steuersatz. Eine Gutschrift geht überall wieder ab, deshalb summiert jede Geldzahl vorzeichen-
richtig — ein Storno kann eine Kachel unter null drücken, und das ist richtig so.

### Behoben: die Kachel zeigte den Umsatz fremder Marken

Bei gewählter Marke zeigte die Gruppe *Rechnungen* vier Dokumente **dreier anderer Marken**. Nicht
bloß eine falsche Zahl: der Umsatz eines Kunden auf dem Schirm eines anderen. Die Regel steht jetzt
einmal in `TableMetric::brandScoped()`; hier wird nur noch die Spalte genannt.

Zwei Abfragen erreichen die Tabelle nicht über den zentralen Weg und tragen die Marke ausdrücklich.
Die zweite davon ist die unangenehmere: `filterOptions()` las die Währungsliste über alle Marken,
und die meistgenutzte Währung ging von dort in das `where` jeder Kachel. Eine Marke, die nur in
Franken abrechnet, bekam auf einer sonst in Euro rechnenden Installation jede Zahl auf eine Währung
gefiltert, die sie nie benutzt, und las 0. **Eine Marke mit Belegen erschien als eine ohne** — das
Leck von hinten.

Dazu war das Zeitfenster der Steuersatz-Aufteilung einschließend und verlor auf einer
Millisekunden-Spalte die letzte Sekunde des Zeitraums.


### Fixed — die Marke der Rechnung war die des Prozesses, nicht die des Kaufs

`brandIdFor()` fragte den Umgebungskontext, und der Zweig, der das absichern sollte, war tot:
`currentId()` hat Rückgabetyp `int` und fällt auf die Standardmarke zurück, ein `null` gab es nie.
Im Mehrmarkenbetrieb ohne gesetzten Kontext — Webhook, Konsolenlauf, Folgeabbuchung, also genau
dort, wo Rechnungen entstehen — bekam die Rechnung damit still die Nummernreihe der Standardmarke,
seit dem PDF-Commit zusätzlich deren Absender. Kein Fehler, kein Log, nur ein falsches Ergebnis auf
einem Dokument, das sich nicht mehr ändern lässt.

Die Marke kommt jetzt von der Zahlung. `statamic-payments` stempelt `brand_id` beim Anlegen der
Zeile, in der Anfrage, in der der Käufer wirklich war, und eine Folgeabbuchung erbt die Marke der
Zeile, zu der sie gehört. Der Kommentar über der Methode behauptete das Gegenteil („a brand is not
recoverable from the payment") — er stimmte, bis es diese Spalte gab.

**Nichts wird dadurch verweigert.** Eine Zahlung mit `brand_id = 0` im Mehrmarkenbetrieb gehört
keiner Marke (Altbestand ohne Backfill, oder ein Checkout, während brand-context nicht antworten
konnte) und bekommt ihre Rechnung wie bisher — wer bezahlt hat, hat Anspruch auf den Beleg, und ein
späterer Lauf könnte ihn nicht nachholen, ohne eine Lücke in einer lückenlosen Reihe zu lassen.
Neu ist nur, dass dieser Rückfall im Log steht. Der Einmarkenbetrieb bleibt bei `0`, und eine
ältere Installation von `statamic-payments` ohne die Spalte läuft in keinen SQL-Fehler.

`Exceptions\BrandUnknown` ist damit ersatzlos weg. Sie wurde nie geworfen, und eine Ausnahme, die
niemand wirft, beschreibt ein Verhalten, das es nicht gibt.

### Added — `invoices:brand-check` misst, was vorher falsch abgelegt wurde

Der Vergleich von `invoices.brand_id` gegen `payments.brand_id`: Abweichungen sind genau die
Rechnungen, die auf dem stillen Weg entstanden sind. Der Befehl nennt Nummer, erwartete und
tatsächliche Marke und **schreibt nichts um** — die Nummer stammt aus dem lückenlosen Zähler einer
Marke und ist dort gezählt worden; sie umzuhängen hinterließe ein Loch in der einen Reihe und einen
Fremdkörper in der anderen. Fehlt die Spalte in `payments`, sagt er das, statt eine leere Liste wie
eine Entwarnung aussehen zu lassen.

### Added — die Rechnung wird ein PDF und geht an den Käufer

Bis hierher existierte die Rechnung nur als HTML, und das war eine bewusste Auslassung: eine
Druckmaschine ist eine Infrastruktur-Entscheidung, und die trifft ein Addon nicht für seinen Host.
Der Ausweg ist ein Contract statt einer festen Klasse — `Contracts\PdfRenderer` hängt im Container,
mitgeliefert wird `DompdfRenderer`. **dompdf**, weil es reines PHP ist: jede andere Kandidatin
(Browsershot, wkhtmltopdf) hätte mit dem Addon eine Node-Laufzeit oder ein Systembinary installiert.

Erzeugt wird aus derselben Blade-Vorlage, die schon die Vorschau zeigt. Zwei Layouts, die
auseinanderlaufen können, kann ein gedrucktes Steuerdokument sich nicht leisten.

**Zweimal erzeugen ergibt dieselbe Datei, Byte für Byte.** dompdf stempelt sonst die Wanduhr
(`CreationDate`, `ModDate`) und eine gewürfelte Dokument-ID in jede Datei; beides wird jetzt aus der
Rechnung selbst abgeleitet. Ohne das wäre der zweite Abruf in neun Jahren ein anderes Dokument als
das, was der Käufer hat — sichtbar niemandem, bis es eine Frage bei einer Prüfung ist.

Zugestellt wird auf `InvoiceIssued`, nicht per Cron: so geht genau das hinaus, was geschrieben
wurde, einmal. Fehlt eine Pflichtangabe, entsteht weiterhin **keine** Rechnung — der Versandweg
bekommt nur fertige Dokumente zu sehen und kann an dieser Prüfung nicht vorbei.

Der Versand läuft über den `BrandMailer` aus brand-context, damit die Absenderidentität zur Marke
gehört. Eine Marke, die eine Mail-Identität angibt und die Adresse weglässt, sendet **gar nicht**;
eine Marke, die nichts angegeben hat, fällt auf den auf *dieser* Rechnung eingefrorenen Verkäufer
zurück, nicht auf den host-weiten Absender — der gehört im Mehrmarkenbetrieb einer anderen Marke.

### Changed — brand-context ist jetzt eine echte Abhängigkeit

Vorher `suggest`. Wer Rechnungen verschickt, braucht den `BrandMailer`; eine Zustellung, die je nach
Installation still unter fremdem Namen hinausgeht, ist keine kleinere Version des Features. Die
übrigen Addons der Familie, die Mail versenden, halten es seit August genauso.

### Fixed — `invoices:pending` brach bei einer fehlenden Pflichtangabe ab

Die Schleife fing nur `RateUndetermined`. Eine `DetailsMissing` flog bis nach oben, der Lauf endete
mit einem Stacktrace, und die übrigen Zahlungen wurden nicht einmal mehr angesehen — bei einem
Befehl, dessen einzige Aufgabe es ist, zu sagen, woran es liegt.

### Changed — die Vorlage kommt ohne Flexbox aus

Kopfzeile, Kennzahlen und Fußzeile lagen auf `display: flex`, und keine reine PHP-Druckmaschine
kennt das: der Absender wäre unter dem Empfänger gelandet statt neben ihm. Jetzt Tabelle und
Ränder, die Browser und Druck gleich verstehen. Die Tabellenköpfe stehen auf `font-weight: 700`
statt 600 — ein Zwischengewicht findet die Druckmaschine nicht und fällt auf ihre Serifenschrift
zurück.

## 1.1.0 — 2026-08-26

### Fixed — über ein Angebot verkauft hieß: keine Rechnung

`product()` las `config('statamic-payments.products')` direkt und fragte nie den `Catalogue` — also
genau die Naht, über die `statamic-offers` seine Angebote unter dem Präfix `offer:` einhängt. Für
jede Zahlung, die über ein Angebot lief, warf `isDigital()` `ProductIncomplete`, und es entstand
**gar kein Dokument**. `statamic-funnels` benutzt für jeden Bezahlschritt ein Angebot, die
beworbene Kette riss also am letzten Glied.

Dazu zwei Nachbarfehler, die derselbe Test aufgedeckt hat:

- **Die Steuerklasse wird je Produkt-Handle konfiguriert**, und ein Angebot hat einen eigenen. Ohne
  den neuen `taxHandle()` wäre ein Angebot für ein ermäßigtes Produkt still auf die Standardklasse
  gefallen — falscher Satz, richtiges Aussehen, unveränderliches Dokument.
- **Der Positionsname war der rohe Handle.** Eine Zahlung ohne Positionen druckte `kurs` statt
  „Chorleitungskurs" und `offer:fruehling-upsell` statt dem Namen, den der Käufer gelesen hatte.
  Vorbestehend; erst sichtbar, als ein Angebot den Handle hässlich genug machte.

### Changed — `prices_include_tax` hat keine Voreinstellung mehr

Ab Werk stand es auf `false`, hinterlegte Preise galten also als netto. Für ein Addon, dessen
Zielgruppe in Deutschland an Verbraucher verkauft, ist das die falsche Vermutung: der angezeigte
Preis ist nach Preisangabenverordnung der Endpreis inklusive Umsatzsteuer. Wer 19 € einträgt, meint
19 € brutto — die Rechnung wies 22,61 € aus, für eine Zahlung über 19 €.

Es gibt jetzt keine Vermutung. Die erste Rechnung verweigert sich mit `PriceBasisUndecided`, bis
jemand einmal je Installation geantwortet hat. Bei Geld ist eine Verweigerung mit Begründung besser
als eine plausible falsche Zahl.

Die Frage wird nur gestellt, wo sie etwas entscheidet: unter § 19, bei einer Befreiung und bei einem
Satz von 0 sind netto und brutto dasselbe, und dort schweigt sie.

### Added — die Rechnung muss zum Geld passen

`DoesNotMatchThePayment`: die Summe eines Dokuments muss der Betrag sein, der tatsächlich eingezogen
wurde. Das ist die einzige Prüfung von außen, die eine Rechnung überhaupt hat — alles andere an ihr
ist konstruktionsbedingt stimmig, weil derselbe Code die Zeilen addiert, der sie schreibt. Ein
falscher Satz ergibt eine falsche Rechnung, die genau wie eine richtige aussieht; der Kontoauszug ist
der einzige Zeuge, den bisher niemand gefragt hat.

Sie fängt die ganze Familie auf einmal: eine falsch herum stehende Preisbasis, einen Satz, wo keiner
hingehört, einen Nachlass, der beim Aufteilen einen Cent verliert, Positionen, die nicht zur Zahlung
summieren.

**Was sie nebenbei gezeigt hat:** `prices_include_tax => false` kann für eine aus einer Zahlung
abgeleitete Rechnung heute gar nicht richtig sein, weil in dieser Familie niemand an der Kasse
Steuer aufschlägt. Die Option bleibt — ein Wirt kann das eines Tages tun — aber eine falsch
konfigurierte Installation erfährt es jetzt bei der ersten Rechnung statt beim nächsten Prüfungstermin.

## 1.0.0 — 2026-08-25

The first release. A review before it went out proved six things wrong; each of them is fixed and
pinned by a test in `tests/Feature/TheCriticsFindingsTest.php`:

- **One payment could get two invoices.** Demonstrated on MySQL: five concurrent calls wrote
  `RE2026-08-001` and `-002` for the same €244. The duplicate check sat before the transaction, and
  the unique index it relied on did not exist — `constrained()` creates a foreign key, not a unique
  one. There is now a real `unique(payment_id, kind)`, which also allows exactly one credit note.
- **Under concurrency most callers lost their invoice.** Three of five MySQL processes hit a
  deadlock, and the transaction ran with a single attempt. Three now.
- **Only the invoice head was immutable.** `InvoiceItem` had no guard at all: a line could be added,
  changed or deleted under a head that kept its totals, and the template printed both. That is a
  falsified invoice that reads as correct.
- **The printed line did not add up at quantity > 1.** 3 × €10 gross printed "3 × €8.40" above a net
  of €25.21. Rounding now goes the other way and the remainder lands in the discount, where it is a
  stated number.
- **`digital` was guessed.** `?? true` made a vinyl record a digital service and printed the wrong
  mandatory note. It refuses now, like a missing country.
- **The brand came from the request.** `currentId()` falls back to the default brand, and nothing is
  current in a webhook — so a second brand's invoice landed silently in the first brand's series.

One more found while wiring it into a demo with five brands, and it is the same class of mistake as
the six above: **two brands shared a prefix and handed out the same number.** The counter is per
brand, the number is globally unique, and `RE` for both means the second brand's invoice dies on the
index — on an order somebody already paid for. A multi-brand installation now has to give each brand
its own prefix, and says so instead of colliding. Deriving one from the brand handle would have been
a guess that silently renumbers an installation the day it adds a brand.

Two mandatory details are checked before writing, because an invoice cannot be corrected: the
sender's own details always, and above €250 the recipient's name and address (§ 14 UStG). Below that
line § 33 UStDV allows a Kleinbetragsrechnung, which is the ordinary case for a digital product.

The totals are broken down **per tax rate**, as § 14 Abs. 4 Nr. 8 requires — a single net line above
an invoice carrying 19 % and 7 % does not satisfy it, and that is the normal case here. An invoice for every payment: a number that is unique and continuous, VAT decided
by the buyer's country, and a document that never changes.

### The number

Taken from a counter row that is **locked while it is incremented**, inside the same transaction
that writes the invoice — not from `MAX() + 1`. German law wants a series that is unique *and*
gapless, and both of those are properties of concurrency rather than arithmetic: two checkouts
finishing in the same millisecond both read the same maximum and both write the same number, and
neither notices. The prior art this replaces did exactly that.

One series per brand, restarting on a configurable period. Changing the format later renumbers
nothing: the resolved series is stored on the counter.

### It does not change

`update()` and `delete()` throw on the model. A correction is a **second document** — a credit note
that takes the next number, copies the original's figures and points at what it reverses. The tax is
copied rather than recalculated: the rate that applied is the rate that applied, and looking it up
again a month later could produce a different one, at which point the two documents would not cancel
out.

On a full refund the credit note is written by itself. A partial refund is not: which lines came
back is a question only a person can answer, and guessing it would put a wrong figure on a tax
document.

### No guessed rate

If no rule matches, **no invoice is written** and `invoices:pending` says which payments are waiting
and why. The alternative — falling back to the standard rate — is the failure this addon exists to
avoid: a wrong rate on a tax document looks like an answer. It is wrong quietly, it is signed, and
it is handed to a customer.

That applies to a missing country too. Payments taken before `statamic-payments` 1.9 have none, and
those get no invoice rather than one at the seller's own rate.

### What it deliberately does not do

- **The OSS threshold.** Below €10,000 of annual turnover into other EU countries the seller's own
  rate applies, above it the recipient's. That is a state over time and needs a turnover figure —
  a bookkeeping question, not a per-line one. There is a switch (`tax.oss.destination_taxation`) and
  a named seam; what is missing is written down in the code.
- **VIES lookups.** A VAT ID is checked for shape, never over the network: a tax calculation that
  depends on somebody else's server is one that fails at checkout when their server is down.
- **PDF rendering.** It renders HTML — the same template the preview shows, so the two cannot drift.
- **Bookkeeping, DATEV, dunning.**

### Requires

`goldnead/statamic-payments` ^1.9 — earlier versions record neither the buyer's country nor the
discount per line, and neither can be reconstructed afterwards.
