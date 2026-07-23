<?php

declare(strict_types=1);

namespace App\Supporters;

/**
 * Where one attempt at importing a list has got to.
 *
 * The states exist so that an operator who uploaded a file can be told
 * something true at every moment, which is the whole reason the import keeps a
 * record at all. Queued work reports nothing back to the request that started
 * it, so "nothing on the page has changed" would otherwise be the answer to a
 * file still being read, a file finished, and a file that died an hour ago.
 *
 * Pending and Running are deliberately separate. A job can sit in the queue for
 * a long time behind other work, and "we have not started yet" is a different
 * and less alarming answer than "we started and have not finished", especially
 * when no worker happens to be running.
 */
enum ImportStatus: string
{
    /**
     * The file is stored and its headers are read, and nothing has been done
     * with it because nobody has said which column is which.
     *
     * The state every import arrives in. An import never leaves it by itself:
     * the mapping is the operator's statement about their own file, and this
     * application does not guess one.
     */
    case AwaitingMapping = 'awaiting_mapping';

    /**
     * The operator has stated the mapping and the job is queued.
     */
    case Pending = 'pending';

    /**
     * A worker has picked the job up and is reading the file.
     */
    case Running = 'running';

    /**
     * The file was read to the end.
     *
     * Not the same as "every row became a supporter" -- rows without a usable
     * address are skipped and counted, and the count is shown, because an
     * import that silently dropped a tenth of a file is worse than one that
     * refused it.
     */
    case Completed = 'completed';

    /**
     * The run stopped before the end of the file, and the record says why.
     *
     * Whatever was written before the failure stays written. The alternative --
     * unwinding an import in one transaction -- would mean holding a
     * transaction open across a file of arbitrary size, and would throw away
     * the thousands of supporters that were read correctly because of one
     * malformed row near the end.
     */
    case Failed = 'failed';

    /**
     * The status an import has when nothing says otherwise.
     *
     * Named here rather than left as a literal at each call site so that the
     * database default and the application cannot drift apart. The migration
     * still hardcodes its own copy -- it has to stay frozen -- and a test pins
     * the two together.
     */
    public static function default(): self
    {
        return self::AwaitingMapping;
    }

    /**
     * Whether this import has reached a state it will not leave on its own.
     *
     * The question the page asks to decide whether to keep polling, answered
     * here rather than by each surface listing the terminal cases -- a surface
     * that listed them would be one new case away from polling forever.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
