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
   https://your-domain.com/clarion-app/life-log/connected-services/callback/{service}
   ```

   Replace `your-domain.com` with your actual domain and `{service}` with the
   service slug (e.g., `google-health`). This must be an exact match — the code
   asserts this (see `RedirectUriDocumentationTest`). Google will reject the
   redirect if the URI does not match exactly.

   > **Note**: If you previously configured a redirect URI from older
   > documentation, you need to update it in the Google Cloud Console to use
   > the path above. You can also re-save the correct value through the
   > Wearable Services area — the mismatch warning will surface if the stored
   > `redirect_uri` differs from the derived frontend callback path.

   > **Important**: Use `https://` — Google requires HTTPS for every redirect
   > URI except the loopback host, where `http://localhost:PORT/...` is
   > accepted. That exception is about the host, not about publishing status:
   > it works in Testing and in production alike.

   > **Google will not accept an IP address.** See
   > [Reaching a node that has no public hostname](#reaching-a-node-that-has-no-public-hostname)
   > below if your instance is served from a LAN address such as
   > `https://192.168.1.50:9000`.

6. Click **Create**.
7. A dialog shows your **Client ID** and **Client Secret**. Copy both — you
   need them in the next step. Close the dialog afterwards (you can always
   re-open Client ID; Client Secret can be regenerated if lost).

### Reaching a node that has no public hostname

Google validates the redirect URI **as a string**, before anything is ever
requested. It rejects:

- any bare IP address, private or public — `https://192.168.199.134:9000/...`
  fails with *"must end with a public top-level domain"* and *"must use a
  domain that is a valid top private domain"*
- mDNS names such as `https://nodename.local/...`
- any hostname without a public TLD

There is no console setting that relaxes this. A self-hosted node on a LAN
address therefore needs a hostname, and one of these four options:

| Option | Register with Google | Notes |
|---|---|---|
| **Loopback** | `http://localhost:9000/clarion-app/life-log/connected-services/callback/google-health` | Simplest, but only usable from a browser **on the node itself** — see the invariant below. |
| **Wildcard DNS** (`sslip.io`, `nip.io`) | `https://192-168-199-134.sslip.io:9000/...` | Public TLD, so Google accepts it; resolves to the LAN IP, so the browser reaches your node. No DNS to run. A self-signed certificate still produces a browser warning — Google never fetches the URL, so it does not care. |
| **A domain you control** | `https://lifelog.example.com/...` | Point an A record — or your LAN's DNS, or a `hosts` entry — at the node. Google validates the string, not reachability. |
| **A tunnel** (Cloudflare Tunnel, ngrok) | the tunnel's public HTTPS hostname | Gives you a real certificate too. Adds a dependency outside your network. |

**The invariant that makes any of them work**: the interface derives the
registration address from `window.location.origin`, so you must browse to the
node at **exactly** the origin you registered — same scheme, same host, same
port. Registering `https://192-168-199-134.sslip.io:9000` and then opening the
app at `https://192.168.199.134:9000` produces a redirect-URI mismatch, because
the address the connection returns to is built from the address you arrived on.

The **Wearable Services** area surfaces this: if the stored `redirect_uri`
differs from the address it derives for the origin you are currently on, it
shows a mismatch warning and offers to save the current value. Treat that
warning as the authoritative signal that the two have diverged.

## 5. Enter Credentials in Life Log

1. In your Life Log instance, navigate to the **Service Credentials** page
   (or use the API directly at `POST /service-credentials`).
2. Store the Google Health credential:

   ```json
   {
     "external_service": "google-health",
     "client_id": "<your client id>",
     "client_secret": "<your client secret>",
     "redirect_uri": "https://your-domain.com/clarion-app/life-log/connected-services/callback/google-health"
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
| Console refuses the URI: "must end with a public top-level domain" / "must use a domain that is a valid top private domain" | The redirect URI is a bare IP address, a `.local` name, or a host with no public TLD | Give the node a hostname — see [Reaching a node that has no public hostname](#reaching-a-node-that-has-no-public-hostname) |
| "Redirect URI mismatch" error from Google | `redirect_uri` in credentials differs from Google Console | Ensure both are byte-identical |
| Mismatch persists after registering a hostname | You registered one origin but browse the app at another | The callback is derived from `window.location.origin`; open the app at the exact origin you registered |
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
