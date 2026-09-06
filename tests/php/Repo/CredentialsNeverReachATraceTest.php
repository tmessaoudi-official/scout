<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\SmtpTransport;

/**
 * NO CREDENTIAL MAY REACH A STACK TRACE.
 *
 * PHP ships `zend.exception_ignore_args = Off` and `zend.exception_string_param_max_len = 15`, so
 * an uncaught trace prints the first 15 characters of every string ARGUMENT of every LIVE frame —
 * this codebase's frames and the built-ins' alike. Object properties and locals are not printed;
 * parameters are. That one distinction is the whole subject of this file.
 *
 * ## Three surfaces, one shape, found in three different ways
 *
 * 1. `tools/dump-eml.php` sent `$cmd('LOGIN "u" "…"')`. Fixed 2026-09-04 by a zero-argument
 *    closure, with the threat model written into its docblock.
 * 2. `Adapters\Mail\ImapMailbox` sent the identical construction through `command()` — **left
 *    standing by the very commit that documented the threat model**, which is this repo's named
 *    recurring defect: a correct rule applied to a subset of the surfaces it belongs on. A C2
 *    round-2 review panel found it and measured two password characters escaping behind a
 *    three-character username. Nothing leaked with the real `IMAP_USER` only because a long
 *    username spends the budget first — luck, not a guard.
 * 3. `Core\Notify\SmtpTransport` passed `base64_encode($this->password)` to `say()`. Found by
 *    asking what else the panel's question reached rather than fixing only its instance, and it is
 *    the WORST of the three: the credential was the only string argument, so the whole budget went
 *    to it whatever the username, and the base64 prefix decodes to eleven characters.
 *
 * ## The fix needed TWO levels, and the first was not enough
 *
 * Moving the credential out of the helper's parameter list left it in `fwrite`'s. A trace prints
 * built-in frames too, and `@` suppresses warnings while doing nothing to the `TypeError` a closed
 * stream raises. Both writes are therefore wrapped, with the original exception DISCARDED rather
 * than chained — a `previous` carries the trace being escaped. A second draft then took the encoded
 * value as a parameter of the wrapper, which put it straight back; the shipped form passes a
 * SELECTOR and reads the credential from `$this` into a local.
 *
 * The stated cost, in both classes: the underlying stream error is lost on that one call, so a
 * failed AUTH or LOGIN write reports only that it could not be written.
 */
final class CredentialsNeverReachATraceTest extends TestCase
{
    private const string PASSWORD = 'SuperSecretPassword';
    private const string USER = 'annette-the-subscriber';

    /**
     * THE MECHANISM, proven on this machine's own PHP before anything is asserted about the code.
     *
     * Without this the tests below could pass on a runtime where arguments are never printed, and
     * would then be guarding nothing while looking green.
     */
    public function testAnArgumentReachesTheTraceOnThisRuntimeButALocalDoesNot(): void
    {
        // The argument is the credential ALONE, which is both the worst real shape (SmtpTransport's)
        // and the only one this assertion can state exactly: the budget is 15 characters, so
        // `LOGIN "u" "SuperSecretPassword"` truncates at `Supe` and a probe looking for `Super`
        // fails while the mechanism it is testing works perfectly. Counted, not guessed.
        $viaArgument = static function (string $line): void {
            throw new \RuntimeException('refused');
        };

        // THE PREMISE IS SET, NOT ASSUMED (CI, 2026-09-05). PHP's development ini prints trace
        // arguments (`zend.exception_ignore_args = Off`, 15 characters each); the PRODUCTION ini —
        // which the CI runner's PHP ships with — suppresses them, so this test failed on the
        // runner for two days while guarding a mechanism the runner never exercised. The threat is
        // the developer's own runtime and any host that prints arguments, so the test creates that
        // runtime rather than depending on finding it: both directives are PHP_INI_ALL.
        $ignore = ini_set('zend.exception_ignore_args', '0');
        $budget = ini_set('zend.exception_string_param_max_len', '15');

        try {
            $viaArgument(self::PASSWORD);
            self::fail('the probe must throw');
        } catch (\Throwable $e) {
            self::assertStringContainsString(
                substr(self::PASSWORD, 0, 15),
                $e->getTraceAsString(),
                'an argument IS printed on this runtime, truncated to zend.exception_string_param_max_len',
            );
        }

        $viaLocal = function (): void {
            $line = 'LOGIN "u" "' . self::PASSWORD . '"';
            self::assertNotSame('', $line);
            throw new \RuntimeException('refused');
        };

        try {
            $viaLocal();
            self::fail('the probe must throw');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('Super', $e->getTraceAsString(), 'a local is NOT printed');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignore);
            ini_set('zend.exception_string_param_max_len', (string) $budget);
        }
    }

    /**
     * A CLOSED STREAM is the shape that matters: it makes `fwrite` RAISE rather than return false,
     * which is the path `@` does not cover and the reason the wrapper exists.
     */
    public function testTheImapLoginPutsNoCredentialInATrace(): void
    {
        $r = new \ReflectionClass(ImapMailbox::class);
        $mailbox = $r->newInstanceWithoutConstructor();
        $r->getProperty('user')->setValue($mailbox, self::USER);
        $r->getProperty('password')->setValue($mailbox, self::PASSWORD);
        $r->getProperty('tag')->setValue($mailbox, 0);
        $r->getProperty('socket')->setValue($mailbox, self::closedStream());

        try {
            $r->getMethod('login')->invoke($mailbox);
            self::fail('a closed stream must be refused');
        } catch (\Throwable $e) {
            $seen = $e->getMessage() . "\n" . $e->getTraceAsString();
            self::assertStringNotContainsString(self::PASSWORD, $seen);
            self::assertStringNotContainsString('Super', $seen, 'not even the 15-character prefix');
            self::assertStringNotContainsString('LOGIN', $seen, 'and not the command line carrying it');
        }
    }

    public function testTheSmtpAuthLinesPutNoCredentialInATrace(): void
    {
        $r = new \ReflectionClass(SmtpTransport::class);

        foreach (['sayUser', 'sayPassword'] as $method) {
            $transport = $r->newInstanceWithoutConstructor();
            $r->getProperty('user')->setValue($transport, self::USER);
            $r->getProperty('password')->setValue($transport, self::PASSWORD);

            try {
                $r->getMethod($method)->invoke($transport, self::closedStream());
                self::fail($method . ' must refuse a closed stream');
            } catch (\Throwable $e) {
                $seen = $e->getMessage() . "\n" . $e->getTraceAsString();

                // Both forms: AUTH LOGIN sends base64, so the plaintext alone is not enough to look for.
                foreach ([self::PASSWORD, base64_encode(self::PASSWORD), self::USER, base64_encode(self::USER)] as $needle) {
                    self::assertStringNotContainsString($needle, $seen, $method . ' leaked ' . substr($needle, 0, 6));
                }
                self::assertStringNotContainsString(substr(base64_encode(self::PASSWORD), 0, 15), $seen, 'nor the truncated prefix');
            }
        }
    }

    /**
     * THE SWALLOW IN `writeCredential()` IS THE GUARANTEE, AND NO TEST COULD REACH IT.
     *
     * `testTheSmtpAuthLinesPutNoCredentialInATrace` drives a CLOSED stream: `@fwrite` on one emits
     * a warning and returns `false`, so the `catch (\Throwable)` is never entered and the `try` is
     * dead weight under that test. The nightly ledger said so — "the SMTP credential write stops
     * swallowing the raise (fwrite's own frame carries it)" reported undetected, because turning
     * the swallow into `catch (\Throwable $e) { throw $e; }` changes nothing on a path that never
     * throws.
     *
     * A userland stream wrapper is the way in: an exception raised in `stream_write()` propagates
     * out of `fwrite` — `@` suppresses diagnostics, not throws — and `fwrite`'s own frame carries
     * `$line`, which IS the base64 credential. That is why the swallow exists at all.
     *
     * TWO PREMISES BEFORE THE GUARANTEE, because this test is worthless if either fails: the raise
     * must actually cross `@fwrite`, and the credential must actually be in that trace on this
     * runtime. Without them a green result is indistinguishable from a runtime that prints no
     * arguments (CI ships the production ini), which is row 45's exact shape. The runtime is SET
     * here rather than found, for the same reason.
     */
    public function testTheCredentialWriteSwallowsARaiseFromTheSocket(): void
    {
        $ignore = ini_set('zend.exception_ignore_args', '0');
        $budget = ini_set('zend.exception_string_param_max_len', '15');

        if (in_array('scoutthrows', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('scoutthrows');
        }
        stream_wrapper_register('scoutthrows', ThrowingStreamWrapper::class);

        try {
            $encoded = base64_encode(self::PASSWORD);

            // PREMISE 1 + 2 — the raise crosses `@fwrite`, and it carries the written line with it.
            $leaked = null;
            try {
                @fwrite(self::throwingStream(), $encoded . "\r\n");
                self::fail('premise: a raise from stream_write() must cross @fwrite, or the swallow guards nothing');
            } catch (\RuntimeException $e) {
                $leaked = $e->getMessage() . "\n" . $e->getTraceAsString();
            }
            self::assertStringContainsString(
                substr($encoded, 0, 15),
                (string) $leaked,
                'premise: the credential must reach an unguarded trace on this runtime, or the guarantee below is free',
            );

            // THE GUARANTEE — the raise is swallowed and re-stated as a ChannelError, whose message
            // is masked. Under the sabotage the RuntimeException above arrives here instead.
            $r = new \ReflectionClass(SmtpTransport::class);
            $transport = $r->newInstanceWithoutConstructor();
            $r->getProperty('user')->setValue($transport, self::USER);
            $r->getProperty('password')->setValue($transport, self::PASSWORD);

            try {
                $r->getMethod('writeCredential')->invoke($transport, self::throwingStream(), true);
                self::fail('writeCredential must refuse a socket it could not write to');
            } catch (\Throwable $e) {
                self::assertInstanceOf(
                    ChannelError::class,
                    $e,
                    'the raise must be swallowed and re-stated — rethrowing it hands fwrite\'s own frame to the caller',
                );

                $seen = $e->getMessage() . "\n" . $e->getTraceAsString();
                foreach ([self::PASSWORD, base64_encode(self::PASSWORD), self::USER, base64_encode(self::USER)] as $needle) {
                    self::assertStringNotContainsString($needle, $seen, 'leaked ' . substr($needle, 0, 6));
                }
                self::assertStringNotContainsString(substr(base64_encode(self::PASSWORD), 0, 15), $seen, 'nor the truncated prefix');
            }
        } finally {
            stream_wrapper_unregister('scoutthrows');
            ini_set('zend.exception_ignore_args', (string) $ignore);
            ini_set('zend.exception_string_param_max_len', (string) $budget);
        }
    }

    /** @return resource */
    private static function throwingStream(): mixed
    {
        $stream = fopen('scoutthrows://credential', 'w');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * THE CONSTRUCTOR IS A SURFACE NO PER-SITE FIX REACHED, and it leaks the most of any of them.
     *
     * Three call sites were repaired one at a time and a review panel then found this one. Each
     * constructor parameter carries its OWN 15-character budget, so the username no longer spends
     * it first and the password arrives in clear text — fifteen characters, against the two that
     * made `ImapMailbox::login()` a finding. Latent, because three `(int)` casts on the port keep
     * the binding from raising; "latent behind a cast nobody asserts" is this repo's own definition
     * of luck rather than a guard.
     *
     * The answer is structural and lives at the entrypoint: `bin/scout` sets
     * `zend.exception_ignore_args=1`, which removes EVERY argument from EVERY frame in that process
     * — including surfaces nobody has enumerated. Asserted here in both directions, because a guard
     * that only checks the line is present cannot show the line does anything.
     */
    public function testTheEntrypointSuppressesEveryArgumentInEveryTrace(): void
    {
        // COMMENT LINES ARE STRIPPED, and their absence made this assertion vacuous (C2 round 4):
        // commenting the line OUT left the whole file green while the guarantee was dead. This same
        // file strips comments in two other assertions and names the trap — and it was still made
        // here, which is why the behavioural halves below are the ones that carry the weight.
        $lines = preg_split('/\R/', (string) file_get_contents(__DIR__ . '/../../../bin/scout')) ?: [];
        $entrypoint = implode("\n", array_filter(
            $lines,
            static fn (string $l): bool => preg_match('~^\s*(//|#|\*|/\*)~', $l) !== 1,
        ));

        self::assertStringContainsString(
            "ini_set('zend.exception_ignore_args', '1');",
            $entrypoint,
            'bin/scout must suppress trace arguments before anything can throw',
        );

        // WITHOUT it — this process — the constructor leaks. Without this half the assertion above
        // is satisfied by a line that does nothing on some future runtime.
        $leaked = $this->constructorTrace(false);
        self::assertStringContainsString(
            substr(self::PASSWORD, 0, 15),
            $leaked,
            'the constructor surface is real on this runtime, which is why the entrypoint sets the flag',
        );

        // WITH it, the same construction prints no arguments at all.
        $suppressed = $this->constructorTrace(true);
        self::assertStringNotContainsString(self::PASSWORD, $suppressed);
        self::assertStringNotContainsString(substr(self::PASSWORD, 0, 15), $suppressed);
        self::assertStringContainsString('__construct()', $suppressed, 'the frame is there, its arguments are not');
    }

    /**
     * Construct `ImapMailbox` from a STRICT-TYPES caller with a mistyped port, in a subprocess.
     *
     * A subprocess because `zend.exception_ignore_args` is process-wide and this suite runs without
     * it; strict types because the production call sites are strict, and it is the binding failure
     * that raises with the arguments still on the frame.
     */
    private function constructorTrace(bool $suppress): string
    {
        $probe = sys_get_temp_dir() . '/scout-ctor-' . bin2hex(random_bytes(6)) . '.php';
        $strict = $probe . '.strict.php';

        file_put_contents($strict, "<?php\ndeclare(strict_types=1);\ntry { new Scout\\Adapters\\Mail\\ImapMailbox('h', 'annette-the-subscriber', '" . self::PASSWORD . "', 'INBOX', '993'); }\ncatch (Throwable \$e) { echo \$e->getTraceAsString(); }\n");
        // The UNSUPPRESSED half creates the printing runtime explicitly (a production ini, as on the
        // CI runner, would otherwise satisfy the "suppressed" assertion for free and fail the
        // "leaked" one) — the same two directives the in-process premise test sets.
        $preamble = $suppress
            ? "ini_set('zend.exception_ignore_args', '1');\n"
            : "ini_set('zend.exception_ignore_args', '0');\nini_set('zend.exception_string_param_max_len', '15');\n";
        file_put_contents($probe, "<?php\n" . $preamble . "require '" . __DIR__ . "/../../../vendor/autoload.php';\nrequire '" . $strict . "';\n");

        try {
            return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
        } finally {
            @unlink($probe);
            @unlink($strict);
        }
    }

    /**
     * THE STRUCTURAL HALF, tying the code to the mechanism above so the shape cannot return.
     *
     * Comment lines are stripped first: both classes DOCUMENT the construction they no longer use,
     * and a naive grep reads the documentation of a guarantee as its violation — a red run already
     * paid for once in `tests/test-dump-eml.sh`.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function forbiddenConstructions(): iterable
    {
        yield 'IMAP LOGIN through the generic command helper' => [
            __DIR__ . '/../../../src/php/Adapters/Mail/ImapMailbox.php',
            '~\$this->command\(\s*.LOGIN~',
        ];
        yield 'SMTP credential as a say() argument' => [
            __DIR__ . '/../../../src/php/Core/Notify/SmtpTransport.php',
            '~\$this->say\([^)]*base64_encode~',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenConstructions')]
    public function testTheConstructionCannotComeBack(string $path, string $pattern): void
    {
        $code = (string) file_get_contents($path);
        $lines = preg_split('/\R/', $code) ?: [];
        $codeOnly = implode("\n", array_filter(
            $lines,
            static fn (string $l): bool => preg_match('~^\s*(\*|/\*|//|#)~', $l) !== 1,
        ));

        self::assertSame(0, preg_match($pattern, $codeOnly), 'the credential is a call argument again');
    }

    /** @return resource */
    private static function closedStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fclose($stream);

        return $stream;
    }
}

/**
 * A stream whose every write RAISES. `@fwrite` suppresses diagnostics, not exceptions, so this is
 * the only way to reach `SmtpTransport::writeCredential()`'s `catch` — a closed stream returns
 * `false` without ever throwing, which is why that branch had no test for as long as it existed.
 */
final class ThrowingStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        throw new \RuntimeException('the socket went away mid-write');
    }

    public function stream_close(): void {}

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
