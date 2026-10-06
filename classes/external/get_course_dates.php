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
 * External function: read the dates of a course.
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
use local_editdates\local\date_collector;


/**
 * Return every addressable date of a course.
 *
 * Read-only. Reports the course start and end date, the access-restriction dates
 * of each section and the dates of each activity, together with whether this API
 * may write them and why not. Contains course configuration only, no personal data.
 */
final class get_course_dates extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'activitytype' => new external_value(
                PARAM_PLUGIN,
                'Restrict activities to one module name, e.g. quiz; empty for all',
                VALUE_DEFAULT,
                ''
            ),
            'includeinvisible' => new external_value(
                PARAM_BOOL,
                'Include sections and activities that are hidden from the calling user',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Collect the dates.
     *
     * @param int $courseid Course ID.
     * @param string $activitytype Module name filter.
     * @param bool $includeinvisible Whether to include hidden items.
     * @return array
     */
    public static function execute(
        int $courseid,
        string $activitytype = '',
        bool $includeinvisible = false
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'activitytype' => $activitytype,
            'includeinvisible' => $includeinvisible,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/editdates:view', $context);

        return (new date_collector($course))->collect(
            $params['activitytype'],
            (bool) $params['includeinvisible']
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
            'format' => new external_value(PARAM_PLUGIN, 'Course format, e.g. weeks or topics'),
            'servertime' => new external_value(
                PARAM_INT,
                'Server time when the report was built; use it as the reference for relative dates'
            ),
            'timezone' => new external_value(
                PARAM_TIMEZONE,
                'Site timezone, e.g. Europe/Vienna; the zone the stored timestamps are shown in'
            ),
            'enableavailability' => new external_value(
                PARAM_BOOL,
                'Whether restricted access is enabled on this site'
            ),
            'enablecompletion' => new external_value(
                PARAM_BOOL,
                'Whether completion tracking is enabled in this course'
            ),
            'course' => new external_single_structure([
                'canedit' => new external_value(PARAM_BOOL, 'Whether the caller may update the course'),
                'dates' => new external_multiple_structure(structures::date()),
            ]),
            'sections' => new external_multiple_structure(new external_single_structure([
                'sectionid' => new external_value(PARAM_INT, 'Section id, used as the target id'),
                'sectionnum' => new external_value(PARAM_INT, 'Position of the section in the course'),
                'name' => new external_value(PARAM_TEXT, 'Section name'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the section is visible'),
                'canedit' => new external_value(
                    PARAM_BOOL,
                    'Whether this API may write the section dates'
                ),
                'hasotherrestrictions' => new external_value(
                    PARAM_BOOL,
                    'Whether the section has access restrictions other than dates'
                ),
                'dates' => new external_multiple_structure(structures::date()),
            ])),
            'activities' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id, used as the target id'),
                'modname' => new external_value(PARAM_PLUGIN, 'Module name, e.g. quiz'),
                'instance' => new external_value(PARAM_INT, 'Module instance id'),
                'idnumber' => new external_value(
                    PARAM_RAW,
                    'Activity ID number, empty when unset; usable as an ownership marker'
                ),
                'name' => new external_value(PARAM_TEXT, 'Activity name'),
                'sectionnum' => new external_value(PARAM_INT, 'Section the activity is in'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible'),
                'canedit' => new external_value(
                    PARAM_BOOL,
                    'Whether the caller may manage this activity'
                ),
                'hasotherrestrictions' => new external_value(
                    PARAM_BOOL,
                    'Whether the activity has access restrictions other than dates'
                ),
                'dates' => new external_multiple_structure(structures::date()),
            ])),
            'warnings' => new external_multiple_structure(new external_single_structure([
                'item' => new external_value(PARAM_TEXT, 'Item the warning refers to'),
                'message' => new external_value(PARAM_TEXT, 'Warning message'),
            ])),
        ]);
    }
}
