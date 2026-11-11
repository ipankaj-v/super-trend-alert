<?php

namespace App\Http\Controllers;

use App\Models\SupertrendAlert;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index()
    {
        return view('alerts', [
            'alerts' => SupertrendAlert::latest()->limit(50)->get(),
        ]);
    }

    /** Polled by the dashboard; returns alerts newer than ?after=<id>. */
    public function feed(Request $request)
    {
        $after = (int) $request->query('after', 0);

        return SupertrendAlert::where('id', '>', $after)->orderBy('id')->limit(50)->get();
    }

    public function ack(SupertrendAlert $alert)
    {
        $alert->update(['acknowledged_at' => now()]);

        return response()->noContent();
    }
}
