<?php

declare(strict_types=1);

namespace CakePasskeys\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Expression\QueryExpression;
use Cake\I18n\DateTime;
use CakePasskeys\Model\Entity\Passkey;
use function Cake\I18n\__d;

/**
 * `bin/cake passkeys prune --unused-for <days>`: deletes passkeys nobody has
 * used for that long. A user's last passkey stays unless `--include-last`.
 */
class PasskeysPruneCommand extends Command
{
    use PasskeyDeletionTrait;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'passkeys prune';
    }

    /**
     * @return string
     */
    public static function getDescription(): string
    {
        return __d('passkeys', 'Delete passkeys that were not used for a given number of days.');
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
            ->addOption('unused-for', [
                'help' => __d('passkeys', 'Days since the last use, or since creation for passkeys never used.'),
                'required' => true,
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => __d('passkeys', 'List the passkeys that would be deleted, without deleting them.'),
            ])
            ->addOption('include-last', [
                'boolean' => true,
                'help' => __d('passkeys', 'Also delete the last passkey of a user.'),
            ])
            ->addOption('yes', [
                'short' => 'y',
                'boolean' => true,
                'help' => __d('passkeys', 'Do not ask before deleting.'),
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
        $days = (string)$args->getOption('unused-for');
        if (!ctype_digit($days) || (int)$days < 1) {
            $io->error(__d('passkeys', '--unused-for must be a positive number of days, got "{0}".', $days));

            return static::CODE_ERROR;
        }

        $cutoff = DateTime::now()->subDays((int)$days);
        $includeLast = (bool)$args->getOption('include-last');
        [$candidates, $kept] = $this->findCandidates($cutoff, $includeLast);

        if (!$candidates) {
            $io->out(__d('passkeys', 'No passkeys unused for {0} days.', $days));
            $this->reportKept($io, $kept);

            return static::CODE_SUCCESS;
        }

        if ($args->getOption('dry-run')) {
            $rows = [
                [
                    __d('passkeys', 'User'),
                    __d('passkeys', 'Id'),
                    __d('passkeys', 'Name'),
                    __d('passkeys', 'Created'),
                    __d('passkeys', 'Last used'),
                ],
            ];
            foreach ($candidates as $passkey) {
                $rows[] = [
                    (string)$passkey->user_id,
                    (string)$passkey->id,
                    $passkey->name,
                    $passkey->created->format('Y-m-d H:i'),
                    $passkey->last_used_at?->format('Y-m-d H:i') ?? '-',
                ];
            }
            $io->helper('Table')->output($rows);
            $io->out(__d('passkeys', 'Passkeys that would be deleted: {0}.', count($candidates)));
            $this->reportKept($io, $kept);

            return static::CODE_SUCCESS;
        }

        if (!$args->getOption('yes')) {
            $answer = $io->askChoice(
                __d('passkeys', 'Delete all passkeys unused for {0} days ({1} in total)?', $days, count($candidates)),
                ['y', 'n'],
                'n',
            );
            if ($answer !== 'y') {
                $io->out(__d('passkeys', 'Nothing deleted.'));

                return static::CODE_SUCCESS;
            }
        }

        $table = $this->fetchTable('CakePasskeys.Passkeys');
        $handles = [];
        $deleted = 0;
        foreach ($candidates as $passkey) {
            $userId = (string)$passkey->user_id;
            // Passkeys may have been deleted elsewhere since the scan.
            if (!$includeLast && !$table->exists(['user_id' => $passkey->user_id, 'id !=' => $passkey->id])) {
                $kept++;

                continue;
            }
            // Conditional delete: a passkey used since the scan stays.
            $affected = $table->deleteAll(function (QueryExpression $exp) use ($passkey, $cutoff): QueryExpression {
                return $exp->eq('id', $passkey->id)->add($this->unusedSince($exp, $cutoff, ''));
            });
            if (!$affected) {
                $io->out(__d('passkeys', 'Skipped passkey {0} ({1}) of user {2}: used since the scan.', $passkey->id, $passkey->name, $userId));

                continue;
            }
            $handles[$userId] ??= $this->userHandle($userId);
            $this->dispatchAfterDelete($passkey, $handles[$userId]);
            $deleted++;
            $io->out(__d('passkeys', 'Deleted passkey {0} ({1}) of user {2}.', $passkey->id, $passkey->name, $userId));
        }
        $io->out(__d('passkeys', 'Passkeys deleted: {0}.', $deleted));
        $this->reportKept($io, $kept);

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Cake\I18n\DateTime $cutoff
     * @param bool $includeLast
     *
     * @return array{array<\CakePasskeys\Model\Entity\Passkey>, int} Candidates and the number kept as last passkey.
     */
    protected function findCandidates(DateTime $cutoff, bool $includeLast): array
    {
        /** @var array<\CakePasskeys\Model\Entity\Passkey> $candidates */
        $candidates = $this->fetchTable('CakePasskeys.Passkeys')->find()
            ->where(fn (QueryExpression $exp): QueryExpression => $this->unusedSince($exp, $cutoff, 'Passkeys.'))
            ->orderBy(['Passkeys.user_id' => 'ASC', 'Passkeys.id' => 'ASC'])
            ->all()
            ->toArray();
        if ($includeLast) {
            return [$candidates, 0];
        }
        $remaining = $this->spareLastPasskeys($candidates);

        return [$remaining, count($candidates) - count($remaining)];
    }

    /**
     * Last used before the cutoff, or never used and created before it.
     *
     * @param \Cake\Database\Expression\QueryExpression $exp
     * @param \Cake\I18n\DateTime $cutoff
     * @param string $prefix Column prefix, '' for deleteAll().
     *
     * @return \Cake\Database\Expression\QueryExpression
     */
    protected function unusedSince(QueryExpression $exp, DateTime $cutoff, string $prefix): QueryExpression
    {
        return $exp->or([
            $prefix . 'last_used_at <' => $cutoff,
            $exp->and([
                $prefix . 'last_used_at IS' => null,
                $prefix . 'created <' => $cutoff,
            ]),
        ]);
    }

    /**
     * Drops the most recently used (or created) passkey of every user whose
     * passkeys would all be deleted.
     *
     * @param array<\CakePasskeys\Model\Entity\Passkey> $candidates
     *
     * @return array<\CakePasskeys\Model\Entity\Passkey>
     */
    protected function spareLastPasskeys(array $candidates): array
    {
        $byUser = [];
        foreach ($candidates as $passkey) {
            $byUser[(string)$passkey->user_id][] = $passkey;
        }
        if (!$byUser) {
            return [];
        }

        $table = $this->fetchTable('CakePasskeys.Passkeys');
        $query = $table->find();
        $totals = $query
            ->select(['user_id' => 'Passkeys.user_id', 'total' => $query->func()->count('*')])
            ->where(['Passkeys.user_id IN' => array_keys($byUser)])
            ->groupBy(['Passkeys.user_id'])
            ->disableHydration()
            ->all()
            ->combine('user_id', 'total')
            ->toArray();

        $spared = [];
        foreach ($byUser as $userId => $passkeys) {
            if ((int)($totals[$userId] ?? 0) > count($passkeys)) {
                continue;
            }
            $newest = $passkeys[0];
            foreach ($passkeys as $passkey) {
                if ($this->lastActivity($passkey) >= $this->lastActivity($newest)) {
                    $newest = $passkey;
                }
            }
            $spared[$newest->id] = true;
        }

        return array_values(array_filter(
            $candidates,
            fn (Passkey $passkey): bool => !isset($spared[$passkey->id]),
        ));
    }

    /**
     * @param \CakePasskeys\Model\Entity\Passkey $passkey
     *
     * @return \Cake\I18n\DateTime
     */
    protected function lastActivity(Passkey $passkey): DateTime
    {
        return $passkey->last_used_at ?? $passkey->created;
    }

    /**
     * @param \Cake\Console\ConsoleIo $io
     * @param int $kept
     *
     * @return void
     */
    protected function reportKept(ConsoleIo $io, int $kept): void
    {
        if ($kept) {
            $io->out(__d('passkeys', 'Kept as the last passkey of their user: {0}. Use --include-last to delete them too.', $kept));
        }
    }
}
