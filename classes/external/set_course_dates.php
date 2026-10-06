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
 * External function: set individual course dates.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_editdates\local\change_report;
use local_editdates\local\date_writer;


/**
 * Set individual dates of a course.
 *
 * Each requested change is addressed by the target/id/key triple that
 * local_editdates_get_course_dates reports. The function defaults to a dry run:
 * a caller has to pass dryrun=0 explicitly to write anything, and the returned
 * diff has the same shape either way.
 */
final class set_course_dates extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'updates' => new external_multiple_structure(new external_single_structure([
                'target' => new external_value(PARAM_ALPHA, 'course, section or module'),
                'id' => new external_value(
                    PARAM_INT,
                    'Section id for section, course module id for module, 0 for course'
                ),
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Address key of the date'),
                'value' => new external_value(
                    PARAM_INT,
                    'New Unix timestamp, or 0 to switch an optional date off'
                ),
            ]), 'Dates to set'),
            'dryrun' => new external_value(
                PARAM_BOOL,
                'Report the planned changes without writing them (default)',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Plan and optionally apply the changes.
     *
     * @param int $courseid Course ID.
     * @param array $updates Requested changes.
     * @param bool $dryrun Whether to only report the plan.
     * @return array
     */
    public static function execute(int $courseid, array $updates, bool $dryrun = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'updates' => $updates,
            'dryrun' => $dryrun,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/editdates:view', $context);
        require_capability('local/editdates:edit', $context);

        guard::check_batch_size(count($params['updates']));

        $writer = new date_writer($course);
        $changes = $writer->plan($params['updates']);

        if (!$params['dryrun']) {
            $changes = $writer->apply($changes);
            guard::log_write($context, 'local_editdates_set_course_dates', $changes);
        }

        return [
            'courseid' => (int) $course->id,
            'dryrun' => (bool) $params['dryrun'],
            'summary' => change_report::summary($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return structures::write_result();
    }
}
