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
 * Web service definitions for local_editdates.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_editdates_get_course_dates' => [
        'classname' => 'local_editdates\external\get_course_dates',
        'methodname' => 'execute',
        'description' => 'Return every editable date of a course: course start/end, '
            . 'section access-restriction dates and activity dates, each with a stable '
            . 'target/key address that the write functions accept.',
        'type' => 'read',
        'capabilities' => 'local/editdates:view',
        'ajax' => true,
    ],
    'local_editdates_set_course_dates' => [
        'classname' => 'local_editdates\external\set_course_dates',
        'methodname' => 'execute',
        'description' => 'Set individual dates of a course by target/key address. '
            . 'Defaults to a dry run that reports the planned before/after diff without writing.',
        'type' => 'write',
        'capabilities' => 'local/editdates:edit',
        'ajax' => true,
    ],
    'local_editdates_shift_dates' => [
        'classname' => 'local_editdates\external\shift_dates',
        'methodname' => 'execute',
        'description' => 'Shift all dates of a course (optionally restricted to sections or '
            . 'activity types) by a number of seconds. Defaults to a dry run.',
        'type' => 'write',
        'capabilities' => 'local/editdates:edit',
        'ajax' => true,
    ],
];

$services = [
    'Course dates API' => [
        'functions' => [
            'local_editdates_get_course_dates',
            'local_editdates_set_course_dates',
            'local_editdates_shift_dates',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'local_editdates_ws',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
