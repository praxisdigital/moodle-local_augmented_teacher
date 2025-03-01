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
 * The Augmented Teacher
 *
 * @package    local_augmented_teacher
 * @author     Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2017 SmartLearning Inc https://www.smartlearning.dk
 */

use local_augmented_teacher\factory;
use local_augmented_teacher\message_send_from_course_builder;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot.'/message/lib.php');

$id = required_param('id', PARAM_INT);
if ($messagebodyarray = optional_param_array('messagebody', '', PARAM_CLEANHTML)) {
    $messagebody = $messagebodyarray['text'];
} else {
    $messagebody = '';
}
$send = optional_param('send', '', PARAM_BOOL);
$preview = optional_param('preview', '', PARAM_BOOL);
$edit = optional_param('edit', '', PARAM_BOOL);
$returnto = optional_param('returnto', '', PARAM_LOCALURL);
$format = optional_param('format', FORMAT_MOODLE, PARAM_INT);
$deluser = optional_param('deluser', 0, PARAM_INT);

$url = new moodle_url('/user/messageselect.php', array('id' => $id));
if ($messagebody !== '') {
    $url->param('messagebody[text]', $messagebody);
}
if ($send !== '') {
    $url->param('send', $send);
}
if ($preview !== '') {
    $url->param('preview', $preview);
}
if ($edit !== '') {
    $url->param('edit', $edit);
}
if ($returnto !== '') {
    $url->param('returnto', $returnto);
}
if ($format !== FORMAT_MOODLE) {
    $url->param('format', $format);
}
if ($deluser !== 0) {
    $url->param('deluser', $deluser);
}
$PAGE->set_url($url);

if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}

require_login($course);

$coursecontext = context_course::instance($id);   // Course context.
$systemcontext = context_system::instance();   // SYSTEM context.
require_capability('moodle/course:bulkmessaging', $coursecontext);

if (empty($SESSION->emailto)) {
    $SESSION->emailto = array();
}
if (!array_key_exists($id, $SESSION->emailto)) {
    $SESSION->emailto[$id] = array();
}

if ($deluser) {
    if (array_key_exists($id, $SESSION->emailto) && array_key_exists($deluser, $SESSION->emailto[$id])) {
        unset($SESSION->emailto[$id][$deluser]);
    }
}

if (empty($SESSION->emailselect[$id]) || $messagebody) {
    $SESSION->emailselect[$id] = array('messagebody' => $messagebody);
}

$messagebody = $SESSION->emailselect[$id]['messagebody'];

$count = 0;

if ($data = data_submitted()) {
    require_sesskey();
    $namefields = implode(',', \core_user\fields::get_name_fields());
    foreach ($data as $k => $v) {
        if (preg_match('/^(user|teacher)(\d+)$/', $k, $m)) {
            if (!array_key_exists($m[2], $SESSION->emailto[$id])) {
                if ($user = $DB->get_record_select('user', "id = ?", array($m[2]), 'id, '.
                        $namefields . ', idnumber, email, mailformat, lastaccess, lang, '.
                        'maildisplay, auth, suspended, deleted, emailstop, username')) {
                    $SESSION->emailto[$id][$m[2]] = $user;
                    $count++;
                }
            }
        }
    }
}

if ($course->id == SITEID) {
    $strtitle = get_string('sitemessage');
    $PAGE->set_pagelayout('admin');
} else {
    $strtitle = get_string('coursemessage', 'local_augmented_teacher');
    $PAGE->set_pagelayout('incourse');
}

$link = null;
if (has_capability('moodle/course:viewparticipants', $coursecontext) ||
    has_capability('moodle/site:viewparticipants', $systemcontext)) {
    $link = new moodle_url("/local/augmented_teacher/mergedmessages.php", array('id' => $course->id));
}
$PAGE->navbar->add($strtitle);

$editor = editors_get_preferred_editor();
$editorpluginname =  get_class($editor);

if ($editorpluginname == 'atto_texteditor') {
    $module = array('name' => 'local_augmented_teacher', 'fullpath' => '/local/augmented_teacher/module.js');
    $PAGE->requires->js_init_call('M.local_augmented_teacher.init_shortcode', null, false, $module);
}

$settingnode = $PAGE->settingsnav->find('local_augmented_teacher', navigation_node::TYPE_SETTING);
$settingnode->make_active();

$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);
echo $OUTPUT->header();
// If messaging is disabled on site, we can still allow users with capabilities to send emails instead.
if (empty($CFG->messaging)) {
    echo $OUTPUT->notification(get_string('messagingdisabled', 'message'));
}

if ($count) {
    if ($count == 1) {
        $heading = get_string('addedrecip', 'local_augmented_teacher', $count);
    } else {
        $heading = get_string('addedrecips', 'local_augmented_teacher', $count);
    }
    echo $OUTPUT->heading($heading);
}

if (!empty($messagebody) && !$edit && !$deluser && ($preview || $send)) {
    require_sesskey();
    if (count($SESSION->emailto[$id])) {
        if (!empty($preview)) {
            echo '<form method="post" action="messageselect.php" style="margin: 0 20px;">
<input type="hidden" name="returnto" value="'.s($returnto).'" />
<input type="hidden" name="id" value="'.$id.'" />
<input type="hidden" name="format" value="'.$format.'" />
<input type="hidden" name="sesskey" value="' . sesskey() . '" />
';
            echo "<h3>".get_string('previewhtml', 'local_augmented_teacher')."</h3>";
            echo "<div class=\"messagepreview\">\n".format_text($messagebody, $format)."\n</div>\n";
            echo '<p align="center"><input type="submit" name="send" value="'.get_string('sendmessage', 'message').'" />'."\n";
            echo '<input type="submit" name="edit" value="'.get_string('update').'" /></p>';
            echo "\n</form>";
        } else if (!empty($send)) {
            $fails = array();
            foreach ($SESSION->emailto[$id] as $user) {

                $search = array(
                    'lastname' => '{{lastname}}',
                    'firstname' => '{{firstname}}',
                    'coursename' => '{{coursename}}'
                );

                $replace = array(
                    'lastname' => $user->lastname,
                    'firstname' => local_augmented_get_first_firstname($user->firstname),
                    'coursename' => $course->fullname
                );

                $messagebodywithshortcode = str_replace($search, $replace, $messagebody);

                $messagebodywithshortcode = factory::append_message_send_from_course(
                    $messagebodywithshortcode,
                    $course,
                    $user
                );

                if (!message_post_message($USER, $user, $messagebodywithshortcode, $format)) {
                    $user->fullname = fullname($user);
                    $fails[] = get_string('messagedselecteduserfailed', 'moodle', $user);
                }
            }
            if (empty($fails)) {
                echo $OUTPUT->heading(get_string('messagedselectedusers'));
                unset($SESSION->emailto[$id]);
                unset($SESSION->emailselect[$id]);
            } else {
                echo $OUTPUT->heading(get_string('messagedselectedcountusersfailed', 'moodle', count($fails)));
                echo '<ul>';
                foreach ($fails as $f) {
                        echo '<li>', $f, '</li>';
                }
                echo '</ul>';
            }
            echo '<p align="center"><a href="index.php?id='.$id.'">'.
                get_string('backtomainmenu', 'local_augmented_teacher').'</a></p>';
        }
        echo $OUTPUT->footer();
        exit;
    } else {
        echo $OUTPUT->notification(get_string('nousersyet'));
    }
}

echo '<p align="center"><a href="'.$returnto.'">'.get_string("keepsearching", 'local_augmented_teacher').'</a>'.
    ((count($SESSION->emailto[$id])) ? ', '.get_string('usemessageform', 'local_augmented_teacher') : '').'</p>';

if ((!empty($send) || !empty($preview) || !empty($edit)) && (empty($messagebody))) {
    echo $OUTPUT->notification(get_string('allfieldsrequired', 'local_augmented_teacher'));
}

if (count($SESSION->emailto[$id])) {
    require_sesskey();
    require("message.html");
}

$PAGE->requires->yui_module('moodle-core-formchangechecker',
        'M.core_formchangechecker.init',
        array(array(
            'formid' => 'theform'
        ))
);
$PAGE->requires->string_for_js('changesmadereallygoaway', 'moodle');

echo $OUTPUT->footer();
