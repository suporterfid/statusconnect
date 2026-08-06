# StatusConnect i18n foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Consume GrandpaSSOn's `locale` claim on SSO login, set app locale per-request, expose/persist it via `/me` + `PATCH /me/preferences`, localize the handful of hardcoded backend messages.

**Architecture:** `GrandpaSsonIdentityProvider` extracts `locale` from the `/session/exchange` response; `GrandpaSsonLoginController` syncs it into `UserPreference` on every login; `SetLocaleFromUser` middleware sets `App::setLocale()` from the saved preference.

**Tech Stack:** Laravel 12, PHPUnit. Locale identifier convention: `en` / `pt_BR` (underscore, matching existing `lang/pt_BR/`).

## Global Constraints
- Use only `./scripts/stc.sh` for composer/npm/artisan/test commands.
- Supported locales: `en`, `pt_BR`.

---

### Task 1: GrandpaSSOn locale claim → `UserPreference` sync on login

**Files:**
- Modify: `app/Application/GrandpaSson/GrandpaSsonBrowserIdentity.php` (add `locale` property)
- Modify: `app/Application/GrandpaSson/GrandpaSsonIdentityProvider.php` (`exchange()`)
- Modify: `app/Http/Controllers/Auth/GrandpaSsonLoginController.php` (`callback()`)
- Test: extend `tests/Feature/GrandpaSson/BrowserLoginTest.php`

- [ ] Write failing test in `BrowserLoginTest`: fake `/session/exchange` response includes `'locale' => 'pt_BR'`; after callback, assert `UserPreference::query()->where('user_id', $user->id)->value('locale') === 'pt_BR'`.
- [ ] Write failing test: response omits `locale`; assert synced value is `'en'`.
- [ ] Write failing test: a second login for the same user with a different locale in the response updates the existing preference row (not a duplicate).
- [ ] Add `public readonly string $locale` to `GrandpaSsonBrowserIdentity`'s constructor.
- [ ] In `GrandpaSsonIdentityProvider::exchange()`, read `$locale = $response->json('locale')`, default to `'en'` when not a string or not in `['en', 'pt_BR']`; pass into the returned `GrandpaSsonBrowserIdentity`.
- [ ] In `GrandpaSsonLoginController::callback()`, after resolving `$user`, add:
  ```php
  \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->updateOrCreate(
      ['user_id' => $user->id],
      ['locale' => $identity->locale],
  );
  ```
- [ ] Run tests, verify pass.
- [ ] Commit: `feat(i18n): sync locale from GrandpaSSOn's session/exchange claim on login`

### Task 2: `SetLocaleFromUser` middleware

**Files:**
- Create: `app/Http/Middleware/SetLocaleFromUser.php`
- Modify: `bootstrap/app.php` (register alias)
- Modify: `routes/api.php` (attach to the authenticated route group, alongside `auth.api_or_sanctum` — check its exact group name/structure first; do NOT add to the global `api` middleware group, since that runs before route-scoped auth resolves the user)
- Test: `tests/Feature/SetLocaleFromUserTest.php` (new)

- [ ] Write failing test: authenticated user with `UserPreference.locale = 'pt_BR'` hitting `PATCH /me/preferences` with an invalid payload (e.g. non-string `timezone`) gets a Portuguese validation message (this test can only pass after Task 2 + Task 4's `lang/pt_BR/validation.php` both land — write it now, it'll stay red until Task 4).
- [ ] Implement middleware (same shape as TaskConnect's):
  ```php
  <?php

  namespace App\Http\Middleware;

  use App\Infrastructure\Persistence\Eloquent\User;
  use Closure;
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\App;
  use Symfony\Component\HttpFoundation\Response;

  class SetLocaleFromUser
  {
      public function handle(Request $request, Closure $next): Response
      {
          $user = $request->user();
          $locale = $user instanceof User
              ? ($user->preferences?->locale ?? config('app.locale'))
              : config('app.locale');
          App::setLocale($locale);

          return $next($request);
      }
  }
  ```
- [ ] Register alias `'locale.from_user' => \App\Http\Middleware\SetLocaleFromUser::class` in `bootstrap/app.php`.
- [ ] Find the `Route::middleware('auth.api_or_sanctum')->group(...)` call (or equivalent) in `routes/api.php` and add `'locale.from_user'` to that same array.
- [ ] Commit alongside Task 3/4 once the validation test can pass (or commit now with the test skipped/pending if the plan's task-by-task discipline requires a green run first — prefer reordering: do Task 4's `lang/` scaffolding before writing this test, or write a simpler locale-detection test here that doesn't depend on validation.php, e.g. asserting `app()->getLocale()` equals `'pt_BR'` mid-request via a trivial authenticated route).

### Task 3: `lang/` scaffolding (validation + messages)

**Files:**
- Create: `lang/en/validation.php` (`php artisan lang:publish`)
- Create: `lang/pt_BR/validation.php` (hand-translate)
- Create: `lang/en/messages.php`, `lang/pt_BR/messages.php`

- [ ] Run `./scripts/stc.sh artisan lang:publish`; fix ownership if root-owned.
- [ ] Hand-translate `lang/en/validation.php` into `lang/pt_BR/validation.php` (same keys; can copy the pt-BR translations already written for jotter/taskconnect's `lang/pt-BR/validation.php` — content is locale-name-agnostic, just place it under the `pt_BR` directory here).
- [ ] `lang/en/messages.php`:
  ```php
  <?php

  return [
      'idempotency_key_required' => 'A valid Idempotency-Key header is required.',
      'invalid_credentials' => 'These credentials do not match our records.',
      'invalid_broker_role_mapping' => 'Broker role mappings may only contain owner, admin, or member.',
  ];
  ```
- [ ] `lang/pt_BR/messages.php`:
  ```php
  <?php

  return [
      'idempotency_key_required' => 'Um cabeçalho Idempotency-Key válido é obrigatório.',
      'invalid_credentials' => 'Essas credenciais não correspondem aos nossos registros.',
      'invalid_broker_role_mapping' => 'Mapeamentos de papel do broker só podem ser owner, admin ou member.',
  ];
  ```
- [ ] Commit: `feat(i18n): scaffold lang/ with validation and messages catalogs`
- [ ] Now run the Task 2 middleware test (it depends on `lang/pt_BR/validation.php` existing) and verify it passes; commit Task 2's middleware + route wiring here if not already committed.

### Task 4: Localize the three hardcoded controller strings

**Files:**
- Modify: `app/Http/Controllers/Api/V1/IncidentController.php:116`
- Modify: `app/Http/Controllers/Api/V1/Auth/LoginController.php:29`
- Modify: `app/Http/Controllers/Api/V1/GrandpaSsonTenantMappingController.php:35`
- Test: extend each controller's existing feature test (find them first) to assert a `pt_BR`-preference actor gets the translated string; if a given endpoint is unauthenticated (no user, no locale to switch), just replace the literal with `__()` without a new locale-specific test — behavior is unchanged since app default is `'en'`.

- [ ] Replace `'A valid Idempotency-Key header is required.'` with `__('messages.idempotency_key_required')`.
- [ ] Replace `'These credentials do not match our records.'` with `__('messages.invalid_credentials')`.
- [ ] Replace `'Broker role mappings may only contain owner, admin, or member.'` with `__('messages.invalid_broker_role_mapping')`.
- [ ] Run the affected tests, verify pass.
- [ ] Commit: `feat(i18n): localize IncidentController, LoginController, and GrandpaSsonTenantMappingController messages`

### Task 5: `/me` returns preferences + `PATCH /me/preferences`

**Files:**
- Modify: `app/Http/Resources/UserResource.php` (add `preferences` key)
- Modify: `app/Http/Controllers/Api/V1/MeController.php` (`loadMissing('preferences')`)
- Create: `app/Http/Controllers/Api/V1/UserPreferencesController.php`
- Modify: `routes/api.php` (add `PATCH /me/preferences`, inside the same authenticated group as Task 2's middleware)
- Test: `tests/Feature/UserPreferencesTest.php` (new)

- [ ] Write failing test: `GET /me` for an authenticated user returns `data.preferences.locale`.
- [ ] Write failing test: `PATCH /me/preferences` with `{"locale": "pt_BR"}` returns 200 and persists it; with `{"locale": "fr"}` returns 422.
- [ ] Update `UserResource::toArray()`:
  ```php
  'preferences' => [
      'locale' => $this->whenLoaded('preferences', fn () => $this->preferences?->locale ?? 'en', 'en'),
      'timezone' => $this->whenLoaded('preferences', fn () => $this->preferences?->timezone ?? 'UTC', 'UTC'),
  ],
  ```
  (Adjust if `whenLoaded`'s default-value overload doesn't fit cleanly — the essential behavior is: return the preference's locale/timezone if loaded, sane defaults otherwise.)
- [ ] Update `MeController` to call `$user->loadMissing('preferences')` before building the resource.
- [ ] Create `UserPreferencesController::update()` mirroring TaskConnect's `UserPreferencesController` (validate `locale` ∈ `['en', 'pt_BR']`, `timezone` string+timezone rule, `firstOrCreate` the preference row, apply validated fields, save, return `UserResource`).
- [ ] Add the route: `Route::patch('/me/preferences', UserPreferencesController::class);` inside the authenticated group.
- [ ] Run tests, verify pass.
- [ ] Commit: `feat(i18n): expose and persist user locale preference via /me`

### Final verification
- [ ] `./scripts/stc.sh test` — full backend suite green.
- [ ] Invoke `superpowers:finishing-a-development-branch`.
