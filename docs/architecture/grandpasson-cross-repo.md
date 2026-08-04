# GrandpaSSOn Cross-Repository Contract

StatusConnect uses GrandpaSSOn only when its feature flags are enabled. The broker owns scope vocabulary and fixed service-client audiences; StatusConnect owns local tenant mapping and authorization enforcement.

| Scope | StatusConnect use | Broker status |
| --- | --- | --- |
| `status:read` | Machine API safe/read methods | Available in [grandpasson#117](https://github.com/suporterfid/grandpasson/pull/117) |
| `status:write` | Machine API mutation methods | Available in [grandpasson#117](https://github.com/suporterfid/grandpasson/pull/117) |
| `status:callback` | Reserved for StatusConnect-originated signed callbacks | Available in [grandpasson#117](https://github.com/suporterfid/grandpasson/pull/117) |

## Audience contract

Create a GrandpaSSOn service client with an immutable audience pin for the StatusConnect environment public id:

```text
workspace/env_<statusconnect-environment-public-id>
```

StatusConnect accepts either that documented form or the raw `env_<…>` value returned by older/operator-created broker clients. It does not use an introspection `tenant` claim because the broker does not populate it for machine tokens.

## Local tenancy contract

A StatusConnect platform administrator must create the local broker-tenant mapping. Browser login never auto-creates a local tenant. The mapping gives broker `owner`/`admin`/`member` and selected opaque groups their local `owner`/`admin`/`viewer` roles. Unmapped groups do not change access; no resolved mapping or any conflicting resolved values denies the login.

## Release dependency

Flags stay off by default and fake-backed tests cover the seam. A live broker verification requires a service client created with the three available scopes and a pinned audience, plus an explicit local tenant mapping.
