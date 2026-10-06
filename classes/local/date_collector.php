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
 * Collects every addressable date of a course.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read model for the course dates API.
 *
 * Produces the same set of dates that the Dates report shows, plus the section
 * access-restriction dates that the report only links out to, and addresses each
 * of them as a (target, id, key) triple that the write functions accept.
 */
final class date_collector {
    /** @var string Course level target. */
    const TARGET_COURSE = 'course';

    /** @var string Course section target. */
    const TARGET_SECTION = 'section';

    /** @var string Activity (course module) target. */
    const TARGET_MODULE = 'module';

    /** @var string A date stored in the module's own table. */
    const SOURCE_MODULE = 'module';

    /** @var string A date stored in course_modules (expected completion). */
    const SOURCE_COMPLETION = 'completion';

    /** @var string A date stored as an availability condition. */
    const SOURCE_AVAILABILITY = 'availability';

    /** @var string A date stored in the course table. */
    const SOURCE_COURSE = 'course';

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \context_course Course context. */
    private \context_course $coursecontext;

    /** @var array Collected warnings. */
    private array $warnings = [];

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
     * Collect the dates of the course.
     *
     * @param string $activitytype Restrict activities to this module name, '' or 'all' for every type.
     * @param bool $includeinvisible Include activities and sections the caller cannot see on the course page.
     * @return array The read model.
     */
    public function collect(string $activitytype = '', bool $includeinvisible = false): array {
        global $CFG;

        $this->warnings = [];
        $modinfo = get_fast_modinfo($this->course);
        $canupdatecourse = has_capability('moodle/course:update', $this->coursecontext);
        $hasavailability = !empty($CFG->enableavailability);
        $hascompletion = !empty($CFG->enablecompletion) && !empty($this->course->enablecompletion);

        return [
            'courseid' => (int) $this->course->id,
            'shortname' => $this->course->shortname,
            'fullname' => $this->course->fullname,
            'format' => $this->course->format,
            'servertime' => time(),
            // A client that has to build a wall-clock time ("every Monday 08:00")
            // cannot do it correctly without knowing which zone the site uses.
            'timezone' => \core_date::get_server_timezone(),
            'enableavailability' => $hasavailability,
            'enablecompletion' => $hascompletion,
            'course' => $this->collect_course($canupdatecourse),
            'sections' => $this->collect_sections($modinfo, $canupdatecourse, $hasavailability, $includeinvisible),
            'activities' => $this->collect_activities(
                $modinfo,
                $activitytype,
                $includeinvisible,
                $hasavailability,
                $hascompletion
            ),
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Course start and end date.
     *
     * @param bool $canedit Whether the caller may update the course.
     * @return array
     */
    private function collect_course(bool $canedit): array {
        $reason = $canedit ? '' : 'nopermission';
        return [
            'canedit' => $canedit,
            'dates' => [
                $this->date('startdate', get_string('startdate'), (int) $this->course->startdate,
                    self::SOURCE_COURSE, 'datetime', false, $canedit, $reason),
                $this->date('enddate', get_string('enddate'), (int) $this->course->enddate,
                    self::SOURCE_COURSE, 'datetime', true, $canedit, $reason),
            ],
        ];
    }

    /**
     * Section access-restriction dates.
     *
     * @param \course_modinfo $modinfo Course modinfo.
     * @param bool $canupdatecourse Whether the caller may update the course.
     * @param bool $hasavailability Whether restricted access is enabled site wide.
     * @param bool $includeinvisible Include sections that are not visible to the caller.
     * @return array
     */
    private function collect_sections(
        \course_modinfo $modinfo,
        bool $canupdatecourse,
        bool $hasavailability,
        bool $includeinvisible
    ): array {
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible && !$includeinvisible) {
                continue;
            }

            $state = availability::read($section->availability);
            $sectionnum = (int) $section->section;

            $editable = $canupdatecourse && $hasavailability && $state['editable'] && $sectionnum > 0;
            $reason = '';
            if (!$editable) {
                if (!$canupdatecourse) {
                    $reason = 'nopermission';
                } else if (!$hasavailability) {
                    $reason = 'availabilitydisabled';
                } else if ($sectionnum === 0) {
                    $reason = 'generalsection';
                } else {
                    $reason = $state['reason'];
                }
            }

            $sections[] = [
                'sectionid' => (int) $section->id,
                'sectionnum' => $sectionnum,
                'name' => get_section_name($this->course, $section),
                'visible' => (bool) $section->visible,
                'canedit' => $editable,
                'hasotherrestrictions' => $this->has_other_restrictions($section->availability),
                'dates' => [
                    $this->date(availability::KEY_FROM, get_string('availablefrom', 'local_editdates'),
                        $state['from'], self::SOURCE_AVAILABILITY, 'datetime', true, $editable, $reason),
                    $this->date(availability::KEY_UNTIL, get_string('availableuntil', 'local_editdates'),
                        $state['until'], self::SOURCE_AVAILABILITY, 'datetime', true, $editable, $reason),
                ],
            ];
        }
        return $sections;
    }

    /**
     * Activity dates.
     *
     * @param \course_modinfo $modinfo Course modinfo.
     * @param string $activitytype Restrict to this module name, '' or 'all' for every type.
     * @param bool $includeinvisible Include activities that are not visible to the caller.
     * @param bool $hasavailability Whether restricted access is enabled site wide.
     * @param bool $hascompletion Whether completion tracking is enabled for the course.
     * @return array
     */
    private function collect_activities(
        \course_modinfo $modinfo,
        string $activitytype,
        bool $includeinvisible,
        bool $hasavailability,
        bool $hascompletion
    ): array {
        $activities = [];
        $cms = $modinfo->get_cms();

        foreach ($modinfo->get_sections() as $sectionnum => $cmids) {
            foreach ($cmids as $cmid) {
                $cm = $cms[$cmid];

                if (!$cm->uservisible && !$includeinvisible) {
                    continue;
                }
                if ($activitytype !== '' && $activitytype !== 'all' && $cm->modname !== $activitytype) {
                    continue;
                }

                $canedit = has_capability('moodle/course:manageactivities',
                    \context_module::instance($cm->id));
                $dates = $this->collect_module_dates($cm, $canedit, $hasavailability, $hascompletion);
                if (!$dates) {
                    continue;
                }

                $activities[] = [
                    'cmid' => (int) $cm->id,
                    'modname' => $cm->modname,
                    'instance' => (int) $cm->instance,
                    // The activity ID number is the only ownership marker that is
                    // machine readable, invisible to students and survives a restore.
                    'idnumber' => (string) ($cm->idnumber ?? ''),
                    'name' => format_string($cm->name, true, ['context' => $this->coursecontext]),
                    'sectionnum' => (int) $sectionnum,
                    'visible' => (bool) $cm->visible,
                    'canedit' => $canedit,
                    'hasotherrestrictions' => $this->has_other_restrictions($cm->availability),
                    'dates' => $dates,
                ];
            }
        }
        return $activities;
    }

    /**
     * Dates of a single activity.
     *
     * @param \cm_info $cm Course module.
     * @param bool $canedit Whether the caller may manage this activity.
     * @param bool $hasavailability Whether restricted access is enabled site wide.
     * @param bool $hascompletion Whether completion tracking is enabled for the course.
     * @return array
     */
    private function collect_module_dates(
        \cm_info $cm,
        bool $canedit,
        bool $hasavailability,
        bool $hascompletion
    ): array {
        $dates = [];
        $permissionreason = $canedit ? '' : 'nopermission';

        try {
            $settings = bridge::settings($cm, $this->course);
        } catch (\Throwable $e) {
            $settings = [];
            $this->warnings[] = [
                'item' => 'module:' . $cm->id,
                'message' => 'Date settings could not be read: ' . $e->getMessage(),
            ];
        }

        foreach ($settings as $key => $setting) {
            $dates[] = $this->date(
                (string) $key,
                (string) $setting->label,
                (int) $setting->currentvalue,
                self::SOURCE_MODULE,
                $setting->type === \report_editdates_mod_date_extractor::DATE ? 'date' : 'datetime',
                (bool) $setting->isoptional,
                $canedit,
                $permissionreason
            );
        }

        if ($hascompletion && isset($cm->completionexpected)) {
            $dates[] = $this->date(
                'completionexpected',
                get_string('completionexpected', 'completion'),
                (int) $cm->completionexpected,
                self::SOURCE_COMPLETION,
                'datetime',
                true,
                $canedit,
                $permissionreason
            );
        }

        if ($hasavailability) {
            $state = availability::read($cm->availability);
            $editable = $canedit && $state['editable'];
            $reason = $editable ? '' : ($canedit ? $state['reason'] : 'nopermission');
            $dates[] = $this->date(availability::KEY_FROM, get_string('availablefrom', 'local_editdates'),
                $state['from'], self::SOURCE_AVAILABILITY, 'datetime', true, $editable, $reason);
            $dates[] = $this->date(availability::KEY_UNTIL, get_string('availableuntil', 'local_editdates'),
                $state['until'], self::SOURCE_AVAILABILITY, 'datetime', true, $editable, $reason);
        }

        return $dates;
    }

    /**
     * Whether an availability tree contains conditions other than dates.
     *
     * A caller must know this before shifting dates: the activity may still be
     * closed for other reasons, and this API never touches those conditions.
     *
     * @param string|null $json Availability JSON.
     * @return bool
     */
    private function has_other_restrictions(?string $json): bool {
        if ($json === null || trim($json) === '') {
            return false;
        }
        $tree = json_decode($json);
        if (!is_object($tree) || !isset($tree->c) || !is_array($tree->c)) {
            return true;
        }
        foreach ($tree->c as $condition) {
            if (!is_object($condition) || !isset($condition->type) || $condition->type !== 'date') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build one date entry of the read model.
     *
     * @param string $key Address key.
     * @param string $label Human readable label in the response language.
     * @param int $value Timestamp, 0 when the date is not set.
     * @param string $source One of the SOURCE_* constants.
     * @param string $type 'date' or 'datetime'.
     * @param bool $optional Whether the date may be switched off (value 0).
     * @param bool $editable Whether this API can write the date.
     * @param string $reason Machine readable reason when not editable.
     * @return array
     */
    private function date(
        string $key,
        string $label,
        int $value,
        string $source,
        string $type,
        bool $optional,
        bool $editable,
        string $reason
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'source' => $source,
            'type' => $type,
            'optional' => $optional,
            'editable' => $editable,
            'reason' => $editable ? '' : $reason,
        ];
    }
}
