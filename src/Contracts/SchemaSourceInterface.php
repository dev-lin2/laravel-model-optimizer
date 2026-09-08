<?php

namespace Devlin\ModelAnalyzer\Contracts;

use Devlin\ModelAnalyzer\Schema\SchemaSnapshot;

/**
 * A provider of schema information, normalized into a SchemaSnapshot.
 *
 * Implementations MUST NOT throw from snapshot(). A source that cannot be
 * read returns SchemaSnapshot::unavailable() so that callers can always
 * render "missing" or "empty" instead of failing.
 */
interface SchemaSourceInterface
{
    /**
     * Build the normalized snapshot. Never throws.
     *
     * @return SchemaSnapshot
     */
    public function snapshot();

    /**
     * Stable identifier used in output and in --source matching.
     *
     * @return string
     */
    public function name();
}
