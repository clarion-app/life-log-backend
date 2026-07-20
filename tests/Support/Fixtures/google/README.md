# Google API Fixtures

## Stamping Rule

Every fixture file in this directory must carry:

1. **`ApiVersion::VERSION`** — the pinned Google Health API version string the fixture was produced against.
2. **Capture date** — the ISO date when the fixture was captured from a live response.

A pin bump (changing `ApiVersion::VERSION`) **requires re-capture** of all affected fixtures. The drift test will fail if a fixture references a stale version.

## Format

Fixtures are JSON files named after the endpoint and scenario they represent:
- `read-measurements-steps-first-page.json`
- `read-measurements-steps-empty.json`
- `read-spans-workouts-single.json`

Each fixture contains the full response body as returned by the Google Health API.

## Capturing

To capture a new fixture:
1. Make a live request to the Google Health API endpoint.
2. Save the response body as a JSON file.
3. Add a comment at the top with the API version and capture date.
4. Verify the test suite passes with the new fixture.
