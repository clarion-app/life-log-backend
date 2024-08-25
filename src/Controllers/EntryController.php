<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use Illuminate\Http\Request;
use ClarionApp\LifeLogBackend\Models\Entry;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class EntryController extends Controller
{
    /**
     * Display a listing of the user's entries.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $entries = Entry::where('user_id', Auth::id())->get();
        return response()->json($entries);
    }

    /**
     * Store a newly created entry in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'entry_date' => 'required|date',
        ]);

        $entry = new Entry();
        $entry->user_id = Auth::id();
        $entry->title = $validatedData['title'];
        $entry->content = $validatedData['content'];
        $entry->entry_date = $validatedData['entry_date'];
        //$entry->location_id = $validatedData['location_id'] ?? null;
        $entry->save();

        return response()->json($entry, 201);
    }

    /**
     * Display the specified entry.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $entry = Entry::where('user_id', Auth::id())->findOrFail($id);
        return response()->json($entry);
    }

    /**
     * Update the specified entry in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $entry = Entry::where('user_id', Auth::id())->findOrFail($id);

        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'entry_date' => 'required|date',
            'location_id' => 'nullable|uuid|exists:life_log_locations,id',
            'contacts' => 'nullable|array',
            'contacts.*' => 'uuid|exists:contacts,id'
        ]);

        $entry->title = $validatedData['title'];
        $entry->content = $validatedData['content'];
        $entry->entry_date = $validatedData['entry_date'];
        $entry->location_id = $validatedData['location_id'] ?? null;
        $entry->save();

        if (!empty($validatedData['contacts'])) {
            $entry->contacts()->sync($validatedData['contacts']);
        }

        return response()->json($entry);
    }

    /**
     * Remove the specified entry from storage.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $entry = Entry::where('user_id', Auth::id())->findOrFail($id);
        $entry->delete();

        return response()->json(null, 204);
    }
}
