<?php

namespace Sitetus\WP\Readers;

/**
 * The wall-clock budget one module run may spend on database reads. A
 * query is only sent while the budget is open; once it is exhausted the
 * remaining fields come back as null and the envelope says so.
 */
final class ReadBudget
{
    /** @var float */
    private $startedAt;

    /** @var float */
    private $seconds;

    /** @var bool */
    private $exhausted = false;

    public function __construct(float $seconds)
    {
        $this->seconds = $seconds;
        $this->startedAt = microtime(true);
    }

    public function open(): bool
    {
        if (! $this->exhausted && microtime(true) - $this->startedAt > $this->seconds) {
            $this->exhausted = true;
        }

        return ! $this->exhausted;
    }

    public function exhausted(): bool
    {
        $this->open();

        return $this->exhausted;
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
