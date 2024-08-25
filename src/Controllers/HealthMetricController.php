<?php

namespace ClarionApp\LifeLogBackend\Http\Controllers;

use Illuminate\Http\Request;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use Illuminate\Support\Facades\Auth;

class HealthMetricController extends Controller
{
    /**
     * Display a listing of the user's health metrics.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $metrics = HealthMetric::where('user_id', Auth::id())->get();
        return response()->json($metrics);
    }

    /**
     * Store a newly created health metric in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'type' => 'required|string|max:255',
            'value' => 'required|numeric',
            'recorded_at' => 'required|date',
        ]);

        $metric = new HealthMetric();
        $metric->user_id = Auth::id();
        $metric->type = $validatedData['type'];
        $metric->value = $validatedData['value'];
        $metric->recorded_at = $validatedData['recorded_at'];
        $metric->save();

        return response()->json($metric, 201);
    }

    /**
     * Display the specified health metric.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $metric = HealthMetric::where('user_id', Auth::id())->findOrFail($id);
        return response()->json($metric);
    }

    /**
     * Update the specified health metric in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $metric = HealthMetric::where('user_id', Auth::id())->findOrFail($id);

        $validatedData = $request->validate([
            'type' => 'required|string|max:255',
            'value' => 'required|numeric',
            'recorded_at' => 'required|date',
        ]);

        $metric->type = $validatedData['type'];
        $metric->value = $validatedData['value'];
        $metric->recorded_at = $validatedData['recorded_at'];
        $metric->save();

        return response()->json($metric);
    }

    /**
     * Remove the specified health metric from storage.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $metric = HealthMetric::where('user_id', Auth::id())->findOrFail($id);
        $metric->delete();

        return response()->json(null, 204);
    }
}
