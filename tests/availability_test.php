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
 * Tests for reading and patching availability date conditions.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_editdates;

use local_editdates\local\availability;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

#[CoversClass(availability::class)]
final class availability_test extends \advanced_testcase {
    /**
     * An empty tree has no dates and is writable.
     */
    public function test_read_empty(): void {
        foreach ([null, '', '   '] as $json) {
            $state = availability::read($json);
            $this->assertSame(0, $state['from']);
            $this->assertSame(0, $state['until']);
            $this->assertTrue($state['editable']);
            $this->assertSame('', $state['reason']);
        }
    }

    /**
     * Both directions are read from a simple tree.
     */
    public function test_read_both_directions(): void {
        $json = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'date', 'd' => '>=', 't' => 1700000000],
                ['type' => 'date', 'd' => '<', 't' => 1700600000],
            ],
            'showc' => [true, true],
        ]);
        $state = availability::read($json);
        $this->assertSame(1700000000, $state['from']);
        $this->assertSame(1700600000, $state['until']);
        $this->assertTrue($state['editable']);
    }

    /**
     * Trees whose meaning would change are reported read-only with a reason.
     */
    public function test_read_reports_unwritable_trees(): void {
        $or = json_encode([
            'op' => '|',
            'c' => [
                ['type' => 'date', 'd' => '>=', 't' => 1700000000],
                ['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'CS'],
            ],
            'show' => true,
        ]);
        $state = availability::read($or);
        $this->assertFalse($state['editable']);
        $this->assertSame('notandavailability', $state['reason']);

        $nested = json_encode([
            'op' => '&',
            'c' => [
                [
                    'op' => '|',
                    'c' => [['type' => 'date', 'd' => '>=', 't' => 1700000000]],
                    'show' => true,
                ],
            ],
            'showc' => [true],
        ]);
        $state = availability::read($nested);
        $this->assertFalse($state['editable']);
        $this->assertSame('nesteddateavailability', $state['reason']);

        $duplicate = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'date', 'd' => '>=', 't' => 1700000000],
                ['type' => 'date', 'd' => '>=', 't' => 1700100000],
            ],
            'showc' => [true, true],
        ]);
        $state = availability::read($duplicate);
        $this->assertFalse($state['editable']);
        $this->assertSame('duplicatedateavailability', $state['reason']);

        $state = availability::read('{not json');
        $this->assertFalse($state['editable']);
        $this->assertSame('unreadableavailability', $state['reason']);
    }

    /**
     * Writing into an empty tree creates the conditions Moodle expects.
     */
    public function test_write_creates_tree(): void {
        $json = availability::write(null, 1700000000, 0);
        $tree = json_decode($json);
        $this->assertSame('&', $tree->op);
        $this->assertCount(1, $tree->c);
        $this->assertSame('date', $tree->c[0]->type);
        $this->assertSame('>=', $tree->c[0]->d);
        $this->assertSame(1700000000, $tree->c[0]->t);
        $this->assertSame([true], $tree->showc);
    }

    /**
     * Foreign conditions and their display flags survive a date change.
     */
    public function test_write_preserves_other_conditions(): void {
        $json = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'CS'],
                ['type' => 'date', 'd' => '>=', 't' => 1700000000],
            ],
            'showc' => [false, true],
        ]);

        $tree = json_decode(availability::write($json, 1700000000, 1700600000));

        $this->assertCount(3, $tree->c);
        $this->assertSame('profile', $tree->c[0]->type);
        $this->assertSame('CS', $tree->c[0]->v);
        $this->assertSame([false, true, true], $tree->showc);
        $state = availability::read(json_encode($tree));
        $this->assertSame(1700000000, $state['from']);
        $this->assertSame(1700600000, $state['until']);
    }

    /**
     * Removing the last date removes the tree, not just the condition.
     */
    public function test_write_zero_removes_condition(): void {
        $json = json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => 1700000000]],
            'showc' => [true],
        ]);
        $this->assertNull(availability::write($json, 0, 0));

        $withprofile = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'CS'],
                ['type' => 'date', 'd' => '<', 't' => 1700600000],
            ],
            'showc' => [true, true],
        ]);
        $tree = json_decode(availability::write($withprofile, 0, 0));
        $this->assertCount(1, $tree->c);
        $this->assertSame('profile', $tree->c[0]->type);
    }

    /**
     * A tree that cannot be interpreted is never rewritten.
     */
    public function test_write_refuses_unwritable_tree(): void {
        $or = json_encode([
            'op' => '|',
            'c' => [
                ['type' => 'date', 'd' => '>=', 't' => 1700000000],
                ['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'CS'],
            ],
            'show' => true,
        ]);
        $this->expectException(\coding_exception::class);
        availability::write($or, 1700000001, 0);
    }
}
