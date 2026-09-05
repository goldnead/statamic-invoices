<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Contracts\Metric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A metric over one database table with a timestamp on it.
 *
 * Optional and offered, not required: the contract is {@see Metric}, and an
 * addon whose numbers come from somewhere else — a file store, an API, a
 * calculation — implements that directly and ignores this. What this saves is
 * the part every table-backed metric would otherwise write again: windowing a
 * period, bucketing a timestamp in three SQL dialects, splitting by a column
 * without dropping the rows whose value is null.
 *
 * Extracted from `statamic-payments` after it had proven itself there, rather
 * than designed in advance — which is why it has exactly the methods a real
 * implementation needed and no others.
 *
 * A contributor writes:
 *
 *     class ActiveMembers extends TableMetric
 *     {
 *         protected function table(): string { return 'memberships'; }
 *         protected function timestamp(): string { return 'started_at'; }
 *
 *         public function handle(): string { return 'memberships.started'; }
 *         public function label(): string  { return __('New memberships'); }
 *         public function group(): string  { return __('Memberships'); }
 *         public function unit(): string   { return Unit::COUNT; }
 *
 *         public function value(MetricQuery $q): int|float|null
 *         {
 *             return $this->inPeriod($q)->count();
 *         }
 *
 *         public function series(MetricQuery $q): array
 *         {
 *             return $this->bucketed($this->inPeriod($q), $q, 'count(*)');
 *         }
 *     }
 *
 * **Loading this class means Insights is installed.** Guard the registration
 * with `class_exists` on the facade and PHP never reaches the file when the
 * sibling is absent — which is what keeps the coupling one-directional and
 * optional. Put Insights in `suggest`, never in `require`.
 */
abstract class TableMetric implements Metric
{
    /** The table the numbers come from. */
    abstract protected function table(): string;

    /**
     * The column that says when a row happened.
     *
     * Not `created_at` by default and deliberately without one: the row is
     * written when the software noticed, and the fact happened when it
     * happened. A payment paid on the 30th and recorded on the 1st belongs to
     * the 30th, and a metric that never had to choose would pick the wrong one
     * silently.
     */
    abstract protected function timestamp(): string;

    /**
     * Nothing to measure, which is not the same as measuring nothing.
     *
     * A metric whose table is absent is left out of every screen rather than
     * reporting a zero — the difference between "this addon is not installed"
     * and "nobody bought anything".
     */
    public function available(): bool
    {
        return Schema::hasTable($this->table());
    }

    public function description(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function meta(MetricQuery $query): array
    {
        return [];
    }

    /**
     * The rows inside the window, ready to be counted or summed.
     *
     * Override to add the conditions that make a row count at all — a status,
     * a soft-delete. Everything downstream builds on this, so a condition put
     * here applies to the figure, the chart and every split at once, and cannot
     * be forgotten in one of them. Override by *extending* it, never by
     * rewriting it: three separate defects in this method — the missing null
     * check, the missing upper bound, the inclusive one — reached only the
     * metrics that had called `parent::inPeriod()` and left every hand-written
     * copy silently wrong.
     *
     * The brand is not among the conditions to add here. Declare
     * {@see brandColumn} instead and it is applied below, so that every metric
     * in the family narrows by exactly the rules the rest of the install uses.
     */
    /**
     * The column that says which brand a row belongs to, or null for none.
     *
     * Declaring it is the whole opt-in: {@see inPeriod} then narrows every
     * figure, chart and split to the current brand at once, and no individual
     * metric can forget to. A table without brands, or a metric that answers a
     * question deliberately spanning all of them, returns null — and then says
     * so in its {@see description}, because a screen where one tile counts one
     * brand and its neighbour counts four, with nothing on either saying which,
     * is worse than a screen that knows no brands at all.
     */
    protected function brandColumn(): ?string
    {
        return null;
    }

    /**
     * Narrow a query to the current brand.
     *
     * This is `Goldnead\BrandContext\Scopes\BrandScope::apply()` transcribed
     * for the query builder, and it must stay a transcription: the metrics read
     * tables through `DB::table()`, so Eloquent's global scope never fires, and
     * a figure that filtered by its own rules would disagree with every other
     * reading of the same install. The order matters and is theirs — bypass
     * first, then single-brand, then the unresolved case, then the filter.
     *
     * An unresolved brand fails closed to no rows, not to an absent metric:
     * {@see Metric::available()} answers whether the thing exists, and a brand
     * that has not been picked yet is not the metric ceasing to exist. A tile
     * reading zero can be understood; a tile that vanished cannot.
     */
    protected function brandScoped(Builder $rows, ?string $column = null): Builder
    {
        $column ??= $this->brandColumn();

        if ($column === null || ! app()->bound('brand-context')) {
            return $rows;
        }

        $manager = app('brand-context');

        if ($manager->scopeIsDisabled() || ! $manager->multiBrandEnabled()) {
            return $rows;
        }

        if (! $manager->hasCurrent()) {
            return $manager->failMode() === 'open'
                ? $rows
                : $rows->whereRaw('1 = 0');
        }

        return $rows->where($this->table().'.'.$column, $manager->currentId());
    }

    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        $rows = DB::table($this->table())
            // A row with no timestamp cannot be placed in time, so it is in no
            // period — including "all time", where both bounds are null and the
            // two clauses below add no condition at all. Without this, a metric
            // over a nullable column counted every row ever written the moment
            // somebody picked the widest range: cancellations that never
            // happened, completions that never completed. Found by a
            // contributor building on this class, which is the only reason it
            // was found before shipping.
            ->whereNotNull($column)
            ->when($query->period->from, fn ($rows) => $rows->where($column, '>=', $query->period->from))
            // Half-open: `< midnight` rather than `<= 23:59:59.999999`. A
            // binding formats the upper bound as `Y-m-d H:i:s` and drops the
            // fraction, so on a column storing milliseconds every row in the
            // last second of the period fell out — invisibly, and only on some
            // engines. Midnight is the same instant at every precision.
            ->when($query->period->toExclusive(), fn ($rows) => $rows->where($column, '<', $query->period->toExclusive()));

        return $this->brandScoped($rows);
    }

    /**
     * The same window, but never reaching past this moment.
     *
     * **A decision every metric has to make, which is why this is opt-in and
     * named rather than done for you.** An open-ended period has no upper
     * bound, and these tables are full of the future: a pre-order starting next
     * month, a licence expiring next year, a campaign scheduled for Friday,
     * a task due on Monday. Counted without a clamp, the widest range reports
     * all of it as though it had already happened.
     *
     * Clamp when the metric answers **what happened** — sales, cancellations,
     * confirmations, bounces. Do not clamp when it answers **what is
     * scheduled** — upcoming events, due tasks, pending retries — because there
     * the future is the point, and a screen that hid it would be lying by
     * omission instead.
     *
     * Found by a contributor whose tables carried pre-orders. It is the sibling
     * of the null-timestamp defect one method up: there the condition was
     * missing entirely, here only its upper half.
     */
    protected function untilNow(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return $this->inPeriod($query, $column)->where($column, '<=', Carbon::now($this->zone()));
    }

    /**
     * The timezone the timestamp column is written in, or null for the site's own.
     *
     * A clamp compares a stored wall-clock against the current one, so both have
     * to be read off the same clock. A table that stores UTC while the site runs
     * on Europe/Berlin was clamped two hours early, and on a US host five hours
     * early — always the newest rows, always silently, and never on the machine
     * of whoever set the metric up, because there the two clocks agree.
     *
     * Two addons had answered this by writing their own `untilNow()`, which is
     * how the last three defects in this class reached only half the family.
     * One line here instead: state where the column lives, and the base class
     * does the rest.
     */
    protected function zone(): ?string
    {
        return null;
    }

    /**
     * Truncating a timestamp to a day or a month, in the dialect at hand.
     *
     * `strftime` is SQLite's and MySQL has never heard of it. Written for one
     * engine, a chart is green in a test suite on SQLite and a 500 on the first
     * production install that runs MySQL — a bill this family has already paid
     * once.
     */
    protected function bucketExpression(MetricQuery $query, ?string $column = null): string
    {
        $column ??= $this->timestamp();
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $monthly
                ? "date_format({$column}, '%Y-%m')"
                : "date_format({$column}, '%Y-%m-%d')",
            'pgsql' => $monthly
                ? "to_char({$column}, 'YYYY-MM')"
                : "to_char({$column}, 'YYYY-MM-DD')",
            default => $monthly
                ? "strftime('%Y-%m', {$column})"
                : "strftime('%Y-%m-%d', {$column})",
        };
    }

    /**
     * One aggregate per bucket, and only for the buckets that have data.
     *
     * The empty ones are left out on purpose: Insights fills the range in for
     * every metric at once. A metric that invented its own zeroes would fill
     * them twice, and one that invented a bucket outside the range would draw
     * a column the axis has no place for.
     *
     * Ordered by the bucket, explicitly. `GROUP BY` promises no order: SQLite
     * happens to hand the groups back sorted, MySQL 8 does not, and a series
     * whose keys arrive out of order draws a line that jumps back in time.
     *
     * @return array<string, int|float>
     */
    protected function bucketed(Builder $rows, MetricQuery $query, string $aggregate, ?string $column = null): array
    {
        return $rows
            ->selectRaw($this->bucketExpression($query, $column).' as bucket, '.$aggregate.' as measured')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('measured', 'bucket')
            ->all();
    }

    /**
     * Split by one column, largest first, with the null rows kept.
     *
     * **A row whose value is null is a row.** A sale with no campaign, a
     * booking with no source, a grant with no reason — grouping them under one
     * heading is honest; dropping them makes the split disagree with the total
     * and nothing on the screen says why. Label them through
     * {@see missingLabel()}.
     *
     * @return array<int, array{key: string|null, value: int|float}>
     */
    protected function splitByColumn(
        Builder $rows,
        MetricQuery $query,
        string $column,
        string $aggregate,
        int $limit,
    ): array {
        return $rows
            ->selectRaw($column.' as split_key, '.$aggregate.' as measured')
            ->groupBy($column)
            ->orderByRaw($aggregate.' desc')
            // Zweites Sortierkriterium, damit die Reihenfolge eine ist.
            //
            // Ohne das entscheidet bei *gleicher* Kennzahl die Datenbank, und
            // sie entscheidet je nach Treiber anders: dieselben zwei Zeilen
            // kamen unter SQLite in der einen und unter MySQL in der anderen
            // Ordnung zurueck. Auf dem Schirm heisst das eine Liste, die ohne
            // Grund springt; in einer Suite heisst es gruen auf dem Laptop und
            // rot in der CI (statamic-events, 05.09.2026).
            //
            // `$limit` macht es mehr als kosmetisch: bei einem Gleichstand an
            // der Abschneidekante haengt sonst vom Treiber ab, *welche* Zeile
            // ueberhaupt zurueckkommt.
            ->orderBy($column)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'key' => ($row->split_key === null || $row->split_key === '') ? null : (string) $row->split_key,
                // `+ 0` rather than a cast: the driver hands back a numeric
                // string, and PHP turns "1500" into an int and "1.5" into a
                // float on its own. Casting to int would silently floor an
                // average; casting to float would print a count as "7.0".
                'value' => $row->measured + 0,
            ])
            ->all();
    }

    /**
     * Turn the raw split rows into what {@see HasBreakdowns}
     * promises, labelling the null.
     *
     * @param  array<int, array{key: string|null, value: int|float}>  $rows
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function labelled(array $rows, string $dimension): array
    {
        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] ?? $this->missingLabel($dimension),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * What to call the rows that have no value for this split.
     *
     * Overridden per addon, because "no campaign" and "no source" read
     * differently and a shared "—" tells a reader nothing.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-insights::report.unassigned');
    }
}
