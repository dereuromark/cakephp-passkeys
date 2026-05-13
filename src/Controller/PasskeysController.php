<?php

declare(strict_types=1);

namespace CakePasskeys\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Contract\RateLimiterInterface;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Model\Entity\Passkey;
use CakePasskeys\Service\AaguidLabelResolver;
use CakePasskeys\Service\ChallengeStore;
use CakePasskeys\Service\NullRateLimiter;
use CakePasskeys\Service\UserResolver;
use CakePasskeys\Service\WebAuthnException;
use CakePasskeys\Service\WebAuthnService;
use DateTimeImmutable;
use Throwable;
use function Cake\I18n\__d;

/**
 * WebAuthn / passkey ceremony controller.
 *
 * Eight JSON actions:
 *  - registerStart POST /passkeys/register/start (auth required)
 *  - registerFinish POST /passkeys/register/finish (auth required)
 *  - loginStart POST /passkeys/login/start (anonymous)
 *  - loginFinish POST /passkeys/login/finish (anonymous)
 *  - reauthStart POST /passkeys/reauth/start (auth required)
 *  - reauthFinish POST /passkeys/reauth/finish (auth required)
 *  - rename POST /passkeys/rename/{id} (auth required, owner-only)
 *  - delete DELETE /passkeys/delete/{id} (auth required, owner-only)
 *
 * The controller intentionally does NOT skip CSRF middleware itself — that
 * is the host application's responsibility via `skipCheckCallback` on its
 * own CSRF middleware (documented in the README). The WebAuthn challenge
 * nonce is the anti-replay guard; FormProtection's signed-fields check is
 * too tight for JSON POSTs and is therefore disabled here.
 *
 * For v1, `loginFinish()` writes a minimum-viable session payload
 * (`Auth.id` + the configured MFA-satisfied flag) so the host can pick up
 * the freshly-logged-in user from its own authentication pipeline (or
 * from an `afterLogin` event subscriber).
 */
class PasskeysController extends Controller
{
    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        if ($this->components()->has('FormProtection')) {
            $this->components()->unload('FormProtection');
        }
    }

    /**
     * Enforces the `CakePasskeys.enabled` master switch on every endpoint.
     * When disabled, the entire controller surface 404s — the plugin's
     * UI cells already hide themselves; this closes the API edge so a
     * disabled host cannot leak ceremony surface or be probed via the
     * passkey routes.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event
     *
     * @throws \Cake\Http\Exception\NotFoundException
     *
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        if (!Configure::read('CakePasskeys.enabled')) {
            throw new NotFoundException();
        }
    }

    /**
     * @return \Cake\Http\Response
     */
    public function registerStart(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->throttle('passkeys.register.' . $user->getUserId(), 10, 60);

        return $this->json($this->webauthn()->startRegistration($user));
    }

    /**
     * @return \Cake\Http\Response
     */
    public function registerFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $body = (array)$this->getRequest()->getParsedBody();
        try {
            $passkey = $this->webauthn()->finishRegistration(
                $user,
                $body,
                trim((string)($body['name'] ?? '')),
                isset($body['emoji']) ? (string)$body['emoji'] : null,
            );
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }
        $this->fire('afterRegister', $passkey, $user->getPasskeyUserHandle());

        return $this->json(['passkey' => $this->serializePasskey($passkey)]);
    }

    /**
     * @return \Cake\Http\Response
     */
    public function loginStart(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->throttle('passkeys.login.' . $this->getRequest()->clientIp(), 20, 60);
        // Accept the hint from BOTH the query string (`?email=...` — handy
        // for prefilled magic links) and the JSON body (`{emailHint: ...}` —
        // what the bundled JS client sends). Body wins when both are set.
        $body = (array)$this->getRequest()->getParsedBody();
        $bodyHint = trim((string)($body['emailHint'] ?? ''));
        $queryHint = trim((string)$this->getRequest()->getQuery('email', ''));
        $hint = $bodyHint !== '' ? $bodyHint : $queryHint;

        return $this->json($this->webauthn()->startLogin($hint !== '' ? $hint : null));
    }

    /**
     * @return \Cake\Http\Response
     */
    public function loginFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $body = (array)$this->getRequest()->getParsedBody();
        try {
            $passkey = $this->webauthn()->finishLogin($body);
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }
        $session = $this->getRequest()->getSession();
        // Defeat session-fixation: rotate the session ID BEFORE writing any
        // identity-bearing data. An attacker who pre-seeded a victim's cookie
        // ends up holding the now-discarded pre-login id; the post-renew id
        // is the one bound to the authenticated session.
        $session->renew();
        $session->write(
            (string)Configure::read('CakePasskeys.mfa.sessionFlag', 'CakePasskeys.mfa_satisfied'),
            true,
        );
        // Minimum-viable hand-off: the host's own auth middleware reads
        // the configured session key (or subscribes to `CakePasskeys.afterLogin`)
        // to populate its identity object. The default `Auth.id` matches
        // the legacy CakePHP AuthComponent shape; hosts using
        // cakephp/authentication typically point this at `Identity.id`
        // or similar. v2 may expose a richer integration hook.
        $userIdKey = (string)Configure::read('CakePasskeys.session.userIdKey', 'Auth.id');
        $session->write($userIdKey, $passkey->user_id);
        $this->fire('afterLogin', $passkey, (string)$passkey->user_id);

        return $this->json([
            'redirectTo' => (string)Configure::read('CakePasskeys.afterLoginRedirect', '/'),
            'mfaSatisfied' => true,
            'userId' => $passkey->user_id,
        ]);
    }

    /**
     * @return \Cake\Http\Response
     */
    public function reauthStart(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->throttle('passkeys.reauth.' . $user->getUserId(), 20, 60);

        return $this->json($this->webauthn()->startReauth($user));
    }

    /**
     * @return \Cake\Http\Response
     */
    public function reauthFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $body = (array)$this->getRequest()->getParsedBody();
        try {
            $ok = $this->webauthn()->finishReauth($user, $body);
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }
        if (!$ok) {
            throw new BadRequestException(__d('passkeys', 'Reauthentication failed.'));
        }
        $action = trim((string)($body['action'] ?? 'default'));
        if ($action === '') {
            $action = 'default';
        }
        $window = (int)Configure::read('CakePasskeys.reauthWindow', 900);
        $until = (new DateTimeImmutable())->modify("+{$window} seconds")->format(DATE_ATOM);
        $this->getRequest()->getSession()->write("CakePasskeys.recent_reauth.{$action}", $until);

        return $this->json(['ok' => true, 'until' => $until]);
    }

    /**
     * @param int $id Passkey id.
     *
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return \Cake\Http\Response
     */
    public function rename(int $id): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $passkey = $this->fetchPasskeyOwnedBy($id, $user->getUserId());
        $body = (array)$this->getRequest()->getParsedBody();
        $newName = trim((string)($body['name'] ?? ''));
        if ($newName === '') {
            throw new BadRequestException(__d('passkeys', 'Name cannot be empty.'));
        }
        $oldName = (string)$passkey->name;
        $passkey->set('name', mb_substr($newName, 0, 80));
        if (array_key_exists('emoji', $body)) {
            $passkey->set('emoji', $body['emoji'] === null ? null : (string)$body['emoji']);
        }
        $this->fetchTable('CakePasskeys.Passkeys')->saveOrFail($passkey);
        $this->fire('afterRename', $passkey, $user->getPasskeyUserHandle(), [
            'old' => $oldName,
            'new' => (string)$passkey->name,
        ]);

        return $this->json(['passkey' => $this->serializePasskey($passkey)]);
    }

    /**
     * @param int $id Passkey id.
     *
     * @return \Cake\Http\Response
     */
    public function delete(int $id): Response
    {
        $this->getRequest()->allowMethod(['post', 'delete']);
        $user = $this->resolveCurrentUser();
        $passkey = $this->fetchPasskeyOwnedBy($id, $user->getUserId());
        // Capture entity for the event payload BEFORE deletion so listeners
        // can still read its fields.
        $this->fire('afterDelete', $passkey, $user->getPasskeyUserHandle());
        $this->fetchTable('CakePasskeys.Passkeys')->delete($passkey);

        return $this->json(['deleted' => true]);
    }

    /**
     * @throws \Cake\Http\Exception\ForbiddenException
     * @throws \Cake\Http\Exception\UnauthorizedException
     *
     * @return \CakePasskeys\Contract\PasskeyUserInterface
     */
    private function resolveCurrentUser(): PasskeyUserInterface
    {
        $identity = $this->getRequest()->getAttribute('identity');
        if (!$identity) {
            throw new UnauthorizedException();
        }
        $id = null;
        if (is_object($identity)) {
            if (method_exists($identity, 'getIdentifier')) {
                $id = $identity->getIdentifier();
            } elseif (isset($identity->id)) {
                $id = $identity->id;
            }
        }
        if ($id === null) {
            throw new UnauthorizedException();
        }
        $user = (new UserResolver())->byId($id);
        if ($user === null || !$user->isPasskeyEligible()) {
            throw new ForbiddenException();
        }

        return $user;
    }

    /**
     * @param int $id Passkey id.
     * @param int $userId Owner id.
     *
     * @throws \Cake\Http\Exception\ForbiddenException
     * @throws \Cake\Http\Exception\NotFoundException
     *
     * @return \CakePasskeys\Model\Entity\Passkey
     */
    private function fetchPasskeyOwnedBy(int $id, int $userId): Passkey
    {
        $passkey = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->where(['Passkeys.id' => $id])
            ->first();
        if (!$passkey instanceof Passkey) {
            throw new NotFoundException();
        }
        if ((int)$passkey->user_id !== $userId) {
            // Owner check is sufficient even when tenancy.column is set:
            // every user belongs to exactly one account, so a matching
            // user_id implies a matching account_id.
            throw new ForbiddenException();
        }

        return $passkey;
    }

    /**
     * @param string $key Rate-limit bucket key.
     * @param int $max Max attempts in the window.
     * @param int $decay Window length in seconds.
     *
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return void
     */
    private function throttle(string $key, int $max, int $decay): void
    {
        $limiter = $this->resolveRateLimiter();
        if (!$limiter->hit($key, $max, $decay)) {
            throw new BadRequestException(__d('passkeys', 'Too many attempts — try again later.'));
        }
    }

    /**
     * Locate the rate-limiter the host has wired (via DI in
     * {@see \CakePasskeys\CakePasskeysPlugin::services()} or directly through the
     * `CakePasskeys.rateLimiter` Configure key). Falls back to the no-op
     * implementation when neither is configured.
     *
     * @return \CakePasskeys\Contract\RateLimiterInterface
     */
    private function resolveRateLimiter(): RateLimiterInterface
    {
        try {
            $container = $this->getRequest()->getAttribute('container');
            if (
                $container instanceof ContainerInterface
                && $container->has(RateLimiterInterface::class)
            ) {
                $instance = $container->get(RateLimiterInterface::class);
                if ($instance instanceof RateLimiterInterface) {
                    return $instance;
                }
            }
        } catch (Throwable) {
            // fall through to Configure lookup
        }
        $impl = Configure::read('CakePasskeys.rateLimiter');
        if (is_string($impl) && class_exists($impl)) {
            $instance = new $impl();
            if ($instance instanceof RateLimiterInterface) {
                return $instance;
            }
        }
        if ($impl instanceof RateLimiterInterface) {
            return $impl;
        }

        return new NullRateLimiter();
    }

    /**
     * Exposed as protected so test doubles can override and inject a stub
     * service (e.g. to bypass the real WebAuthn ceremony when asserting
     * post-login session behavior).
     *
     * @return \CakePasskeys\Service\WebAuthnService
     */
    protected function webauthn(): WebAuthnService
    {
        return new WebAuthnService(new ChallengeStore(), new UserResolver(), new AaguidLabelResolver());
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $status
     *
     * @return \Cake\Http\Response
     */
    private function json(array $payload, int $status = 200): Response
    {
        return $this->getResponse()
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody((string)json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $name Event suffix (e.g. "afterRegister").
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     * @param string $userHandle
     * @param array<string, mixed> $data
     *
     * @return void
     */
    private function fire(string $name, Passkey $passkey, string $userHandle, array $data = []): void
    {
        EventManager::instance()->dispatch(new Event(
            'CakePasskeys.' . $name,
            null,
            ['event' => new PasskeyEvent($passkey, $userHandle, $data)],
        ));
    }

    /**
     * @param \CakePasskeys\Model\Entity\Passkey $p
     *
     * @return array<string, mixed>
     */
    private function serializePasskey(Passkey $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'emoji' => $p->emoji,
            'aaguidLabel' => $p->aaguid_label,
            'createdAt' => $p->created->format(DATE_ATOM),
            'lastUsedAt' => $p->last_used_at?->format(DATE_ATOM),
        ];
    }
}
