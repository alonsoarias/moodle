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

namespace tool_bruteforce\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to add manual blocks.
 *
 * @package    tool_bruteforce
 * @copyright  2024 The Moodle Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_form extends \moodleform {
    /**
     * Define form fields.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'ip', get_string('ip', 'tool_bruteforce'));
        $mform->setType('ip', PARAM_RAW_TRIMMED);

        $mform->addElement('text', 'username', get_string('user'));
        $mform->setType('username', PARAM_USERNAME);

        $durations = [15 * MINSECS, 30 * MINSECS, HOURSECS, 6 * HOURSECS, 12 * HOURSECS, DAYSECS, 3 * DAYSECS, 7 * DAYSECS];
        $options = [];
        foreach ($durations as $d) {
            $options[$d] = format_time($d);
        }
        $mform->addElement('select', 'duration', get_string('durationpreset', 'tool_bruteforce'), $options);

        $mform->addElement('text', 'reason', get_string('reason', 'tool_bruteforce'));
        $mform->setType('reason', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('addblock', 'tool_bruteforce'));
    }
}
