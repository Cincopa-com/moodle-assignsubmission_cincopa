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

namespace assignsubmission_cincopa\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

if (!class_exists(\core_external\external_api::class)) {
    // Before Moodle 4.2 these were global classes.
    require_once($CFG->libdir . '/externallib.php');
    foreach (['external_api', 'external_function_parameters', 'external_single_structure', 'external_value'] as $name) {
        class_alias($name, 'core_external\\' . $name);
    }
}

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Token and account for one assignment, for the Moodle app.
 *
 * The app runs the plugin's mobile handler once per session, before it knows
 * which assignment is open, and reuses the result everywhere. The page script
 * calls this instead each time an assignment opens, so every upload uses that
 * assignment's own token.
 */
class get_mobile_config extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id'),
            'userid' => new external_value(PARAM_INT, 'Owner of the submission, 0 for the current user', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $assignid
     * @param int $userid
     * @return array
     */
    public static function execute($assignid, $userid = 0) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['assignid' => $assignid, 'userid' => $userid]);

        list($course, $cm) = get_course_and_cm_from_instance($params['assignid'], 'assign');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // The token can upload into and delete from the assignment's account, so
        // hand it only to someone who may submit here, or grade when it is
        // somebody else's submission.
        $userid = $params['userid'] ? $params['userid'] : $USER->id;
        if ($userid == $USER->id) {
            require_capability('mod/assign:submit', $context);
        } else {
            require_capability('mod/assign:grade', $context);
        }

        $assign = new \assign($context, $cm, $course);
        $plugin = $assign->get_submission_plugin_by_type('cincopa');
        if (!$plugin || !$plugin->is_enabled()) {
            throw new \moodle_exception('cincopa_not_enabled', 'assignsubmission_cincopa');
        }

        return $plugin->get_mobile_config($userid);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'token' => new external_value(PARAM_RAW, 'Short-lived Cincopa token, empty when none is configured'),
            'uid' => new external_value(PARAM_RAW, 'Cincopa account id holding the gallery'),
            'allowedtypes' => new external_value(PARAM_RAW, 'Allowed asset types, empty for all'),
            'userid' => new external_value(PARAM_INT, 'Owner of the submission'),
        ]);
    }
}
