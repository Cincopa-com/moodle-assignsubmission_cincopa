<?php

namespace assignsubmission_cincopa\output;

require_once __DIR__ . '/../../encrypter.php';

define('ASSIGNSUBMISSION_CINCOPA_FILEAREA', 'submissions_cincopa');

class mobile  {
    public static function mobile_submission_edit($args) {
        global $OUTPUT, $DB;

        $args = (object) $args;

        // Parse args correctly from mobile web service format
        $parsed_args = [];
        if (is_array($args)) {
            foreach ($args as $arg) {
                if (isset($arg['name']) && isset($arg['value'])) {
                    $parsed_args[$arg['name']] = $arg['value'];
                }
            }
        } else {
            $parsed_args = (array) $args;
        }
        //s$userid = $parsed_args['userid'] ?? 0;
        $userid = $args->userid;
        
        // The assignment ID should be in the args but with a different name
        $assignmentid = $parsed_args['assign'] ?? 
                        $parsed_args['assignid'] ?? 
                        $parsed_args['assignmentid'] ?? 
                        $parsed_args['instanceid'] ?? 0;
        
        // If still no assignment ID, get from user's current submission
        if (!$assignmentid && $userid) {
            $assignmentid = $DB->get_field_sql(
                "SELECT assignment FROM {assign_submission} 
                WHERE userid = :userid 
                ORDER BY timemodified DESC LIMIT 1",
                ['userid' => $userid]
            );
        }

        $sql = "SELECT *
        FROM {assign_plugin_config}
        WHERE (name = 'courseApiToken' OR name = 'courseAssetTypes' OR name = 'cincopa_uid')
          AND assignment = ?";

        // Pass the ID as a parameter in an array
        $params = [(int) $assignmentid];

        // This will now only get records for that one assignment
        $configs_map = $DB->get_records_sql($sql, $params);

        
        $api_token = null;
        $asset_types = ''; // Use an empty string for types
        $cincopa_uid = null;

        // 2. Loop through the array of results
        foreach ($configs_map as $config_row) {
            switch ($config_row->name) {
                case 'courseApiToken':
                    $api_token = $config_row->value;
                    break;
                case 'courseAssetTypes':
                    $asset_types = $config_row->value;
                    break;
                case 'cincopa_uid':
                    $cincopa_uid = $config_row->value;
                    break;
            }
        }


        $token = get_config('assignsubmission_cincopa', 'api_token_cincopa');
        if (isset($api_token)) {
            $token = $api_token; // Assignment-specific token
        }        
        if (!empty($token)) {
            $studentgallery = "assign:" . $assignmentid . ":" . $userid;
            $expire = new \DateTime('+2 hour');
            $token = createSelfGeneratedTempTokenV3getTempAPIKeyV2($token, $expire, null, null, null, null);
        }

        
        $template = get_config('assignsubmission_cincopa', 'template_cincopa');
        $defaultView = get_config('assignsubmission_cincopa', 'submission_thumb_size_cincopa');

        // 4. Get UID from settings, with fallback for backward compatibility
        $uid = '';
        if (isset($cincopa_uid)) {
            $uid = $cincopa_uid; // Get saved UID
        } else if (!empty($token)) {
            // 5. Backward compatibility: Old submission, UID not saved. Fetch it.
            $url = "https://api.cincopa.com/v2/ping.json?api_token=".$token;
            $result = @file_get_contents($url);
            if($result) {
                $result_decoded = json_decode($result, true);
                if (isset($result_decoded['accid'])) {
                    $uid = $result_decoded['accid'];
                }
            }
        }

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('assignsubmission_cincopa/mobile_view_page', (object) array('token' => $token, 'userid' => $userid, 'uid' => ($uid ? $uid : ''), 'template' => $template, 'view' => $defaultView)),
                ]
            ],
            'javascript' => '
            var that = this; var phpUserId = "'.$userid.'"; var defToken = "'.$token.'"; var configs = "{token:\"'.$token.'\", uid:\"'.$uid.'\", allowed_types:\"'.$asset_types.'\"}";
            var allowed_types = "'.$asset_types.'";

            var result = {
                isEnabledForEdit: function () {
                    return true;
                },
                componentInit: async function() {       
                         
                    console.warn("Plugin did load Javascript");
                    console.log("Plugin loaded!");
                    // @codingStandardsIgnoreStart
                    // Wait for the DOM to be rendered.
                    setTimeout(() => {
                        console.log("DOM Loaded!")
                    });
                    
                    this.currentToken = "'.$token.'"
                    this.allowedTypes = "all";
                    this.allowedExtension = "all";
                    if(allowed_types){
                        this.allowedTypes = allowed_types;
                    }
                    this.currentUID = "'.$uid.'";
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