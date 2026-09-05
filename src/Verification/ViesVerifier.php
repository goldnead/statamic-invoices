<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Verification;

use Goldnead\Invoices\Contracts\VatIdVerifier;
use Goldnead\Invoices\Support\VatIdCheck;
use Goldnead\Invoices\Support\VatIdStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The EU's own confirmation service, over its REST endpoint.
 *
 * ## The one rule this class is built around
 *
 * **It never turns a failure into a verdict.** Every way this call can go wrong
 * — a timeout, a 500, a body that is not JSON, a body that is JSON but says
 * nothing about validity, the member state's own register being offline, which
 * VIES reports as `MS_UNAVAILABLE` inside a perfectly successful 200 — comes
 * back as {@see VatIdStatus::Pending} with the reason attached. Not as
 * `Invalid`, which would tell a buyer their correct number is wrong and stop a
 * legitimate sale; not as `Valid`, which would print a reverse-charge note on
 * an invoice standing on nothing.
 *
 * The distinction that makes that possible: `valid: false` is an *answer* and
 * becomes `Invalid`; everything else is a *non-answer* and becomes `Pending`.
 * The two collapse into one boolean easily, and the collapse is silent — which
 * is why the parsing below is written out rather than shortened.
 *
 * ## Qualified versus simple
 *
 * With the seller's own number in hand the enquiry is qualified: VIES then
 * returns a `requestIdentifier`, and that reference is what makes the check
 * quotable years later (§ 18e UStG is the German equivalent, at the BZSt).
 * Without it the service still answers, but the answer is a yes with nothing
 * behind it. The caller decides; this class reports which of the two it got.
 */
final class ViesVerifier implements VatIdVerifier
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /**
     * Answers that mean "ask again later", not "no".
     *
     * Every one of these arrives inside HTTP 200. A reading that only looks at
     * the status code and the `valid` flag files them all as invalid, which is
     * the exact failure this addon exists to avoid.
     */
    private const UNANSWERABLE = [
        'MS_UNAVAILABLE',
        'MS_MAX_CONCURRENT_REQ',
        'TIMEOUT',
        'SERVICE_UNAVAILABLE',
        'SERVER_BUSY',
        'GLOBAL_MAX_CONCURRENT_REQ',
    ];

    public function __construct(
        private readonly int $timeoutSeconds = 8,
        private readonly ?string $endpoint = null,
    ) {}

    public function name(): string
    {
        return 'vies';
    }

    public function verify(string $vatId, ?string $requesterVatId = null): VatIdCheck
    {
        $normalised = $this->normalise($vatId);

        // Two characters of country and at least one of number. Below that there is
        // nothing to ask about, and asking anyway spends a call to be told so.
        if (strlen($normalised) < 3) {
            return new VatIdCheck(
                vatId: $vatId,
                status: VatIdStatus::Invalid,
                checkedAt: Carbon::now(),
                service: $this->name(),
                failure: 'Not a VAT ID: fewer than three characters after normalisation.',
            );
        }

        $payload = [
            'countryCode' => substr($normalised, 0, 2),
            'vatNumber' => substr($normalised, 2),
        ];

        if (($requester = $this->splitRequester($requesterVatId)) !== null) {
            $payload['requesterMemberStateCode'] = $requester[0];
            $payload['requesterNumber'] = $requester[1];
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint ?? self::ENDPOINT, $payload);
        } catch (Throwable $e) {
            // Connection refused, DNS, TLS, timeout. Logged with the exception class,
            // because "could not connect" and "the certificate expired" want different
            // repairs and the message alone often does not separate them.
            // The exception object, not its class name: Monolog writes the trace from
            // it, and for a TLS handshake failure inside the HTTP client the trace is
            // the only thing that says where it broke. The message stays alongside so
            // the line is readable without unfolding anything.
            Log::warning('statamic-invoices: the VAT ID could not be confirmed.', [
                'service' => $this->name(),
                'vat_id' => $normalised,
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);

            return VatIdCheck::pending($normalised, $this->name(), $this->shorten($e->getMessage()));
        }

        if (! $response->successful()) {
            Log::warning('statamic-invoices: the confirmation service refused the enquiry.', [
                'service' => $this->name(),
                'vat_id' => $normalised,
                'status' => $response->status(),
            ]);

            return VatIdCheck::pending(
                $normalised,
                $this->name(),
                sprintf('The service answered with HTTP %d.', $response->status()),
            );
        }

        // `json()` on a body that is not JSON gives null, and on the JSON literal
        // `null` it gives null as well. Neither of the two is a verdict.
        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('statamic-invoices: the confirmation service sent something unreadable.', [
                'service' => $this->name(),
                'vat_id' => $normalised,
                'body' => $this->shorten($response->body()),
            ]);

            return VatIdCheck::pending($normalised, $this->name(), 'The answer was not a JSON object.');
        }

        // The service's own error channel, inside a 200.
        $userError = $body['userError'] ?? null;

        if (is_string($userError) && $userError !== '' && strtoupper($userError) !== 'VALID') {
            if (in_array(strtoupper($userError), self::UNANSWERABLE, true)) {
                Log::warning('statamic-invoices: the confirmation service could not answer.', [
                    'service' => $this->name(),
                    'vat_id' => $normalised,
                    'user_error' => $userError,
                ]);

                return VatIdCheck::pending(
                    $normalised,
                    $this->name(),
                    sprintf('The service could not answer: %s.', $userError),
                );
            }

            // INVALID_INPUT and its relatives: the service did answer, and the answer
            // is that this is not a number it recognises.
            return new VatIdCheck(
                vatId: $normalised,
                status: VatIdStatus::Invalid,
                checkedAt: Carbon::now(),
                service: $this->name(),
                failure: sprintf('The service rejected the enquiry: %s.', $userError),
            );
        }

        // The verdict itself. Absent — a shape this class does not know, or a body
        // that carried only an error field — there is nothing to read, and a missing
        // boolean must not read as false.
        if (! array_key_exists('valid', $body) || ! is_bool($body['valid'])) {
            Log::warning('statamic-invoices: the confirmation service gave no verdict.', [
                'service' => $this->name(),
                'vat_id' => $normalised,
                'keys' => array_keys($body),
            ]);

            return VatIdCheck::pending($normalised, $this->name(), 'The answer carried no "valid" field.');
        }

        return new VatIdCheck(
            vatId: $normalised,
            status: $body['valid'] ? VatIdStatus::Valid : VatIdStatus::Invalid,
            checkedAt: $this->answeredAt($body['requestDate'] ?? null),
            service: $this->name(),
            requestId: $this->string($body['requestIdentifier'] ?? null),
            // VIES blanks these when the member state does not disclose them, and
            // sends "---" rather than an empty string while doing so.
            name: $this->disclosed($body['name'] ?? null),
            address: $this->disclosed($body['address'] ?? null),
        );
    }

    private function normalise(string $vatId): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vatId));
    }

    /**
     * "DE123456789" into ["DE", "123456789"].
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitRequester(?string $vatId): ?array
    {
        if ($vatId === null) {
            return null;
        }

        $normalised = $this->normalise($vatId);

        if (strlen($normalised) < 3) {
            return null;
        }

        return [substr($normalised, 0, 2), substr($normalised, 2)];
    }

    /**
     * The moment the service says it answered, falling back to ours.
     *
     * Theirs is the better evidence — it is the timestamp on their side of the
     * enquiry — but a service sending an unparseable date must not cost the
     * timestamp altogether.
     */
    private function answeredAt(mixed $requestDate): Carbon
    {
        if (is_string($requestDate) && $requestDate !== '') {
            try {
                return Carbon::parse($requestDate);
            } catch (Throwable) {
                // Falls through to our own clock.
            }
        }

        return Carbon::now();
    }

    private function disclosed(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === null || trim($value, '- ') === '' ? null : $value;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'…' : $text;
    }
}
