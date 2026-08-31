# Runbook

## Every delivery attempt answers `no_transport_bound`

**Symptom.** `POST /deliveries/{id}/attempts` returns 200 with
`{"outcome": "refused", "refusal_reason": "no_transport_bound"}`, and nothing is ever sent.

**Cause.** `commerce_extensions.transport` is unbound, which is how the module ships. A default
transport reporting "delivered" would settle deliveries nothing ever sent, so unbound is the honest
state.

**Fix.** Bind one — `docs/adoption.md` §4. Nothing is lost in the meantime: a refusal consumes no
retry slot, so every delivery raised while unbound is still owed and is picked up on the next sweep,
as long as its window has not closed.

## Attempting a delivery returns 500

**Symptom.** A 500 with `BindingResolutionException` or `ReflectionException` from
`Support\Seams::transport()`.

**Cause.** `commerce_extensions.transport` names a class that cannot be built — a typo, a class that
does not exist, or a constructor the container cannot satisfy. The domain resolves the seam outside
any try and does not catch it, so this is not recorded as a refusal: no attempt row is written and
the delivery is untouched.

**Why it is a 500 and not a refusal.** Mapping it would answer with a refusal that no row
corroborates. The delivery log — the thing this module exists to keep — would say nothing happened
while the API said it had refused. A deployment defect should reach whoever is on call rather than
disappear into a worker's ordinary-refusal metric.

**Fix.** Correct the configured class name, or bind the interface in a service provider. Nothing
needs replaying: the delivery was never touched.

## Somebody lost a signing secret

**Symptom.** A receiver rejects every signature, and nobody has the secret.

**Cause.** It was returned once, in the response to `POST /endpoints` or
`POST /endpoints/{endpoint}/secret-rotations`, and nothing reads one back: both columns are encrypted
at rest and hidden, and there is no query that returns one.

**Fix.** `POST /endpoints/{endpoint}/secret-rotations`. The previous secret stays live for the
configured overlap and every request is signed under both, so a receiver still holding an older one
keeps verifying while it picks the new one up. Do **not** delete and recreate the endpoint: the
delivery log is keyed to it.

If the old secret has leaked and cannot wait for the window,
`DELETE /endpoints/{endpoint}/previous-secret` closes the overlap immediately — every receiver still
on the old secret starts failing at that moment, which is the point.

## Adding an endpoint answers 409

**Symptom.** `POST /endpoints` returns `endpoint_already_registered`.

**Cause.** This extension already has an endpoint at that URL. The natural key is
`(merchant, extension, url)`, and the index is the arbiter.

**Fix.** If the intent was a retry after a lost response, rotate — the endpoint exists and its secret
cannot be reissued. If the intent was a second destination, use a different URL, or register a second
extension.

## Raising an event answers 409 `cause_reference_claimed`

**Symptom.** A raise that used to work now conflicts.

**Cause.** The cause reference is already held on that endpoint by a **different** subject.
`(merchant, endpoint, cause)` is unique, and the same cause for a different subject is refused rather
than answered with the first subject's delivery.

**Fix.** The cause reference must identify one occurrence. A caller that derives it from something
shared — a batch id, a date, a constant — will collide the moment two subjects are in the same batch.
Derive it from the occurrence: an order id, a refund id, an invoice number.

### A 409 here can leave deliveries behind

The fan-out is not wrapped in a transaction, so when a cause collides on the *third* of five
subscribed endpoints, the deliveries raised for the first two are already committed and will be sent.
The 409 reports a total failure that was partial, and the response names none of the rows that
survived.

**Check** `GET /deliveries?endpoint=…` for the cause reference after any 409 from a raise, before
retrying. Retrying the same cause and the same subject is safe — the survivors come back
`already_raised` — but retrying under a *corrected* cause reference raises a **second** set of
deliveries for the endpoints that succeeded the first time, and the receiver gets the event twice
under two references.

This is a defect in the domain module, reported rather than papered over here: a surface that
swallowed the 409 to report the partial set would be inventing a result the domain did not return.

## Raising an event returns an empty list

**Not a fault.** Nothing subscribes to that name for that merchant. The response is 200 and the list
is empty, which is the ordinary state of most registered names.

**Check** `GET /event-names` for the name and `GET /endpoints` for a live endpoint, then
`POST /endpoints/{id}/subscriptions`. A retired extension or a retired endpoint is skipped by the
fan-out, so reinstating one is `DELETE /extensions/{id}/retirement`.

## A delivery is stuck unsettled

**Symptom.** A delivery appears in `GET /deliveries/due` run after run.

**Look at** `GET /deliveries/{id}` and read the attempts. Three shapes:

- `outcome: refused` with a reason — the reason is the fault. `endpoint_retired` and
  `extension_retired` are configuration; `destination_not_public` means the URL's DNS now resolves
  into private space, checked again at the moment of sending because the answer at registration was
  only true of the DNS that existed then.
- `outcome: failed` with a `response_status` — the receiver rejected it. `excerpt` is what they said.
- `outcome: failed` with `transport_completed: false` — the request never finished, and `excerpt` is
  an exception message rather than a response body. No duration is published, because the module
  stores a zero there and a zero substituted for an unknown is not a measurement.

A delivery whose window closes is settled `abandoned` on the next attempt. There is no attempt cap
and no verb that reopens a settled delivery: raise the event again under a new cause reference.

## An attempt row sits at `outcome: pending`

**Symptom.** An attempt with `attempted_at` set and `settled_at` null.

**Cause.** A worker died between the write and the response. The row is written before the request on
purpose, so this is the evidence that something was attempted rather than a gap in the log.

**Do not** try to settle it: attempts are append-only and a settled one cannot be rewritten. The
delivery itself is still owed and the next sweep picks it up under the next sequence number.

## A caller gets 404 for a record they can see in the panel

**Cause.** The credential names a different merchant, or none. Every reference belonging to another
merchant answers exactly as one nobody ever minted, deliberately: a 403 would confirm the record
exists.

**Check** the actor's `actor.tenant_attribute` value. `actor_has_no_tenant` at 403 is the credential
carrying no merchant at all; a plain 404 is a credential carrying the wrong one.

## A caller gets 403 `insufficient_scope` on a route they expect to reach

**Cause.** One of three. The token carries no such ability; the actor has no real `tokenCan()` method
(`method_exists`, not `is_callable` — Eloquent's `__call` makes `is_callable` true for every model
there is); or the action publishes no ability at all, which is how an unrouted method is refused.

**Fix.** Reissue the token with the ability the endpoint needs. Reading, managing, raising and
delivering are four abilities on purpose.

## A subject-access request arrives

This surface publishes no export and no erasure. The domain's verbs span every merchant on the
deployment, and a merchant-scoped credential must not reach them. Answer from a panel or a console
process holding a platform-wide identity, calling
`Queries\ExportSubjectRecordAcrossTenants` and `Actions\ForgetSubjectAcrossTenants` directly.

Erasure redacts the subject reference and the stored payload and keeps every attempt, its status, its
timing and its outcome. A delivery still owed is settled `redacted`, because there is no payload left
to send.
