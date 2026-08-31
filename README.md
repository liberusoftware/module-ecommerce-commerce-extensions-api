# Commerce Extensions — HTTP API

An HTTP adapter over [`liberusoftware/ecommerce-commerce-extensions`](https://github.com/liberusoftware/module-ecommerce-commerce-extensions).

It presents that module and no other, and it holds **no business rules**: every decision is delegated
to the domain's published actions and queries. What this package owns is the transport — who is
asking, what a domain refusal means over HTTP, and what leaves in a body.

## What it owns

| | |
| --- | --- |
| `routes/api.php` | Nineteen endpoints, mounted under a configurable prefix |
| `src/Http/Scope.php` | Four abilities, and which of them may name a subject |
| `src/Http/Failure.php` | One table from domain exception to status, code and classification |
| `src/Http/Present.php` | The only place a domain object becomes JSON |
| `src/Http/Controllers/Controller.php` | The actor, the merchant, the clock, and the exception mapping |
| `resources/openapi/openapi.json` | An OpenAPI 3.1 document whose parity with the routes is a test |

## What it does not own

- **The events.** A caller hands in a name, a cause, a subject and already-serialised bytes. This
  package never builds a payload, never names an order and never touches money.
- **Delivery.** `commerce_extensions.transport` stays unbound; a host binds its own egress.
- **Which PHP boots.** An extension here is a data record, not a service provider.
  `liberusoftware/module-manager` remains the only registrar, and the epic's words
  "install/update/uninstall" invite exactly the mistake ADR 0011 was written to prevent.

## The one fact that shaped it

**A signing secret exists outside the module for exactly one response.** Both columns are encrypted
at rest and hidden, and no query returns one, so `POST /endpoints` and
`POST /endpoints/{endpoint}/secret-rotations` are the only places it can ever be published. A surface
that failed to publish it there would have destroyed it, and the only recovery would be another
rotation.

That is also why there is **no idempotency key** and why a duplicate endpoint is a `409` rather than
the endpoint that already exists: serving a retry would mean this package had stored a one-time secret
in order to hand it out twice.

## Abilities

| Ability | Reaches |
| --- | --- |
| `commerce-extensions:read` | Extensions, endpoints, event names, the delivery log and its attempts |
| `commerce-extensions:manage` | Registering, retiring, subscribing, issuing and rotating a secret |
| `commerce-extensions:raise` | Raising one event — the only ability that may name a subject |
| `commerce-extensions:deliver` | The due list, and attempting one delivery |

The line that matters runs between `manage` and `raise`: a credential embedded in an order service
must not be able to point every merchant event at a destination of its own.

## What it publishes

| | |
| --- | --- |
| `GET/POST /extensions`, `PUT/DELETE /extensions/{id}/retirement` | Registering and retiring a third party |
| `GET/POST /endpoints`, `PUT/DELETE /endpoints/{id}/retirement` | Where it receives |
| `POST /endpoints/{id}/secret-rotations`, `DELETE /endpoints/{id}/previous-secret` | The secret, and closing a rotation overlap |
| `POST /endpoints/{id}/subscriptions`, `DELETE /endpoints/{id}/subscriptions/{name}` | What it receives |
| `GET/POST /event-names` | What may be subscribed to at all |
| `POST /events` | The fan-out |
| `GET /deliveries`, `GET /deliveries/{id}`, `GET /deliveries/due`, `POST /deliveries/{id}/attempts` | The log, and the one verb that acts on it |

There is **no delete anywhere**: retirement is a dated fact on a sub-resource, because deleting an
extension or an endpoint would take the record of what it received with it.

There is **no privacy endpoint and no retention endpoint**, and both absences are decisions —
`docs/domain.md` §8 says why, and what would change either.

## Installing

```
composer require liberusoftware/ecommerce-commerce-extensions-api
```

Nothing boots on install: `extra.laravel.providers` is absent on purpose and the host's module
manager registers the provider only when the module is named in `MODULES_ENABLED`. See
`docs/adoption.md`.

## Documentation

| | |
| --- | --- |
| `docs/domain.md` | What this surface publishes and every decision behind it |
| `docs/adoption.md` | What a host binds, issues and schedules |
| `docs/runbook.md` | What breaks, what the symptom looks like, what to do |
