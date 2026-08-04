# GrandpaSSOn Integration

StatusConnect supports GrandpaSSOn as an opt-in identity provider and machine-token authority. It remains disabled by default; native `sc_*` API keys and local authentication are unaffected by the flags.

## Broker prerequisites

The broker must first ship `status:read`, `status:write`, and `status:callback` from [grandpasson#116](https://github.com/suporterfid/grandpasson/issues/116). Create two different registrations; the browser RP client cannot introspect machine tokens, and the service client cannot perform browser login.

```bash
# Browser code exchange client: exact callback URL and confidential secret.
php cron/seed_oauth_client.php \
  --client-id=statusconnect \
  --name="StatusConnect" \
  --redirect-uri=https://status.example.com/auth/grandpasson/callback \
  --secret='<long-random>'

# Machine-token and introspection service client: audience is an immutable pin.
php cron/admin.php client:create-service "StatusConnect" \
  --scopes=status:read,status:write,status:callback \
  --aud=workspace/env_<statusconnect-environment-public-id>
```

Store the service-client secret when printed; the broker does not show it again.

## StatusConnect configuration

```dotenv
GRANDPASSON_INBOUND_ENABLED=false
GRANDPASSON_OUTBOUND_ENABLED=false
GRANDPASSON_BASE_URL=https://identity.example.com

GRANDPASSON_RP_CLIENT_ID=statusconnect
GRANDPASSON_RP_CLIENT_SECRET=<browser-client-secret>
GRANDPASSON_REDIRECT_URI=https://status.example.com/auth/grandpasson/callback

GRANDPASSON_CLIENT_ID=<service-client-id>
GRANDPASSON_CLIENT_SECRET=<service-client-secret>

GRANDPASSON_READ_SCOPE=status:read
GRANDPASSON_WRITE_SCOPE=status:write
GRANDPASSON_CALLBACK_SCOPE=status:callback
GRANDPASSON_INTROSPECTION_CACHE_SECONDS=30
```

Enable either flag only after the corresponding registration and local tenant mapping exist. URL-specific variables can override the paths derived from `GRANDPASSON_BASE_URL`.

## Browser login and tenant mappings

Begin login at `/auth/grandpasson/login/google`, `/microsoft`, or `/github`. StatusConnect records a one-use session state, then redeems the returned code immediately at `/session/exchange` with form-body confidential-client credentials.

A platform administrator creates a mapping through `POST /v1/platform/grandpasson/tenant-mappings` while authenticated locally. Supply the broker tenant id, local tenant public id, and explicit broker-role/group mappings. A login with no mapping or conflicting mapped role/group values is denied. Broker claims never grant platform-admin status. The local email/password login prerequisite is tracked in [StatusConnect #14](https://github.com/suporterfid/statusconnect/issues/14); do not enable browser login until it is released.

## Machine tokens

Send the opaque `gpat_live_…` token as `Authorization: Bearer …`. StatusConnect posts `client_id`, `client_secret`, and `token` in the introspection form body. Safe methods require `status:read`; mutations require `status:write`; both require an active token whose audience covers the current environment.

Introspection responses are cached under a SHA-256 token fingerprint for up to 30 seconds and never beyond `exp`. Revocation can therefore take up to the configured cache window to take effect. Tokens are never logged.

## Local Docker topology

GrandpaSSOn is not on the StatusConnect compose network. From the StatusConnect app container, use:

```dotenv
GRANDPASSON_BASE_URL=http://host.docker.internal:8080
```

The compose service includes `host.docker.internal:host-gateway`; do not configure an assumed `web` service hostname.
