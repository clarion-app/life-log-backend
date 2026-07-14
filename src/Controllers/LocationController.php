<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use Illuminate\Http\Request;
use ClarionApp\LifeLogBackend\Models\Location;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class LocationController extends Controller
{
    /**
     * Display a listing of the user's locations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $locations = Location::where('user_id', Auth::id())->with('contacts')->get();
        return response()->json($locations);
    }

    /**
     * Store a newly created location in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'description' => 'nullable|string|max:255',
            'visited_at' => 'nullable|date',
            'contacts' => 'nullable|array',
            'contacts.*' => 'uuid|exists:contacts,id'
        ]);

        $location = new Location();
        $location->user_id = Auth::id();
        $location->latitude = $validatedData['latitude'];
        $location->longitude = $validatedData['longitude'];
        $location->description = $validatedData['description'] ?? null;
        $location->visited_at = $validatedData['visited_at'] ?? null;
        $location->save();

        if (array_key_exists('contacts', $validatedData)) {
            $location->contacts()->sync($validatedData['contacts']);
        }

        return response()->json($location->load('contacts'), 201);
    }

    /**
     * Display the specified location.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $location = Location::where('user_id', Auth::id())->with('contacts')->findOrFail($id);
        return response()->json($location);
    }

    /**
     * Update the specified location in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $location = Location::where('user_id', Auth::id())->findOrFail($id);

        $validatedData = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'description' => 'nullable|string|max:255',
            'visited_at' => 'nullable|date',
            'contacts' => 'nullable|array',
            'contacts.*' => 'uuid|exists:contacts,id'
        ]);

        $location->latitude = $validatedData['latitude'];
        $location->longitude = $validatedData['longitude'];
        $location->description = $validatedData['description'] ?? null;
        $location->visited_at = $validatedData['visited_at'] ?? null;
        $location->save();

        if (array_key_exists('contacts', $validatedData)) {
            $location->contacts()->sync($validatedData['contacts']);
        }

        return response()->json($location->load('contacts'));
    }

    /**
     * Remove the specified location from storage.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $location = Location::where('user_id', Auth::id())->findOrFail($id);
        $location->delete();

        return response()->json(null, 204);
    }
}
