<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Batch limits and audit logging for the write functions.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\external;

use local_editdates\event\dates_updated;
use local_editdates\local\change_report;


/**
 * Guards around the write path.
 *
 * The limits exist because the caller may be an automated agent: a wrong unit in
 * an offset, or a loop that addresses a whole site, should hit a hard stop rather
 * than rewrite a term's worth of deadlines.
 */
final class guard {
    /** @var int Default maximum number of dates in one call. */
    const DEFAULT_MAX_UPDATES = 500;

    /** @var int Default maximum shift in days. */
    const DEFAULT_MAX_SHIFT_DAYS = 366;

    /**
     * Reject batches that are larger than the configured maximum.
     *
     * @param int $count Number of dates in the request.
     */
    public static function check_batch_size(int $count): void {
        $max = (int) (get_config('local_editdates', 'maxupdates') ?: self::DEFAULT_MAX_UPDATES);
        if ($count > $max) {
            throw new \moodle_exception(
                'errortoomanyupdates',
                'local_editdates',
                '',
                (object) ['count' => $count, 'max' => $max]
            );
        }
    }

    /**
     * Reject offsets that are larger than the configured maximum.
     *
     * @param int $offsetseconds Requested offset.
     */
    public static function check_offset(int $offsetseconds): void {
        $maxdays = (int) (get_config('local_editdates', 'maxshiftdays')
            ?: self::DEFAULT_MAX_SHIFT_DAYS);
        $maxseconds = $maxdays * DAYSECS;
        if (abs($offsetseconds) > $maxseconds) {
            throw new \moodle_exception(
                'erroroffsettoolarge',
                'local_editdates',
                '',
                (object) ['days' => round($offsetseconds / DAYSECS, 1), 'max' => $maxdays]
            );
        }
    }

    /**
     * Log an applied write in the standard Moodle log.
     *
     * @param \context_course $context Course context.
     * @param string $function Web service function name.
     * @param array $changes Plan records after applying.
     */
    public static function log_write(\context_course $context, string $function, array $changes): void {
        $summary = change_report::summary($changes);
        if ($summary['applied'] === 0) {
            return;
        }
        $event = dates_updated::create([
            'context' => $context,
            'courseid' => $context->instanceid,
            'other' => [
                'function' => $function,
                'applied' => $summary['applied'],
                'failed' => $summary['error'],
            ],
        ]);
        $event->trigger();
    }
}
