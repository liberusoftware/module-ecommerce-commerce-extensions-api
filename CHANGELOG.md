# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-31

The first release: an HTTP adapter over `liberusoftware/ecommerce-commerce-extensions`, holding no
business rules of its own.

### Added

- Nineteen endpoints across extensions, endpoints, event names, subscriptions, events and
  deliveries, under `api/commerce-extensions` and a configurable prefix, middleware stack and domain.
- Four abilities — `commerce-extensions:read`, `:manage`, `:raise` and `:deliver` — split so that a
  credential which raises an event cannot reconfigure where events go, and a worker which drains the
  queue registers nothing.
- One failure map in `Http\Failure`, covering all ten of the domain's concrete exceptions plus
  `ModelNotFoundException`, asserted exhaustively so a new exception type cannot arrive as a 500.
- One presenter in `Http\Present`, the only place a domain object becomes JSON.
- An OpenAPI 3.1 document whose parity with the route table, with the abilities the controllers
  enforce, and with the domain's three enumerations is a test in both directions.

### Decisions

- **The merchant is derived from the credential** and appears in no path, query string or body.
- **A signing secret is returned exactly once**, by `POST /endpoints` and
  `POST /endpoints/{endpoint}/secret-rotations`, and by nothing else. A duplicate endpoint is a 409
  rather than the endpoint that exists, because answering the retry would mean this surface had
  stored a one-time secret in order to hand it out twice.
- **No idempotency key.** Every write has a natural key the database enforces; a key a client holds
  is a key a client can change.
- **A reference belonging to another merchant and one nobody ever minted are one answer**, with one
  status and one body, and no custody is checked before the domain's own tenant-scoped lookup.
- **A delivery refusal is rendered at 200 with its reason**, because the domain records a refusal as
  a row rather than raising it. `no_transport_bound` is not a 503.
- **A `duration_ms` is published only when the transport completed.** The module stores `0` for a
  request that threw, and a zero substituted for an unknown is a measurement nobody took.
- **Every length is bounded before the domain writes it.** The domain catches every `QueryException`
  at an insert and rethrows it as "already registered", so an over-wide value would reach a caller as
  a 409. Bounding each field here makes it a 422 naming the field instead.
- **`EndpointUrlIsInvalid` carries a typed reason and this surface does not publish it.**
  `Destination::refusalFor()` reports "not https" for a wrong scheme, an unparseable URL and a
  hostless one alike, so the reason is sometimes wrong. Every refused URL gets one message that is
  always true.
- **The clock is read once per request**, in the base controller, and shared by all six domain calls
  that take an instant.

### Deliberately not shipped

- **No privacy endpoint.** The domain's `ExportSubjectRecordAcrossTenants` and
  `ForgetSubjectAcrossTenants` both span every merchant on the deployment; this surface holds one
  merchant's credential. Reported to the domain as a gap rather than filtered here.
- **No retention endpoint.** Pruning settled deliveries is a scheduled command the module already
  ships.
- **No delete of an extension or an endpoint**, only a dated retirement that can be reversed.
- **No manifest, install, update, uninstall, scope grant, rollback, health model, UI-extension
  registry or inbound signature verification**, because the domain publishes none of them.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-commerce-extensions-api/releases/tag/0.1.0
