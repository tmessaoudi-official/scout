<?php

declare(strict_types=1);

namespace Scout\Job\Cli;

use Scout\Adapters\Http\HttpClient;
use Scout\Core\Notify\Notifier;

/**
 * `scout --domain=job …`: the job watcher. Rulings live in `docs/plans/job-domain.plan.md`.
 *
 * It is BEING BUILT one slice at a time (2026-09-13). The registry entry lands first so the dispatch
 * contract holds from day one. Every verb that does not exist yet REFUSES by name, with exit 2. A stub
 * exiting 0 would be a watcher reporting a quiet market while watching nothing, which is this
 * project's defining failure. Each verb replaces its refusal as its slice lands.
 */
final readonly class JobScout
{
    /** The verbs slice 1 will build, in the order `help` lists them. */
    private const array PLANNED = ['doctor', 'dump', 'run', 'test-notify', 'rollup'];

    /** @var resource */
    private mixed $out;
    /** @var resource */
    private mixed $err;

    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        private ?HttpClient $http = null,
        private ?Notifier $notifier = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';

        if (in_array($command, ['help', '--help', '-h'], true)) {
            return $this->help(0);
        }

        if (in_array($command, self::PLANNED, true)) {
            fwrite($this->err, 'scout --domain=job ' . $command . ' : pas encore construit (voir docs/plans/job-domain.plan.md)' . PHP_EOL);

            return 2;
        }

        fwrite($this->err, 'commande inconnue : ' . $command . PHP_EOL);
        $this->help(2);

        return 2;
    }

    private function help(int $code): int
    {
        foreach ([
            'scout --domain=job — veille sur les offres d\'emploi (en construction)',
            '',
            '  verbes prévus : ' . implode(', ', self::PLANNED),
            '',
            '  config : config/job/criteria.json, config/job/sources.json',
            '  env    : JOB_* (les identifiants IMAP/SMTP et NTFY_SERVER sont partagés)',
        ] as $line) {
            fwrite($code === 0 ? $this->out : $this->err, $line . PHP_EOL);
        }

        return $code;
    }
}
