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
 * Reading and patching date conditions inside an availability tree.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for the "available from" / "available until" dates of sections and activities.
 *
 * Those dates are not columns; they are `date` conditions inside the availability
 * JSON of `course_sections.availability` / `course_modules.availability`. This class
 * reads them and patches them back while leaving every other condition untouched.
 *
 * A tree is only considered writable when its meaning is unambiguous: a top level
 * AND operator, at most one date condition per direction, and no date condition
 * hidden inside a nested subtree. Anything else is reported read-only with a reason
 * so a caller can fall back to the Moodle UI instead of guessing.
 */
final class availability {
    /** @var string Address key of the "available from" date. */
    const KEY_FROM = 'availablefrom';

    /** @var string Address key of the "available until" date. */
    const KEY_UNTIL = 'availableuntil';

    /** @var string Direction of a "from" date condition. */
    const DIRECTION_FROM = '>=';

    /** @var string Direction of an "until" date condition. */
    const DIRECTION_UNTIL = '<';

    /**
     * Read the date conditions of an availability tree.
     *
     * @param string|null $json Raw availability JSON, empty or null when unrestricted.
     * @return array from, until (0 when not set), editable and reason ('' when editable).
     */
    public static function read(?string $json): array {
        $result = ['from' => 0, 'until' => 0, 'editable' => true, 'reason' => ''];

        if ($json === null || trim($json) === '') {
            return $result;
        }

        $tree = json_decode($json);
        if (!is_object($tree) || !isset($tree->c) || !is_array($tree->c)) {
            $result['editable'] = false;
            $result['reason'] = 'unreadableavailability';
            return $result;
        }

        $seen = [self::DIRECTION_FROM => 0, self::DIRECTION_UNTIL => 0];
        foreach ($tree->c as $condition) {
            if (self::subtree_has_date($condition)) {
                // A date buried in a nested tree: report it, never rewrite it.
                $result['editable'] = false;
                $result['reason'] = 'nesteddateavailability';
                continue;
            }
            if (!self::is_date_condition($condition)) {
                continue;
            }
            $seen[$condition->d]++;
            if ($condition->d === self::DIRECTION_FROM) {
                $result['from'] = (int) $condition->t;
            } else {
                $result['until'] = (int) $condition->t;
            }
        }

        if ($result['editable'] && (isset($tree->op) && $tree->op !== '&')) {
            $result['editable'] = false;
            $result['reason'] = 'notandavailability';
        }

        if ($result['editable']
                && ($seen[self::DIRECTION_FROM] > 1 || $seen[self::DIRECTION_UNTIL] > 1)) {
            $result['editable'] = false;
            $result['reason'] = 'duplicatedateavailability';
        }

        return $result;
    }

    /**
     * Return an availability tree with the date conditions replaced.
     *
     * Every non-date condition and its individual "display greyed out" flag is kept.
     *
     * @param string|null $json Current availability JSON.
     * @param int $from New "available from" timestamp, 0 to remove the condition.
     * @param int $until New "available until" timestamp, 0 to remove the condition.
     * @return string|null New JSON, or null when no condition is left at all.
     */
    public static function write(?string $json, int $from, int $until): ?string {
        $current = self::read($json);
        if (!$current['editable']) {
            throw new \coding_exception('Refusing to patch a non-editable availability tree: '
                . $current['reason']);
        }

        $tree = ($json === null || trim($json) === '') ? null : json_decode($json);
        if (!is_object($tree)) {
            $tree = (object) ['op' => '&', 'c' => [], 'showc' => []];
        }
        if (!isset($tree->c) || !is_array($tree->c)) {
            $tree->c = [];
        }
        if (!isset($tree->op)) {
            $tree->op = '&';
        }

        $usesshowc = property_exists($tree, 'showc') && is_array($tree->showc);
        $keptconditions = [];
        $keptshowc = [];
        $showcfor = [self::DIRECTION_FROM => true, self::DIRECTION_UNTIL => true];

        foreach ($tree->c as $index => $condition) {
            $showc = $usesshowc && array_key_exists($index, $tree->showc)
                ? (bool) $tree->showc[$index] : true;
            if (self::is_date_condition($condition)) {
                // Remember how this date used to be displayed, then drop it.
                $showcfor[$condition->d] = $showc;
                continue;
            }
            $keptconditions[] = $condition;
            $keptshowc[] = $showc;
        }

        if ($from > 0) {
            $keptconditions[] = (object) ['type' => 'date', 'd' => self::DIRECTION_FROM, 't' => $from];
            $keptshowc[] = $showcfor[self::DIRECTION_FROM];
        }
        if ($until > 0) {
            $keptconditions[] = (object) ['type' => 'date', 'd' => self::DIRECTION_UNTIL, 't' => $until];
            $keptshowc[] = $showcfor[self::DIRECTION_UNTIL];
        }

        if (!$keptconditions) {
            return null;
        }

        $tree->c = $keptconditions;
        if ($usesshowc || $tree->op === '&' || $tree->op === '!|') {
            $tree->showc = $keptshowc;
        }

        $newjson = json_encode($tree);
        self::assert_valid_tree($newjson);

        return $newjson;
    }

    /**
     * Whether a condition is a date condition.
     *
     * @param mixed $condition Decoded condition.
     * @return bool
     */
    private static function is_date_condition($condition): bool {
        return is_object($condition)
            && isset($condition->type) && $condition->type === 'date'
            && isset($condition->d) && isset($condition->t)
            && in_array($condition->d, [self::DIRECTION_FROM, self::DIRECTION_UNTIL], true);
    }

    /**
     * Whether a nested subtree contains a date condition at any depth.
     *
     * @param mixed $condition Decoded condition.
     * @return bool
     */
    private static function subtree_has_date($condition): bool {
        if (!is_object($condition) || !isset($condition->c) || !is_array($condition->c)) {
            return false;
        }
        foreach ($condition->c as $child) {
            if (self::is_date_condition($child) || self::subtree_has_date($child)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Make sure Moodle itself accepts the tree we built.
     *
     * @param string $json Availability JSON.
     */
    private static function assert_valid_tree(string $json): void {
        if (!class_exists('\core_availability\tree')) {
            return;
        }
        $decoded = json_decode($json);
        // Throws \coding_exception when the structure is not a valid tree.
        new \core_availability\tree($decoded);
    }
}
