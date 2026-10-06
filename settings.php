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
 * Administration settings for local_editdates.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_editdates', get_string('pluginname', 'local_editdates'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_editdates/maxupdates',
        get_string('maxupdates', 'local_editdates'),
        get_string('maxupdates_desc', 'local_editdates'),
        \local_editdates\external\guard::DEFAULT_MAX_UPDATES,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_editdates/maxshiftdays',
        get_string('maxshiftdays', 'local_editdates'),
        get_string('maxshiftdays_desc', 'local_editdates'),
        \local_editdates\external\guard::DEFAULT_MAX_SHIFT_DAYS,
        PARAM_INT
    ));
}
