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
 * Shared return structures of the local_editdates web services.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\external;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;


/**
 * Return structures reused by the read and the write functions.
 *
 * Dates are addressed the same way everywhere: the triple target/id/key that the
 * read function reports is exactly what the write functions accept.
 */
final class structures {
    /**
     * One date of the course, a section or an activity.
     *
     * @return external_single_structure
     */
    public static function date(): external_single_structure {
        return new external_single_structure([
            'key' => new external_value(
                PARAM_ALPHANUMEXT,
                'Address key of this date, e.g. duedate, timeclose, availablefrom'
            ),
            'label' => new external_value(
                PARAM_TEXT,
                'Label in the response language; use key for automation'
            ),
            'value' => new external_value(
                PARAM_INT,
                'Unix timestamp, 0 when the date is not set'
            ),
            'source' => new external_value(
                PARAM_ALPHA,
                'Where the date is stored: course, module, completion or availability'
            ),
            'type' => new external_value(
                PARAM_ALPHA,
                'date when only the day is used, datetime when the time matters'
            ),
            'optional' => new external_value(
                PARAM_BOOL,
                'Whether 0 (switched off) is an accepted value'
            ),
            'editable' => new external_value(
                PARAM_BOOL,
                'Whether this API may write the date'
            ),
            'reason' => new external_value(
                PARAM_ALPHANUMEXT,
                'Why the date is not editable, empty when it is'
            ),
        ]);
    }

    /**
     * One record of a change plan.
     *
     * @return external_single_structure
     */
    public static function change(): external_single_structure {
        return new external_single_structure([
            'target' => new external_value(PARAM_ALPHA, 'course, section or module'),
            'id' => new external_value(PARAM_INT, 'Section id, course module id, or 0 for the course'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Address key of the date'),
            'label' => new external_value(PARAM_TEXT, 'Label of the date'),
            'name' => new external_value(PARAM_TEXT, 'Name of the course, section or activity'),
            'oldvalue' => new external_value(PARAM_INT, 'Timestamp before the change'),
            'newvalue' => new external_value(PARAM_INT, 'Requested timestamp'),
            'status' => new external_value(
                PARAM_ALPHA,
                'planned (dry run), applied, unchanged or error'
            ),
            'code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Machine readable reason for a rejected change, empty otherwise'
            ),
            'message' => new external_value(
                PARAM_TEXT,
                'Human readable reason for a rejected change, empty otherwise'
            ),
        ]);
    }

    /**
     * Counts per plan status.
     *
     * @return external_single_structure
     */
    public static function summary(): external_single_structure {
        return new external_single_structure([
            'planned' => new external_value(PARAM_INT, 'Changes that would be written'),
            'applied' => new external_value(PARAM_INT, 'Changes that were written'),
            'unchanged' => new external_value(PARAM_INT, 'Requests that match the current value'),
            'error' => new external_value(PARAM_INT, 'Rejected requests'),
        ]);
    }

    /**
     * The result envelope of a write function.
     *
     * @return external_single_structure
     */
    public static function write_result(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'dryrun' => new external_value(
                PARAM_BOOL,
                'True when nothing was written and the changes are a preview'
            ),
            'summary' => self::summary(),
            'changes' => new external_multiple_structure(self::change()),
        ]);
    }
}
