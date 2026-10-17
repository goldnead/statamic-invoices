<?php

namespace Goldnead\Invoices\Console\Concerns;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A command that reads brand settings runs once per brand, on that brand's rows.
 *
 * A console run has no brand, and `SettingsManager` answers "packaged values"
 * for no brand. What an operator saved on the settings page (the seller on an
 * invoice, the delivery subject, the VAT ID check) was therefore never seen by
 * `invoices:pending`, `invoices:retry` and the rest, and nothing said so.
 *
 * Running per brand fixes the settings and creates a second duty: **the rows
 * must be cut to the brand as well.** Under brand B's settings, a loop over all
 * payments would write brand A's invoice with B's seller. So every query of a
 * command that uses this trait goes through {@see self::forBrand()}.
 *
 * On a single-brand install nothing changes: the callback runs once, with no
 * brand, and the rows are not cut.
 */
trait RunsPerBrand
{
    use RunsForEachBrand;

    /**
     * Whether brands are in play at all. brand-context is a hard requirement,
     * but its service provider is not booted in every test of this suite; an
     * unbound manager and a single-brand install both mean: run once, cut nothing.
     */
    protected function multiBrandActive(): bool
    {
        return class_exists(BrandContext::class)
            && app()->bound('brand-context')
            && BrandContext::multiBrandEnabled();
    }

    /**
     * Every brand, one after the other, and the worst exit code at the end.
     *
     * Not `forEachBrand()`: that one stops at the first brand that fails, and
     * here a failure is routine (an invoice that cannot be written because of a
     * missing detail is a finding, not a reason to leave the next brand's
     * payments unwritten). Each brand gets its turn.
     *
     * @param  \Closure(Brand|null): ?int  $callback
     */
    protected function forEveryBrand(\Closure $callback): int
    {
        if (! $this->multiBrandActive()) {
            return (int) ($callback(null) ?? self::SUCCESS);
        }

        $exit = self::SUCCESS;

        try {
            $brands = collect($this->brandsToProcess());
        } catch (ModelNotFoundException) {
            $this->components->error(sprintf('Die Marke "%s" gibt es nicht.', (string) $this->option('brand')));

            return self::FAILURE;
        }

        if ($brands->isEmpty()) {
            // "0 processed" from a run that did nothing reads like a healthy one.
            $this->components->error('Mehrmarken ist eingeschaltet, aber es gibt keine Marke. Nichts wurde verarbeitet.');

            return self::FAILURE;
        }

        foreach ($brands as $brand) {
            if ($this->shouldAnnounceBrand()) {
                $this->line("Brand: {$brand->handle}");
            }

            try {
                $code = (int) (BrandContext::runFor($brand, fn () => $callback($brand)) ?? self::SUCCESS);
            } catch (\Throwable $e) {
                // One brand blowing up must not leave the next one's work undone.
                report($e);
                $this->components->error("Brand {$brand->handle}: ".$e->getMessage());
                $code = self::FAILURE;
            }

            if ($exit === self::SUCCESS) {
                $exit = $code;
            }
        }

        return $exit;
    }

    /**
     * Cut a query to the brand being processed.
     *
     * Rows that belong to no brand (`brand_id` 0 or null, a payment from before
     * the column existed) go with the default brand: that is where
     * `InvoiceWriter::brandIdFor()` puts them anyway, and a per-brand loop that
     * skipped them would leave them unprocessed for good, quietly.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    protected function forBrand($query, ?Brand $brand, string $column = 'brand_id')
    {
        if ($brand === null) {
            return $query;
        }

        return $query->where(function ($q) use ($brand, $column) {
            $q->where($column, $brand->id);

            if ($brand->is_default) {
                $q->orWhereNull($column)->orWhere($column, 0);
            }
        });
    }
}
