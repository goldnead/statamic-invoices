<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

use Goldnead\Invoices\Contracts\VatIdVerifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The gate in front of the checkout: may this buyer buy, and in which zone?
 *
 * Sitting in this package rather than in the checkout one, because the reason
 * for the refusal is a tax reason. Selling to a consumer abroad drags in OSS,
 * the 10.000 € threshold, a UK registration from the first sale and the whole
 * consumer-protection apparatus; selling only to businesses removes all of it
 * at once. That is a decision about invoices, so the rule lives with the
 * invoices — and the checkout asks.
 *
 * ## What it refuses, and why each refusal is not optional
 *
 * - **No country.** The zone is a function of the country. Without one there is
 *   no rate, no note and no invoice — and the failure would surface after the
 *   money had moved.
 * - **No company name.** § 14 UStG wants the recipient named. "Business only"
 *   with a blank company field is a claim nobody checked.
 * - **An EU buyer without a confirmed VAT ID.** This is the one the whole ticket
 *   is about: without the number the buyer is a consumer as far as the law is
 *   concerned, whatever they ticked.
 * - **A third-country buyer who has not said they are a business.** Same reason,
 *   different evidence — there is no register to ask, so the declaration plus the
 *   company name is what is available, and it is the customary standard.
 *
 * ## What it does not refuse
 *
 * A VAT ID whose confirmation could not be reached. That check comes back
 * `pending`, the sale goes through, and the invoice says so. The alternative is
 * losing paid orders to somebody else's outage, and the rule from 25.08. — an
 * invoice does not fall over because a foreign server did — points the same way.
 */
final class BuyerAdmission
{
    public function __construct(
        private readonly VatIdVerifier $verifier,
        /** @var array<string, mixed> The `tax` block of config/invoices.php. */
        private readonly array $config = [],
        private readonly ?CacheRepository $cache = null,
    ) {}

    /** @param  array<string, mixed>|null  $taxConfig */
    public static function fromConfig(VatIdVerifier $verifier, ?array $taxConfig = null): self
    {
        if ($taxConfig === null) {
            $fromApp = function_exists('config') ? config('invoices.tax') : [];
            $taxConfig = is_array($fromApp) ? $fromApp : [];
        }

        return new self($verifier, $taxConfig);
    }

    /**
     * @param  bool  $businessConfirmed  What a third-country buyer ticked: that they
     *                                   are buying as a business. Meaningless inside the EU, where the VAT ID is
     *                                   the evidence and a tick is not.
     */
    public function for(
        ?string $country,
        ?string $vatId = null,
        ?string $company = null,
        bool $businessConfirmed = false,
    ): Admission {
        $rules = TaxRules::fromConfig($this->config);

        $country = $this->normaliseCountry($country);
        $vatId = $this->normaliseVatId($vatId);
        $company = is_string($company) ? trim($company) : null;
        $merchantCountry = $this->normaliseCountry($this->config['merchant_country'] ?? 'DE') ?? 'DE';

        if ($country === null) {
            return Admission::refuse(
                'country_missing',
                'Please choose the country your business is registered in. We need it to issue a valid invoice.',
            );
        }

        // The business-only rule, switched off.
        //
        // Then this class stops refusing and only reports: a number that was given
        // still gets confirmed, because the invoice wants the answer either way, but
        // nobody is turned away for being a consumer. The three refusals below all
        // exist to enforce "businesses only"; without that rule they are three ways
        // of losing a sale for no reason.
        //
        // The missing country above is not one of them. Without a country there is no
        // rate, no note and no invoice — that refusal belongs to the document, not to
        // the sales policy, so it stands in both modes.
        if (! $this->businessOnly()) {
            return Admission::admit(
                $this->zoneWithoutTheRule($country, $merchantCountry, $vatId, $businessConfirmed, $rules),
                $vatId === null ? null : $this->check($vatId),
            );
        }

        if ($this->requiresCompany() && ($company === null || $company === '')) {
            return Admission::refuse(
                'company_missing',
                'Please enter the name of your company. We sell to businesses only, and the invoice has to name the recipient.',
            );
        }

        // Domestic. § 19 answers, no number is needed, and asking for one would be
        // asking for evidence that changes nothing.
        if ($country === $merchantCountry) {
            return Admission::admit(TaxZone::Domestic, $vatId === null ? null : $this->check($vatId));
        }

        if ($rules->isEuMemberState($country)) {
            return $this->euBusiness($country, $vatId, $rules);
        }

        if (! $businessConfirmed) {
            return Admission::refuse(
                'business_not_confirmed',
                'Please confirm that you are buying as a business. We sell to businesses only.',
            );
        }

        return Admission::admit(TaxZone::ThirdCountryBusiness, $vatId === null ? null : $this->check($vatId));
    }

    /**
     * Which zone a buyer lands in when nobody is being refused.
     *
     * Null for a consumer abroad, and that is the honest answer: the three zones are
     * the three cases of a business-only seller, and a consumer in Vienna is none of
     * them. The tax rules then answer that line the way they always did, through the
     * threshold and OSS switches — which is exactly what turning the rule off asks
     * for.
     */
    private function zoneWithoutTheRule(
        string $country,
        string $merchantCountry,
        ?string $vatId,
        bool $businessConfirmed,
        TaxRules $rules,
    ): ?TaxZone {
        if ($country === $merchantCountry) {
            return TaxZone::Domestic;
        }

        if ($rules->isEuMemberState($country)) {
            return $vatId !== null
                && $rules->isPlausibleVatId($vatId)
                && $rules->vatIdCountry($vatId) === $country
                && $this->check($vatId)->status->permitsReverseCharge()
                    ? TaxZone::EuBusiness
                    : null;
        }

        return $businessConfirmed ? TaxZone::ThirdCountryBusiness : null;
    }

    private function euBusiness(string $country, ?string $vatId, TaxRules $rules): Admission
    {
        if ($vatId === null) {
            return Admission::refuse(
                'vat_id_missing',
                'Please enter your VAT identification number. Inside the EU we can only sell to businesses that have one.',
            );
        }

        // The format first, and locally: a number that cannot be one is refused
        // without spending a network call, and without the buyer waiting for it.
        if (! $rules->isPlausibleVatId($vatId)) {
            return Admission::refuse(
                'vat_id_malformed',
                sprintf('"%s" does not look like a VAT identification number. Please check it and try again.', $vatId),
            );
        }

        if (($idCountry = $rules->vatIdCountry($vatId)) !== $country) {
            return Admission::refuse(
                'vat_id_country_mismatch',
                sprintf(
                    'The VAT identification number "%s" belongs to %s, but you selected %s. Please make the two agree.',
                    $vatId,
                    (string) $idCountry,
                    $country,
                ),
            );
        }

        $check = $this->check($vatId);

        if (! $check->status->permitsPurchase()) {
            return Admission::refuse(
                'vat_id_not_confirmed',
                sprintf(
                    'The VAT identification number "%s" was not confirmed by the EU service (VIES). '
                    .'Please check it and try again.',
                    $vatId,
                ),
                $check,
            );
        }

        return Admission::admit(TaxZone::EuBusiness, $check);
    }

    /**
     * Ask the service, once per number for as long as the answer keeps.
     *
     * Only a confirmed number is remembered. A `pending` one must not be: caching
     * a non-answer would turn one outage into hours of invoices all saying
     * "verification pending" for a service that came back five minutes later. An
     * `invalid` one is not cached either — the usual cause is a typo, and the
     * buyer's second attempt has to reach the service.
     */
    public function check(string $vatId): VatIdCheck
    {
        $vatId = $this->normaliseVatId($vatId) ?? $vatId;

        if (! $this->checkingEnabled()) {
            return VatIdCheck::unchecked($vatId);
        }

        $key = 'invoices:vat-id:'.$this->verifier->name().':'.$vatId;
        $store = $this->store();

        if ($store !== null) {
            try {
                if (($cached = VatIdCheck::fromArray($store->get($key))) !== null) {
                    return $cached;
                }
            } catch (Throwable $e) {
                // A cache store that cannot be read is an operations problem, not a
                // reason to refuse a business — so it falls through and asks the
                // service. But it is said out loud: a cache that is quietly gone
                // shows up only as VIES rate-limiting hours later, and the log line
                // is the only thing that points at the actual cause.
                $this->cacheFailed('gelesen', $e);
            }
        }

        $check = $this->verifier->verify($vatId, $this->requesterVatId());

        if ($store !== null && $check->isValid() && ($hours = $this->cacheHours()) > 0) {
            try {
                $store->put($key, $check->toArray(), $hours * 3600);
            } catch (Throwable $e) {
                $this->cacheFailed('geschrieben', $e);
            }
        }

        return $check;
    }

    private function cacheFailed(string $was, Throwable $e): void
    {
        Log::warning(sprintf(
            'statamic-invoices: der Zwischenspeicher für USt-IdNr.-Prüfungen konnte nicht %s werden. '
            .'Jede Prüfung geht jetzt an den Dienst, der irgendwann drosselt.',
            $was,
        ), ['exception' => $e]);
    }

    private function store(): ?CacheRepository
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            return Cache::store();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The seller's own number, which is what makes the enquiry a qualified one.
     *
     * Null when it is not configured. The caller then still gets an answer, and
     * {@see TaxRules} adds the note that § 14a wants both numbers — so the missing
     * one is visible on the document rather than only here.
     */
    private function requesterVatId(): ?string
    {
        $id = $this->config['merchant_vat_id'] ?? null;

        return is_string($id) && trim($id) !== '' ? strtoupper(trim($id)) : null;
    }

    private function checkingEnabled(): bool
    {
        $block = $this->config['vat_id_check'] ?? [];

        return ! is_array($block) || ! array_key_exists('enabled', $block) || (bool) $block['enabled'];
    }

    private function cacheHours(): int
    {
        $block = $this->config['vat_id_check'] ?? [];

        return is_array($block) && isset($block['cache_hours']) ? max(0, (int) $block['cache_hours']) : 0;
    }

    private function businessOnly(): bool
    {
        return $this->flag('business_only', 'enabled');
    }

    private function requiresCompany(): bool
    {
        return $this->flag('business_only', 'require_company');
    }

    /**
     * A boolean out of the config, defaulting to true when it is not there.
     *
     * True is the safe default for both flags this reads: an installation that has
     * not answered gets the stricter behaviour, and the looser one has to be asked
     * for. The other way round, a typo in the key name would quietly open the gate.
     */
    private function flag(string $block, string $key): bool
    {
        $values = $this->config[$block] ?? [];

        if (! is_array($values) || ! array_key_exists($key, $values)) {
            return true;
        }

        return (bool) $values[$key];
    }

    private function normaliseCountry(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * The same normalisation the verifier applies before it asks.
     *
     * Everything but letters and digits goes, not merely whitespace. Inside the EU
     * the format check would have caught the difference; outside it there is no
     * format to check, so "US 12-345" and "US12345" would otherwise be one number
     * to the service and two keys in the cache — and the punctuation would end up
     * verbatim in a key of a store that may be shared with other applications.
     */
    private function normaliseVatId(mixed $vatId): ?string
    {
        if (! is_string($vatId)) {
            return null;
        }

        $vatId = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vatId));

        return $vatId === '' ? null : $vatId;
    }
}
