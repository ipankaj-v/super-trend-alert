<?php

namespace App\Http\Controllers;

use App\Services\OptionChain;
use Illuminate\Http\Request;

class OptionChainController extends Controller
{
    public function show(Request $request, OptionChain $chain)
    {
        $expiry = (string) $request->query('expiry', '');
        if (! preg_match('/^\d{2}-\d{2}-\d{4}$/', $expiry)) {
            $expiry = OptionChain::defaultExpiry();
        }

        $window = min(40, max(3, (int) $request->query('window', 12)));

        try {
            return response()->json($chain->build($expiry, $window));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not load option chain: ' . $e->getMessage()], 502);
        }
    }
}