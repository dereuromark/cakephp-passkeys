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
 * `bin/cake passkeys list <user>`: the passkeys of one user.
 */
class PasskeysListCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'passkeys list';
    }

    /**
     * @return string
     */
    public static function getDescription(): string
    {
        return __d('passkeys', 'List the passkeys of a user.');
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
        $passkeys = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->where(['Passkeys.user_id' => $userId])
            ->orderBy(['Passkeys.id' => 'ASC'])
            ->all()
            ->toArray();
        if (!$passkeys) {
            $io->out(__d('passkeys', 'No passkeys found for user {0}.', $userId));

            return static::CODE_SUCCESS;
        }

        $rows = [
            [
                __d('passkeys', 'Id'),
                __d('passkeys', 'Name'),
                __d('passkeys', 'Created'),
                __d('passkeys', 'Last used'),
                __d('passkeys', 'Authenticator'),
            ],
        ];
        foreach ($passkeys as $passkey) {
            assert($passkey instanceof Passkey);
            $rows[] = [
                (string)$passkey->id,
                $passkey->name,
                $passkey->created->format('Y-m-d H:i'),
                $passkey->last_used_at?->format('Y-m-d H:i') ?? '-',
                $passkey->aaguid_label ?? '-',
            ];
        }
        $io->helper('Table')->output($rows);

        return static::CODE_SUCCESS;
    }
}
