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
 * Summary of a date change plan.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Counts the outcomes of a plan so a caller can branch without walking the list.
 */
final class change_report {
    /**
     * Count the plan records per status.
     *
     * @param array $changes Plan records.
     * @return array Counts keyed by planned, applied, unchanged, error.
     */
    public static function summary(array $changes): array {
        $summary = [
            'planned' => 0,
            'applied' => 0,
            'unchanged' => 0,
            'error' => 0,
        ];
        foreach ($changes as $change) {
            $status = $change['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }
        return $summary;
    }
}
