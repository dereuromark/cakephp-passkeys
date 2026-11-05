<?php

declare(strict_types=1);

namespace CakePasskeys\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use CakePasskeys\Model\Entity\Passkey;
use function Cake\I18n\__d;

/**
 * `bin/cake passkeys revoke <user> [<passkey>]`: deletes one or all passkeys
 * of a user, for example after a lost device.
 */
class PasskeysRevokeCommand extends Command
{
    use PasskeyDeletionTrait;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'passkeys revoke';
    }

    /**
     * @return string
     */
    public static function getDescription(): string
    {
        return __d('passkeys', 'Delete one passkey of a user, or all of them.');
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription(static::getDescription())
            ->addArgument('user', [
                'help' => __d('passkeys', 'User id.'),
                'required' => true,
            ])
            ->addArgument('passkey', [
                'help' => __d('passkeys', 'Passkey id. Without it, all passkeys of the user are deleted.'),
            ])
            ->addOption('yes', [
                'short' => 'y',
                'boolean' => true,
                'help' => __d('passkeys', 'Do not ask before deleting all passkeys.'),
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args
     * @param \Cake\Console\ConsoleIo $io
     *
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $userId = (string)$args->getArgument('user');
        $passkeyId = $args->getArgument('passkey');
        $query = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->where(['Passkeys.user_id' => $userId])
            ->orderBy(['Passkeys.id' => 'ASC']);

        if ($passkeyId !== null) {
            $passkey = ctype_digit($passkeyId) ? $query->where(['Passkeys.id' => (int)$passkeyId])->first() : null;
            if (!$passkey instanceof Passkey) {
                $io->error(__d('passkeys', 'User {0} has no passkey {1}.', $userId, $passkeyId));

                return static::CODE_ERROR;
            }
            $passkeys = [$passkey];
        } else {
            /** @var array<\CakePasskeys\Model\Entity\Passkey> $passkeys */
            $passkeys = $query->all()->toArray();
            if (!$passkeys) {
                $io->out(__d('passkeys', 'No passkeys found for user {0}.', $userId));

                return static::CODE_SUCCESS;
            }
            if (!$args->getOption('yes')) {
                $answer = $io->askChoice(
                    __d('passkeys', 'Delete all {0} passkeys of user {1}?', count($passkeys), $userId),
                    ['y', 'n'],
                    'n',
                );
                if ($answer !== 'y') {
                    $io->out(__d('passkeys', 'Nothing deleted.'));

                    return static::CODE_SUCCESS;
                }
            }
        }

        $userHandle = $this->userHandle($userId);
        foreach ($passkeys as $passkey) {
            $this->deletePasskey($passkey, $userHandle);
            $io->out(__d('passkeys', 'Deleted passkey {0} ({1}).', $passkey->id, $passkey->name));
        }

        return static::CODE_SUCCESS;
    }
}
