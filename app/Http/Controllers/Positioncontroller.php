<?php

namespace App\Http\Controllers;

use App\Models\TrackedPosition;
use App\Services\DeltaClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function index()
    {
        return [
            'active' => TrackedPosition::where('status', 'active')->orderByDesc('id')->get(),
            'recent' => TrackedPosition::where('status', '!=', 'active')->orderByDesc('id')->limit(10)->get(),
        ];
    }

    public function store(Request $request, DeltaClient $delta)
    {
        $data = $request->validate([
            'symbol' => ['required', 'regex:/^[CP]-BTC-\d+-\d{6}$/'],
            'entry_price' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', Rule::in(['mark', 'traded'])],
            'resolution' => ['required', Rule::in(array_merge(array_keys(DeltaClient::NATIVE), array_keys(DeltaClient::DERIVED)))],
        ]);

        // C-BTC-73800-111126 -> side CE, strike 73800, expiry 11-11-2026
        preg_match('/^([CP])-BTC-(\d+)-(\d{2})(\d{2})(\d{2})$/', $data['symbol'], $m);
        $side = $m[1] === 'C' ? 'CE' : 'PE';
        $strike = (float) $m[2];
        $expiry = "{$m[3]}-{$m[4]}-20{$m[5]}";

        try {
            $listed = collect($delta->optionTickers($expiry))->contains(fn ($t) => ($t['symbol'] ?? null) === $data['symbol']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not check the contract on Delta: ' . $e->getMessage()], 502);
        }

        if (! $listed) {
            return response()->json(['message' => "Contract {$data['symbol']} is not listed on Delta for {$expiry}."], 422);
        }

        $exists = TrackedPosition::where([
            'symbol' => $data['symbol'], 'source' => $data['source'],
            'resolution' => $data['resolution'], 'status' => 'active',
        ])->exists();

        if ($exists) {
            return response()->json(['message' => 'You are already tracking this contract with the same feed and timeframe.'], 422);
        }

        $position = TrackedPosition::create([
            'symbol' => $data['symbol'],
            'side' => $side,
            'strike' => $strike,
            'expiry' => $expiry,
            'source' => $data['source'],
            'resolution' => $data['resolution'],
            'entry_price' => $data['entry_price'],
            'entry_at' => time(),
            'status' => 'active',
        ]);

        return response()->json($position, 201);
    }

    /** Stop tracking (you exited the trade yourself). */
    public function destroy(TrackedPosition $position)
    {
        if ($position->status === 'active') {
            $position->update(['status' => 'closed', 'exit_at' => time()]);
        }

        return response()->noContent();
    }
}