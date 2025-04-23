********************************************************************************
# Report Scheduler

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au
[https://github.com/lsgs/redcap-report-scheduler](https://github.com/lsgs/redcap-report-scheduler)
********************************************************************************
## Summary

Create a schedule on which to run a report and email it (or a link to it) to a list of recipients.

**WARNING: THIS MODULE MAKES IT POSSIBLE TO SEND PARTICIPANT IDENTIFYING DATA (PHI) TO PEOPLE THAT SHOULD NOT HAVE ACCESS TO IT.**
**BE CAREFUL!**

**It is strongly recommended that this module is configured to require a module-specific permission.**
With the default "Design" permission option a user with "Design" but not "User Rights" permissions could circumvent any export restriction on their role by setting up a scheduled report with the "Full data set" option.

## Configuration

Scheduled reports are configured on the external module configuration page. You may set up multiple scheduled reports per project.
- Report ID of report you want exported and linked to or attached to the email.
- Export format (CSV raw or labels - stats packages may be added in a future version).
- Export permissions (De-identified/Remove identifier fields/Full data set)
- Frequency (every X hours/days/months/years)
- Date/time range (i.e. the start time and optionally an end time)
- Suppress the sending of the message if the report output is empty
- Message details:
  - From email address (of project user)
  - To email addresses (repeating field)
  - Subject line
  - Message body (rich text) - including link piping options (see below)
- Option to attach export file
- Enabled or not.

## Notes

- The module utilises a scheduled task running every 10 minutes to check whether any scheduled report is due.
- **It is strongly recommended that this module is configured to require a module-specific permission.**
- **THIS MODULE MAKES IT POSSIBLE TO SEND PARTICIPANT IDENTIFYING DATA (PHI) TO PEOPLE THAT SHOULD NOT HAVE ACCESS TO IT. BE CAREFUL!**
- The following "smart variables" may be utilised within the message body:
  - [report-link]: Hyperlink to View Report page
  - [report-url]: View Report page URL
  - [download-link]: Hyperlink to download report from File Repository
  - [download-url]: File Repository report download URL
- **Including the export file in the email as an attachment is not recommended**. It is generally not good practice to send data by email. Utilise the smart variables to send links to the report/output instead.

********************************************************************************