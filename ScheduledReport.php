<?php
/**
 * REDCap External Module: Report Scheduler
 * Set up a schedule on which to run a report and email it to a list of recipients.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ReportScheduler;

/**
 * ScheduledReport
 * @author luke.stevens
 */
class ScheduledReport {
        protected $srid;
        protected $pid;
        protected $report_id;
        protected $report_title;
        protected $permission_level;
        protected $report_format;
        protected $frequency;
        protected $frequency_unit;
        protected $active_from;
        protected $active_to;
        protected $last_sent;
        protected $suppress_empty;
        protected $attach_export;
        protected $message;
        
        public function getSRId() { return $this->srid; }
        public function setSRid($val) { $this->srid = $val; }
        public function getPid() { return $this->pid; }
        public function setPid($val) { $this->pid = $val; }
        public function getReportId() { return $this->report_id; }
        public function setReportId($val) { $this->report_id = intval($val); }
        public function getReportTitle() { return $this->report_title; }
        public function setReportTitle($val) { $this->report_title = $val; }
        public function getPermissionLevel() { return $this->permission_level; }
        public function setPermissionLevel($val) { $this->permission_level = $val; }
        public function getReportFormat() { return $this->report_format; }
        public function setReportFormat($val) { $this->report_format = $val; }
        public function getFrequency() { return $this->frequency; }
        public function setFrequency($val) { $this->frequency = trim($val); }
        public function getFrequencyUnit() { return $this->frequency_unit; }
        public function setFrequencyUnit($val) { $this->frequency_unit = $val; }
        public function getActiveFrom() { return $this->active_from; }
        public function setActiveFrom($val) { $this->active_from = $this->setDateTimeFromVal($val); }
        public function getActiveTo() { return $this->active_to; }
        public function setActiveTo($val) { $this->active_to = $this->setDateTimeFromVal($val); }
        public function getLastSent() { return $this->last_sent; }
        public function setLastSent($val) { $this->last_sent = $this->setDateTimeFromVal($val); }
        public function getSuppressEmpty() { return $this->suppress_empty; }
        public function setSuppressEmpty($val) { $this->suppress_empty = $val; }
        public function getAttachExport() { return $this->attach_export; }
        public function setAttachExport($val) { $this->attach_export = intval($val); }
        public function getMessage() { return $this->message; }
        public function setMessage($val) { 
            if (!($val instanceof \Message)) {
                throw new \Exception('Not a valid \\Message object: '.print_r($val, true));
            }
            $this->message = $val; 
        }

        public function getSettingsPageIndex() {
                return 1+$this->getSRId();
        }
        
        protected function setDateTimeFromVal($val) {
            if (empty($val)) {
                $dtVal = null;
            } else if ($val instanceof \DateTime) {
                $dtVal = $val;
            } else {
                $dtVal =  new \DateTime($val);
            }
            return $dtVal;
        }
        
        public function isDue() {
                $now = new \DateTime();
                
                $from = $this->getActiveFrom();
                $to = ($this->getActiveTo() instanceof \DateTime) ? $this->getActiveTo() : (new \DateTime())->setTimestamp(PHP_INT_MAX>>32); // 2038-01-19 04:14:07
                $last = ($this->getLastSent() instanceof \DateTime) ? $this->getLastSent() : (new \DateTime())->setTimestamp(0); // 1970-01-01 00:00:00;
                
                if ($this->getFrequencyUnit()=='h') {
                        // hourly sending - add hour to last sent and round back to last hour hh:00
                        $interval_spec = 'PT'.$this->getFrequency().'H';
                        $next = new \DateTime($last->format('Y-m-d H:00:00'));
                        $next->add(new \DateInterval($interval_spec));
                } else {
                        // daily/monthly/yearly sending - use time component of from datetime as (approx) send time
                        $interval_spec = 'P'.$this->getFrequency().strtoupper($this->getFrequencyUnit());
                        $fromHr = $from->format('H');
                        $next = (clone $last);
                        $next->add(new \DateInterval($interval_spec));
                        $next->setTime($fromHr, 0, 0, 0);
                }
                
                return $now >= $from && $now <= $to && $now >= $next;
        }

        /**
         * messagePiping($fileId)
         * Pipe values for custom "smart variables" into message body
         * [report-link]: Hyperlink to View Report page
         * [report-link]: View Report page URL
         * [download-link]: Hyperlink to download report from File Repository
         * [download-url]: File Repository report download URL
         * param int $fileId doc id of exported file
         */
        public function messagePiping($fileId, $fileName) {
            $smartVars = array();
            $smartVars['report-url'] = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/DataExport/index.php?pid={$this->pid}&report_id={$this->report_id}";
            $smartVars['download-url'] = (\REDCap::versionCompare(REDCAP_VERSION, '13.0.0', '>='))
                ? APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/index.php?pid={$this->pid}&route=FileRepositoryController:download&id=$fileId"
                : APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/FileRepository/file_download.php?pid={$this->pid}&id=$fileId";

            $smartVars['report-link'] = "<a href='{$smartVars['report-url']}'>{$this->report_title}</a>";
            $smartVars['download-link'] = "<a href='{$smartVars['download-url']}'>$fileName</a>";
            
            foreach ($smartVars as $var => $repl) {
                $body = str_replace("[$var]", $repl, $this->message->getBody());
                $this->message->setBody($body);
            }
        }
}
