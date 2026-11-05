# Events

The plugin dispatches four events on the global event manager.

## Events

| Event | Extra data | Fired from |
| --- | --- | --- |
| `CakePasskeys.afterRegister` | none | `registerFinish` |
| `CakePasskeys.afterLogin` | none | `loginFinish` |
| `CakePasskeys.afterRename` | `['old' => $old, 'new' => $new]` | `rename` |
| `CakePasskeys.afterDelete` | none | `delete` |
| `CakePasskeys.beforeRegister` | `['user' => PasskeyUserInterface]`, no payload | `registerStart`, `registerFinish` |
| `CakePasskeys.beforeDelete` | `['user' => PasskeyUserInterface, 'passkey' => Passkey]`, no payload | `delete` |

A listener that stops `beforeRegister` or `beforeDelete` refuses the action with a 403. A string set as result becomes the error message.

An `afterLogin` listener can set an array as result; its keys are added to the JSON answer of `login/finish` (the plugin's own keys win). Use it to hand an API token to a client that does not run on cookies.

The subject of every event is the plugin's `PasskeysController`, so a listener can reach the request and the session through `$event->getSubject()->getRequest()`. The payload is a `CakePasskeys\Event\PasskeyEvent` under `$event->getData('event')`.

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
- With `cakephp/authentication` it persists the user as identity after a sign-in; without it, it only writes the user id to the configured session key. Anything else a login does in your app (timestamps, flags) belongs in an `afterLogin` listener.
- It does not decide who may register or delete a passkey beyond being signed in. Stop `beforeRegister` or `beforeDelete` for anything stricter, such as a recent sign-in.
- `getUserHandle()` returns the opaque handle the authenticator knows the user by, the same value for all four events. The database id is `getPasskey()->user_id`.
