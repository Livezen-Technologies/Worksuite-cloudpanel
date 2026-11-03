<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** FIN-2: the finance dashboard -- headline figures, trends and splits. */
class OverviewController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function show(Request $request)
    {
        [$from, $to] = self::window($request);

        return $this->data([
            'kpis' => $this->finance->kpis($from, $to),
            'series' => $this->finance->monthlySeries(12),
            'breakdowns' => $this->finance->breakdowns($from, $to),
        ]);
    }

    /**
     * The range the screens ask for, as a half-open pair.
     *
     * A named range is resolved here rather than in the browser so that two
     * clients looking at "this month" agree, and so the server's clock -- not
     * the reader's -- decides where a month ends.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(Request $request): array
    {
        $now = CarbonImmutable::now();
        $range = (string) $request->query('range', '30d');
        if ($range === 'custom') {
            $from = CarbonImmutable::parse((string) $request->query('from', $now->subDays(30)->toDateString()))->startOfDay();
            $to = CarbonImmutable::parse((string) $request->query('to', $now->toDateString()))->endOfDay();

            return $from <= $to ? [$from, $to] : [$to, $from];
        }

        return match ($range) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };
    }
}
