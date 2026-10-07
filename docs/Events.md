# Events

The plugin dispatches four events on the global event manager.

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

## Attaching listeners

The events are dispatched on the global event manager, from the plugin's own controller. That controller does not extend your `AppController`, so a listener attached in `AppController::beforeFilter()` never runs. Attach listeners where they exist for every request, such as `Application::bootstrap()`:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

EventManager::instance()->on('CakePasskeys.afterRegister', function (EventInterface $event): void {
    /** @var \CakePasskeys\Event\PasskeyEvent $payload */
    $payload = $event->getData('event');
    // $payload->getPasskey()->user_id
    // $payload->getPasskey()->name
    // $payload->getCredentialIdBase64Url()
});
```

For more than one or two events, use a listener class:

```php
EventManager::instance()->on(new \App\Listener\PasskeyListener());
```

with the event names in its `implementedEvents()`.

## What the plugin leaves to you

- It writes no audit record. Do that in a listener.
- It does not build your application's identity after a sign-in. It writes the user id to the configured session key and dispatches `afterLogin`.
- `getUserHandle()` returns the opaque handle the authenticator knows the user by, the same value for all four events. The database id is `getPasskey()->user_id`.
