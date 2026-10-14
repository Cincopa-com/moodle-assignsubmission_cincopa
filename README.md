# Cincopa submissions for Moodle Assignment

`assignsubmission_cincopa` is an assignment submission plugin. Students can upload video, audio and images to [Cincopa](https://www.cincopa.com), or record them with a webcam, as their assignment submission. Teachers view the submission in the Moodle grader.

Each student gets a separate Cincopa gallery for each assignment.

## Requirements

- Moodle 4.2 or later. Tested on Moodle 4.5.
- A Cincopa account and an API token with read and write access to assets and galleries.
- If you use [automatic sub-accounts](#automatic-sub-accounts), the site-level token also needs account and token permissions, because the plugin lists and creates sub-accounts and tokens with it.

## Installation

**From a ZIP file**

1. Go to *Site administration → Plugins → Install plugins*.
2. Upload the plugin ZIP and follow the prompts.

**Manually**

1. Copy this folder to `<moodle>/mod/assign/submission/cincopa`.
2. Go to *Site administration → Notifications* to finish the install.

When you update the plugin, finish the upgrade on the Notifications page. This step registers the web service that the Moodle app uses.

## Site settings

You'll find these under *Site administration → Plugins → Activity modules → Assignment → Submission plugins → Cincopa submissions*.

| Setting | What it does |
|---|---|
| Enabled by default | Turns Cincopa submissions on for new assignments. |
| Template | The Cincopa gallery template used to display submissions. Defaults to `AICAK79_S47M`. |
| Cincopa Api Token | The site-wide token. Assignments that have no token of their own use it. |
| Use Sub-Accounts | Lets assignments use [automatic sub-accounts](#automatic-sub-accounts). |
| Submission template thumbnail size | How the uploader shows files: large thumbnails, smaller thumbnails, or a list. |
| Enable Recording for grade | Lets teachers record their screen from the grader page as feedback. The recording appears in the student's submission. |

## Assignment settings

When Cincopa submissions are enabled on an assignment, teachers can set:

- **Cincopa Api Token**: the account this assignment's submissions go to. If left empty, the site-wide token is used.
- **Allowed Asset Types**: optional. Passed to the Cincopa uploader to limit what students can upload.

### Automatic sub-accounts

When **Use Sub-Accounts** is on, the token field is replaced by a choice between **Automatic** and **Manual**:

- **Automatic** uses a Cincopa sub-account named after the month and year the course was created, for example `September_2026`. The plugin uses the site-level token to find that sub-account, and creates it if it doesn't exist. It then uses a token on that sub-account with asset and gallery access, creating one if there isn't one already.
- **Manual** lets the teacher enter a token.

If the plugin can't get a token for Automatic, that option is disabled and the form says why, for example that no site-level token is set, or which Cincopa API call failed.

### Per-course defaults

Two course custom fields, identified by their short names, set defaults for the course's assignments:

| Short name | Effect |
|---|---|
| `cp_token` | Pre-fills the token field. It also hides the Automatic/Manual choice for the course. |
| `cp_asset_types` | Pre-fills Allowed Asset Types. |

### Changing an assignment's account

Once an assignment has a submission record, the plugin avoids moving it to a different Cincopa account by accident. Moodle creates that record as soon as a student opens the assignment or a teacher opens the grader, so this can apply before anything has been uploaded. From then on:

- `cp_token` no longer pre-fills the token field.
- The Automatic/Manual choice opens on Manual, unless Automatic was saved before.
- Saving the form doesn't change the token unless the teacher chooses a different option.

A teacher can still change the token on purpose. Files that were already uploaded stay in the old Cincopa account. The plugin doesn't move them.

## Moodle app

Students can submit from the official Moodle app. The app gets the assignment's token and account from the `assignsubmission_cincopa_get_mobile_config` web service. That service is part of Moodle's mobile web service, so the mobile web service must be enabled on your site.

## Good to know

- **Reverting or removing a submission deletes its files.** When a submission is reverted to draft or removed, the plugin asks Cincopa to delete the student's gallery for that assignment, including its files.
- The upload form, the submission view and the Moodle app all use 2-hour temporary tokens. They never get the stored API token.
- If the automatic sub-account lookup fails, the assignment settings form shows the error that came back from the Cincopa API.

## Support

Registration and support documents are on Cincopa's [Moodle welcome page](https://www.cincopa.com/moodle/welcome).

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
