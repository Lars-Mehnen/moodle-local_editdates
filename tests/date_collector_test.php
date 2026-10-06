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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the course date read model.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates;

use local_editdates\local\date_collector;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the course date read model.
 */
#[CoversClass(date_collector::class)]
final class date_collector_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Quiz course module. */
    private \stdClass $quiz;

    /** @var \stdClass Assignment course module. */
    private \stdClass $assign;

    /**
     * Build a course with one quiz, one assignment and a restricted section.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course([
            'numsections' => 3,
            'enablecompletion' => 1,
            'startdate' => strtotime('2026-10-05 08:00'),
            'enddate' => strtotime('2027-01-31 23:59'),
        ]);
        $this->quiz = $generator->create_module('quiz', [
            'course' => $this->course->id,
            'section' => 1,
            'timeopen' => strtotime('2026-11-02 08:00'),
            'timeclose' => strtotime('2026-11-09 23:59'),
            'completionexpected' => strtotime('2026-11-09 12:00'),
        ]);
        $this->assign = $generator->create_module('assign', [
            'course' => $this->course->id,
            'section' => 2,
            'allowsubmissionsfromdate' => strtotime('2026-10-12 08:00'),
            'duedate' => strtotime('2026-10-19 23:59'),
        ]);

        // A section with a date condition next to a foreign condition.
        $section = $DB->get_record(
            'course_sections',
            ['course' => $this->course->id, 'section' => 2],
            '*',
            MUST_EXIST
        );
        $DB->set_field('course_sections', 'availability', json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'CS'],
                ['type' => 'date', 'd' => '>=', 't' => strtotime('2026-11-01 00:00')],
            ],
            'showc' => [false, true],
        ]), ['id' => $section->id]);
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * Find one date of one activity in the model.
     *
     * @param array $model Read model.
     * @param int $cmid Course module id.
     * @param string $key Date key.
     * @return array
     */
    private function activity_date(array $model, int $cmid, string $key): array {
        foreach ($model['activities'] as $activity) {
            if ($activity['cmid'] !== $cmid) {
                continue;
            }
            foreach ($activity['dates'] as $date) {
                if ($date['key'] === $key) {
                    return $date;
                }
            }
        }
        $this->fail("No date {$key} for course module {$cmid}");
    }

    /**
     * The model reports course, module, completion and availability dates.
     */
    public function test_collect_reports_every_date_source(): void {
        $model = (new date_collector($this->course))->collect();

        $this->assertSame((int) $this->course->id, $model['courseid']);
        $this->assertSame(\core_date::get_server_timezone(), $model['timezone']);
        $this->assertGreaterThan(0, $model['servertime']);
        $this->assertTrue($model['course']['canedit']);
        $this->assertSame((int) $this->course->startdate, $model['course']['dates'][0]['value']);
        $this->assertSame('startdate', $model['course']['dates'][0]['key']);
        $this->assertFalse($model['course']['dates'][0]['optional']);

        $timeclose = $this->activity_date($model, $this->quiz->cmid, 'timeclose');
        $this->assertSame(date_collector::SOURCE_MODULE, $timeclose['source']);
        $this->assertSame(strtotime('2026-11-09 23:59'), $timeclose['value']);
        $this->assertTrue($timeclose['editable']);

        $expected = $this->activity_date($model, $this->quiz->cmid, 'completionexpected');
        $this->assertSame(date_collector::SOURCE_COMPLETION, $expected['source']);
        $this->assertSame(strtotime('2026-11-09 12:00'), $expected['value']);

        $from = $this->activity_date($model, $this->assign->cmid, 'availablefrom');
        $this->assertSame(date_collector::SOURCE_AVAILABILITY, $from['source']);
        $this->assertSame(0, $from['value']);

        $due = $this->activity_date($model, $this->assign->cmid, 'duedate');
        $this->assertSame(strtotime('2026-10-19 23:59'), $due['value']);

        // The ID number is the ownership marker a multi-lecturer course needs.
        $idnumbers = array_column($model['activities'], 'idnumber', 'cmid');
        $this->assertArrayHasKey((int) $this->quiz->cmid, $idnumbers);
        $this->assertSame('', $idnumbers[(int) $this->quiz->cmid]);
    }

    /**
     * A set ID number is reported, so a client can select by ownership.
     */
    public function test_collect_reports_the_activity_idnumber(): void {
        global $DB;

        $DB->set_field('course_modules', 'idnumber', 'MVA-01', ['id' => $this->quiz->cmid]);
        rebuild_course_cache($this->course->id, true);

        $model = (new date_collector($this->course))->collect('quiz');
        $this->assertSame('MVA-01', $model['activities'][0]['idnumber']);
    }

    /**
     * Section dates come from the availability tree, section 0 is read-only.
     */
    public function test_collect_reports_section_dates(): void {
        $model = (new date_collector($this->course))->collect();
        $sections = [];
        foreach ($model['sections'] as $section) {
            $sections[$section['sectionnum']] = $section;
        }

        $this->assertFalse($sections[0]['canedit']);
        $this->assertSame('generalsection', $sections[0]['dates'][0]['reason']);

        $this->assertTrue($sections[2]['canedit']);
        $this->assertTrue($sections[2]['hasotherrestrictions']);
        $this->assertSame(strtotime('2026-11-01 00:00'), $sections[2]['dates'][0]['value']);
        $this->assertSame(0, $sections[2]['dates'][1]['value']);

        $this->assertTrue($sections[1]['canedit']);
        $this->assertFalse($sections[1]['hasotherrestrictions']);
    }

    /**
     * The activity type filter restricts the payload.
     */
    public function test_collect_filters_by_activity_type(): void {
        $model = (new date_collector($this->course))->collect('quiz');
        $modnames = array_unique(array_column($model['activities'], 'modname'));
        $this->assertSame(['quiz'], array_values($modnames));
    }

    /**
     * Completion dates disappear when the course does not track completion.
     */
    public function test_collect_without_completion(): void {
        global $DB;

        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);
        $course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);

        $model = (new date_collector($course))->collect();
        $this->assertFalse($model['enablecompletion']);
        foreach ($model['activities'] as $activity) {
            $this->assertNotContains('completionexpected', array_column($activity['dates'], 'key'));
        }
    }

    /**
     * A teacher without activity management rights gets read-only dates.
     */
    public function test_collect_marks_dates_read_only_without_permission(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $model = (new date_collector($this->course))->collect();
        $this->assertFalse($model['course']['canedit']);
        $this->assertSame('nopermission', $model['course']['dates'][0]['reason']);
        $timeclose = $this->activity_date($model, $this->quiz->cmid, 'timeclose');
        $this->assertFalse($timeclose['editable']);
        $this->assertSame('nopermission', $timeclose['reason']);
    }
}
