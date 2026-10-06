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
 * External function: shift the dates of a course.
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
 * Move every date of a course by a fixed offset.
 *
 * This is the "the course runs a week later" operation. Dates that are switched
 * off stay off, dates this API cannot write are reported instead of skipped
 * silently, and the function defaults to a dry run.
 */
final class shift_dates extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'offsetseconds' => new external_value(
                PARAM_INT,
                'Offset in seconds, negative to move dates earlier'
            ),
            'sectionnums' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Section number'),
                'Restrict to these section numbers, empty for the whole course',
                VALUE_DEFAULT,
                []
            ),
            'activitytypes' => new external_multiple_structure(
                new external_value(PARAM_PLUGIN, 'Module name'),
                'Restrict to these module names, empty for all activities',
                VALUE_DEFAULT,
                []
            ),
            'includecourse' => new external_value(
                PARAM_BOOL,
                'Also shift the course start and end date',
                VALUE_DEFAULT,
                false
            ),
            'includeavailability' => new external_value(
                PARAM_BOOL,
                'Also shift access-restriction dates of sections and activities',
                VALUE_DEFAULT,
                true
            ),
            'preservetimeofday' => new external_value(
                PARAM_BOOL,
                'Shift a whole-day offset as calendar days so the local time of day survives a '
                    . 'daylight-saving change; other offsets are always applied literally',
                VALUE_DEFAULT,
                true
            ),
            'dryrun' => new external_value(
                PARAM_BOOL,
                'Report the planned changes without writing them (default)',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Plan and optionally apply the shift.
     *
     * @param int $courseid Course ID.
     * @param int $offsetseconds Offset in seconds.
     * @param array $sectionnums Section numbers to restrict to.
     * @param array $activitytypes Module names to restrict to.
     * @param bool $includecourse Whether to shift the course dates too.
     * @param bool $includeavailability Whether to shift access-restriction dates too.
     * @param bool $preservetimeofday Whether whole-day offsets use calendar arithmetic.
     * @param bool $dryrun Whether to only report the plan.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $offsetseconds,
        array $sectionnums = [],
        array $activitytypes = [],
        bool $includecourse = false,
        bool $includeavailability = true,
        bool $preservetimeofday = true,
        bool $dryrun = true
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'offsetseconds' => $offsetseconds,
            'sectionnums' => $sectionnums,
            'activitytypes' => $activitytypes,
            'includecourse' => $includecourse,
            'includeavailability' => $includeavailability,
            'preservetimeofday' => $preservetimeofday,
            'dryrun' => $dryrun,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/editdates:view', $context);
        require_capability('local/editdates:edit', $context);

        if ($params['offsetseconds'] === 0) {
            // A named exception, so a consumer sees erroroffsetzero rather than the
            // generic "invalid parameter value" that carries no hint.
            throw new \moodle_exception('erroroffsetzero', 'local_editdates');
        }
        guard::check_offset($params['offsetseconds']);

        $writer = new date_writer($course);
        $changes = $writer->plan_shift(
            $params['offsetseconds'],
            $params['sectionnums'],
            $params['activitytypes'],
            (bool) $params['includecourse'],
            (bool) $params['includeavailability'],
            (bool) $params['preservetimeofday']
        );

        guard::check_batch_size(count($changes));

        if (!$params['dryrun']) {
            $changes = $writer->apply($changes);
            guard::log_write($context, 'local_editdates_shift_dates', $changes);
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
