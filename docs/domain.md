# What this surface publishes, and every decision behind it

This package is an HTTP adapter over `liberusoftware/ecommerce-commerce-extensions` and holds no
business rules. Every decision below is about the transport: what a status code means, what leaves in
a body, and which of the domain's shapes this surface refuses to collapse.

## 1. The merchant is the credential

Nineteen endpoints and not one takes a merchant identifier, in a path, a query string or a body. It
is read once, in `Http\Controllers\Controller`, off the attribute `commerce-extensions-api.actor.tenant_attribute`
names — `team_id` by default, because a package cannot know how a host resolves a merchant. A
caller-supplied merchant is how one storefront's credential would point another business's events at
a destination of its own, which is the host defect this module was extracted to end.

`CustodyTest` sends `tenant_id` and `team_id` in a raise body and watches them be ignored.

## 2. Four abilities, split by what a compromise of each would cost

| Ability | What it reaches |
| --- | --- |
| `commerce-extensions:read` | Extensions, endpoints, event names, the delivery log and its attempts |
| `commerce-extensions:manage` | Registering, retiring, reinstating, subscribing, issuing and rotating a secret |
| `commerce-extensions:raise` | Raising one event |
| `commerce-extensions:deliver` | The due list, and attempting one delivery |

The line that matters is between `manage` and `raise`. The thing that raises `order.paid` is the
application's own domain code, running in a request or a job; the thing that registers an endpoint is
an operator at a console. A single ability covering both would mean a credential embedded in an order
service could point every merchant event at a destination it chose. `deliver` is separate from
`raise` for the same reason one level down: a queue worker drains, it does not raise.

**Only `raise` may name a subject.** The cause of an event is outside this module, and so is the
person it concerns, so `subject_ref` arrives in that body. Every other ability is refused one by
`Scope::refuseSubjectUnder()`, which the base controller calls before it reads a body. An endpoint
that gained a `subject_ref` parameter tomorrow would fail rather than quietly widen.

## 3. There is no idempotency key, and there is not going to be one

Two reasons, and either would be enough.

**Every write already has a natural key the database enforces** — `(tenant, extension_ref)`,
`(tenant, extension, url_digest)`, `(tenant, name)`, `(tenant, endpoint, event_name)`,
`(tenant, endpoint, cause_ref)`. A key a client holds is a key a client can change, so it is strictly
weaker than the constraint already there.

**And two of these responses carry a one-time secret.** `POST endpoints` and
`POST endpoints/{endpoint}/secret-rotations` are the only places a signing secret exists outside the
module: both columns are encrypted at rest and hidden, and no query returns one. Serving a retry from
a stored response would mean this surface had persisted a secret in order to hand it out twice. That
is also why a duplicate endpoint is a **409** rather than the endpoint that already exists — the
domain throws `EndpointIsAlreadyRegistered` for exactly this reason, and an adapter that answered the
retry would have undone it.

A caller that loses either response rotates. There is no recovery and there should not be one.

## 4. What a status code means here

| Status | When |
| --- | --- |
| 200 | A read, a replay of a raise, a rotation, a delivery attempt — including a refused one |
| 201 | Something addressable was created, or at least one delivery was newly raised |
| 204 | A subscription was released |
| 401 | No authenticated actor |
| 403 | The credential carries no ability, cannot answer an ability question, or names no merchant |
| 404 | Every reference this credential may not have |
| 409 | A decision already made: a duplicate, a claimed cause, a settled delivery |
| 422 | Input the caller can correct, including a name nobody registered |

**There is no 503 and no 429.** The domain's one seam refuses *inside* an `AttemptReport` — an
unbound transport is a row with a reason — so an unbound seam is a 200 carrying `no_transport_bound`
rather than an error. And nothing here is transient: no rate limit of the module's own, no in-flight
claim to wait out, so no message anywhere invites a retry.

**There is no 403 for a reference belonging to somebody else.** Every action and query in the domain
is `where('tenant_id', …)` then `firstOrFail()`, so another merchant's row and a row nobody ever
created raise the same `ModelNotFoundException`. This surface answers both with the same status and
the same body, and checks no custody before the lookup: a 403 in front of it would publish which rows
exist. `CustodyTest` asserts the two bodies are identical on every write.

## 5. Raising: 201, 200 and 409 are three different facts

- At least one delivery newly raised → **201**.
- Every delivery came back `already_raised` → **200**. The cause reference is the natural key, so the
  same cause for the same subject is a replay of what exists. Nothing was created.
- The same cause for a **different** subject → **409** `cause_reference_claimed`. Never the first
  subject's row handed to this caller; that is wave 16's sharpest defect, one layer up.
A 409 from a raise does **not** mean nothing was written. The domain's fan-out is not transactional,
so a cause that collides on the third of five endpoints leaves the first two committed and owed. This
surface reports the domain's answer rather than inventing a partial one; the runbook says what to
check before retrying, and it is reported to the domain as a defect.

- Nobody subscribes → **200** with an empty list. That is a success. An event with no subscriber is
  the ordinary state of most event names, and rendering it as a failure would have every caller
  alerting on nothing.

## 6. Attempting: a refusal is a rendered fact, never an error

`POST deliveries/{delivery}/attempts` answers 200 for every report the domain returns, and the caller
branches on `outcome` and `refusal_reason`. The refusals are `no_transport_bound`,
`extension_retired`, `endpoint_retired`, `destination_not_https`, `destination_not_public`,
`window_closed` and `concurrent_attempt`. Each removes exactly the claim it controls and nothing
else: the reason is on the response and on a row, and — except for `concurrent_attempt`, where
another worker holds the sequence — no slot is consumed, so binding a transport later does not find
the delivery already exhausted.

Two nulls in that payload are not absences:

- **`delivery_outcome` is null while the delivery is still owed.** `delivery_settled` carries that
  distinction as a field so no caller has to infer it.
- **`attempt_id` is null only for `concurrent_attempt`**, which is the one refusal that writes no row.

### A host that names a transport class that does not exist gets a 500, on purpose

`Support\Seams::transport()` calls `App::make()` on the configured class name outside any try, and
`AttemptDelivery` does not catch it, so a misconfigured transport raises `BindingResolutionException`
rather than recording `no_transport_bound`. No attempt row is written and the delivery is untouched.

This surface lets it bubble. Mapping it to a 503, or to a synthesised refusal, would answer with a
refusal that **no row corroborates** — the delivery log, which is the thing this module exists to
keep, would say nothing happened while the API said it had refused. A 500 is the honest report of a
deployment defect, and it reaches whoever is on call instead of looking like an ordinary refusal in a
worker's metrics. `ActorTest` pins it. See the runbook for the symptom.

A **settled** delivery is the exception: `DeliveryIsAlreadySettled` is a 409. There is no verb that
reopens one, deliberately, and this surface does not invent it — the answer is to raise the event
again under a new cause reference.

On an attempt row, `excerpt` is paired with `transport_completed`. An excerpt with no
`response_status` is an **exception message and not a response body**: the request never finished.
The field is not called `response` for that reason.

## 7. What a listing does not publish

A delivery listing carries neither `payload` nor `subject_ref`. Both are on `GET deliveries/{delivery}`
only. The payload is opaque bytes a caller chose and may be anything — the host this module replaces
put a customer's email address in one — and a listing is the shape that gets logged, cached and
pasted into a ticket.

### Paging adds a tiebreaker the domain's ordering does not have

`ListExtensions` orders by name and `ListDeliveries` by the instant an event was raised, and neither
is a **total** order: two extensions may share a name, and one raise fans out to every subscribed
endpoint at the same instant. Paging over a non-total order silently repeats one row on page two and
drops another. Paging is this package's own concern, so the controllers add `orderBy('id')` on top of
the domain's ordering rather than replacing it. `ListingTest` proves the union of two pages is every
row exactly once. This is a domain gap reported rather than closed there.

`has_more` rather than a total. A `COUNT(*)` over the delivery log is a table scan nobody asked for,
and no caller here renders a page count.

## 8. Deliberately not shipped

**No privacy endpoint.** The domain publishes `ExportSubjectRecordAcrossTenants` and
`ForgetSubjectAcrossTenants`, and both span every merchant on the deployment — correctly, because a
person is a customer of whichever merchants they choose. This surface holds one merchant's
credential. Publishing the export would let merchant A read merchant B's stored payloads for a
subject; publishing the erasure would let merchant A settle and redact merchant B's owed deliveries.
Filtering the result to the credential's merchant is not available either: it would put the
definition of "everything about this person" in the transport, and it would make the erasure report a
count that is not the count. **This is a gap in the domain, not a decision of this package**: a
tenant-scoped `ExportSubjectRecord($tenantId, $subjectRef)` and `RedactSubject($tenantId, …)` — the
shape `module-ecommerce-loyalty` publishes — would bring both endpoints here unchanged.

**No retention endpoint.** Pruning settled deliveries is the host's policy and the module ships
`commerce-extensions:prune-deliveries` plus the schedule line for it. An HTTP verb that deletes a
security log is a worse way to reach a job that already has a runner.

**No delete of an extension or an endpoint.** Retirement is a dated fact on a sub-resource: `PUT` to
retire, `DELETE` to reinstate. Deleting either would take the record of what it received with it,
which is precisely the host defect the module ends.

**No manifest, install, update, uninstall, scope grant, rollback, health model or UI-extension
registry.** The domain publishes none of them, and a surface for something that does not exist is a
promise nothing keeps. The epic's words "install/update/uninstall" invite the mistake ADR 0011 in the
host repository was written to prevent: an extension here is a data record, and
`liberusoftware/module-manager` remains the only thing that decides which PHP boots.

**No inbound webhook verification.** This module sends. Verifying a provider's signature on the way
in belongs to whoever owns the provider.

## 9. Where this package takes a domain type

It imports the domain's actions, queries, `Data\` DTOs, `Enums\` and `Support\Cast`, and **no
`Models\` class** — `BoundaryAssertions::apiAdapterAvoidsDomainModels` greps `src/` for exactly that.
The read queries hand back Eloquent builders, so rows flow through this package anyway; they flow as
opaque handles read through `Model::getAttribute()`, which is the framework's API rather than the
domain's. `Http\Present` is the only place that happens.

`Support\Cast` is reused rather than reimplemented. It narrows an untyped configuration or input
value and holds no rule of the domain's.

## 10. The clock

The domain reads no clock: six of its entry points take a `CarbonImmutable`. This surface resolves
`Carbon::now()` **once per request**, in `Http\Controllers\Controller::now()`, and shares it.
`ClockTest` pins both halves — that the method returns the same instance twice, and that exactly one
file in `src/` names `Carbon::now()` at all. A request that read the clock twice could raise a
delivery whose window began before the attempt due at it.
