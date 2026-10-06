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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the web service layer.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates;

use core_external\external_api;
use local_editdates\external\get_course_dates;
use local_editdates\external\set_course_dates;
use local_editdates\external\shift_dates;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

#[CoversClass(get_course_dates::class)]
#[CoversClass(set_course_dates::class)]
#[CoversClass(shift_dates::class)]
final class external_test extends \advanced_testcase {
    /** @var \stdClass Course used by the tests. */
    private \stdClass $course;

    /** @var \stdClass Quiz course module. */
    private \stdClass $quiz;

    /**
     * Build a course with a dated quiz.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course([
            'numsections' => 2,
            'enablecompletion' => 1,
            'startdate' => strtotime('2026-10-05 08:00'),
        ]);
        $this->quiz = $generator->create_module('quiz', [
            'course' => $this->course->id,
            'section' => 1,
            'timeopen' => strtotime('2026-11-02 08:00'),
            'timeclose' => strtotime('2026-11-09 23:59'),
        ]);
    }

    /**
     * The read function returns a payload that matches its declared structure.
     */
    public function test_get_course_dates_matches_its_structure(): void {
        $result = get_course_dates::execute($this->course->id);
        $clean = external_api::clean_returnvalue(get_course_dates::execute_returns(), $result);

        $this->assertSame((int) $this->course->id, $clean['courseid']);
        $this->assertSame(\core_date::get_server_timezone(), $clean['timezone']);
        $this->assertNotEmpty($clean['sections']);
        $this->assertNotEmpty($clean['activities']);
        $this->assertSame([], $clean['warnings']);

        $keys = [];
        foreach ($clean['activities'] as $activity) {
            if ($activity['cmid'] === (int) $this->quiz->cmid) {
                $keys = array_column($activity['dates'], 'key');
            }
        }
        $this->assertContains('timeopen', $keys);
        $this->assertContains('timeclose', $keys);
        $this->assertArrayHasKey('idnumber', $clean['activities'][0]);
    }

    /**
     * Omitting dryrun must not write: the default is a preview.
     */
    public function test_set_course_dates_defaults_to_dry_run(): void {
        global $DB;

        $new = strtotime('2026-11-16 23:59');
        $result = set_course_dates::execute($this->course->id, [[
            'target' => 'module',
            'id' => (int) $this->quiz->cmid,
            'key' => 'timeclose',
            'value' => $new,
        ]]);
        $clean = external_api::clean_returnvalue(set_course_dates::execute_returns(), $result);

        $this->assertTrue($clean['dryrun']);
        $this->assertSame(1, $clean['summary']['planned']);
        $this->assertSame(0, $clean['summary']['applied']);
        $this->assertSame(strtotime('2026-11-09 23:59'),
            (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id]));
    }

    /**
     * With dryrun=0 the change is written and logged.
     */
    public function test_set_course_dates_writes_and_logs(): void {
        global $DB;

        $sink = $this->redirectEvents();
        $new = strtotime('2026-11-16 23:59');
        $result = set_course_dates::execute($this->course->id, [[
            'target' => 'module',
            'id' => (int) $this->quiz->cmid,
            'key' => 'timeclose',
            'value' => $new,
        ]], false);
        $clean = external_api::clean_returnvalue(set_course_dates::execute_returns(), $result);

        $this->assertFalse($clean['dryrun']);
        $this->assertSame(1, $clean['summary']['applied']);
        $this->assertSame($new, (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id]));

        $events = array_filter($sink->get_events(), function ($event) {
            return $event instanceof \local_editdates\event\dates_updated;
        });
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame(1, $event->other['applied']);
        $this->assertSame('local_editdates_set_course_dates', $event->other['function']);
    }

    /**
     * A shift previews by default too.
     */
    public function test_shift_dates_defaults_to_dry_run(): void {
        global $DB;

        $result = shift_dates::execute($this->course->id, WEEKSECS);
        $clean = external_api::clean_returnvalue(shift_dates::execute_returns(), $result);

        $this->assertTrue($clean['dryrun']);
        $this->assertGreaterThan(0, $clean['summary']['planned']);
        $this->assertSame(strtotime('2026-11-09 23:59'),
            (int) $DB->get_field('quiz', 'timeclose', ['id' => $this->quiz->id]));
    }

    /**
     * Reading needs :view, writing needs :edit on top of it.
     */
    public function test_write_needs_the_edit_capability(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);

        // Reading is allowed for the role that may use the Dates report.
        $result = get_course_dates::execute($this->course->id);
        $this->assertNotEmpty($result['activities']);

        $this->expectException(\required_capability_exception::class);
        set_course_dates::execute($this->course->id, [[
            'target' => 'module',
            'id' => (int) $this->quiz->cmid,
            'key' => 'timeclose',
            'value' => strtotime('2026-11-16 23:59'),
        ]], false);
    }

    /**
     * An implausible offset is refused before anything is planned.
     */
    public function test_shift_dates_rejects_an_implausible_offset(): void {
        $this->expectException(\moodle_exception::class);
        shift_dates::execute($this->course->id, 400 * DAYSECS);
    }

    /**
     * A zero offset is a caller mistake, not a no-op to be executed.
     */
    public function test_shift_dates_rejects_a_zero_offset(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/would not change anything/');
        shift_dates::execute($this->course->id, 0);
    }

    /**
     * The batch limit stops oversized requests instead of applying them partly.
     */
    public function test_batch_limit_is_enforced(): void {
        set_config('maxupdates', 1, 'local_editdates');

        $this->expectException(\moodle_exception::class);
        set_course_dates::execute($this->course->id, [
            [
                'target' => 'module',
                'id' => (int) $this->quiz->cmid,
                'key' => 'timeopen',
                'value' => strtotime('2026-11-03 08:00'),
            ],
            [
                'target' => 'module',
                'id' => (int) $this->quiz->cmid,
                'key' => 'timeclose',
                'value' => strtotime('2026-11-16 23:59'),
            ],
        ], false);
    }
}
