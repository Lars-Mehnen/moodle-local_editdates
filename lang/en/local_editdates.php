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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'local_editdates', language 'en'.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['availablefrom'] = 'Access restricted from';
$string['availableuntil'] = 'Access restricted until';
$string['editdates:edit'] = 'Change course dates through the dates API';
$string['editdates:view'] = 'Read course dates through the dates API';
$string['erroroffsettoolarge'] = 'The requested offset of {$a->days} days exceeds the '
    . 'configured maximum of {$a->max} days.';
$string['erroroffsetzero'] = 'An offset of zero seconds would not change anything.';
$string['errortoomanyupdates'] = 'The request covers {$a->count} dates, the configured '
    . 'maximum is {$a->max}.';
$string['eventdatesupdated'] = 'Course dates updated through the API';
$string['maxshiftdays'] = 'Maximum shift (days)';
$string['maxshiftdays_desc'] = 'Upper limit for the offset accepted by the shift function, in days. '
    . 'This is the guard against a wrong unit in an automated request.';
$string['maxupdates'] = 'Maximum dates per call';
$string['maxupdates_desc'] = 'Upper limit for the number of dates one API call may change. '
    . 'A larger request is rejected instead of partly applied.';
$string['missingreportplugin'] = 'The Dates report (report_editdates) is not installed. '
    . 'This API reuses its per-module date definitions and cannot work without it.';
$string['pluginname'] = 'Course dates API';






$string['privacy:metadata'] = 'The Course dates API plugin stores no personal data. It reads and '
    . 'writes course configuration dates; who changed what is recorded in the standard Moodle log.';
$string['reason_availabilitydisabled'] = 'Restricted access is disabled on this site.';
$string['reason_duplicatedateavailability'] = 'The item has more than one restriction date in the '
    . 'same direction, so the one to change is ambiguous.';
$string['reason_enddatebeforestartdate'] = 'The course end date would be before the course start date.';
$string['reason_fromafteruntil'] = 'The "from" date would be at or after the "until" date.';
$string['reason_generalsection'] = 'The general section cannot have access-restriction dates.';
$string['reason_invalidvalue'] = 'A date must be a positive Unix timestamp, or 0 to switch it off.';
$string['reason_modulevalidation'] = 'The activity rejected the new dates.';
$string['reason_nesteddateavailability'] = 'A date is nested inside a grouped access restriction; '
    . 'edit it in the Moodle interface so the grouping stays intact.';
$string['reason_nopermission'] = 'You do not have permission to change this date.';
$string['reason_nostartdate'] = 'The course start date cannot be removed.';
$string['reason_notandavailability'] = 'The access restrictions are combined with "or", where '
    . 'changing a single date would change their meaning; edit them in the Moodle interface.';
$string['reason_noteditable'] = 'This date cannot be changed through the API.';
$string['reason_notoptional'] = 'This date is required and cannot be switched off.';
$string['reason_unknowndate'] = 'There is no such date in this course.';
$string['reason_unreadableavailability'] = 'The access restrictions of this item cannot be parsed.';
