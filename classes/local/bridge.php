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
 * Access to the date extractors of report_editdates.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin bridge to report_editdates.
 *
 * The per-module knowledge about which columns hold dates, how they are labelled
 * and how they must be validated and saved lives in report_editdates. This plugin
 * reuses it instead of duplicating it, so a module that works in the Dates report
 * works in this API too, including modules that ship their own
 * mod_x_report_editdates_integration class.
 */
final class bridge {
    /** @var bool Whether the report library has been loaded already. */
    private static bool $loaded = false;

    /**
     * Load the report_editdates library.
     *
     * @return void
     */
    public static function load(): void {
        global $CFG;

        if (self::$loaded) {
            return;
        }
        $library = $CFG->dirroot . '/report/editdates/lib.php';
        if (!file_exists($library)) {
            throw new \moodle_exception('missingreportplugin', 'local_editdates');
        }
        require_once($library);
        self::$loaded = true;
    }

    /**
     * Return the date extractor for a module type, or null when the module has no dates.
     *
     * @param string $modname Module name, e.g. 'quiz'.
     * @param \stdClass $course Course record.
     * @return \report_editdates_mod_date_extractor|null
     */
    public static function extractor(string $modname, \stdClass $course) {
        self::load();
        return \report_editdates_mod_date_extractor::make($modname, $course);
    }

    /**
     * Return the date settings of one course module, or an empty array.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $course Course record.
     * @return \report_editdates_date_setting[] Keyed by date name, e.g. 'duedate'.
     */
    public static function settings(\cm_info $cm, \stdClass $course): array {
        $extractor = self::extractor($cm->modname, $course);
        if (!$extractor) {
            return [];
        }
        $settings = $extractor->get_settings($cm);
        return is_array($settings) ? $settings : [];
    }
}
