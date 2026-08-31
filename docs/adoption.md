# Adopting this package

## 1. Require it

```
composer require liberusoftware/ecommerce-commerce-extensions-api
```

The domain package is not on Packagist yet, so a host adds the VCS repository this package's own
`composer.json` carries:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-commerce-extensions" }
    ]
}
```

Nothing boots on install. `extra.laravel.providers` is absent on purpose: the host's module manager
registers `CommerceExtensionsApiServiceProvider` only when `ecommerce-commerce-extensions-api` is
named in `MODULES_ENABLED`.

## 2. Publish and set the configuration

```
php artisan vendor:publish --tag=commerce-extensions-api-config
```

| Key | What it decides |
| --- | --- |
| `route.prefix` | Where the surface mounts. `api/commerce-extensions` by default |
| `route.middleware` | **`[]` by default, and never null.** An empty stack is a host that has not opted in; a null stack is Laravel substituting one |
| `route.domain` | A dedicated host name, if the API is served from one |
| `actor.tenant_attribute` | Which attribute on the authenticated actor holds the merchant. `team_id` by default |
| `listing.default_per_page`, `listing.max_per_page` | Page size and the ceiling a caller may ask for |
| `due.default_limit`, `due.max_limit` | How many due deliveries one worker call returns |
| `payload.max_bytes` | The largest payload this deployment stores. Raise it only if the delivery table's `payload` column is wider than `text` |

**Add an authentication middleware.** Every endpoint refuses an unauthenticated caller in the
controller regardless, so the middleware decides how the actor arrives, not whether one is required:

```php
'middleware' => ['auth:sanctum'],
```

## 3. Issue credentials

The actor must satisfy two things, and both are checked before any domain call:

1. It answers `tokenCan(string $ability): bool` — a real method, not `__call`. A Sanctum personal
   access token's owner does.
2. It carries the merchant on `actor.tenant_attribute`.

Issue the narrowest ability that does the job:

```php
$user->createToken('storefront-events', ['commerce-extensions:raise']);
$user->createToken('webhook-worker', ['commerce-extensions:deliver']);
$user->createToken('operator-console', ['commerce-extensions:read', 'commerce-extensions:manage']);
```

**Do not issue one token carrying all four.** A credential that both raises events and manages
endpoints can point every merchant event at a destination of its own, which is the whole reason the
abilities are split.

## 4. Bind a transport

The domain ships `commerce_extensions.transport` unbound, and this package does not bind it. Until a
host does, every delivery attempt answers `200` with `refusal_reason: no_transport_bound` — the
delivery is still owed and no retry slot is consumed, so binding one later picks up everything that
accumulated.

```php
// config/commerce_extensions.php
'transport' => App\Webhooks\HttpTransport::class,
```

The class must implement `Liberu\Ecommerce\CommerceExtensions\Contracts\DeliveryTransport` and must be
resolvable from the container. **A class name that cannot be built raises rather than refusing**, and
this surface answers 500 rather than dressing a deployment defect as an ordinary refusal — see
`docs/runbook.md`.

## 5. Schedule the module's own commands

This package schedules nothing and publishes no retention endpoint. The domain ships the commands;
the host schedules them. Add both, per merchant:

```php
// routes/console.php
Schedule::command('commerce-extensions:deliver-due', [$tenantId])->everyMinute()->withoutOverlapping();
Schedule::command('commerce-extensions:prune-deliveries', [$tenantId, '--days=90'])->dailyAt('03:10');
```

A host that would rather drive delivery over HTTP calls `GET /deliveries/due` and then
`POST /deliveries/{id}/attempts` per row from its own worker; the two are equivalent and the second
is what a deployment with no console access uses.

The host this module replaces wrote `webhooks:retry-failed` and never scheduled it. The command
existed, was tested, and never ran.

## 6. What the host deletes, and why each is not adopted

| Host artefact | Why it is not adopted |
| --- | --- |
| `app/Models/WebhookEndpoint.php` | No tenant column and no scoping trait. The module's `Endpoint` is the replacement |
| `app/Jobs/DispatchOutboundWebhook.php` | Read every endpoint on the deployment and filtered in PHP. `RaiseEvent` is one indexed query scoped to one merchant |
| `app/Jobs/SendWebhookDelivery.php` | Built an order-shaped payload inside the delivery job and cast money through `(float)`. This module is handed already-serialised bytes and never touches money |
| `app/Console/Commands/RetryFailedWebhooks.php` | Re-dispatched every failure from attempt one on every run. The schedule now lives on the delivery row |
| `app/Http/Controllers/Api/WebhookEndpointController.php` | Listed every merchant's endpoints to any user holding `admin`. Replaced by this package, where the merchant is the credential |
| `app/Models/Module.php` | An orphan: ADR 0011 dropped the database-backed module system and the model survived the deletion. It is not this module's to resurrect |
| `StripeWebhookController`, `PaypalWebhookController` | **Kept.** They verify a provider's signature on the way *in*. This module only sends |

## 7. What would bring the two missing endpoints here

`POST /subject-records` and `POST /erasures` are not published because the domain's only export and
erasure verbs span every merchant on the deployment. A tenant-scoped pair —
`ExportSubjectRecord($tenantId, $subjectRef)` and `RedactSubject($tenantId, $subjectRef, $at)`, the
shape `module-ecommerce-loyalty` publishes — would bring both here unchanged, under a fifth ability.
Until then a host answers a subject-access request for this module from a panel or a console command
holding a platform-wide identity, which is what the cross-tenant verbs are for.
