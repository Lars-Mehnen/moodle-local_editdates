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
 * Tests for planning and applying date changes.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates;

use local_editdates\local\availability;
use local_editdates\local\date_collector;
use local_editdates\local\date_writer;


/**
 * Tests for planning and applying date changes.
 * @covers \local_editdates\local\date_writer
 */
final class date_writer_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Quiz course module. */
    private \stdClass $quiz;

    /** @var \stdClass Assignment course module. */
    private \stdClass $assign;

    /** @var \stdClass Section 2 record. */
    private \stdClass $section;

    /**
     * Build a course with dated activities and a restricted section.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;

        // Fixtures and expectations have to share one timezone, and it has to be
        // one with a daylight-saving change: the shift tests are about what "the
        // same time of day" means across that change. Set it before the fixtures
        // are built, or their timestamps and the assertions mean different things.
        $this->setTimezone('Europe/Vienna', 'Europe/Vienna');

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
        $this->section = $DB->get_record(
            'course_sections',
            ['course' => $this->course->id, 'section' => 2],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Shorthand for one update record.
     *
     * @param string $target Target type.
     * @param int $id Target id.
     * @param string $key Date key.
     * @param int $value New value.
     * @return array
     */
    private function update(string $target, int $id, string $key, int $value): array {
        return ['target' => $target, 'id' => $id, 'key' => $key, 'value' => $value];
    }

    /**
     * A plan changes nothing until it is applied.
     */
    public function test_plan_does_not_write(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $new = strtotime('2026-11-16 23:59');
        $changes = $writer->plan([
            $this->update(date_collector::TARGET_MODULE, $this->quiz->cmid, 'timeclose', $new),
        ]);

        $this->assertCount(1, $changes);
        $this->assertSame(date_writer::STATUS_PLANNED, $changes[0]['status']);
        $this->assertSame(strtotime('2026-11-09 23:59'), $changes[0]['oldvalue']);
        $this->assertSame($new, $changes[0]['newvalue']);
        $this->assertSame(
            strtotime('2026-11-09 23:59'),
            (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id])
        );
    }

    /**
     * Applying a plan writes the module table and refreshes the calendar.
     */
    public function test_apply_writes_module_date_and_calendar(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $new = strtotime('2026-11-16 23:59');
        $changes = $writer->apply($writer->plan([
            $this->update(date_collector::TARGET_MODULE, $this->quiz->cmid, 'timeclose', $new),
        ]));

        $this->assertSame(date_writer::STATUS_APPLIED, $changes[0]['status']);
        $this->assertSame($new, (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id]));
        $this->assertSame($new, (int) $DB->get_field('event', 'timestart', [
            'modulename' => 'quiz', 'instance' => $this->quiz->id, 'eventtype' => 'close',
        ]));
    }

    /**
     * Expected completion is stored in course_modules and reaches the calendar.
     */
    public function test_apply_writes_completion_expected(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $new = strtotime('2026-11-23 12:00');
        $changes = $writer->apply($writer->plan([
            $this->update(date_collector::TARGET_MODULE, $this->quiz->cmid, 'completionexpected', $new),
        ]));

        $this->assertSame(date_writer::STATUS_APPLIED, $changes[0]['status']);
        $this->assertSame(
            $new,
            (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $this->quiz->cmid])
        );
    }

    /**
     * The module's own validation rejects an inverted window.
     */
    public function test_plan_rejects_dates_the_module_refuses(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $changes = $writer->plan([
            $this->update(
                date_collector::TARGET_MODULE,
                $this->assign->cmid,
                'duedate',
                strtotime('2026-10-01 08:00')
            ),
        ]);

        $this->assertSame(date_writer::STATUS_ERROR, $changes[0]['status']);
        $this->assertSame('modulevalidation', $changes[0]['code']);
        $this->assertNotSame('', $changes[0]['message']);

        $writer->apply($changes);
        $this->assertSame(
            strtotime('2026-10-19 23:59'),
            (int) $DB->get_field('assign', 'duedate', ['id' => $this->assign->id])
        );
    }

    /**
     * Unknown addresses and required dates are reported, not guessed.
     */
    public function test_plan_rejects_bad_requests(): void {
        $writer = new date_writer($this->course);
        $changes = $writer->plan([
            $this->update(date_collector::TARGET_MODULE, $this->quiz->cmid, 'nosuchdate', 1700000000),
            $this->update(date_collector::TARGET_COURSE, 0, 'startdate', 0),
            $this->update(date_collector::TARGET_MODULE, $this->quiz->cmid, 'timeclose', -5),
        ]);

        $this->assertSame('unknowndate', $changes[0]['code']);
        $this->assertSame('notoptional', $changes[1]['code']);
        $this->assertSame('invalidvalue', $changes[2]['code']);
        foreach ($changes as $change) {
            $this->assertSame(date_writer::STATUS_ERROR, $change['status']);
        }
    }

    /**
     * A course end date before the start date is rejected as a pair.
     */
    public function test_plan_rejects_course_end_before_start(): void {
        $writer = new date_writer($this->course);
        $changes = $writer->plan([
            $this->update(date_collector::TARGET_COURSE, 0, 'enddate', strtotime('2026-01-01 08:00')),
        ]);
        $this->assertSame('enddatebeforestartdate', $changes[0]['code']);
    }

    /**
     * Requesting the current value is reported as unchanged, not as a write.
     */
    public function test_plan_detects_unchanged_values(): void {
        $writer = new date_writer($this->course);
        $changes = $writer->plan([
            $this->update(
                date_collector::TARGET_MODULE,
                $this->quiz->cmid,
                'timeclose',
                strtotime('2026-11-09 23:59')
            ),
        ]);
        $this->assertSame(date_writer::STATUS_UNCHANGED, $changes[0]['status']);
    }

    /**
     * Section dates are written into the availability tree.
     */
    public function test_apply_writes_section_availability(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $from = strtotime('2026-11-01 00:00');
        $until = strtotime('2026-12-01 00:00');
        $changes = $writer->apply($writer->plan([
            $this->update(date_collector::TARGET_SECTION, $this->section->id, availability::KEY_FROM, $from),
            $this->update(date_collector::TARGET_SECTION, $this->section->id, availability::KEY_UNTIL, $until),
        ]));

        foreach ($changes as $change) {
            $this->assertSame(date_writer::STATUS_APPLIED, $change['status']);
        }
        $state = availability::read(
            $DB->get_field('course_sections', 'availability', ['id' => $this->section->id])
        );
        $this->assertSame($from, $state['from']);
        $this->assertSame($until, $state['until']);
    }

    /**
     * An inverted availability window is rejected.
     */
    public function test_plan_rejects_inverted_availability_window(): void {
        $writer = new date_writer($this->course);
        $changes = $writer->plan([
            $this->update(
                date_collector::TARGET_SECTION,
                $this->section->id,
                availability::KEY_FROM,
                strtotime('2026-12-01 00:00')
            ),
            $this->update(
                date_collector::TARGET_SECTION,
                $this->section->id,
                availability::KEY_UNTIL,
                strtotime('2026-11-01 00:00')
            ),
        ]);
        foreach ($changes as $change) {
            $this->assertSame(date_writer::STATUS_ERROR, $change['status']);
            $this->assertSame('fromafteruntil', $change['code']);
        }
    }

    /**
     * A shift moves the dates that are set and leaves the others alone.
     */
    public function test_plan_shift_covers_set_dates_only(): void {
        global $DB;

        $writer = new date_writer($this->course);
        $changes = $writer->apply($writer->plan_shift(WEEKSECS, [], [], true));

        $keys = [];
        foreach ($changes as $change) {
            $this->assertSame(date_writer::STATUS_APPLIED, $change['status']);
            $keys[] = $change['target'] . ':' . $change['key'];
        }
        // The assignment has no cut-off date, so it is not part of the plan.
        $this->assertNotContains('module:cutoffdate', $keys);
        $this->assertContains('course:startdate', $keys);

        $this->assertSame(
            strtotime('2026-11-16 23:59'),
            (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id])
        );
        $this->assertSame(
            strtotime('2026-10-26 23:59'),
            (int) $DB->get_field('assign', 'duedate', ['id' => $this->assign->id])
        );
        $this->assertSame(
            strtotime('2026-10-12 08:00'),
            (int) $DB->get_field('course', 'startdate', ['id' => $this->course->id])
        );
    }

    /**
     * A whole-day shift keeps the local time of day across a daylight-saving change.
     */
    public function test_plan_shift_preserves_time_of_day(): void {
        // 2026-10-19 is before and 2026-10-26 after the European clock change
        // (see setUp), so the two offset interpretations differ by one hour here.
        $writer = new date_writer($this->course);
        $calendardays = $this->shift_value($writer->plan_shift(WEEKSECS, [2], ['assign']), 'duedate');
        $literal = $this->shift_value(
            $writer->plan_shift(WEEKSECS, [2], ['assign'], false, true, false),
            'duedate'
        );

        $this->assertSame(strtotime('2026-10-26 23:59'), $calendardays);
        $this->assertSame(strtotime('2026-10-19 23:59') + WEEKSECS, $literal);
        $this->assertSame(HOURSECS, $calendardays - $literal);
    }

    /**
     * The planned new value of one date in a plan.
     *
     * @param array $changes Plan records.
     * @param string $key Date key.
     * @return int
     */
    private function shift_value(array $changes, string $key): int {
        foreach ($changes as $change) {
            if ($change['key'] === $key) {
                return (int) $change['newvalue'];
            }
        }
        $this->fail("No planned change for {$key}");
    }

    /**
     * A shift can be restricted to sections and activity types.
     */
    public function test_plan_shift_respects_scope(): void {
        $writer = new date_writer($this->course);
        $changes = $writer->plan_shift(DAYSECS, [1], ['quiz']);

        foreach ($changes as $change) {
            $this->assertSame(date_collector::TARGET_MODULE, $change['target']);
            $this->assertSame($this->quiz->cmid, $change['id']);
        }
        $this->assertNotEmpty($changes);
    }
}
