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
 * Event fired when course dates were changed through the API.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Course dates updated through the web service API.
 *
 * Automated consumers leave no trace in the Dates report, so every applied write
 * is logged here with the number of changed dates and the function that did it.
 */
class dates_updated extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventdatesupdated', 'local_editdates');
    }

    /**
     * Event description for the log.
     *
     * @return string
     */
    public function get_description(): string {
        $applied = $this->other['applied'] ?? 0;
        $failed = $this->other['failed'] ?? 0;
        $function = $this->other['function'] ?? '';
        return "The user with id '{$this->userid}' changed {$applied} date(s) in the course with id "
            . "'{$this->courseid}' through '{$function}' ({$failed} rejected).";
    }

    /**
     * Link to the Dates report of the course.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/report/editdates/index.php', ['id' => $this->courseid]);
    }
}
