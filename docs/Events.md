# Events

The plugin fires four events on the global Cake `EventManager`. Host
apps subscribe to wire audit logging, identity setting, analytics, or
any other side effect.

## Events

| Event | Subject | Extra data | Fired from |
| --- | --- | --- | --- |
| `Passkeys.afterRegister` | `Passkeys\Event\PasskeyEvent` | none | `registerFinish` |
| `Passkeys.afterLogin` | `Passkeys\Event\PasskeyEvent` | none | `loginFinish` |
| `Passkeys.afterRename` | `Passkeys\Event\PasskeyEvent` | `['old' => $old, 'new' => $new]` | `rename` |
| `Passkeys.afterDelete` | `Passkeys\Event\PasskeyEvent` | none | `delete` |

## Event payload

```php
namespace Passkeys\Event;

class PasskeyEvent
{
    public function getPasskey(): \Passkeys\Model\Entity\Passkey;
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

    $em->on('Passkeys.afterRegister', function ($cakeEvent) {
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

    $em->on('Passkeys.afterLogin', function ($cakeEvent) {
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
  `Passkeys.mfa_satisfied` is set; identity hand-off is up to the host
  (typically the `Passkeys.afterLogin` subscriber, or the host's
  authentication middleware reading the configurable session key).
- It does not retry, rate-limit, or coalesce events. Each successful
  ceremony fires exactly one event.
