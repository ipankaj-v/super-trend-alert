<?php

namespace App\Http\Controllers;

use App\Models\PaperTrade;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PaperTradeController extends Controller
{
    public function index()
    {
        return view('paper');
    }

    public function data(): JsonResponse
    {
        $closed = PaperTrade::where('status', 'closed')->orderBy('exit_at')->get();

        $curve = [];
        $cum = 0.0;
        foreach ($closed as $t) {
            $cum += $t->pnl;
            $curve[] = ['t' => $t->exit_at, 'v' => round($cum, 2)];
        }

        $byDay = [];
        foreach ($closed as $t) {
            $day = Carbon::createFromTimestamp($t->exit_at, 'Asia/Kolkata')->format('Y-m-d');
            $byDay[$day][] = $t;
        }
        krsort($byDay);

        $today = Carbon::now('Asia/Kolkata')->format('Y-m-d');

        return response()->json([
            'stats' => self::stats($closed->all()),
            'today' => self::stats($byDay[$today] ?? []),
            'curve' => $curve,
            'by_day' => collect($byDay)->map(fn ($g, $day) => ['day' => $day] + self::stats($g))->values(),
            'open' => PaperTrade::where('status', 'open')->orderBy('entry_at')->get()->map(fn ($t) => self::row($t)),
            'pending' => PaperTrade::where('status', 'pending')->orderBy('flip_time')->get()->map(fn ($t) => self::row($t)),
            'closed' => $closed->reverse()->take(200)->values()->map(fn ($t) => self::row($t)),
            'skipped' => PaperTrade::where('status', 'skipped')->count(),
            'server_time' => time(),
        ]);
    }

    /** Wipe all paper trades (to start a fresh test). */
    public function reset(): JsonResponse
    {
        PaperTrade::query()->delete();

        return response()->json(['ok' => true]);
    }

    private static function row(PaperTrade $t): array
    {
        $open = $t->status === 'open';
        $pnl = $open && $t->last_price !== null ? $t->entry_price - $t->last_price : $t->pnl;

        return [
            'id' => $t->id, 'status' => $t->status, 'label' => "{$t->side} " . (int) $t->strike, 'side' => $t->side,
            'symbol' => $t->symbol, 'source' => $t->source, 'resolution' => $t->resolution,
            'flip_time' => $t->flip_time, 'flip_low' => $t->flip_low,
            'entry' => $t->entry_price, 'entry_at' => $t->entry_at, 'sl' => $t->sl_current, 'sl0' => $t->sl_initial,
            'risk' => $t->risk, 'last' => $t->last_price, 'dir' => $t->last_dir,
            'exit' => $t->exit_price, 'exit_at' => $t->exit_at, 'reason' => $t->exit_reason ?: $t->skip_reason,
            'pnl' => $pnl, 'r' => ($pnl !== null && $t->risk > 0) ? round($pnl / $t->risk, 2) : null,
            'best' => ($t->entry_price !== null && $t->low_seen !== null) ? $t->entry_price - $t->low_seen : null,
            'worst' => ($t->entry_price !== null && $t->high_seen !== null) ? $t->high_seen - $t->entry_price : null,
        ];
    }

    /** Win rate, reward:risk, profit factor ... for closed trades (points = entry - exit). */
    private static function stats(array $trades): array
    {
        $n = count($trades);
        if ($n === 0) {
            return ['n' => 0, 'total' => 0.0];
        }

        usort($trades, fn ($a, $b) => $a->exit_at <=> $b->exit_at);

        $win = array_filter($trades, fn ($t) => $t->pnl > 0);
        $loss = array_filter($trades, fn ($t) => $t->pnl < 0);
        $gw = array_sum(array_map(fn ($t) => $t->pnl, $win));
        $gl = -array_sum(array_map(fn ($t) => $t->pnl, $loss));
        $total = array_sum(array_map(fn ($t) => $t->pnl, $trades));
        $avgWin = $win ? $gw / count($win) : 0.0;
        $avgLoss = $loss ? $gl / count($loss) : 0.0;

        $eq = $peak = $dd = 0.0;
        $streak = $maxStreak = 0;
        foreach ($trades as $t) {
            $eq += $t->pnl;
            $peak = max($peak, $eq);
            $dd = max($dd, $peak - $eq);
            $streak = $t->pnl < 0 ? $streak + 1 : 0;
            $maxStreak = max($maxStreak, $streak);
        }

        return [
            'n' => $n, 'wins' => count($win), 'losses' => count($loss),
            'win_rate' => round(count($win) / $n * 100, 1),
            'total' => round($total, 2), 'avg' => round($total / $n, 2),
            'avg_win' => round($avgWin, 2), 'avg_loss' => round($avgLoss, 2),
            'rr' => $avgLoss > 0 ? round($avgWin / $avgLoss, 2) : null,
            'pf' => $gl > 0 ? round($gw / $gl, 2) : null,
            'breakeven' => $avgLoss > 0 ? round(100 / (1 + $avgWin / $avgLoss), 1) : null,
            'avg_r' => round(array_sum(array_map(fn ($t) => (float) $t->r_multiple, $trades)) / $n, 2),
            'best' => round(max(array_map(fn ($t) => $t->pnl, $trades)), 2),
            'worst' => round(min(array_map(fn ($t) => $t->pnl, $trades)), 2),
            'max_dd' => round($dd, 2), 'streak' => $maxStreak,
        ];
    }
}