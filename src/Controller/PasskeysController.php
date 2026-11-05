<?php

declare(strict_types=1);

namespace CakePasskeys\Controller;

use ArrayAccess;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\TooManyRequestsException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\Routing\Router;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Contract\RateLimiterInterface;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Mailer\PasskeyMailer;
use CakePasskeys\Model\Entity\Passkey;
use CakePasskeys\Service\AaguidLabelResolver;
use CakePasskeys\Service\CacheRateLimiter;
use CakePasskeys\Service\ChallengeStore;
use CakePasskeys\Service\ConventionUserAdapter;
use CakePasskeys\Service\IdentityResolver;
use CakePasskeys\Service\NullRateLimiter;
use CakePasskeys\Service\Signals;
use CakePasskeys\Service\UnknownCredentialException;
use CakePasskeys\Service\UserResolver;
use CakePasskeys\Service\WebAuthnException;
use CakePasskeys\Service\WebAuthnService;
use DateTimeImmutable;
use Throwable;
use function Cake\I18n\__d;

/**
 * WebAuthn / passkey ceremony controller.
 *
 * Nine JSON actions:
 *  - registerStart POST /passkeys/register/start (signed-in user)
 *  - registerFinish POST /passkeys/register/finish (signed-in user)
 *  - loginStart POST /passkeys/login/start (anonymous)
 *  - loginFinish POST /passkeys/login/finish (anonymous)
 *  - reauthStart POST /passkeys/reauth/start (signed-in user)
 *  - reauthFinish POST /passkeys/reauth/finish (signed-in user)
 *  - rename POST /passkeys/rename/{id} (owner only)
 *  - delete POST|DELETE /passkeys/delete/{id} (owner only)
 *  - wellKnown GET /.well-known/passkey-endpoints (anonymous)
 *
 * The application's CSRF protection applies to all of them: the bundled
 * JavaScript sends the token it gets from `PasskeysHelper::endpointsMeta()`.
 * `rename` and `delete` carry no WebAuthn challenge, so they must not be
 * exempted. FormProtection's signed-fields check does not fit JSON bodies
 * and is unloaded here.
 *
 * `loginFinish()` writes the user id to the session key in
 * `CakePasskeys.session.userIdKey` and dispatches `CakePasskeys.afterLogin`.
 * The application builds its own identity from either.
 */
class PasskeysController extends Controller
{
    /**
     * Session key holding the login challenges this browser session started.
     *
     * @var string
     */
    protected const SESSION_LOGIN_CHALLENGES = 'CakePasskeys.login_challenges';

    /**
     * A page can have a conditional-UI request pending and a button click on
     * top, so more than one login challenge may be open per session.
     *
     * @var int
     */
    protected const MAX_OPEN_LOGIN_CHALLENGES = 5;

    /**
     * Shape of the action name a reauthentication is recorded under.
     *
     * @var string
     */
    protected const REAUTH_ACTION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @var int
     */
    protected const NAME_MAX_LENGTH = 80;

    /**
     * @var int
     */
    protected const EMOJI_MAX_LENGTH = 8;

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
        // These endpoints check the user themselves. Without this,
        // cakephp/authorization's requireAuthorizationCheck refuses every one.
        $authorization = $this->getRequest()->getAttribute('authorization');
        if (is_object($authorization) && method_exists($authorization, 'skipAuthorization')) {
            $authorization->skipAuthorization();
        }
    }

    /**
     * @return \Cake\Http\Response
     */
    public function registerStart(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->guardRegistration($user);
        $this->throttle('passkeys.register.' . $user->getUserId(), 10, 60);

        try {
            return $this->json($this->webauthn()->startRegistration($user));
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }
    }

    /**
     * @return \Cake\Http\Response
     */
    public function registerFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->guardRegistration($user);
        $this->throttle('passkeys.register_finish.' . $user->getUserId(), 10, 60);
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
        $this->notifyRegistered($user, $passkey);

        return $this->json(['passkey' => $this->serializePasskey($passkey)]);
    }

    /**
     * @return \Cake\Http\Response
     */
    public function loginStart(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->throttle('passkeys.login.' . $this->getRequest()->clientIp(), 20, 60);

        $options = $this->webauthn()->startLogin($this->emailHint());
        $this->rememberLoginChallenge((string)$options['challengeKey']);

        return $this->json($options);
    }

    /**
     * @return \Cake\Http\Response
     */
    public function loginFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->throttle('passkeys.login_finish.' . $this->getRequest()->clientIp(), 20, 60);
        $body = (array)$this->getRequest()->getParsedBody();
        if (!$this->forgetLoginChallenge((string)($body['challengeKey'] ?? ''))) {
            // The challenge was started in another browser session. Finishing
            // it here would sign this browser in as whoever answered it.
            throw new BadRequestException(__d('passkeys', 'Login challenge missing or expired.'));
        }
        try {
            $passkey = $this->webauthn()->finishLogin($body);
        } catch (UnknownCredentialException) {
            return $this->unknownCredential();
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }
        $user = (new UserResolver())->byId($passkey->user_id);
        if ($user === null) {
            throw new BadRequestException(__d('passkeys', 'Unknown passkey credential.'));
        }

        $session = $this->getRequest()->getSession();
        // Rotate the session id before any identity is written, so an id
        // planted before the login is worthless afterwards.
        $session->renew();
        $userVerified = $this->requiresUserVerification();
        $mfaFlag = (string)Configure::read('CakePasskeys.mfa.sessionFlag', 'CakePasskeys.mfa_satisfied');
        if ($userVerified) {
            $session->write($mfaFlag, true);
        } else {
            // A flag left by an earlier sign-in must not carry over.
            $session->delete($mfaFlag);
        }
        $userIdKey = (string)Configure::read('CakePasskeys.session.userIdKey', 'Auth.id');
        $rootKey = explode('.', $userIdKey)[0];
        // Skip the id when the authenticator just stored the identity under
        // the same root key (entity, nested array or bare primary key alike):
        // the write would clobber it.
        $identityAtRoot = $this->persistIdentity($user, $rootKey);
        if ($userIdKey !== '' && !$identityAtRoot) {
            $session->write($userIdKey, $passkey->user_id);
        }
        $event = $this->fire('afterLogin', $passkey, $user->getPasskeyUserHandle());
        // A listener may add to the answer, e.g. a token for API clients.
        $extra = $event->getResult();

        return $this->json([
            'redirectTo' => (string)Configure::read('CakePasskeys.afterLoginRedirect', '/'),
            'mfaSatisfied' => $userVerified,
            'userId' => $passkey->user_id,
        ] + (is_array($extra) ? $extra : []));
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
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return \Cake\Http\Response
     */
    public function reauthFinish(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->throttle('passkeys.reauth_finish.' . $user->getUserId(), 20, 60);
        $body = (array)$this->getRequest()->getParsedBody();

        $action = trim((string)($body['action'] ?? ''));
        if ($action === '') {
            $action = 'default';
        }
        if (preg_match(static::REAUTH_ACTION_PATTERN, $action) !== 1) {
            throw new BadRequestException(__d('passkeys', 'Invalid action name.'));
        }

        try {
            $this->webauthn()->finishReauth($user, $body);
        } catch (WebAuthnException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $window = (int)Configure::read('CakePasskeys.reauthWindow', 900);
        $until = (new DateTimeImmutable())->modify("+{$window} seconds")->format(DATE_ATOM);
        // Bound to the user, so the confirmation does not carry over when
        // another account signs in on the same session.
        $this->getRequest()->getSession()->write("CakePasskeys.recent_reauth.{$action}", [
            'until' => $until,
            'userId' => (string)$user->getUserId(),
        ]);

        return $this->json(['ok' => true, 'until' => $until]);
    }

    /**
     * @param string $id Passkey id.
     *
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return \Cake\Http\Response
     */
    public function rename(string $id): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->resolveCurrentUser();
        $this->throttle('passkeys.manage.' . $user->getUserId(), 30, 60);
        $passkey = $this->fetchPasskeyOwnedBy($id, $user->getUserId());
        $body = (array)$this->getRequest()->getParsedBody();
        $newName = trim((string)($body['name'] ?? ''));
        if ($newName === '') {
            throw new BadRequestException(__d('passkeys', 'Name cannot be empty.'));
        }
        $oldName = (string)$passkey->name;
        $passkey->set('name', mb_substr($newName, 0, static::NAME_MAX_LENGTH));
        if (array_key_exists('emoji', $body)) {
            $emoji = trim((string)$body['emoji']);
            $passkey->set('emoji', $emoji === '' ? null : mb_substr($emoji, 0, static::EMOJI_MAX_LENGTH));
        }
        $this->fetchTable('CakePasskeys.Passkeys')->saveOrFail($passkey);
        $this->fire('afterRename', $passkey, $user->getPasskeyUserHandle(), [
            'old' => $oldName,
            'new' => (string)$passkey->name,
        ]);

        return $this->json(['passkey' => $this->serializePasskey($passkey)]);
    }

    /**
     * @param string $id Passkey id.
     *
     * @return \Cake\Http\Response
     */
    public function delete(string $id): Response
    {
        $this->getRequest()->allowMethod(['post', 'delete']);
        $user = $this->resolveCurrentUser();
        $this->throttle('passkeys.manage.' . $user->getUserId(), 30, 60);
        $passkey = $this->fetchPasskeyOwnedBy($id, $user->getUserId());
        $this->guard(
            'beforeDelete',
            ['user' => $user, 'passkey' => $passkey],
            __d('passkeys', 'You cannot delete this passkey right now.'),
        );
        $this->fetchTable('CakePasskeys.Passkeys')->deleteOrFail($passkey);
        // The entity still carries its fields after the delete.
        $this->fire('afterDelete', $passkey, $user->getPasskeyUserHandle());

        $payload = ['deleted' => true];
        if (Signals::enabled()) {
            $payload += (new Signals())->acceptedCredentials($user);
        }

        return $this->json($payload);
    }

    /**
     * A sign-in with a credential the server does not store. With signals on,
     * the body carries a code so the browser can drop the passkey; the message
     * stays the same either way.
     *
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return \Cake\Http\Response
     */
    private function unknownCredential(): Response
    {
        $message = __d('passkeys', 'Unknown passkey credential.');
        if (!Signals::enabled()) {
            throw new BadRequestException($message);
        }

        return $this->json([
            'message' => $message,
            'code' => 400,
            'error' => Signals::UNKNOWN_CREDENTIAL,
        ], 400);
    }

    /**
     * W3C "Well-Known URL for Passkey Endpoints" document for password managers.
     *
     * @throws \Cake\Http\Exception\NotFoundException
     *
     * @return \Cake\Http\Response
     */
    public function wellKnown(): Response
    {
        $this->getRequest()->allowMethod(['get']);
        $payload = [];
        foreach (['enroll', 'manage'] as $key) {
            $url = Configure::read('CakePasskeys.wellKnown.' . $key);
            if (!$url || (!is_string($url) && !is_array($url))) {
                continue;
            }
            if (is_array($url)) {
                // The request runs inside the plugin, so keep array URLs in the app.
                $url += ['plugin' => false, 'prefix' => false];
            }
            $payload[$key] = Router::url($url, true);
        }
        if (!$payload) {
            throw new NotFoundException();
        }

        return $this->json($payload);
    }

    /**
     * @throws \Cake\Http\Exception\ForbiddenException
     * @throws \Cake\Http\Exception\UnauthorizedException
     *
     * @return \CakePasskeys\Contract\PasskeyUserInterface
     */
    private function resolveCurrentUser(): PasskeyUserInterface
    {
        $id = (new IdentityResolver())->userId($this->getRequest());
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
     * @param string $id Passkey id.
     * @param string|int $userId Owner id.
     *
     * @throws \Cake\Http\Exception\ForbiddenException
     * @throws \Cake\Http\Exception\NotFoundException
     *
     * @return \CakePasskeys\Model\Entity\Passkey
     */
    private function fetchPasskeyOwnedBy(string $id, string|int $userId): Passkey
    {
        if (!ctype_digit($id)) {
            throw new NotFoundException();
        }
        $passkey = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->where(['Passkeys.id' => (int)$id])
            ->first();
        if (!$passkey instanceof Passkey) {
            throw new NotFoundException();
        }
        if ((string)$passkey->user_id !== (string)$userId) {
            throw new ForbiddenException();
        }

        return $passkey;
    }

    /**
     * The email hint narrows the login to one account's credentials, which
     * also tells an anonymous caller whether that account has passkeys. It is
     * therefore off unless `CakePasskeys.login.emailHint` is enabled.
     *
     * @return string|null
     */
    private function emailHint(): ?string
    {
        if (!Configure::read('CakePasskeys.login.emailHint')) {
            return null;
        }
        $body = (array)$this->getRequest()->getParsedBody();
        $hint = trim((string)($body['emailHint'] ?? ''));
        if ($hint === '') {
            $hint = trim((string)$this->getRequest()->getQuery('email', ''));
        }

        return $hint !== '' ? $hint : null;
    }

    /**
     * @return bool Whether a login proves user verification (PIN or biometrics)
     */
    private function requiresUserVerification(): bool
    {
        return Configure::read('CakePasskeys.ceremony.userVerification', 'required') === 'required';
    }

    /**
     * @param string $challengeKey
     *
     * @return void
     */
    private function rememberLoginChallenge(string $challengeKey): void
    {
        $session = $this->getRequest()->getSession();
        $open = (array)$session->read(static::SESSION_LOGIN_CHALLENGES);
        $open[] = $challengeKey;
        $session->write(
            static::SESSION_LOGIN_CHALLENGES,
            array_slice($open, -static::MAX_OPEN_LOGIN_CHALLENGES),
        );
    }

    /**
     * @param string $challengeKey
     *
     * @return bool Whether this session had started that challenge
     */
    private function forgetLoginChallenge(string $challengeKey): bool
    {
        $session = $this->getRequest()->getSession();
        $open = (array)$session->read(static::SESSION_LOGIN_CHALLENGES);
        $index = $challengeKey === '' ? false : array_search($challengeKey, $open, true);
        if ($index === false) {
            return false;
        }
        unset($open[$index]);
        $session->write(static::SESSION_LOGIN_CHALLENGES, array_values($open));

        return true;
    }

    /**
     * @param string $key Rate-limit bucket key.
     * @param int $max Max attempts in the window.
     * @param int $decay Window length in seconds.
     *
     * @throws \Cake\Http\Exception\TooManyRequestsException When the bucket is full.
     *
     * @return void
     */
    private function throttle(string $key, int $max, int $decay): void
    {
        $limiter = $this->resolveRateLimiter();
        if (!$limiter->hit($key, $max, $decay)) {
            throw new TooManyRequestsException(__d('passkeys', 'Too many attempts. Try again later.'));
        }
    }

    /**
     * The limiter from `CakePasskeys.rateLimiter`: a class name or instance
     * implementing RateLimiterInterface, null for the cache-backed default,
     * false to turn limiting off.
     *
     * @return \CakePasskeys\Contract\RateLimiterInterface
     */
    private function resolveRateLimiter(): RateLimiterInterface
    {
        $impl = Configure::read('CakePasskeys.rateLimiter');
        if ($impl === false) {
            return new NullRateLimiter();
        }
        if ($impl instanceof RateLimiterInterface) {
            return $impl;
        }
        if (is_string($impl) && class_exists($impl)) {
            $instance = new $impl();
            if ($instance instanceof RateLimiterInterface) {
                return $instance;
            }
        }

        return new CacheRateLimiter();
    }

    /**
     * Protected so a test double can inject a stub service.
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
     * @return \Cake\Event\EventInterface<$this>
     */
    private function fire(string $name, Passkey $passkey, string $userHandle, array $data = []): EventInterface
    {
        $event = new Event(
            'CakePasskeys.' . $name,
            $this,
            ['event' => new PasskeyEvent($passkey, $userHandle, $data)],
        );
        EventManager::instance()->dispatch($event);

        return $event;
    }

    /**
     * Sends the `notify.onRegister` alert. The passkey is already stored, so
     * a mail failure is logged and never fails the registration.
     *
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     *
     * @return void
     */
    private function notifyRegistered(PasskeyUserInterface $user, Passkey $passkey): void
    {
        if (!Configure::read('CakePasskeys.notify.onRegister') || $user->getPasskeyDisplayEmail() === '') {
            return;
        }
        try {
            $this->mailer()->send('registered', [$user, $passkey]);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                'CakePasskeys: could not send the passkey alert for user %s: %s',
                (string)$user->getUserId(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * Protected so a host or test can swap the mailer.
     *
     * @return \CakePasskeys\Mailer\PasskeyMailer
     */
    protected function mailer(): PasskeyMailer
    {
        return new PasskeyMailer((string)Configure::read('CakePasskeys.notify.emailProfile', 'default'));
    }

    /**
     * Lets the host refuse a registration, for example when the sign-in is
     * not recent.
     *
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     *
     * @return void
     */
    private function guardRegistration(PasskeyUserInterface $user): void
    {
        $this->guard(
            'beforeRegister',
            ['user' => $user],
            __d('passkeys', 'You cannot add a passkey right now.'),
        );
    }

    /**
     * Dispatches a `before*` event the host can stop to refuse the action. A
     * string result becomes the message of the 403.
     *
     * @param string $name Event suffix.
     * @param array<string, mixed> $data Event data.
     * @param string $fallbackMessage Message when the listener sets none.
     *
     * @throws \Cake\Http\Exception\ForbiddenException
     *
     * @return void
     */
    private function guard(string $name, array $data, string $fallbackMessage): void
    {
        $event = new Event('CakePasskeys.' . $name, $this, $data);
        EventManager::instance()->dispatch($event);
        if (!$event->isStopped()) {
            return;
        }
        $result = $event->getResult();

        throw new ForbiddenException(is_string($result) && $result !== '' ? $result : $fallbackMessage);
    }

    /**
     * Hands the signed-in user to cakephp/authentication when the host runs it.
     *
     * A bare id under the authenticator's session key would be read back as an
     * identity without the user's other fields (role, flags), so the full user
     * row is persisted through the service instead.
     *
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     * @param string $rootKey Session root of `session.userIdKey`.
     *
     * @return bool Whether persisting the identity wrote under `$rootKey`.
     */
    private function persistIdentity(PasskeyUserInterface $user, string $rootKey): bool
    {
        if (!Configure::read('CakePasskeys.session.persistIdentity', true)) {
            return false;
        }
        $service = $this->getRequest()->getAttribute('authentication');
        if (!is_object($service) || !method_exists($service, 'persistIdentity')) {
            return false;
        }

        // The Session authenticator does not overwrite an identity that is
        // already there, so a sign-in on top of another user would keep them.
        if (method_exists($service, 'clearIdentity')) {
            $cleared = $service->clearIdentity($this->getRequest(), $this->getResponse());
            $this->setRequest($cleared['request']);
            $this->setResponse($cleared['response']);
        }

        $identity = $user instanceof ConventionUserAdapter ? $user->getEntity() : $user;
        if (!$identity instanceof ArrayAccess) {
            // cakephp/authentication only stores entities and other ArrayAccess
            // identities; anything else falls back to session.userIdKey,
            // with the previous identity already cleared above.
            return false;
        }

        $session = $this->getRequest()->getSession();
        $before = $session->read($rootKey);
        $result = $service->persistIdentity($this->getRequest(), $this->getResponse(), $identity);
        $this->setRequest($result['request']);
        $this->setResponse($result['response']);

        return $rootKey !== '' && $session->read($rootKey) !== $before;
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
