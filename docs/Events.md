# Events

The plugin fires four events on the global Cake `EventManager`. Host
apps subscribe to wire audit logging, identity setting, analytics, or
any other side effect.

## Events

| Event | Subject | Extra data | Fired from |
| --- | --- | --- | --- |
| `CakePasskeys.afterRegister` | `CakePasskeys\Event\PasskeyEvent` | none | `registerFinish` |
| `CakePasskeys.afterLogin` | `CakePasskeys\Event\PasskeyEvent` | none | `loginFinish` |
| `CakePasskeys.afterRename` | `CakePasskeys\Event\PasskeyEvent` | `['old' => $old, 'new' => $new]` | `rename` |
| `CakePasskeys.afterDelete` | `CakePasskeys\Event\PasskeyEvent` | none | `delete` |

## Event payload

```php
namespace CakePasskeys\Event;

class PasskeyEvent
{
    public function getPasskey(): \CakePasskeys\Model\Entity\Passkey;
    public function getUserHandle(): string;            // hashed, opaque
    public function getData(): array;                   // event-specific extras
    public function getCredentialIdBase64Url(): string; // never the raw binary id
}
```

Credential IDs in payloads are **base64url-encoded**. Never include the
raw binary in logs.

## Subscription pattern

In `AppController::beforeFilter()`:

```php
public function beforeFilter(\Cake\Event\EventInterface $event): void
{
    parent::beforeFilter($event);
    $em = $this->getEventManager();

    $em->on('CakePasskeys.afterRegister', function ($cakeEvent) {
        $payload = $cakeEvent->getData('event');  // PasskeyEvent
        $this->fetchTable('AuditLogs')->writeLog(
            'passkey_registered',
            $payload->getPasskey()->user_id,
            [
                'passkey_id' => $payload->getPasskey()->id,
                'name' => $payload->getPasskey()->name,
                'credential_id' => $payload->getCredentialIdBase64Url(),
            ],
        );
    });

    $em->on('CakePasskeys.afterLogin', function ($cakeEvent) {
        $payload = $cakeEvent->getData('event');
        // Set identity, record login, etc.
    });

    // afterRename, afterDelete: same shape.
}
```

## Alternative: global subscriber

For larger codebases, register a dedicated subscriber class in
`Application::bootstrap()`:

```php
$em = \Cake\Event\EventManager::instance();
$em->on(new \App\Listener\PasskeysAuditSubscriber());
```

With `PasskeysAuditSubscriber implementing EventListenerInterface` and
the four event names in `implementedEvents()`.

## What the plugin does NOT do

- It does not write any audit row itself. All audit is host-controlled.
- It does not call `setIdentity()` after login. The session flag
  `CakePasskeys.mfa_satisfied` is set; identity hand-off is up to the host
  (typically the `CakePasskeys.afterLogin` subscriber, or the host's
  authentication middleware reading the configurable session key).
- It does not retry, rate-limit, or coalesce events. Each successful
  ceremony fires exactly one event.
