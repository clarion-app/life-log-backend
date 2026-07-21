# Google Health Integration — Administrator Setup

This document walks you through configuring Google Health as an external data
source. Follow these steps in order. You need a Google account with permission
to create projects in a Google Cloud organisation (or a personal Google
account for self-hosted use).

---

## 1. Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/) and sign in.
2. Click the project dropdown at the top, then **New Project**.
3. Name it (e.g., "Life Log Health Sync") and click **Create**.
4. Wait for the project to provision, then select it from the project dropdown.

## 2. Enable the Google Health API

1. In the left navigation, go to **APIs & Services → Library**.
2. Search for **Google Health API** and enable it.

## 3. Configure the OAuth Consent Screen

1. Go to **APIs & Services → OAuth consent screen**.
2. Select **External** user type (or **Internal** if you are in a GSuite
   organisation and all users share the same domain), then **Create**.
3. Fill in:
   - **App name**: "Life Log Health Sync" (or whatever you prefer)
   - **User support email**: your contact address
   - **Developer contact email**: your address
4. Click **Save and Continue**.
5. **Scopes** page: click **Save and Continue** (no scopes to add here — the
   app requests scopes at runtime).
6. **Test users** page: at this stage you can add Google accounts for testing.
   Click **Add Users**, enter your own Google account, then **Save and
   Continue**. You will remove these test users later (Step 6).
7. **Summary** page: click **Back to Dashboard**.

### Publishing status — set to "In production" (FR-020a)

**This is critical.** By default the consent screen is in **Testing** mode.
While in Testing mode, every user's authorization expires after **7 days** —
their refresh token becomes invalid and they must reconnect.

To avoid this:

1. On the OAuth consent screen dashboard, click **Publish app** in the
   **Publishing status** section.
2. Select **In production** and confirm.

**Verification is NOT required to publish.** You can set publishing status to
"In production" without submitting the app for Google's verification process.

After publishing, you will see an **"unverified app" interstitial** when users
go through the consent flow. This is **expected and not a misconfiguration**.
It reads something like:

> "This app is not verified. This app has not passed the Google verification
> process. Continue at your own risk."

The user clicks **Continue** and the flow proceeds normally.

### User cap on unverified projects (FR-020b)

An unverified project in production is capped at **100 users** for the
project's lifetime. This is not reset by republishing or changing the app name.

Because `googlehealth.*` scopes are classified as **restricted scopes**, lifting
this cap requires:
1. Google's verification review, **plus**
2. An annual paid third-party **CASA** (Certified Application Security
   Assessment) audit

**For self-hosted use, verification is unnecessary.** The 100-user cap is more
than sufficient for any single self-hosted instance, and the verification
process adds cost and delay without improving security — the security boundary
is your own infrastructure, not Google's review.

If you genuinely need more than 100 authorized users on one project, that is
the point where you reconsider whether a single self-hosted instance is the
right architecture.

## 4. Create OAuth 2.0 Credentials

1. Go to **APIs & Services → Credentials**.
2. Click **Create Credentials → OAuth client ID**.
3. Application type: **Web application**.
4. Name: "Life Log Web Client".
5. **Authorized redirect URIs**: click **Add URI** and enter the callback URL
   from your instance. The redirect URI must match the callback route exactly:

   ```
   https://your-domain.com/api/life-log/connected-accounts/callback
   ```

   Replace `your-domain.com` with your actual domain. This must be an exact
   match — the code asserts this (see `RedirectUriDocumentationTest`). Google
   will reject the redirect if the URI does not match exactly.

   > **Important**: Use `https://` — Google requires HTTPS for production
   > redirect URIs. For local development you may use `http://localhost` but
   > only in Testing mode.

6. Click **Create**.
7. A dialog shows your **Client ID** and **Client Secret**. Copy both — you
   need them in the next step. Close the dialog afterwards (you can always
   re-open Client ID; Client Secret can be regenerated if lost).

## 5. Enter Credentials in Life Log

1. In your Life Log instance, navigate to the **Service Credentials** page
   (or use the API directly at `POST /service-credentials`).
2. Store the Google Health credential:

   ```json
   {
     "external_service": "google-health",
     "client_id": "<your client id>",
     "client_secret": "<your client secret>",
     "redirect_uri": "https://your-domain.com/api/life-log/connected-accounts/callback"
   }
   ```

   The `redirect_uri` **must** match the value you entered in Step 4 exactly.

3. Verify the credential works:

   ```
   POST /service-credentials/google-health/verify
   ```

   A successful response confirms the client ID and secret are accepted by
   Google.

## 6. Scope Bundles and Data Types

The app requests exactly three OAuth scope bundles. Google presents these as
three checkboxes on the consent screen. The user can accept any combination:

| Bundle | What the user sees on consent | Data types provided |
|---|---|---|
| **Activity and Fitness** | Activity and fitness data | Steps, Heart Rate, Calories Burned, Workouts |
| **Health Metrics** | Health metrics and measurements | Weight |
| **Sleep** | Sleep data | Sleep sessions |

A user who declines one bundle still gets the others. The connection records
which bundles were granted and only fetches the types those bundles cover.
There is no per-type toggle — the bundle is the atomic grant unit.

## 7. Remove Test Users (After First Successful Connection)

Once you have verified the flow works end-to-end:

1. Go back to **OAuth consent screen → Test users**.
2. Remove all test user entries.

In **In production** mode, test users are not required — any Google account
can authorize the app. The test user list only matters in Testing mode.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "This app isn't verified" and user cannot proceed | Publishing status is still "Testing" | Set to "In production" (Step 3) |
| "Redirect URI mismatch" error from Google | `redirect_uri` in credentials differs from Google Console | Ensure both are byte-identical |
| Refresh token stops working after 7 days | App is still in Testing mode | Publish to production (Step 3) |
| "User count exceeded" error | 100 users authorized on unverified project | You have hit the FR-020b cap; see above |
| Connection fails with "credentials rejected" | Client ID or secret is wrong, or the project was deleted | Re-enter credentials at `/service-credentials` |

---

## API Version Monitoring

The Google Health API client pins a specific API version (`v1`). When Google
releases a new version:

1. Watch the [Google Health API release notes](https://developers.google.com/healthcare-api/releases)
   for version announcements.
2. A version bump requires **re-capturing test fixtures**. Existing fixtures
   are stamped with the API version and capture date they were produced
   against; a pin change invalidates them.
3. Update `src/Google/Api/ApiVersion.php` with the new version string and
   re-run the test suite. `ApiVersionPinTest` will fail until the new version
   appears in the client's constructed URIs.

This is an operational duty, not an automated process. A self-hosted node has
nothing to poll release notes with — it is a manual check before deploying
after a known Google API update.

---

## Refresh Token Validity — Manual Validation Note

> **Observation required before relying on this in production:** Hold a real
> refresh token for 8+ days against an unverified-**production** project and
> confirm it still works. Google documents the 7-day expiry against *testing*
> status only; restricted + unverified + production is exactly where an
> undocumented exception would hide.
>
> Record the observation date here once verified: `[DATE]`

---

*Document last reviewed: 2026-07-20*
