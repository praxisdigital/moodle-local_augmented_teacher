<?php
// This file is part of Moodle - https://moodle.org/
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

namespace local_augmented_teacher\integration;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use advanced_testcase;
use context_course;
use local_augmented_teacher\app\mergedmessages\RecipientIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Tests the merged-message recipient list's identity field projection and optional values.
 *
 * @package local_augmented_teacher
 * @copyright 2026 Praxis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(RecipientIdentity::class)]
final class recipient_identity_test extends advanced_testcase {
    /** Test that populated, empty, and unsaved fields all come back from Moodle DML. */
    public function test_projects_configured_identity_fields(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $generator->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'team', 'name' => 'Team']);
        set_config('showuseridentity', 'phone1,phone2,institution,idnumber,profile_field_team');

        $populated = [
            'phone1' => '01234567',
            'phone2' => '0',
            'institution' => 'R&D',
            'idnumber' => 'Z01',
            'profile_field_team' => 'Teaching',
        ];
        $first = $generator->create_user($populated);
        $second = $generator->create_user(['idnumber' => 'A01']);
        $identity = new RecipientIdentity(context_course::instance($course->id));
        $this->assertSame(array_keys($populated), $identity->get_fields());

        $sql = $identity->get_user_sql();
        $rows = $DB->get_records_sql(
            "SELECT {$sql->selects}
               FROM {user} u {$sql->joins}
              WHERE u.id IN (:firstid, :secondid)
           ORDER BY idnumber ASC",
            array_merge(['firstid' => $first->id, 'secondid' => $second->id], $sql->params)
        );

        $this->assertEquals([$second->id, $first->id], array_keys($rows));
        foreach ($populated as $field => $value) {
            $this->assertSame($value, $identity->get_value($rows[$first->id], $field));
        }
        $this->assertSame('', $identity->get_value($rows[$second->id], 'phone1'));
        $this->assertTrue(property_exists($rows[$second->id], 'profile_field_team'));
        $this->assertNull($rows[$second->id]->profile_field_team);
        $this->assertSame('', $identity->get_value($rows[$second->id], 'profile_field_team'));
        $this->assertDebuggingNotCalled();
    }

    /** Identity fields must not be added for a viewer without permission. */
    public function test_does_not_project_identity_fields_for_viewer_without_permission(): void {
        $this->resetAfterTest();
        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        set_config('showuseridentity', 'phone1,idnumber');

        $identity = new RecipientIdentity(context_course::instance($course->id));
        $this->assertSame([], $identity->get_fields());
        $mappings = $identity->get_user_sql()->mappings;
        $this->assertArrayNotHasKey('phone1', $mappings);
        $this->assertArrayNotHasKey('idnumber', $mappings);
        $this->assertArrayHasKey('email', $mappings);
    }

    /** Missing columns get one diagnostic per field even when multiple rows are affected. */
    public function test_reports_missing_columns_without_treating_null_as_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('showuseridentity', 'phone1');
        $course = self::getDataGenerator()->create_course();
        $identity = new RecipientIdentity(context_course::instance($course->id));

        $this->assertSame('', $identity->get_value((object) ['phone1' => null], 'phone1'));
        $this->assertDebuggingNotCalled();
        $this->assertSame('', $identity->get_value(new stdClass(), 'phone1'));
        $this->assertSame('', $identity->get_value(new stdClass(), 'phone1'));
        $this->assertDebuggingCalled(
            "Merged-message recipient query did not return the identity field 'phone1'.",
            DEBUG_DEVELOPER
        );
    }
}
