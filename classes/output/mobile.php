<?php

namespace assignsubmission_cincopa\output;

require_once __DIR__ . '/../../encrypter.php';

define('ASSIGNSUBMISSION_CINCOPA_FILEAREA', 'submissions_cincopa');

class mobile  {
    public static function mobile_submission_edit($args) {
        global $OUTPUT, $DB;

        $args = (object) $args;
        $assignmentid = $args->assignmentid;

        // Fetch all relevant configs for this specific assignment in one query.
        $sql = "SELECT name, value FROM {assign_plugin_config} WHERE assignment = :assignmentid AND plugin = 'cincopa'";
        $params = ['assignmentid' => $assignmentid];
        $configs = $DB->get_records_sql($sql, $params);

        // Determine the correct API token to use.
        $token = get_config('assignsubmission_cincopa', 'api_token_cincopa'); // Default global token.
        if (isset($configs['courseapitoken'])) {
            $token = $configs['courseapitoken']->value; // Override with assignment-specific token if it exists.
        }

        // Get the saved UID.
        $uid = '';
        if (isset($configs['cincopa_uid'])) {
            $uid = $configs['cincopa_uid']->value;
        }
        
        $userid = $args->userid;
        $studentgallery = "assign:" . $assignmentid . ":" . $userid;

        // Generate the temporary token for the iframe, now including the rrid.
        $temp_token = '';
        if (!empty($token)) {
            $expire = new \DateTime('+2 hour');
            // *** UPDATED LINE: Pass the rrid to the temp token generator. ***
            $temp_token = createSelfGeneratedTempTokenV3getTempAPIKeyV2($token, $expire, null, null, null, $studentgallery);
        }
        
        $template = get_config('assignsubmission_cincopa', 'template_cincopa');
        $defaultView = get_config('assignsubmission_cincopa', 'submission_thumb_size_cincopa');

        // Pass the correct UID and the new temporary token to the template.
        $template_context = (object)[
            'token' => $temp_token, // This is the temporary token for the upload iframe.
            'permanent_token' => $token, // This is the permanent token for gallery viewing.
            'userid' => $userid,
            'uid' => $uid,
            'template' => $template,
            'view' => $defaultView
        ];

        // Fetch all configs for JS, not just the two from the old query.
        $allconfigs = $DB->get_records('assign_plugin_config', ['assignment' => $assignmentid]);

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('assignsubmission_cincopa/mobile_view_page', $template_context),
                ]
            ],
            'javascript' => '
            var that = this; var phpUserId = "'.$userid.'"; var defToken = "'.$token.'"; var configs = '.json_encode(array_values($allconfigs)).';
            var result = {
                isEnabledForEdit: function () {
                    return true;
                },
                componentInit: async function() {       
                         
                    console.warn("Plugin did load Javascript");
                    // The UID is now passed directly from PHP, so we set it here.
                    this.currentUID = "' . $uid . '";
                    
                    this.currentToken = configs?.find?.(el => el.assignment == this.assign.id && el.name == "courseApiToken")?.value;
                    this.allowedTypes = "all";
                    this.allowedExtension = "all";
                    if(configs?.find?.(el => el.assignment == this.assign.id && el.name == "courseAssetTypes")?.value) {
                        this.allowedTypes = configs?.find?.(el => el.assignment == this.assign.id && el.name == "courseAssetTypes")?.value;
                    }

                    if(this.submission?.status == "submitted") {
                        this.hasAssignmentSubmitted = true;
                    } else {
                        this.hasAssignmentSubmitted = false;
                    }
                    try {
                        if(!this.edit) {
                            const galleryName = "rrid:assign:"+this.assign.id+":"+phpUserId;
                            const galleryReq = await fetch("https://api.cincopa.com/v2/gallery.list.json?api_token="+(this.currentToken ?? defToken)+"&search=caption="+galleryName);
                            const galleryRes = await galleryReq.json();
    
                            if(galleryRes?.success && galleryRes?.galleries?.length) {
                                const fid = galleryRes.galleries[0].fid;
                                const itemsReq = await fetch("https://api.cincopa.com/v2/gallery.get_items.json?api_token="+(this.currentToken ?? defToken)+"&fid="+fid);
                                const itemsRes = await itemsReq.json();
                                if(itemsRes?.success && !itemsRes?.folder?.items_data?.items_count) {
                                    this.hasAssignmentSubmitted = false;
                                }
                            }
                        }
                    } catch(e) {
                        console.log(e);
                    }
                    // @codingStandardsIgnoreEnd
                    return true;
                },

                hasDataChanged: function() {
                    return true;
                },

                canEditOffline: function() {
                    return false;
                },

                prepareSubmissionData: function(assign, submission, plugin, inputData, pluginData) {
                    pluginData.onlinetext_editor = {
                        text: "submission for" + "rrid:assign"+assign.id+":"+submission.userid,
                        format: 1,
                        itemid: 0,
                    };
                }

            };
            result;',
        ];
    
    }
}