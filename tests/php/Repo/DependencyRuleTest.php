<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * THE DEPENDENCY RULE, ENFORCED: the pure core reaches no infrastructure (architecture review B-5, 2026-10-08).
 *
 * The rule held on the day the review measured it, and nothing checked it. The ruled target makes the
 * dependency rule THE enforcement of the architecture, and a rule nobody checks drifts at the first
 * convenient import. This turns it into a property of the tree, in two halves:
 *
 *   - **No I/O, clock, randomness or environment** in a pure file — no baseline, because none exists today.
 *     Parsing a GIVEN instant (`new \DateTimeImmutable($iso)`) is pure; reading the wall clock is not.
 *   - **No reference to an impure class** — by `use`, by an inline `\Scout\…` name, or by a BARE name of a
 *     class in the same namespace. The bare case is the one an import scan misses: `Scout\Car` and
 *     `Scout\Core` hold pure and infrastructure classes side by side, so `VehicleStore` needs no `use`.
 *
 * The pure set is a CURATED list, and that is a stated cost: `Car`, `Job` and `Core` are flat namespaces,
 * so purity is a classification there, not a folder. A file in the list that disappears fails the suite
 * ({@see self::testEveryPureEntryExists()}), so a rename cannot drop a file out of the rule silently. Step 13
 * of the plan splits `Core` into kernel and infrastructure; the list shrinks to folders then.
 *
 * {@see self::BASELINE} holds today's known edges and only ever shrinks: an edge that no longer exists
 * fails the suite until its line is deleted.
 *
 * The import scan reads `use` at the start of a line only, so a trait `use` inside a class body is not read
 * as an import. That loses nothing: a trait named there by a short name is either a sibling (caught bare),
 * imported at the top of the file (caught there), or written as an inline `\Scout\…` name (caught as one).
 */
final class DependencyRuleTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src/php';

    /** Directories (every file pure) and files, relative to `src/php`. */
    private const array PURE = [
        'Rent/Core',
        'Rent/Config/Criteria.php',
        'Rent/Config/Weights.php',
        'Rent/Config/NotifyPolicy.php',
        'Car/VehicleClassifier.php',
        'Car/VehicleClassification.php',
        'Car/VehicleOutcome.php',
        'Car/VehicleVerdict.php',
        'Car/VehicleCriteria.php',
        'Car/VehicleScorer.php',
        'Car/VehicleNotifyPolicy.php',
        'Car/VehicleFacts.php',
        'Car/VehicleListing.php',
        'Car/AuctionUrgency.php',
        'Car/Hail.php',
        'Job/JobClassifier.php',
        'Job/JobCriteria.php',
        'Job/JobScorer.php',
        'Job/JobNotifyPolicy.php',
        'Job/JobPay.php',
        'Job/PayLine.php',
        'Job/JobText.php',
        'Job/JobTerms.php',
        'Job/JobFacts.php',
        'Job/JobListing.php',
        'Job/JobVerdict.php',
        'Job/JobOutcome.php',
        'Core/Text.php',
        'Core/Whitespace.php',
        'Core/Redact.php',
        'Core/MalformedText.php',
        'Core/RecoverableForms.php',
        'Core/SourceHealth.php',
        'Core/SourceStatus.php',
        'Core/SameFilterWarning.php',
        'Core/PatternMissLog.php',
        'Core/CountsPatternMisses.php',
        'Core/Heartbeat.php',
        'Core/MutableByDesign.php',
    ];

    /** Known edges from a pure file to an impure class, `Pure\Class => [Referenced\Class, …]`. Shrinks only. */
    private const array BASELINE = [
        // Both read their block through `Reader`, whose `fromFile()` opens the file (`file_get_contents`), so
        // the policy object knows how it is loaded. Removed when the factories move to the loader (plan step 8).
        'Scout\\Rent\\Config\\NotifyPolicy' => ['Scout\\Config\\Reader'],
        'Scout\\Rent\\Config\\Weights' => ['Scout\\Config\\Reader'],
    ];

    /**
     * I/O, the clock, randomness, the environment and the process — code lines only. A method or static call
     * of the same name (`->file(`, `::time(`) and a variable are not the global function.
     */
    private const string PRIMITIVE = '/(?<![\w$>:])(?:'
        . 'PDO(?:Statement|Exception)?\b|SQLite3\b'
        . '|curl_\w+\s*\(|stream_socket_\w+\s*\(|fsockopen\s*\(|imap_\w+\s*\(|mail\s*\('
        . '|getenv\s*\(|putenv\s*\(|STDOUT\b|STDERR\b|STDIN\b|echo\b|print\s*\(|printf\s*\(|var_dump\s*\('
        . '|file_get_contents\s*\(|file_put_contents\s*\(|fopen\s*\(|file\s*\(|glob\s*\(|unlink\s*\(|mkdir\s*\('
        . '|time\s*\(\s*\)|date\s*\(|date_create\s*\(|uniqid\s*\(|random_int\s*\(|random_bytes\s*\(|mt_rand\s*\(|rand\s*\(|usleep\s*\(|sleep\s*\('
        . '|hrtime\s*\(|microtime\s*\(|shell_exec\s*\(|exec\s*\(|proc_open\s*\(|passthru\s*\(|system\s*\('
        . ')|\$_(?:ENV|SERVER|GET|POST|COOKIE)\b|new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:[\'"]now[\'"]\s*)?\)/';

    public function testNoPureFileUsesIoClockRandomnessOrTheEnvironment(): void
    {
        $offenders = [];
        foreach ($this->pureFiles() as $rel => $code) {
            foreach (explode("\n", $code) as $line) {
                if (preg_match(self::PRIMITIVE, $line, $m) === 1) {
                    $offenders[] = $rel . ': ' . $m[0] . '   «' . trim($line) . '»';
                }
            }
        }

        self::assertSame([], $offenders, 'a pure file reaches I/O, the clock, randomness or the environment');
    }

    public function testNoPureFileReferencesAnImpureClassOutsideTheBaseline(): void
    {
        $edges = $this->impureEdges();
        $unexpected = [];
        foreach ($edges as $from => $targets) {
            foreach ($targets as $to) {
                if (!\in_array($to, self::BASELINE[$from] ?? [], true)) {
                    $unexpected[] = $from . ' -> ' . $to;
                }
            }
        }

        self::assertSame([], $unexpected, 'a pure file references an impure class (B-5): move the dependency behind a port, or justify a baseline line');
    }

    /** The baseline only shrinks: a line whose edge is gone must be deleted, or it would excuse the edge's return. */
    public function testEveryBaselineEdgeStillExists(): void
    {
        $edges = $this->impureEdges();
        $stale = [];
        foreach (self::BASELINE as $from => $targets) {
            foreach ($targets as $to) {
                if (!\in_array($to, $edges[$from] ?? [], true)) {
                    $stale[] = $from . ' -> ' . $to;
                }
            }
        }

        self::assertSame([], $stale, 'these baseline edges no longer exist — delete their lines so they cannot return unnoticed');
    }

    public function testEveryPureEntryExists(): void
    {
        foreach (self::PURE as $entry) {
            self::assertFileExists(self::SRC . '/' . $entry, 'a PURE entry vanished — a rename must move it, not drop it from the rule');
        }
    }

    /** The discovery's own proof: the three ways a pure file can reach infrastructure are each seen. */
    public function testEachKindOfReferenceIsSeen(): void
    {
        $code = <<<'PHP'
            namespace Scout\Car;
            use Scout\Core\RunStore;
            final class Probe
            {
                public function a(): void { $x = VehicleStore::class; }
                public function b(): void { $y = \Scout\Adapters\Http\CurlHttpClient::class; }
            }
            PHP;

        $refs = self::referencedClasses($code, 'Scout\\Car', ['VehicleStore', 'Hail']);

        self::assertContains('Scout\\Core\\RunStore', $refs, 'a use statement');
        self::assertContains('Scout\\Car\\VehicleStore', $refs, 'a bare same-namespace name');
        self::assertContains('Scout\\Adapters\\Http\\CurlHttpClient', $refs, 'an inline fully-qualified name');
        self::assertNotContains('Scout\\Car\\Hail', $refs, 'a class that is never named is not a reference');
    }

    /** The walk's own proof: a file two folders under a pure directory is read. */
    public function testAPureDirectoryIsWalkedAtAnyDepth(): void
    {
        $dir = sys_get_temp_dir() . '/depwalk-' . bin2hex(random_bytes(6));
        mkdir($dir . '/Sub/Deeper', 0o777, true);

        try {
            file_put_contents($dir . '/Sub/Deeper/Moved.php', "<?php\n");

            self::assertSame([$dir . '/Sub/Deeper/Moved.php'], self::phpFilesUnder($dir), 'a file moved into a subfolder left the rule');
        } finally {
            @unlink($dir . '/Sub/Deeper/Moved.php');
            @rmdir($dir . '/Sub/Deeper');
            @rmdir($dir . '/Sub');
            @rmdir($dir);
        }
    }

    /**
     * The primitive pattern's own proof: it fires on each kind of impurity, and stays quiet on the
     * lookalikes a pure file legitimately writes — a method of the same name, a longer function name,
     * a GIVEN instant, a variable. Without this the half that found nothing on its first run could be
     * finding nothing because it cannot.
     */
    public function testThePrimitivePatternFiresOnImpurityAndNotOnLookalikes(): void
    {
        $impure = [
            '$x = time();',
            '$d = new \DateTimeImmutable();',
            "\$d = new DateTimeImmutable('now');",
            '$d = date_create();',
            '$id = uniqid();',
            '$raw = file_get_contents($path);',
            '$db = new \PDO($dsn);',
            '$key = $_ENV[\'KEY\'];',
            '$k = getenv(\'KEY\');',
            '$n = random_int(1, 6);',
            'curl_exec($h);',
            'echo $x;',
        ];
        $pure = [
            '$t = $clock->time();',
            '$t = Clock::time();',
            'if (is_file($p)) {',
            '$k = array_rand($a);',
            '$d = new \DateTimeImmutable($iso);',
            '$v = $date($x);',
            '$f = $this->file();',
            '$s = $this->echoed;',
        ];

        foreach ($impure as $line) {
            self::assertSame(1, preg_match(self::PRIMITIVE, $line), 'not caught: ' . $line);
        }
        foreach ($pure as $line) {
            self::assertSame(0, preg_match(self::PRIMITIVE, $line), 'false positive: ' . $line);
        }
    }

    /** @return array<string, list<string>> every pure class's references to classes outside the pure set */
    private function impureEdges(): array
    {
        $pure = array_flip($this->pureClasses());
        $edges = [];
        foreach ($this->pureFiles() as $rel => $code) {
            $from = self::classOf($rel);
            $namespace = substr($from, 0, (int) strrpos($from, '\\'));
            $siblings = self::classesIn(\dirname(self::SRC . '/' . $rel));
            foreach (self::referencedClasses($code, $namespace, $siblings) as $to) {
                if (str_starts_with($to, 'Scout\\') && !isset($pure[$to]) && $to !== $from) {
                    $edges[$from][] = $to;
                }
            }
        }
        foreach ($edges as &$targets) {
            $targets = array_values(array_unique($targets));
            sort($targets);
        }
        ksort($edges);

        return $edges;
    }

    /**
     * The `Scout\…` classes `$code` names: imports, inline fully-qualified names, and bare names of the
     * `$siblings` (classes sharing its namespace). Resolved to fully-qualified names.
     *
     * @param list<string> $siblings
     * @return list<string>
     */
    private static function referencedClasses(string $code, string $namespace, array $siblings): array
    {
        $out = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/^use\s+(?!function\b|const\b)([^;{]+?)(?:\{([^}]*)\})?\s*;/', $line, $m) === 1) {
                $prefix = trim($m[1]);
                if (($m[2] ?? '') === '') {
                    $out[] = ltrim(preg_replace('/\s+as\s+\w+$/', '', $prefix) ?? $prefix, '\\');
                } else {
                    foreach (explode(',', $m[2]) as $part) {
                        $out[] = ltrim($prefix . trim(preg_replace('/\s+as\s+\w+$/', '', trim($part)) ?? ''), '\\');
                    }
                }
                continue;
            }
            if (preg_match_all('/\\\\(Scout(?:\\\\\w+)+)/', $line, $fq) > 0) {
                foreach ($fq[1] as $name) {
                    $out[] = $name;
                }
            }
            foreach ($siblings as $sibling) {
                if (preg_match('/(?<![\w$>:\\\\])' . preg_quote($sibling, '/') . '\b/', $line) === 1) {
                    $out[] = $namespace . '\\' . $sibling;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> `relative path => comment-stripped code` for every pure file */
    private function pureFiles(): array
    {
        $out = [];
        foreach (self::PURE as $entry) {
            $path = self::SRC . '/' . $entry;
            $files = is_dir($path) ? self::phpFilesUnder($path) : [$path];
            foreach ($files as $file) {
                $lines = file($file, \FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    continue;
                }
                $rel = substr((string) realpath($file), \strlen((string) realpath(self::SRC)) + 1);
                $out[$rel] = implode("\n", array_filter($lines, static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1));
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * At any depth: a pure directory's subfolder is pure too, and a walk one level deep would let a file
     * moved into `Rent/Core/Sub/` leave the rule while the suite stayed green (the B-4 lesson).
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function pureClasses(): array
    {
        return array_map(self::classOf(...), array_keys($this->pureFiles()));
    }

    /** PSR-4: `Car/VehicleScorer.php` is `Scout\Car\VehicleScorer`. */
    private static function classOf(string $rel): string
    {
        return 'Scout\\' . str_replace('/', '\\', substr($rel, 0, -4));
    }

    /** @return list<string> the short names of the classes declared beside `$dir`'s files */
    private static function classesIn(string $dir): array
    {
        return array_map(static fn (string $f): string => basename($f, '.php'), glob($dir . '/*.php') ?: []);
    }
}
