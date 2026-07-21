<?php

namespace ClarionApp\LifeLogBackend\Controllers;

use Illuminate\Http\Request;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;

class HealthMetricController extends Controller
{
    /** The source recorded for a reading the user entered themselves. */
    private const MANUAL_SOURCE = 'manual';

    /** Page size when the caller opts into pagination without naming one. */
    private const DEFAULT_PER_PAGE = 100;

    /** Ceiling on per_page, so one request cannot ask for a whole backfill. */
    private const MAX_PER_PAGE = 500;

    /**
     * Display a listing of the user's health metrics.
     *
     * Two shapes, and which one you get is the caller's choice:
     *
     *   GET health-metric                 → a bare array of every row (unchanged)
     *   GET health-metric?page=1          → { data: [...], meta: {...} }
     *   GET health-metric?per_page=50     → likewise
     *
     * Pagination is opt-in rather than default for two reasons. The bare-array
     * shape is a frozen contract (ManualEntryBackwardCompatibilityTest), so an
     * existing client must keep receiving exactly what it received before. And
     * a caller that has not asked to be paginated must never be handed a
     * truncated list it would reasonably read as the complete one — silently
     * capping would trade a performance ceiling for a correctness bug.
     *
     * `source` filters either shape. A row whose source was never recorded —
     * possible on a node that has not run the provenance backfill — is treated
     * as manual, because that is what it is.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'source'   => ['sometimes', 'string', 'max:64'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer'],
        ]);

        $query = HealthMetric::where('user_id', Auth::id());

        if (array_key_exists('source', $validated)) {
            $this->scopeToSource($query, $validated['source']);
        }

        // recorded_at alone is not a total order — imported readings routinely
        // share a timestamp — so id breaks ties. Without it, rows drift between
        // pages and the caller sees one row twice and another not at all.
        $query->orderByDesc('recorded_at')->orderByDesc('id');

        if (! $this->wantsPagination($request)) {
            return response()->json($query->get());
        }

        $page = $query->paginate($this->perPage($request))->appends($request->query());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page'      => $page->currentPage(),
                'last_page'         => $page->lastPage(),
                'per_page'          => $page->perPage(),
                'total'             => $page->total(),
                // Deliberately computed over the user's whole set, not the
                // filtered one: this is the list of filters worth offering, and
                // it must not collapse to whatever is currently selected. An
                // empty array is also how a caller tells "you have no readings"
                // apart from "this filter matched nothing".
                'available_sources' => $this->availableSources(),
            ],
        ]);
    }

    /**
     * Restrict a query to one source, folding unrecorded provenance into manual.
     */
    private function scopeToSource($query, string $source): void
    {
        if ($source === self::MANUAL_SOURCE) {
            $query->where(function ($q) {
                $q->where('source', self::MANUAL_SOURCE)
                  ->orWhereNull('source')
                  ->orWhere('source', '');
            });

            return;
        }

        $query->where('source', $source);
    }

    /**
     * Whether the caller asked to be paginated.
     */
    private function wantsPagination(Request $request): bool
    {
        return $request->has('page') || $request->has('per_page');
    }

    /**
     * The page size to use, clamped to something a database can serve.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min($requested, self::MAX_PER_PAGE));
    }

    /**
     * The distinct sources present in this user's own measurements.
     *
     * @return list<string>
     */
    private function availableSources(): array
    {
        $sources = HealthMetric::where('user_id', Auth::id())
            ->distinct()
            ->pluck('source')
            ->map(fn ($source) => ($source === null || $source === '') ? self::MANUAL_SOURCE : $source)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $sources;
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
            'source' => 'sometimes|string|max:255',
            'unit' => 'sometimes|nullable|string|max:32',
        ]);

        $metric = new HealthMetric();
        $metric->user_id = Auth::id();
        $metric->type = $validatedData['type'];
        $metric->value = $validatedData['value'];
        $metric->recorded_at = $validatedData['recorded_at'];
        $metric->source = $validatedData['source'] ?? 'manual';
        if (array_key_exists('unit', $validatedData)) {
            $metric->unit = $validatedData['unit'];
        }
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
            'source' => 'sometimes|string|max:255',
            'unit' => 'sometimes|nullable|string|max:32',
        ]);

        $metric->type = $validatedData['type'];
        $metric->value = $validatedData['value'];
        $metric->recorded_at = $validatedData['recorded_at'];
        if (array_key_exists('source', $validatedData)) {
            $metric->source = $validatedData['source'];
        }
        if (array_key_exists('unit', $validatedData)) {
            $metric->unit = $validatedData['unit'];
        }
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
