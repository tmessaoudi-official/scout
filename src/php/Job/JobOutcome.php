<?php

declare(strict_types=1);

namespace Scout\Job;

/** What becomes of an offer: pushed, or rejected and logged — there is no doubt bin in this domain. */
enum JobOutcome: string
{
    case MATCH = 'MATCH';
    case REJECT = 'REJECT';
}
