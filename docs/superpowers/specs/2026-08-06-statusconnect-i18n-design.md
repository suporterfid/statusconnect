# StatusConnect i18n foundation (design)

**Goal:** consume the `locale` claim GrandpaSSOn already returns from `/session/exchange`, sync it onto the local user on every SSO login, set the app locale per-request, and localize the handful of backend-generated messages. No operator SPA exists yet, so this is backend-only for now.

## Context

StatusConnect authenticates human operators via GrandpaSSOn's browser OAuth code flow (`GrandpaSsonLoginController` → `GrandpaSsonIdentityProvider::exchange()` → `POST /session/exchange`), same shape as jotter's SSO integration. GrandpaSSOn's `SessionClaimsResolver` (see grandpasson's locale-foundation branch) already returns a top-level `locale` field alongside `subject`/`tenant`/`groups`/`scopes` — `GrandpaSsonIdentityProvider::exchange()` and `GrandpaSsonBrowserIdentity` just don't read it yet.

`user_preferences.locale` (default `'en'`) already exists (`UserPreference` model, ported from TaskConnect). This repo uses `pt_BR` (underscore) as its locale identifier — matching `lang/pt_BR/` and `StatusPageCacheWriter`'s `in_array($page->locale, ['en', 'pt_BR'])` check — not `pt-BR`; stay consistent with that throughout.

There is no `MeController`-adjacent preferences-write endpoint yet, no `SetLocaleFromUser`-equivalent middleware, and `UserResource` doesn't expose preferences at all.

## Design

1. **`GrandpaSsonBrowserIdentity` gains a `locale` property**; `GrandpaSsonIdentityProvider::exchange()` reads `$response->json('locale')`, defaulting to `'en'` when absent or not one of `['en', 'pt_BR']`.
2. **`GrandpaSsonLoginController::callback()`** syncs locale into `UserPreference` via `updateOrCreate(['user_id' => $user->id], ['locale' => $identity->locale])` on every successful login (sync-on-every-login, matching jotter's read-time-consistency choice — GrandpaSSOn is the source of truth for SSO users).
3. **`SetLocaleFromUser` middleware** (same shape as TaskConnect's) reads `$request->user()?->preferences?->locale` and calls `App::setLocale()`. Registered on the authenticated route group in `routes/api.php` (next to `auth.api_or_sanctum`), not the global `api` middleware group — that group's middleware runs before route-scoped auth resolves the user.
4. **`UserResource`** gains a `preferences` key (`{ locale, timezone }`), loading the relation if missing — mirrors TaskConnect's shape so the eventual operator SPA can reuse the same contract.
5. **`PATCH /me/preferences`** — new `UserPreferencesController`, validates `locale` ∈ `['en', 'pt_BR']` and `timezone` (valid timezone string), `firstOrCreate`s the preference row, returns the updated `UserResource`.
6. **`lang/en/validation.php` / `lang/pt_BR/validation.php`** — publish + hand-translate, so the new endpoint's validation errors localize.
7. **`lang/en/messages.php` / `lang/pt_BR/messages.php`** — the three hardcoded controller strings (`LoginController`, `IncidentController`, `GrandpaSsonTenantMappingController`).

## Out of scope

- `app/Domain/Outbound/UrlValidator.php` / `HeaderPolicy.php` messages — operator-facing technical validation for monitor configuration, not conversational UI copy; leave in English for now.
- No Vue SPA exists yet, so no frontend component work.
- No changes to GrandpaSSOn itself (the `locale` claim already exists on its locale-foundation branch).

## Testing

- Unit/feature: `GrandpaSsonIdentityProvider::exchange()` extracts `locale` from a faked `/session/exchange` response; defaults to `'en'` when absent.
- Feature: `BrowserLoginTest`-style callback test asserting `UserPreference.locale` is synced from the broker response, and re-synced (updated) on a second login with a different locale.
- Feature: authenticated request with `pt_BR` preference gets validation errors in Portuguese; without a saved preference, defaults to English.
- Feature: `PATCH /me/preferences` happy path + invalid-locale rejection.
- Feature: the three localized controller strings render in the acting user's locale.
