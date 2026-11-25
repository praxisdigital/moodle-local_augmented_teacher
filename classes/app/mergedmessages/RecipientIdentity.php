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

namespace local_augmented_teacher\app\mergedmessages;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use context_course;
use core_user\fields;
use stdClass;

/**
 * Identity fields selected and displayed for the merged-message recipient list.
 *
 * @package local_augmented_teacher
 * @copyright 2026 Praxis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class RecipientIdentity {
    /** @var string[] Identity fields visible to the viewer in this course. */
    private array $fields;

    /** @var array<string, bool> Missing properties already reported for this list. */
    private array $reported_missing = [];

    /**
     * @param context_course $context Course in which the list is displayed.
     */
    public function __construct(context_course $context) {
        $this->fields = fields::get_identity_fields($context);
    }

    /**
     * @return string[] The identity columns to display, in the configured order.
     */
    public function get_fields(): array {
        return $this->fields;
    }

    /**
     * @return stdClass SQL fragments for the user projection, including any custom-field joins and parameters.
     */
    public function get_user_sql(): stdClass {
        return fields::for_userpic()
            ->including('username', 'email', 'city', 'country', 'lang', 'timezone', 'maildisplay', ...$this->fields)
            ->get_sql('u', true, '', 'id', false);
    }

    /**
     * Return a raw display value; an unset optional value is distinct from an absent column.
     *
     * @param stdClass $user Recipient row.
     * @param string $field Expected identity field from get_fields().
     * @return string The value, or an empty string when unavailable.
     */
    public function get_value(stdClass $user, string $field): string {
        if (!property_exists($user, $field)) {
            if (!isset($this->reported_missing[$field])) {
                $this->reported_missing[$field] = true;
                debugging("Merged-message recipient query did not return the identity field '{$field}'.", DEBUG_DEVELOPER);
            }
            return '';
        }

        return (string) ($user->{$field} ?? '');
    }
}
