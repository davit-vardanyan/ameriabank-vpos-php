<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests;

use function array_filter;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function fclose;
use function file_exists;
use function file_put_contents;
use function getenv;
use function implode;
use function is_resource;
use function is_string;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function proc_close;
use function proc_open;
use function random_bytes;
use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;
use function sort;

use SplFileInfo;

use function sprintf;
use function str_starts_with;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * `probe/` and `backup/` are untracked, and stay untracked, held against git
 * itself rather than against a reading of `.gitignore`.
 *
 * ## Why this guard exists
 *
 * `probe/report-payment.json` holds real CONVENTIONS.md §6 data: a real
 * `ProcessingIP` — the address of the machine that ran the probe — a real
 * `ClientName`, which is the cardholder's own name, a masked PAN, an `ExpDate`
 * and an `ApprovalCode`. A copy of the same file sits under `backup/probe/`.
 * That data is evidence: the §4 findings this package is built on cite it, and
 * redacting it would break the citations. It is therefore kept, in full, and
 * kept **out of git**.
 *
 * Which means the whole of its safety is one property — that git does not know
 * those two directories exist — and until this file was written **nothing
 * asserted it**. It was true, and it was true by nobody's continuing decision.
 *
 * ## Untracked is not the same mechanism as `export-ignore`
 *
 * CONVENTIONS.md §7 draws the line and this guard sits on one side of it. A
 * file listed in `.gitattributes` as `export-ignore` is **tracked**: git knows
 * it, a clone gets it, and only `git archive` leaves it out. A file matched by
 * `.gitignore` is **untracked**: there is nothing for it to travel in, so it
 * reaches neither a clone, nor an archive, nor a dist.
 *
 * §7 says why the difference is worth writing down — read the two as one
 * mechanism and the natural "fix" for this directory is an `export-ignore`
 * entry, which would change nothing at all while looking like a safeguard, and
 * would in fact require tracking the file first. `probe/` and `backup/` carry
 * no `.gitattributes` entry deliberately. This guard pins the mechanism they
 * do use.
 *
 * ## Why git is asked, and not `.gitignore`
 *
 * The subjects are read out of git's own index and working tree at test time.
 * Nothing here lists a filename, so a probe report added tomorrow is covered
 * the moment it lands, and AL-003's failure mode — a hand-maintained list that
 * silently exempts everything not on it — has no purchase.
 *
 * Parsing `.gitignore` was the obvious alternative and is the wrong one twice
 * over. It would re-implement git's pattern matcher, and a matcher that is
 * subtly wrong reads exactly like a clean tree; and a file force-added with
 * `git add -f` is tracked **while still matching an ignore rule**, so a reader
 * of `.gitignore` would confirm the rule, find it intact, and report green over
 * a staged payment report.
 *
 * ## Four questions, because one does not cover it
 *
 * Each is a different way the property can be lost, and no one command sees
 * more than its own:
 *
 * - **Tracked now.** `git ls-files -- probe backup`. The task's check, and the
 *   direct one: anything the index holds under either directory.
 * - **Still ignored.** `git ls-files --others --exclude-standard -- probe
 *   backup`. Files present on disk that **no** ignore rule covers — so an edit
 *   that weakens or deletes the `/probe/` line fails here *before* anyone runs
 *   `git add`, rather than after. `ls-files` alone cannot see this: nothing is
 *   tracked yet, so it stays green right up to the commit that exposes the
 *   data.
 * - **Nothing anywhere defies an ignore rule.** `git ls-files --cached
 *   --ignored --exclude-standard`, over the whole tree, with no pathspec and so
 *   no hand-maintained input of any kind. This is what a `git add -f` looks
 *   like from git's side, wherever it happened.
 * - **Never in history.** `git log --all -- probe backup`. The three above read
 *   the index and the working tree, which a `git rm --cached` empties while
 *   leaving every byte in the object store and in every clone that already
 *   pulled it. A guard that cannot tell "never committed" from "committed and
 *   then tidied up" is not the guard this data needs.
 *
 * `git check-ignore` was considered for the second question and is weaker than
 * `--others --exclude-standard`: it answers about a path handed to it, so it
 * needs a path list to hand it — which is the hand-maintained subject list this
 * file exists without.
 *
 * ## What happens where there is no `.git`
 *
 * These tests **skip**, loudly and by name, and only in the one case where the
 * question is not merely unanswered but meaningless: the repository root holds
 * no `.git` entry, so there is no index, and "tracked" is not a property this
 * copy of the tree has. Every other inability is a failure — git missing, git
 * erroring, git answering about a different repository. The distinction is
 * between *cannot apply* and *could not check*, and only the first is a skip.
 *
 * Two notes on that. `tests/` is `export-ignore`d, so `git archive` output has
 * no suite to run and the archive case is hypothetical; a consumer running the
 * suite from a git-less copy of the source tree is not. And the check is not
 * attempted anyway in that case, deliberately: git resolves upwards, so a tree
 * unpacked inside another repository would be answered about **that** index and
 * would come back green for entirely the wrong reason.
 * `testTheGuardIsAnsweringAboutThisRepositoryAndNotAnEnclosingOne()` is what
 * makes the wrong-repository answer impossible rather than unlikely.
 *
 * ## What this guard does not catch
 *
 * Stated plainly, because a guard that overstates its reach is worse than none:
 *
 * - **A shallow clone weakens the history question and says nothing about it.**
 *   `git log --all` can only walk the commits present. Under a CI checkout with
 *   `fetch-depth: 1` it will find nothing because there is nothing to find, and
 *   report green. That is not asserted against here — failing a build for a
 *   checkout depth would be failing for a condition unrelated to the invariant
 *   — so read a green history check as "not in the history this clone has".
 * - **A file that left the working tree.** `--others` can only see what is on
 *   disk. A probe report deleted locally is not reported as unignored, because
 *   it is not there to be ignored.
 * - **Anything already pushed.** All four questions are about this clone. Once
 *   a commit is out, no local check recalls it; the history question exists to
 *   make that visible early, never to undo it.
 * - **Whether the data ought to be redacted.** This repository keeps it
 *   unredacted, because the findings in CONVENTIONS.md §4 cite it and a
 *   redacted record would not support them. This file holds the containment
 *   that decision depends on, and takes no view on the decision itself.
 */
#[CoversNothing]
final class UntrackedDirectoriesTest extends TestCase
{
    /**
     * The directories whose safety is *containment* rather than redaction.
     *
     * Two entries, hand-written, each with its reason — AL-003 permits that
     * only where the source of truth cannot name the subject, and nothing in
     * the tree can. `.gitignore` lists these beside `/vendor/`, `.env` and the
     * tool caches; a pattern list records that a path is not committed, never
     * why, and "holds §6 evidence" is a classification no file here makes.
     * Everything downstream of these two names — which files, which rules cover
     * them — is read out of git.
     *
     * The third and fourth questions above take no pathspec at all and so do
     * not consult this list.
     *
     * @var list<string>
     */
    private const array LOCAL_EVIDENCE_DIRECTORIES = [
        // backup/ — the local archive. Holds a copy of probe/, report-payment.json
        // included, so it carries the same §6 data at a second path.
        'backup',
        // probe/ — the discovery probes and their reports. report-payment.json
        // carries a real ProcessingIP, a real ClientName, a masked PAN, an
        // ExpDate and an ApprovalCode from a completed sandbox payment.
        'probe',
    ];

    /**
     * The guard proper: git's index holds nothing under either directory.
     */
    public function testNeitherProbeNorBackupIsTracked(): void
    {
        $root = $this->repositoryRoot();

        $tracked = $this->pathsFromGit($root, ['ls-files', '-z', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES]);

        self::assertSame(
            [],
            $tracked,
            'These paths are in git\'s index and must not be. probe/ and backup/ hold real §6 data — a real '
            . 'ProcessingIP, a cardholder name, a masked PAN, an ExpDate and an ApprovalCode from a live '
            . 'sandbox payment — kept unredacted because the CONVENTIONS.md §4 findings cite it, and kept '
            . 'safe only by never entering git. Un-track each with `git rm --cached <path>`, which leaves '
            . 'the file on disk, and check that nothing has been committed yet. Adding an export-ignore '
            . 'entry is not the fix: that mechanism applies to tracked files and would leave these in every '
            . 'clone (CONVENTIONS.md §7).'
            . $this->asList($tracked, 'git ls-files -- ' . implode(' ', self::LOCAL_EVIDENCE_DIRECTORIES)),
        );
    }

    /**
     * Every file present under either directory is covered by an ignore rule.
     *
     * This is the half `git ls-files` cannot see. Delete the `/probe/` line
     * from `.gitignore` and nothing becomes tracked — so the index stays clean,
     * the guard above stays green, and the exposure arrives with the next
     * `git add .` by someone who had no reason to look. Here it fails at the
     * edit.
     */
    public function testEveryFilePresentUnderProbeAndBackupIsCoveredByAnIgnoreRule(): void
    {
        $root = $this->repositoryRoot();

        $unignored = $this->pathsFromGit(
            $root,
            ['ls-files', '-z', '--others', '--exclude-standard', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
        );

        self::assertSame(
            [],
            $unignored,
            'These files exist under probe/ or backup/ and no ignore rule covers them, so the next `git add` '
            . 'that touches the directory stages real §6 data. Nothing is tracked yet — which is exactly why '
            . 'this is checked separately from the index: an ignore rule that has been weakened or deleted '
            . 'leaves the index clean and looks like a clean tree. Restore the `/probe/` and `/backup/` '
            . 'entries in .gitignore.'
            . $this->asList($unignored, 'git ls-files --others --exclude-standard -- ' . implode(' ', self::LOCAL_EVIDENCE_DIRECTORIES)),
        );
    }

    /**
     * Nothing anywhere in the tree is tracked in defiance of an ignore rule.
     *
     * No pathspec, no list, no input from this file at all: git is asked which
     * tracked files its own exclude rules say should not be there. That is the
     * shape a `git add -f` leaves behind, wherever it happened — which makes
     * this the one question that would still catch a force-added
     * `probe/report-payment.json` if the two names above were ever edited out
     * of this file.
     *
     * It is deliberately wider than probe/ and backup/. A tracked-and-ignored
     * file is a contradiction somewhere regardless of which directory it is in,
     * and a red here asks for a decision rather than presuming one.
     */
    public function testNoIgnoredFileAnywhereInTheTreeIsTracked(): void
    {
        $root = $this->repositoryRoot();

        $forceAdded = $this->pathsFromGit($root, ['ls-files', '-z', '--cached', '--ignored', '--exclude-standard']);

        self::assertSame(
            [],
            $forceAdded,
            'These files are tracked and are also matched by an ignore rule, which only happens deliberately '
            . '— `git add -f`. Either the file should not be tracked, or the ignore rule should not match it; '
            . 'the two statements contradict each other and this guard does not guess which one was meant. If '
            . 'the path is under probe/ or backup/ it carries §6 data and must be un-tracked with '
            . '`git rm --cached <path>`.'
            . $this->asList($forceAdded, 'git ls-files --cached --ignored --exclude-standard'),
        );
    }

    /**
     * No commit reachable from any ref ever touched either directory.
     *
     * `git rm --cached` empties the index and leaves the object store untouched,
     * so the three questions above all go green over data that is already in
     * every clone. This one asks the store. Its limit under a shallow clone is
     * stated on the class docblock and is not asserted against.
     */
    public function testNoCommitInHistoryEverTouchedProbeOrBackup(): void
    {
        $root = $this->repositoryRoot();

        $commits = $this->linesFromGit(
            $root,
            ['log', '--all', '--format=%H %s', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
        );

        self::assertSame(
            [],
            $commits,
            'These commits touch probe/ or backup/, so the §6 data those directories hold is in the object '
            . 'store and in every clone taken since. Un-tracking the file now does not recall it. Treat the '
            . 'sandbox credentials and the payment record as disclosed, and raise it rather than rewriting '
            . 'history quietly — anyone who has already fetched keeps their copy either way.'
            . $this->asList($commits, 'git log --all -- ' . implode(' ', self::LOCAL_EVIDENCE_DIRECTORIES)),
        );
    }

    /**
     * The answers above came from this repository.
     *
     * git resolves upwards. A tree unpacked inside another checkout, or a
     * `.git` that has been moved away while an enclosing repository exists,
     * would answer every question in this file from **that** index — cleanly,
     * and about the wrong thing. Green would then mean nothing, and would look
     * exactly like green meaning something.
     */
    public function testTheGuardIsAnsweringAboutThisRepositoryAndNotAnEnclosingOne(): void
    {
        $root = $this->repositoryRoot();

        $toplevel = realpath(trim($this->gitOutput($root, ['rev-parse', '--show-toplevel'])));

        self::assertSame(
            $root,
            $toplevel,
            'git answered about a repository whose root is not this package\'s root, so every other check in '
            . 'this file was reading someone else\'s index. That happens when the tree sits inside another '
            . 'checkout and its own .git has gone missing.',
        );
    }

    /**
     * The tracked check reports a force-added file, executed rather than read.
     *
     * A fixture repository, built in a temporary directory and never this one:
     * the mutation the guard claims to catch is performed on every run instead
     * of once, by hand, at review time. `git add -f` is the only way to stage a
     * path an ignore rule covers, and it is what a well-meaning "the tests need
     * this fixture" commit does.
     *
     * The ignore rule is left in place, so this also pins the interaction the
     * class docblock names: the file is tracked **and** ignored at once, which
     * is why reading `.gitignore` cannot answer this question.
     */
    public function testTheTrackedCheckReportsAForceAddedFileInAFixtureRepository(): void
    {
        $fixture = $this->fixtureRepository();

        try {
            $this->gitOutput($fixture, ['add', '-f', 'probe/report-payment.json'], $this->hermeticEnvironment($fixture));

            self::assertSame(
                ['probe/report-payment.json'],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                    $this->hermeticEnvironment($fixture),
                ),
                'A file staged under probe/ must be reported by the tracked check.',
            );

            self::assertSame(
                ['probe/report-payment.json'],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--cached', '--ignored', '--exclude-standard'],
                    $this->hermeticEnvironment($fixture),
                ),
                'The same file is tracked while still matching /probe/, which is what the tree-wide check '
                . 'looks for and what a reading of .gitignore would miss.',
            );

            self::assertSame(
                [],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--others', '--exclude-standard', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                    $this->hermeticEnvironment($fixture),
                ),
                'The ignore check must stay silent here: the rule is intact, and a staged file is not an '
                . '"other" file. The two questions are separate and must fail separately.',
            );
        } finally {
            $this->removeTree($fixture);
        }
    }

    /**
     * The ignore check reports a file no rule covers any more.
     *
     * The failure this pins is the quiet one. Nothing is staged, nothing is
     * committed, the index is clean and the tracked check is green — the only
     * thing that changed is a line in `.gitignore`, and the exposure is waiting
     * for whoever next types `git add .`.
     */
    public function testTheIgnoreCheckReportsAFileNoRuleCoversAnyMore(): void
    {
        $fixture = $this->fixtureRepository();

        try {
            file_put_contents($fixture . '/.gitignore', "/vendor/\n");

            self::assertSame(
                [],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                    $this->hermeticEnvironment($fixture),
                ),
                'Nothing is staged, so the tracked check is green — which is the whole point of asking the '
                . 'second question.',
            );

            self::assertSame(
                ['backup/probe/report-payment.json', 'probe/report-payment.json'],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--others', '--exclude-standard', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                    $this->hermeticEnvironment($fixture),
                ),
                'With the rules gone, every file under both directories must be reported — both copies of '
                . 'the payment report, not just the first one found.',
            );
        } finally {
            $this->removeTree($fixture);
        }
    }

    /**
     * The history check reports a path that was committed and then un-tracked.
     *
     * This is the state the other three questions cannot tell from clean: the
     * commit stands, the object store holds every byte, and `git ls-files`
     * reports nothing at all.
     */
    public function testTheHistoryCheckReportsAPathThatWasCommittedAndThenUnTracked(): void
    {
        $fixture = $this->fixtureRepository();
        $environment = $this->hermeticEnvironment($fixture);

        try {
            $this->gitOutput($fixture, ['add', '-f', 'probe/report-payment.json'], $environment);
            $this->gitOutput(
                $fixture,
                [
                    '-c', 'user.name=Guard',
                    '-c', 'user.email=guard@example.invalid',
                    '-c', 'commit.gpgsign=false',
                    'commit', '-q', '-m', 'accidental',
                ],
                $environment,
            );
            $this->gitOutput($fixture, ['rm', '-q', '--cached', 'probe/report-payment.json'], $environment);

            self::assertSame(
                [],
                $this->pathsFromGit(
                    $fixture,
                    ['ls-files', '-z', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                    $environment,
                ),
                'The index is empty again, so every index-reading check reads clean over a commit that is '
                . 'still there. That is the gap the history check exists to close.',
            );

            $commits = $this->linesFromGit(
                $fixture,
                ['log', '--all', '--format=%s', '--', ...self::LOCAL_EVIDENCE_DIRECTORIES],
                $environment,
            );

            self::assertSame(
                ['accidental'],
                $commits,
                'The commit that added the file must still be reported after it has been un-tracked.',
            );
        } finally {
            $this->removeTree($fixture);
        }
    }

    /**
     * The repository root, or a skip if this copy of the tree has no git index.
     *
     * Skipped and never passed: a guard that returns green when it could not
     * look is indistinguishable from one that looked and found nothing, which
     * is the decorative-guard shape this suite has paid for repeatedly. Every
     * other inability — git absent, git erroring, git answering about another
     * repository — is a failure, in `gitOutput()` and in
     * `testTheGuardIsAnsweringAboutThisRepositoryAndNotAnEnclosingOne()`.
     *
     * `.git` is tested with `file_exists()` rather than `is_dir()` because a
     * linked worktree and a submodule carry it as a file holding a `gitdir:`
     * pointer, and both are real repositories.
     */
    private function repositoryRoot(): string
    {
        $root = realpath(__DIR__ . '/..');

        self::assertIsString($root, 'The repository root does not resolve to a real path.');

        if (!file_exists($root . '/.git')) {
            self::markTestSkipped(sprintf(
                '%s holds no .git entry, so this copy of the tree has no index and "tracked" is not a '
                . 'property it has. Nothing is checked and nothing is claimed. Re-run inside a clone. The '
                . 'check is not attempted anyway because git resolves upwards: a tree sitting inside another '
                . 'checkout would be answered about that repository\'s index and would come back green for '
                . 'the wrong reason.',
                $root,
            ));
        }

        return $root;
    }

    /**
     * A throwaway repository shaped like this one's containment problem: two
     * ignored directories, each holding a file under the name that matters.
     *
     * Nothing here touches the real `probe/`. The fixture's file is a stub with
     * the real file's *name* and none of its content — the point being
     * exercised is git's behaviour toward a path, and putting anything
     * resembling §6 data in a temporary directory to test a guard about §6 data
     * would be the guard defeating itself.
     */
    private function fixtureRepository(): string
    {
        $root = sys_get_temp_dir() . '/vpos-untracked-guard-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($root . '/backup/probe', 0o700, true), sprintf('Could not create %s.', $root));
        self::assertTrue(mkdir($root . '/probe', 0o700, true));

        file_put_contents($root . '/.gitignore', "/probe/\n/backup/\n");
        file_put_contents($root . '/probe/report-payment.json', "{\"stub\":true}\n");
        file_put_contents($root . '/backup/probe/report-payment.json', "{\"stub\":true}\n");
        file_put_contents($root . '/README.md', "fixture\n");

        $this->gitOutput($root, ['init', '-q', '-b', 'main'], $this->hermeticEnvironment($root));

        return $root;
    }

    /**
     * An environment in which git reads no configuration but the fixture's own.
     *
     * The developer's global `core.excludesFile` would otherwise decide what
     * `--exclude-standard` covers, and a machine that globally ignores `*.json`
     * would turn `testTheIgnoreCheckReportsAFileNoRuleCoversAnyMore()` red for
     * a reason that has nothing to do with this package. `HOME` and
     * `XDG_CONFIG_HOME` point into the fixture, which holds no config; system
     * config is switched off outright.
     *
     * The checks against the real repository run under the inherited
     * environment instead, deliberately: there the guard should see exactly
     * what the developer's own git sees.
     *
     * @return array<string, string>
     */
    private function hermeticEnvironment(string $fixture): array
    {
        $path = getenv('PATH');

        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'HOME' => $fixture,
            'PATH' => is_string($path) ? $path : '/usr/bin:/bin',
            'XDG_CONFIG_HOME' => $fixture,
        ];
    }

    /**
     * The NUL-separated paths a `-z` listing produced, sorted.
     *
     * `-z` rather than plain output because git quotes and escapes a pathname
     * holding a newline, a quote or a non-ASCII byte, and a guard that has to
     * un-quote is a guard with a parser in it.
     *
     * @param list<string>               $arguments
     * @param array<string, string>|null $environment
     *
     * @return list<string>
     */
    private function pathsFromGit(string $root, array $arguments, ?array $environment = null): array
    {
        return $this->sortedNonEmpty(explode("\0", $this->gitOutput($root, $arguments, $environment)));
    }

    /**
     * The lines a listing produced, sorted. Used where the output is not a
     * pathname and `-z` does not apply.
     *
     * @param list<string>               $arguments
     * @param array<string, string>|null $environment
     *
     * @return list<string>
     */
    private function linesFromGit(string $root, array $arguments, ?array $environment = null): array
    {
        return $this->sortedNonEmpty(explode("\n", $this->gitOutput($root, $arguments, $environment)));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sortedNonEmpty(array $values): array
    {
        $kept = array_values(array_filter($values, static fn(string $value): bool => $value !== ''));

        sort($kept);

        return $kept;
    }

    /**
     * git's standard output, with a non-zero exit treated as a failure of this
     * guard rather than as an empty answer.
     *
     * An empty answer is what every assertion here expects, so a git that
     * failed to run would satisfy all four of them. That is the precise shape
     * of a guard that has stopped guarding, and it is why the status is checked
     * before the output is read.
     *
     * **Standard error is a file, not a second pipe, and that is deliberate.**
     * Two blocking pipes cannot both be drained by one reader: draining stdout
     * to EOF first lets a child that fills the stderr buffer — roughly 64 KiB —
     * block on its own write while this end is still reading the other stream,
     * and neither side moves again. That would **hang** the suite rather than
     * fail it, which is a worse outcome than a false green in a guard whose
     * whole purpose is to fail loudly. No git command this file runs produces
     * bulk stderr today, so the deadlock is latent; a file descriptor removes
     * the possibility rather than relying on that staying true.
     *
     * @param list<string>               $arguments
     * @param array<string, string>|null $environment
     */
    private function gitOutput(string $root, array $arguments, ?array $environment = null): string
    {
        $errorLog = tempnam(sys_get_temp_dir(), 'vpos-git-stderr-');

        self::assertIsString(
            $errorLog,
            'Could not create a temporary file for git\'s standard error. This guard reports that rather '
            . 'than running git without somewhere to put its diagnostics.',
        );

        $pipes = [];

        $process = proc_open(
            ['git', '-C', $root, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'w']],
            $pipes,
            $root,
            $environment,
        );

        if (!is_resource($process)) {
            unlink($errorLog);

            self::fail(sprintf(
                'Could not start git. This guard cannot answer whether probe/ and backup/ are tracked '
                . 'without it, and reports that rather than passing: `git %s`.',
                implode(' ', $arguments),
            ));
        }

        $stdout = is_resource($pipes[1] ?? null) ? stream_get_contents($pipes[1]) : false;

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_close($process);

        $stderr = file_get_contents($errorLog);

        unlink($errorLog);

        self::assertSame(
            0,
            $status,
            sprintf(
                'git exited %d and this guard treats that as a failure, never as an empty answer — every '
                . 'assertion here expects empty output, so a git that did not run would satisfy all of them. '
                . "Command: git %s\nstderr: %s",
                $status,
                implode(' ', $arguments),
                is_string($stderr) ? trim($stderr) : '(unreadable)',
            ),
        );

        return is_string($stdout) ? $stdout : '';
    }

    /**
     * The offending paths, named, with the command that lists all of them.
     *
     * A bare `assertSame([], $output)` prints a diff and tells the next reader
     * nothing about which file is exposed. The list is capped so a deleted
     * ignore rule does not bury the message under a hundred paths, and the
     * count and the reproducing command carry what the cap drops.
     *
     * @param list<string> $paths
     */
    private function asList(array $paths, string $command): string
    {
        $shown = array_slice($paths, 0, 25);
        $remainder = count($paths) - count($shown);

        return sprintf("\n%d path(s), listed in full by `%s`:\n  - %s%s\n", count($paths), $command, implode("\n  - ", $shown), $remainder > 0 ? sprintf("\n  … and %d more", $remainder) : '');
    }

    /**
     * Deletes a fixture tree, refusing to touch anything outside the system
     * temporary directory.
     *
     * A recursive delete is needed because `git init` creates a `.git/` whose
     * contents this file cannot enumerate in advance — and a recursive delete
     * in a test that also knows the path of a directory holding real §6 data is
     * exactly the place to assert where it is allowed to point.
     */
    private function removeTree(string $root): void
    {
        $temporary = realpath(sys_get_temp_dir());
        $resolved = realpath($root);

        self::assertIsString($temporary, 'The system temporary directory does not resolve to a real path.');
        self::assertIsString($resolved, sprintf('Refusing to delete %s: it does not resolve to a real path.', $root));

        // Both sides are resolved before they are compared. On macOS
        // sys_get_temp_dir() answers /var/folders/… while realpath() of the
        // same directory answers /private/var/folders/…, so comparing the
        // unresolved path against the resolved prefix rejects every legitimate
        // fixture — and a check that always says no protects nothing, it just
        // leaves the trees behind.
        self::assertTrue(
            str_starts_with($resolved, $temporary . '/'),
            sprintf('Refusing to delete %s: a fixture tree lives under %s and nowhere else.', $resolved, $temporary),
        );

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                rmdir($entry->getPathname());

                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($resolved);
    }
}
