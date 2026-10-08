<?php

namespace assignsubmission_cincopa\output;

class mobile  {
    /**
     * Template and script for the Moodle app's submission page.
     *
     * The app calls this once, when it loads the plugin, with no assignment in
     * $args, and reuses the result for every assignment. So nothing here may
     * depend on the assignment: the page script asks for the open assignment's
     * token and account through assignsubmission_cincopa_get_mobile_config.
     *
     * @param array $args
     * @return array
     */
    public static function mobile_submission_edit($args) {
        global $OUTPUT;

        $template = get_config('assignsubmission_cincopa', 'template_cincopa');
        $defaultView = get_config('assignsubmission_cincopa', 'submission_thumb_size_cincopa');

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('assignsubmission_cincopa/mobile_view_page', (object) array('template' => $template, 'view' => $defaultView)),
                ]
            ],
            'javascript' => '
            var result = {
                isEnabledForEdit: function () {
                    return true;
                },
                componentInit: async function() {
                    // @codingStandardsIgnoreStart
                    this.currentToken = "";
                    this.currentUID = "";
                    this.allowedTypes = "all";
                    this.allowedExtension = "all";
                    this.configLoaded = false;
                    this.configError = false;
                    this.hasAssignmentSubmitted = this.submission?.status == "submitted";

                    // Ask for this assignment\'s token every time it opens. Anything
                    // the PHP handler put here would be shared by all assignments.
                    var config;
                    try {
                        var args = {assignid: this.assign.id, userid: this.submission?.userid || 0};
                        var preSets = {getFromCache: false, saveToCache: false};
                        config = this.CoreSitePluginsProvider
                            ? await this.CoreSitePluginsProvider.callWS("assignsubmission_cincopa_get_mobile_config", args, preSets)
                            : await this.CoreSitesProvider.getCurrentSite().read("assignsubmission_cincopa_get_mobile_config", args, preSets);
                    } catch (e) {
                        console.error(e);
                        this.configError = true;
                        return true;
                    }
                    this.currentToken = config.token;
                    this.currentUID = config.uid;
                    if (config.allowedtypes) {
                        this.allowedTypes = config.allowedtypes;
                    }
                    this.configLoaded = true;

                    try {
                        if (!this.edit && this.currentToken) {
                            const galleryName = "rrid:assign:"+this.assign.id+":"+config.userid;
                            const galleryReq = await fetch("https://api.cincopa.com/v2/gallery.list.json?api_token="+this.currentToken+"&search=caption="+galleryName);
                            const galleryRes = await galleryReq.json();

                            if(galleryRes?.success && galleryRes?.galleries?.length) {
                                const fid = galleryRes.galleries[0].fid;
                                const itemsReq = await fetch("https://api.cincopa.com/v2/gallery.get_items.json?api_token="+this.currentToken+"&fid="+fid);
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
