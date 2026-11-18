<?php

namespace App\Services\Ledger;

/**
 * A due-date change that is not allowed (ineligible charge, date outside the
 * permitted window, past month...). Rendered as HTTP 422 with the reason.
 */
class DueDateChangeRefused extends \RuntimeException {}
