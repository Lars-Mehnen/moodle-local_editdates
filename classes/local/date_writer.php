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
 * Plans and applies date changes for a course.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Write model for the course dates API.
 *
 * Every write goes through two steps. `plan()` resolves each requested change
 * against the current state, checks permissions and runs the module's own date
 * validation without touching the database. `apply()` then executes a plan in a
 * single transaction. A dry run is simply a plan that is never applied, so the
 * diff a caller inspects is produced by the same code that performs the write.
 */
final class date_writer {
    /** @var string The change will be written. */
    const STATUS_PLANNED = 'planned';

    /** @var string The change was written. */
    const STATUS_APPLIED = 'applied';

    /** @var string The requested value equals the current value. */
    const STATUS_UNCHANGED = 'unchanged';

    /** @var string The change was rejected. */
    const STATUS_ERROR = 'error';

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \context_course Course context. */
    private \context_course $coursecontext;

    /** @var array|null Cached read model. */
    private ?array $model = null;

    /**
     * Constructor.
     *
     * @param \stdClass $course Course record.
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
        $this->coursecontext = \context_course::instance($course->id);
    }

    /**
     * Resolve requested changes into a plan, without writing anything.
     *
     * @param array $updates List of ['target' => string, 'id' => int, 'key' => string, 'value' => int].
     * @return array List of change records.
     */
    public function plan(array $updates): array {
        $model = $this->model();
        $changes = [];

        foreach ($updates as $update) {
            $target = (string) ($update['target'] ?? '');
            $id = (int) ($update['id'] ?? 0);
            $key = (string) ($update['key'] ?? '');
            $value = (int) ($update['value'] ?? 0);

            if ($target === date_collector::TARGET_COURSE) {
                // The course can be addressed by id or by 0; store it as 0.
                $id = 0;
            }

            $entry = $this->find_date($model, $target, $id, $key);
            if ($entry === null) {
                $changes[] = $this->change($target, $id, $key, '', '', 0, $value,
                    self::STATUS_ERROR, 'unknowndate');
                continue;
            }
            [$owner, $date] = $entry;

            $change = $this->change($target, $id, $key, $date['label'], $owner['name'],
                (int) $date['value'], $value, self::STATUS_PLANNED, '');

            if ($value < 0) {
                $change['status'] = self::STATUS_ERROR;
                $change['code'] = 'invalidvalue';
            } else if (!$date['editable']) {
                $change['status'] = self::STATUS_ERROR;
                $change['code'] = $date['reason'] !== '' ? $date['reason'] : 'noteditable';
            } else if ($value === 0 && !$date['optional']) {
                $change['status'] = self::STATUS_ERROR;
                $change['code'] = 'notoptional';
            } else if ($value === (int) $date['value']) {
                $change['status'] = self::STATUS_UNCHANGED;
            }

            $changes[] = $change;
        }

        $changes = $this->validate_plan($changes);

        foreach ($changes as &$change) {
            if ($change['status'] === self::STATUS_ERROR && $change['message'] === '') {
                $change['message'] = self::message_for($change['code']);
            }
        }
        unset($change);

        return $changes;
    }

    /**
     * Build a plan that moves every date in scope by an offset.
     *
     * Dates that are switched off (value 0) stay off: shifting them would invent
     * a restriction that the course does not currently have.
     *
     * @param int $offsetseconds Offset in seconds, may be negative.
     * @param int[] $sectionnums Restrict to these section numbers, empty for all.
     * @param string[] $activitytypes Restrict to these module names, empty for all.
     * @param bool $includecourse Also shift the course start and end date.
     * @param bool $includeavailability Also shift access-restriction dates of sections and activities.
     * @param bool $preservetimeofday Shift whole-day offsets as calendar days, see shifted().
     * @return array List of change records.
     */
    public function plan_shift(
        int $offsetseconds,
        array $sectionnums = [],
        array $activitytypes = [],
        bool $includecourse = false,
        bool $includeavailability = true,
        bool $preservetimeofday = true
    ): array {
        $model = $this->model();
        $updates = [];

        if ($includecourse) {
            foreach ($model['course']['dates'] as $date) {
                if ($date['value'] > 0 && $date['editable']) {
                    $updates[] = [
                        'target' => date_collector::TARGET_COURSE,
                        'id' => 0,
                        'key' => $date['key'],
                        'value' => self::shifted($date['value'], $offsetseconds, $preservetimeofday),
                    ];
                }
            }
        }

        // Section dates are course structure, not an activity type: a request that
        // narrows to quizzes should not move the dates of the section around them.
        if ($includeavailability && !$activitytypes) {
            foreach ($model['sections'] as $section) {
                if ($sectionnums && !in_array($section['sectionnum'], $sectionnums, true)) {
                    continue;
                }
                foreach ($section['dates'] as $date) {
                    if ($date['value'] > 0 && $date['editable']) {
                        $updates[] = [
                            'target' => date_collector::TARGET_SECTION,
                            'id' => $section['sectionid'],
                            'key' => $date['key'],
                            'value' => self::shifted($date['value'], $offsetseconds, $preservetimeofday),
                        ];
                    }
                }
            }
        }

        foreach ($model['activities'] as $activity) {
            if ($sectionnums && !in_array($activity['sectionnum'], $sectionnums, true)) {
                continue;
            }
            if ($activitytypes && !in_array($activity['modname'], $activitytypes, true)) {
                continue;
            }
            foreach ($activity['dates'] as $date) {
                if ($date['value'] <= 0 || !$date['editable']) {
                    continue;
                }
                if (!$includeavailability && $date['source'] === date_collector::SOURCE_AVAILABILITY) {
                    continue;
                }
                $updates[] = [
                    'target' => date_collector::TARGET_MODULE,
                    'id' => $activity['cmid'],
                    'key' => $date['key'],
                    'value' => self::shifted($date['value'], $offsetseconds, $preservetimeofday),
                ];
            }
        }

        return $this->plan($updates);
    }

    /**
     * Move a single timestamp.
     *
     * A whole number of days is added as calendar days, so a date keeps its local
     * time of day when the shift crosses a daylight-saving change: "one week later"
     * should stay 23:59, not become 22:59. Offsets that are not whole days are
     * added literally, because there the caller clearly means seconds.
     *
     * @param int $value Current timestamp.
     * @param int $offsetseconds Offset in seconds.
     * @param bool $preservetimeofday Whether to use calendar arithmetic for whole days.
     * @return int New timestamp.
     */
    private static function shifted(int $value, int $offsetseconds, bool $preservetimeofday): int {
        if ($preservetimeofday && $offsetseconds % DAYSECS === 0) {
            $days = intdiv($offsetseconds, DAYSECS);
            return (int) strtotime(($days < 0 ? '-' : '+') . abs($days) . ' days', $value);
        }
        return $value + $offsetseconds;
    }

    /**
     * Apply the planned changes of a plan.
     *
     * @param array $changes Plan as returned by plan() or plan_shift().
     * @return array The same records with applied changes marked.
     */
    public function apply(array $changes): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $planned = array_filter($changes, function ($change) {
            return $change['status'] === self::STATUS_PLANNED;
        });
        if (!$planned) {
            return $changes;
        }

        $modinfo = get_fast_modinfo($this->course);
        $cms = $modinfo->get_cms();
        $touchedmodules = [];

        $transaction = $DB->start_delegated_transaction();
        try {
            // Course start and end date.
            $coursedates = $this->collect_values($planned, date_collector::TARGET_COURSE, 0);
            if ($coursedates) {
                $data = (object) [
                    'id' => $this->course->id,
                    'startdate' => $coursedates['startdate'] ?? (int) $this->course->startdate,
                    'enddate' => $coursedates['enddate'] ?? (int) $this->course->enddate,
                ];
                update_course($data);
            }

            // Section access-restriction dates.
            foreach ($this->target_ids($planned, date_collector::TARGET_SECTION) as $sectionid) {
                $sectioninfo = $modinfo->get_section_info_by_id($sectionid);
                $current = availability::read($sectioninfo->availability);
                $values = $this->collect_values($planned, date_collector::TARGET_SECTION, $sectionid);
                $json = availability::write(
                    $sectioninfo->availability,
                    $values[availability::KEY_FROM] ?? $current['from'],
                    $values[availability::KEY_UNTIL] ?? $current['until']
                );
                \core_courseformat\formatactions::section($this->course)
                    ->update($sectioninfo, ['availability' => $json]);
            }

            // Activity dates.
            foreach ($this->target_ids($planned, date_collector::TARGET_MODULE) as $cmid) {
                if (!isset($cms[$cmid])) {
                    continue;
                }
                $cm = $cms[$cmid];
                $values = $this->collect_values($planned, date_collector::TARGET_MODULE, $cmid);
                $settings = bridge::settings($cm, $this->course);

                $moduledates = [];
                foreach ($settings as $key => $setting) {
                    $moduledates[$key] = array_key_exists($key, $values)
                        ? $values[$key] : (int) $setting->currentvalue;
                }
                if ($moduledates && array_intersect_key($values, $moduledates)) {
                    bridge::extractor($cm->modname, $this->course)->save_dates($cm, $moduledates);
                }

                $record = (object) ['id' => $cmid];
                $updatecm = false;
                if (array_key_exists('completionexpected', $values)) {
                    $record->completionexpected = $values['completionexpected'];
                    $updatecm = true;
                }
                if (array_key_exists(availability::KEY_FROM, $values)
                        || array_key_exists(availability::KEY_UNTIL, $values)) {
                    $current = availability::read($cm->availability);
                    $record->availability = availability::write(
                        $cm->availability,
                        $values[availability::KEY_FROM] ?? $current['from'],
                        $values[availability::KEY_UNTIL] ?? $current['until']
                    );
                    $updatecm = true;
                }
                if ($updatecm) {
                    $DB->update_record('course_modules', $record);
                }

                $touchedmodules[] = $cmid;
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        // The cache is rebuilt after the commit: an in-transaction rebuild could
        // otherwise cache data that a rollback discards.
        rebuild_course_cache($this->course->id, true);
        $this->model = null;

        $this->refresh_calendar($touchedmodules);

        foreach ($changes as &$change) {
            if ($change['status'] === self::STATUS_PLANNED) {
                $change['status'] = self::STATUS_APPLIED;
            }
        }
        unset($change);

        return $changes;
    }

    /**
     * Rebuild the calendar and completion events of the activities that changed.
     *
     * report_editdates leaves this to the individual extractors, which do it for
     * some modules only. Doing it here keeps the calendar in step for every module.
     *
     * @param int[] $cmids Course module ids.
     */
    private function refresh_calendar(array $cmids): void {
        global $DB;

        if (!$cmids) {
            return;
        }
        $modinfo = get_fast_modinfo($this->course->id);
        foreach (array_unique($cmids) as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
                $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                if ($instance) {
                    course_module_calendar_event_update_process($instance, $cm);
                }
            } catch (\Throwable $e) {
                debugging('local_editdates could not refresh calendar events for course module '
                    . $cmid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Run cross-field validation over a plan.
     *
     * @param array $changes Plan records.
     * @return array Plan records with validation failures marked.
     */
    private function validate_plan(array $changes): array {
        $model = $this->model();
        $modinfo = get_fast_modinfo($this->course);
        $cms = $modinfo->get_cms();

        // Course dates.
        $coursevalues = $this->collect_values($changes, date_collector::TARGET_COURSE, 0, true);
        if ($coursevalues) {
            $startdate = $coursevalues['startdate'] ?? (int) $this->course->startdate;
            $enddate = $coursevalues['enddate'] ?? (int) $this->course->enddate;
            if ($enddate > 0 && $startdate > 0 && $enddate < $startdate) {
                $changes = $this->reject($changes, date_collector::TARGET_COURSE, 0,
                    array_keys($coursevalues), 'enddatebeforestartdate');
            } else if ($startdate <= 0) {
                $changes = $this->reject($changes, date_collector::TARGET_COURSE, 0,
                    array_keys($coursevalues), 'nostartdate');
            }
        }

        // Availability windows must not be inverted.
        foreach ([date_collector::TARGET_SECTION, date_collector::TARGET_MODULE] as $target) {
            foreach ($this->target_ids($changes, $target, true) as $id) {
                $values = $this->collect_values($changes, $target, $id, true);
                $keys = array_intersect_key($values,
                    [availability::KEY_FROM => 1, availability::KEY_UNTIL => 1]);
                if (!$keys) {
                    continue;
                }
                $owner = $this->find_owner($model, $target, $id);
                if ($owner === null) {
                    continue;
                }
                $currentfrom = $this->owner_value($owner, availability::KEY_FROM);
                $currentuntil = $this->owner_value($owner, availability::KEY_UNTIL);
                $from = $values[availability::KEY_FROM] ?? $currentfrom;
                $until = $values[availability::KEY_UNTIL] ?? $currentuntil;
                if ($from > 0 && $until > 0 && $until <= $from) {
                    $changes = $this->reject($changes, $target, $id, array_keys($keys), 'fromafteruntil');
                }
            }
        }

        // Module date validation, delegated to report_editdates.
        foreach ($this->target_ids($changes, date_collector::TARGET_MODULE, true) as $cmid) {
            if (!isset($cms[$cmid])) {
                continue;
            }
            $cm = $cms[$cmid];
            $values = $this->collect_values($changes, date_collector::TARGET_MODULE, $cmid, true);
            $extractor = bridge::extractor($cm->modname, $this->course);
            if (!$extractor) {
                continue;
            }
            $settings = bridge::settings($cm, $this->course);
            $merged = [];
            $requested = [];
            foreach ($settings as $key => $setting) {
                $merged[$key] = array_key_exists($key, $values)
                    ? $values[$key] : (int) $setting->currentvalue;
                if (array_key_exists($key, $values)) {
                    $requested[] = $key;
                }
            }
            if (!$requested) {
                continue;
            }
            $errors = $extractor->validate_dates($cm, $merged);
            if (!is_array($errors) || !$errors) {
                continue;
            }
            foreach ($errors as $key => $message) {
                // Reject the requested date the module complains about; when the
                // complaint is about an untouched date, reject what was requested.
                $reject = in_array($key, $requested, true) ? [$key] : $requested;
                $changes = $this->reject($changes, date_collector::TARGET_MODULE, $cmid,
                    $reject, 'modulevalidation', (string) $message);
            }
        }

        return $changes;
    }

    /**
     * Mark plan records as rejected.
     *
     * @param array $changes Plan records.
     * @param string $target Target type.
     * @param int $id Target id.
     * @param string[] $keys Date keys to reject.
     * @param string $code Machine readable reason.
     * @param string $message Human readable reason, defaults to the string for $code.
     * @return array
     */
    private function reject(array $changes, string $target, int $id, array $keys,
            string $code, string $message = ''): array {
        foreach ($changes as &$change) {
            if ($change['status'] !== self::STATUS_PLANNED) {
                continue;
            }
            if ($change['target'] !== $target || $change['id'] !== $id) {
                continue;
            }
            if (!in_array($change['key'], $keys, true)) {
                continue;
            }
            $change['status'] = self::STATUS_ERROR;
            $change['code'] = $code;
            $change['message'] = $message !== '' ? $message : self::message_for($code);
        }
        unset($change);
        return $changes;
    }

    /**
     * Values of the plan records for one target, keyed by date key.
     *
     * @param array $changes Plan records.
     * @param string $target Target type.
     * @param int $id Target id.
     * @param bool $plannedonly Only consider records that are still planned.
     * @return array
     */
    private function collect_values(array $changes, string $target, int $id, bool $plannedonly = true): array {
        $values = [];
        foreach ($changes as $change) {
            if ($change['target'] !== $target || $change['id'] !== $id) {
                continue;
            }
            if ($plannedonly && $change['status'] !== self::STATUS_PLANNED) {
                continue;
            }
            $values[$change['key']] = (int) $change['newvalue'];
        }
        return $values;
    }

    /**
     * Distinct target ids of a target type in a plan.
     *
     * @param array $changes Plan records.
     * @param string $target Target type.
     * @param bool $plannedonly Only consider records that are still planned.
     * @return int[]
     */
    private function target_ids(array $changes, string $target, bool $plannedonly = true): array {
        $ids = [];
        foreach ($changes as $change) {
            if ($change['target'] !== $target) {
                continue;
            }
            if ($plannedonly && $change['status'] !== self::STATUS_PLANNED) {
                continue;
            }
            $ids[(int) $change['id']] = true;
        }
        return array_keys($ids);
    }

    /**
     * The cached read model of the course.
     *
     * @return array
     */
    private function model(): array {
        if ($this->model === null) {
            $this->model = (new date_collector($this->course))->collect('', false);
        }
        return $this->model;
    }

    /**
     * Find a date entry and its owner in the read model.
     *
     * @param array $model Read model.
     * @param string $target Target type.
     * @param int $id Target id.
     * @param string $key Date key.
     * @return array|null [owner, date] or null when the address is unknown.
     */
    private function find_date(array $model, string $target, int $id, string $key): ?array {
        $owner = $this->find_owner($model, $target, $id);
        if ($owner === null) {
            return null;
        }
        foreach ($owner['dates'] as $date) {
            if ($date['key'] === $key) {
                return [$owner, $date];
            }
        }
        return null;
    }

    /**
     * Find the course, section or activity a target address refers to.
     *
     * @param array $model Read model.
     * @param string $target Target type.
     * @param int $id Target id.
     * @return array|null
     */
    private function find_owner(array $model, string $target, int $id): ?array {
        if ($target === date_collector::TARGET_COURSE) {
            if ($id !== 0 && $id !== (int) $model['courseid']) {
                return null;
            }
            return $model['course'] + ['name' => $model['fullname']];
        }
        if ($target === date_collector::TARGET_SECTION) {
            foreach ($model['sections'] as $section) {
                if ($section['sectionid'] === $id) {
                    return $section;
                }
            }
            return null;
        }
        if ($target === date_collector::TARGET_MODULE) {
            foreach ($model['activities'] as $activity) {
                if ($activity['cmid'] === $id) {
                    return $activity;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * Current value of one date of an owner.
     *
     * @param array $owner Owner from the read model.
     * @param string $key Date key.
     * @return int
     */
    private function owner_value(array $owner, string $key): int {
        foreach ($owner['dates'] as $date) {
            if ($date['key'] === $key) {
                return (int) $date['value'];
            }
        }
        return 0;
    }

    /**
     * Build a plan record.
     *
     * @param string $target Target type.
     * @param int $id Target id.
     * @param string $key Date key.
     * @param string $label Human readable date label.
     * @param string $name Name of the course, section or activity.
     * @param int $oldvalue Current timestamp.
     * @param int $newvalue Requested timestamp.
     * @param string $status One of the STATUS_* constants.
     * @param string $code Machine readable reason, '' when there is none.
     * @return array
     */
    private function change(string $target, int $id, string $key, string $label, string $name,
            int $oldvalue, int $newvalue, string $status, string $code): array {
        return [
            'target' => $target,
            'id' => $id,
            'key' => $key,
            'label' => $label,
            'name' => $name,
            'oldvalue' => $oldvalue,
            'newvalue' => $newvalue,
            'status' => $status,
            'code' => $code,
            'message' => '',
        ];
    }

    /**
     * Human readable message for a reason code.
     *
     * @param string $code Reason code.
     * @return string
     */
    private static function message_for(string $code): string {
        $identifier = 'reason_' . $code;
        if (get_string_manager()->string_exists($identifier, 'local_editdates')) {
            return get_string($identifier, 'local_editdates');
        }
        return $code;
    }
}
