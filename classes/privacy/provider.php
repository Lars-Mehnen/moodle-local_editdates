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
 * Privacy provider for local_editdates.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\privacy;


/**
 * The plugin stores no personal data of its own.
 *
 * It reads and writes course configuration dates; the actor of a write is recorded
 * by the standard Moodle log, not by this plugin.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Reason why this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
