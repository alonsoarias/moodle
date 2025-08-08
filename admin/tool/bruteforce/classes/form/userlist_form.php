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
 * Form to add usernames to white or black lists.
 *
 * @package    tool_bruteforce
 */
class userlist_form extends \moodleform {
    /** @var string list type. */
    protected string $list;

    /**
     * Define fields.
     */
    public function definition() {
        $mform = $this->_form;
        $this->list = $this->_customdata['list'];

        $mform->addElement('text', 'username', get_string('username'));
        $mform->setType('username', PARAM_USERNAME);
        $mform->addRule('username', null, 'required', null, 'client');

        $mform->addElement('text', 'comment', get_string('comment', 'tool_bruteforce'));
        $mform->setType('comment', PARAM_TEXT);

        $mform->addElement('hidden', 'list', $this->list);
        $mform->setType('list', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('add', 'tool_bruteforce'));
    }
}
