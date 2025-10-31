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
 * This file contains the definition for the library class for cincopa submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package    assignsubmission_cincopa
 * @copyright  2017 Cincopa LTD <moodle@cincopa.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once 'encrypter.php';
defined('MOODLE_INTERNAL') || die();
define('ASSIGNSUBMISSION_CINCOPA_FILEAREA', 'submissions_cincopa');

/**
 * library class for onlinetext submission plugin extending submission plugin base class
 *
 * @package    assignsubmission_cincopa
 * @copyright  2017 Cincopa LTD <moodle@cincopa.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

 */

class assign_submission_cincopa extends assign_submission_plugin
{
    static $uid;

    /**
     * Get the API token to use for Cincopa calls.
     * This function now simply retrieves the token saved in the course settings.
     * The logic for finding/creating the token is now handled in the get_settings() form.
     * @return string
     */
    private function get_cincopa_token() {
        // Use the token saved for the course if it exists.
        if ($this->get_config('courseApiToken')) {
            return $this->get_config('courseApiToken');
        }
        // Otherwise, fall back to the global token from the main plugin settings.
        return get_config('assignsubmission_cincopa', 'api_token_cincopa');
    }

    /**
     * Get the Cincopa User ID (accid) based on the current token.
     * This is now only called once when the submission is saved.
     * @return string
     */
    public function get_uid(){
        if(!self::$uid) {
            $current_token = $this->get_cincopa_token();
            if (empty($current_token)) {
                return null;
            }
    
            $url = "https://api.cincopa.com/v2/ping.json?api_token=" . $current_token;
            $result = @file_get_contents($url); // Use @ to suppress warnings if the URL fails.
            if ($result) {
                $result_decoded = json_decode($result, true);
                if (isset($result_decoded['accid'])) {
                    self::$uid = $result_decoded['accid'];
                }
            }
        }

        return self::$uid;
    }
    
    public function get_name()
    {
        return get_string('cincopa', 'assignsubmission_cincopa');
    }

    /**
     * Get the settings for the Cincopa submission plugin form.
     *
     * @global stdClass $CFG
     * @global stdClass $COURSE
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform)
    {
        global $CFG, $COURSE;

        // Helper function to get course custom field metadata.
        function get_cp_course_metadata($courseid)
        {
            if (!class_exists('\core_customfield\handler')) {
                return [];
            }
            $handler = \core_customfield\handler::get_handler('core_course', 'course');
            $datas = $handler->get_instance_data($courseid);
            $metadata = [];
            foreach ($datas as $data) {
                if (empty($data->get_value())) {
                    continue;
                }
                $metadata[$data->get_field()->get('shortname')] = $data->get_value();
            }
            return $metadata;
        }

        $use_sub_accounts = get_config('assignsubmission_cincopa', 'use_sub_accounts');
        $course_metadata = get_cp_course_metadata($COURSE->id);
        $has_custom_field_token = isset($course_metadata['cp_token']) && !empty($course_metadata['cp_token']);

        // If a token is in a custom field OR sub-accounts are disabled, use the simple manual input.
        if ($has_custom_field_token || !$use_sub_accounts) {
            $mform->addElement('text', 'assignsubmission_cincopa_courseApiToken', get_string('manual_token', 'assignsubmission_cincopa'), '');
            
            $courseToken = $this->get_config('courseApiToken');
            if ($courseToken) {
                // If a token is already saved for this assignment, it takes precedence.
                $mform->setDefault('assignsubmission_cincopa_courseApiToken', $courseToken);
            } else if ($has_custom_field_token) {
                // Otherwise, if no assignment token is set, use the custom field as the default.
                $mform->setDefault('assignsubmission_cincopa_courseApiToken', $course_metadata['cp_token']);
            }

        } else {
            // --- NEW SUB-ACCOUNT TOKEN LOGIC ---
            $mform->addElement('radio', 'assignsubmission_cincopa_token_mode', get_string('token_selection_mode', 'assignsubmission_cincopa'), get_string('token_mode_auto', 'assignsubmission_cincopa'), 'auto');
            $mform->addElement('radio', 'assignsubmission_cincopa_token_mode', '', get_string('token_mode_manual', 'assignsubmission_cincopa'), 'manual');
            
            $auto_token_details = $this->find_course_subaccount_token($COURSE->id);

            if ($auto_token_details['token']) {
                $params = new stdClass();
                $params->subaccount = $auto_token_details['sub_account_name'];
                $params->token = substr($auto_token_details['token'], 0, 10) . '...';
                $mform->addElement('static', 'assignsubmission_cincopa_auto_token_display', '', get_string('auto_token_found', 'assignsubmission_cincopa', $params));
                $mform->addElement('hidden', 'assignsubmission_cincopa_auto_token_value', $auto_token_details['token']);
                $mform->setDefault('assignsubmission_cincopa_auto_token_value', $auto_token_details['token']);

            } else {
                $error_string = get_string($auto_token_details['error_code'], 'assignsubmission_cincopa', $auto_token_details['error_message']);
                $mform->addElement('static', 'assignsubmission_cincopa_auto_token_display', '', $error_string);
                $mform->hardFreeze('assignsubmission_cincopa_token_mode', 'auto');
                $mform->setDefault('assignsubmission_cincopa_token_mode', 'manual');
            }

            $mform->addElement('text', 'assignsubmission_cincopa_manualApiToken', get_string('manual_token', 'assignsubmission_cincopa'));
            $mform->disabledIf('assignsubmission_cincopa_manualApiToken', 'assignsubmission_cincopa_token_mode', 'eq', 'auto');

            if ($this->get_config('tokenMode')) {
                $mform->setDefault('assignsubmission_cincopa_token_mode', $this->get_config('tokenMode'));
            } else {
                 $mform->setDefault('assignsubmission_cincopa_token_mode', 'auto');
            }
            if ($this->get_config('courseApiToken')) {
                 $mform->setDefault('assignsubmission_cincopa_manualApiToken', $this->get_config('courseApiToken'));
            }
        }

        $mform->addElement('text', 'assignsubmission_cincopa_courseAssetTypes', 'Allowed Asset Types (Optional)', '');
        // *** RESTORED LOGIC FOR ASSET TYPES ***
        $courseAssetTypes = $this->get_config('courseAssetTypes');
        if ($courseAssetTypes) {
            // If a value is saved for this assignment, use it.
            $mform->setDefault('assignsubmission_cincopa_courseAssetTypes', $courseAssetTypes);
        } else if (isset($course_metadata['cp_asset_types'])) {
            // Otherwise, check for a course custom field as a fallback.
            $mform->setDefault('assignsubmission_cincopa_courseAssetTypes', $course_metadata['cp_asset_types']);
        }


        if ($CFG->version >= 2017111300) {
            $mform->hideIf('assignsubmission_cincopa_courseApiToken', 'assignsubmission_cincopa_enabled', 'notchecked');
            $mform->hideIf('assignsubmission_cincopa_token_mode', 'assignsubmission_cincopa_enabled', 'notchecked');
            $mform->hideIf('assignsubmission_cincopa_auto_token_display', 'assignsubmission_cincopa_enabled', 'notchecked');
            $mform->hideIf('assignsubmission_cincopa_manualApiToken', 'assignsubmission_cincopa_enabled', 'notchecked');
            $mform->hideIf('assignsubmission_cincopa_courseAssetTypes', 'assignsubmission_cincopa_enabled', 'notchecked');
        }
    }

    /**
     * Finds or creates a Cincopa token for a sub-account based on the course creation date.
     * @param int $courseid
     * @return array ['token' => string|null, 'sub_account_name' => string, 'error_code' => string|null, 'error_message' => string|null]
     */
    private function find_course_subaccount_token($courseid) {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        
        $target_account_name = date('F_Y', $course->timecreated);
        
        $main_api_token = get_config('assignsubmission_cincopa', 'api_token_cincopa');
        if (empty($main_api_token)) {
            return ['token' => null, 'sub_account_name' => $target_account_name, 'error_code' => 'auto_token_not_found', 'error_message' => $target_account_name];
        }

        $url_list_sub = "https://api.cincopa.com/v2/account.list_sub.json?api_token=" . $main_api_token;
        $response_list_sub = @file_get_contents($url_list_sub);
        $sub_account_uid = null;

        if ($response_list_sub) {
            $data_list_sub = json_decode($response_list_sub, true);
            if (!empty($data_list_sub['sub_accounts'])) {
                foreach ($data_list_sub['sub_accounts'] as $sub_account) {
                    if (isset($sub_account['account_name']) && $sub_account['account_name'] == $target_account_name) {
                        $sub_account_uid = $sub_account['uid'];
                        break;
                    }
                }
            }
        }
        
        if (empty($sub_account_uid)) {
            $url_create_sub = "https://api.cincopa.com/v2/account.add_sub.json?api_token=" . $main_api_token . "&name=" . urlencode($target_account_name);
            $response_create_sub = @file_get_contents($url_create_sub);

            if ($response_create_sub) {
                $data_create_sub = json_decode($response_create_sub, true);
                if (isset($data_create_sub['success']) && $data_create_sub['success'] && !empty($data_create_sub['new_sub_account_uid'])) {
                    $sub_account_uid = $data_create_sub['new_sub_account_uid'];
                } else if (isset($data_create_sub['message'])) {
                    // GENERIC ERROR HANDLING
                    return ['token' => null, 'sub_account_name' => $target_account_name, 'error_code' => 'sub_account_creation_api_error', 'error_message' => $data_create_sub['message']];
                }
            }

            if (empty($sub_account_uid)) {
                 return ['token' => null, 'sub_account_name' => $target_account_name, 'error_code' => 'auto_token_not_found', 'error_message' => $target_account_name];
            }
        }

        $url_list_tokens = "https://api.cincopa.com/v2/token.list.json?api_token=" . $main_api_token . "&sub_account=" . $sub_account_uid;
        $response_list_tokens = @file_get_contents($url_list_tokens);
        $final_token = null;

        if ($response_list_tokens) {
            $data_list_tokens = json_decode($response_list_tokens, true);
            if (!empty($data_list_tokens['tokens'])) {
                foreach ($data_list_tokens['tokens'] as $token) {
                    $permissions = $token['permissions'];
                    if (strpos($permissions, 'asset.*') !== false ||
                       (strpos($permissions, 'asset.read') !== false && strpos($permissions, 'asset.write') !== false)) {
                        $final_token = $token['api_token'];
                        break;
                    }
                }
            }
        }

        if (empty($final_token)) {
            $url_create_token = "https://api.cincopa.com/v2/token.create.json?api_token=" . $main_api_token .
                                "&name=moodle_course_" . $courseid . "_token&permissions=asset.*&sub_account=" . $sub_account_uid;
            $response_create_token = @file_get_contents($url_create_token);
            if ($response_create_token) {
                $data_create_token = json_decode($response_create_token, true);
                if (isset($data_create_token['success']) && $data_create_token['success'] && !empty($data_create_token['api_token'])) {
                    $final_token = $data_create_token['api_token'];
                }
            }
        }
        
        if ($final_token) {
            return ['token' => $final_token, 'sub_account_name' => $target_account_name, 'error_code' => null, 'error_message' => null];
        } else {
            return ['token' => null, 'sub_account_name' => $target_account_name, 'error_code' => 'auto_token_not_found', 'error_message' => $target_account_name];
        }
    }

    /**
     * Save the settings for file submission plugin
     *
     * @param stdClass $data
     * @return bool 
     */
    public function save_settings(stdClass $data)
    {
        // The manual/override field is present.
        if (isset($data->assignsubmission_cincopa_courseApiToken)) {
            $this->set_config('courseApiToken', $data->assignsubmission_cincopa_courseApiToken);
            $this->set_config('tokenMode', null); // Clear the mode if we switch back to manual.
        }
        // The sub-account fields are present.
        else if (isset($data->assignsubmission_cincopa_token_mode)) {
            $token_mode = $data->assignsubmission_cincopa_token_mode;
            $this->set_config('tokenMode', $token_mode);

            if ($token_mode == 'auto') {
                if (isset($data->assignsubmission_cincopa_auto_token_value)) {
                    $this->set_config('courseApiToken', $data->assignsubmission_cincopa_auto_token_value);
                }
            } else { // manual
                if (isset($data->assignsubmission_cincopa_manualApiToken)) {
                    $this->set_config('courseApiToken', $data->assignsubmission_cincopa_manualApiToken);
                }
            }
        }
        
        if (isset($data->assignsubmission_cincopa_courseAssetTypes)) {
            $this->set_config('courseAssetTypes', $data->assignsubmission_cincopa_courseAssetTypes);
        }

        return true;
    }

    /**
     * Add elements to submission form
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return true if elements were added to the form
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data)
    {
        if (!isset($data->cincopa)) {
            $data->cincopa = '';
        }

        if ($submission) {
            $defaultapitoken = $this->get_cincopa_token();
            $cmid = $submission->assignment;
            $userid = $submission->userid;
            $studentgallery = "assign:" . $cmid . ":" . $userid;
            
            $expire = new DateTime('+2 hour');
            $defaultapitoken = createSelfGeneratedTempTokenV3getTempAPIKeyV2($defaultapitoken, $expire, null, null, null, $studentgallery);

            $defaultView = get_config('assignsubmission_cincopa', 'submission_thumb_size_cincopa');

            if($this->get_config('courseAssetTypes')) {
                $allowedAssets = $this->get_config('courseAssetTypes');
            } else {
                $allowedAssets = 'all';
            }
            if($this->get_config('courseAssetTypesExtensions')) {
                $allowedExtensions = $this->get_config('courseAssetTypesExtensions');
            } else {
                $allowedExtensions = 'all';
            }
            $iframe = '<iframe height="500" width="100%"  allow="microphone *; camera *; display-capture *" allowfullscreen src="https://api.cincopa.com/v2/upload.iframe?api_token=' . $defaultapitoken . '&rrid=' . $studentgallery . '&disable_mobile_app=true&disable-undo=true&view=' . $defaultView . '&allow=' . $allowedAssets . '&allowExtensions='.$allowedExtensions.'" ></iframe>';
            $mform->addElement('html', $iframe);
        }
        return true;
    }

    /**
     * Called when a submission is created or updated.
     * We use this to save the Cincopa User ID so we don't have to fetch it on every view.
     * @param stdClass $submission
     * @param stdClass $data
     * @return boolean
     */
    public function save(stdClass $submission, stdClass $data)
    {
        // Get the UID from the API. This will only run once per request due to static caching in get_uid().
        $uid = $this->get_uid();
        if ($uid) {
            // Save the UID to the plugin's configuration for this specific assignment instance.
            $this->set_config('cincopa_uid', $uid);
        }
        return true;
    }
    
    /**
     * * @param stdClass $submission
     * @param boolean $showviewlink
     * @return string
     */
    public function view_summary(stdClass $submission, &$showviewlink)
    {   
        $showviewlink = ($submission->status == 'submitted' || $submission->timemodified) ? true : false;

        $result = '';
       
        $title = 'Gallery';

        if ($submission && ($submission->status == 'submitted' || $submission->timemodified)) {
                                    $result .= $title;
                            } else {
                        $result = 'No Cincopa Submission';
        }
        return $result;
    }

    /**
     * Display gallery in iframe
     * * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission)
    {
        $cmid = $submission->assignment;
        $defaultapitoken = $this->get_cincopa_token();
        $defaulttemplate = get_config('assignsubmission_cincopa', 'template_cincopa');
        $enabled_recording = get_config('assignsubmission_cincopa', 'enabled_recording');
        
        // *** BACKWARD COMPATIBILITY FOR UID ***
        // Get the UID from the saved configuration.
        $uid = $this->get_config('cincopa_uid');
        // If it doesn't exist (for older submissions), get it from the API as a fallback.
        if (empty($uid)) {
            $uid = $this->get_uid();
        }

        $iframeteacher = '<div id="deleteBox"></div><div id="cp_widget_1">...</div>
                            <div id="recorderBox"><div id="recorderBox_title"></div><div id="recorderBox_recorder"></div></div> 
                            <script type="text/javascript"> 
                                var url = new URL(location.href);
                                 var enabled_recording = "'.($enabled_recording ? 'true' : 'false').'";
                                var cpo = []; var token =  "'. $defaultapitoken .'"; var submissionStatus = "'.$submission->status.'";
                                cpo["_object"] ="cp_widget_1"; 
                                cpo["_fid"] = "rrid:assign:' . $cmid . ':' . $submission->userid . '!'.$uid.'!'.$defaulttemplate.'";
                                var _cpmp = _cpmp || []; _cpmp.push(cpo); 
                                (function() { var cp = document.createElement("script"); cp.type = "text/javascript"; 
                                cp.async = true; cp.src = "//rtcdn.cincopa.com/libasync.js"; 
                                var c = document.getElementsByTagName("script")[0]; 
                                c.parentNode.insertBefore(cp, c);
                                cp.onload = async function() { 
                                    if(location.hash.indexOf("purgecache") > -1){
                                        window.cincopa = window.cincopa || {};
                                        window.cincopa.qs = window.cincopa.qs || {};
                                        window.cincopa.qs["cpdebug"] = "purgecache";
                                    }
                                    window.cincopa.registeredFunctions.push({
                                        func: function (name, data, gallery) {
                                            gallery.args.add_text = "title";
                                            //push this line with grader code
                                            //gallery.args.lightbox_text = url.searchParams.get("action") == "grader" ? "title and description and exif" : "title and description";
                                            gallery.args.lightbox_text = "title and description";
                                            gallery.args.lightbox_zoom = true;
                                            gallery.args.lightbox_zoom_type = "mousewheel";
                                            gallery.args.lightbox_video_autoplay = false;
                                            gallery.args.optimize_load = "load_all";
                                            if(url.searchParams.get("action") == "grader" && enabled_recording == "true"){
                                                gallery.args.showProcessingItems = true;
                                                gallery.args.reloadForProcessing = true;
                                            }                                            
                                        }, filter: "runtime.on-args"
                                    });

                                    window.cincopa.registeredFunctions.push({
                                        func: function (name, data, gallery) {
                                                if(gallery && gallery.args && gallery.args.fid ) {
                                                    const fid = gallery.args.fid;
                                                    if(!document.querySelector(".delete_submission")){
                                                        var deleteButton = document.createElement("button");
                                                        deleteButton.className = "btn btn-primary delete_submission";
                                                        deleteButton.innerText = "Delete Submission";
                                                        deleteButton.style.marginBottom = "20px";
                                                        deleteButton.style.outline = "none";
                                                        document.getElementById("deleteBox").append(deleteButton);

                                                        var confirmDeleteBlock = document.createElement("div");
                                                        confirmDeleteBlock.className = "confirm-delete-block";                                        
                                                        confirmDeleteBlock.style.marginBottom = "20px";
                                                        confirmDeleteBlock.style.display = "none";
                                                        confirmDeleteBlock.style.border = "1px solid #d6d6d6";
                                                        confirmDeleteBlock.style.maxWidth = "360px";
                                                        confirmDeleteBlock.style.padding = "20px";
                                                        document.getElementById("deleteBox").append(confirmDeleteBlock);

                                                        var confirmDeleteMessage = document.createElement("div");
                                                        confirmDeleteMessage.innerText = "Are you sure you want to delete this gallery?";
                                                        confirmDeleteMessage.style.marginBottom = "20px";
                                                        confirmDeleteMessage.style.fontWeight = "700";
                                                        confirmDeleteBlock.append(confirmDeleteMessage);

                                                        var confirmDeleteYes = document.createElement("button");
                                                        confirmDeleteYes.className = "btn btn-primary";
                                                        confirmDeleteYes.innerText = "Yes";
                                                        confirmDeleteBlock.append(confirmDeleteYes);

                                                        var confirmDeleteNo = document.createElement("button");
                                                        confirmDeleteNo.className = "btn btn-primary";
                                                        confirmDeleteNo.innerText = "No";
                                                        confirmDeleteNo.style.backgroundColor = "#db4c3f";
                                                        confirmDeleteNo.style.borderColor = "#db4c3f";
                                                        confirmDeleteNo.style.marginLeft = "10px";
                                                        confirmDeleteBlock.append(confirmDeleteNo);

                                                        deleteButton.onclick = async function() {
                                                            confirmDeleteBlock.style.display = "block";
                                                        }

                                                        confirmDeleteNo.onclick = async function() {
                                                            confirmDeleteBlock.style.display = "none";
                                                        }

                                                        confirmDeleteYes.onclick = async function() {
                                                            const deleteReq = await fetch("https://api.cincopa.com/v2/gallery.delete.json?api_token="+token+"&fid="+fid+"&delete_assets=yes");
                                                            const deleteRes = await deleteReq.json();
                                                            var oldHash = location.hash;
                                                            location.hash = oldHash.indexOf("#") > -1 ? oldHash + "&purgecache" : "#purgecache";
                                                            setTimeout(function(){
                                                                location.reload();
                                                            }, 1000);
                                                        }
                                                    }
                                                    
                                                    // Check if only in grader page
                                                    //return;
                                                    if(url.searchParams.get("action") == "grader" && !window.isRecorderInit && enabled_recording == "true") {
                                                        const recorderBox = document.getElementById("recorderBox_title");
                                                        recorderBox.innerHTML = "<br /><br /><h4 class=\"cp_recording_title\">Recording</h4><h3>Grade your student\'s work by recording your screen, leave Notify student checkmark active so student will see new recording in his submission</h3><br /><br />"
                                                        const uploadScript = document.createElement("script");
                                                        uploadScript.src = "//wwwcdn.cincopa.com/_cms/ugc/uploaderUI.js";
                                                        c.parentNode.insertBefore(uploadScript, cp.nextSibling);

                                                        const recorderBoxRecorder = document.getElementById("recorderBox_recorder");

                                                        uploadScript.onload = function() {
                                                            const recorderScript = document.createElement("script");
                                                            recorderScript.src = "//www.cincopa.com/_cms/ugc/v2/recorderui.js";
                                                            c.parentNode.insertBefore(recorderScript, uploadScript.nextSibling);
                                                            var reloadTimer;
                                                            recorderScript.onload = function() {
                                                                const uploadURL = gallery.args.upload_url.replace("&addtofid=*", "&addtofid="); //res.galleries[0].upload_url;
                                                                console.log(uploadURL);
                                                                const cpRecorder = new cpRecorderUI(recorderBoxRecorder, {
                                                                    width: "400px",
                                                                    height: "400px",
                                                                    resolution: "480",
                                                                    frameRate: 25,
                                                                    theme_color: "#37b3ff",
                                                                    uploadWhileRecording: true,
                                                                    default_tab: "screen",
                                                                    upload_url: uploadURL,
                                                                    rectraceMode: true,
                                                                    textRetake: "The video has been processed.",
                                                                    textRetakeLink: "if you would like to delete and retake the video.",
                                                                    onUploadComplete: async function(e) {
                                                                        const rid = e.rid;
                                                                        const req = await fetch("https://api.cincopa.com/v2/asset.set_meta.json?api_token=" + token + "&rid=" + rid + "&caption=Teacher grade recording " + Date.now());
                                                                        const res = await req.json();
                                                                        console.log(res);
                                                                        clearTimeout(reloadTimer);
                                                                        reloadTimer = setTimeout(function(){
                                                                            window.cincopa = window.cincopa || {};
                                                                            window.cincopa.qs = window.cincopa.qs || {};
                                                                            window.cincopa.qs["cpdebug"] = "purgecache";
                                                                            window.cincopa.boot_gallery({"_object": "cp_widget_1" ,"_fid" : "rrid:assign:' . $cmid . ':' . $submission->userid . '!'.$uid.'!'.$defaulttemplate.'"});
                                                                        },5000);
                                                                    },
                                                                    onDelete: async function(e){
                                                                        var serverData = e.xhr.responseText;
                                                                        var rid = serverData.split("\n")[5].split(" ")[3];
                                                                        const deleteReq = await fetch("https://api.cincopa.com/v2/asset.delete.json?api_token="+token+"&rid="+rid);
                                                                        const deleteRes = await deleteReq.json();
                                                                        clearTimeout(reloadTimer);
                                                                        window.cincopa = window.cincopa || {};
                                                                        window.cincopa.qs = window.cincopa.qs || {};
                                                                        window.cincopa.qs["cpdebug"] = "purgecache";
                                                                        window.cincopa.boot_gallery({"_object": "cp_widget_1" ,"_fid" : "rrid:assign:' . $cmid . ':' . $submission->userid . '!'.$uid.'!'.$defaulttemplate.'"});
                                                                    },
                                                                });
                                                                console.log(cpRecorder);
                                                                window.isRecorderInit = false;
                                                                document.querySelector(".assignsubmission_cincopa .expandsummaryicon").onclick = function() {
                                                                    if(window.isRecorderInit) {
                                                                        return;
                                                                    }
                                                                    window.isRecorderInit = true;
                                                                    if(this.querySelector("fa-plus")) {
                                                                        return true
                                                                    } else cpRecorder.start();
                                                                }
                                                            }
                                                        }
                                                    }
                                            }
                                        }, filter: "runtime.on-media-json"
                                    });                                    
                                }
                            })(); 
                        </script>';
        if ($submission->status != 'submitted') {
            return '';
        } else {
            return $iframeteacher;
        }
    }

    /**
     * * @param stdClass $submission
     * @return type
     */
    public function is_empty(stdClass $submission)
    {
        return $this->view($submission) == '';
    }
}