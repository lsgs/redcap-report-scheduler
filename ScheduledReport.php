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
        protected $report_id;
        protected $permission_level;
        protected $report_format;
        protected $frequency;
        protected $frequency_unit;
        protected $active_from;
        protected $active_to;
        protected $last_sent;
        protected $message;
        
        public function getSRId() { return $this->srid; }
        public function setSRId($val) { $this->srid = $val; }
        public function getReportId() { return $this->report_id; }
        public function setReportId($val) { $this->report_id = $val; }
        public function getPermissionLevel() { return $this->$permission_level; }
        public function setPermissionLevel($val) { $this->$permission_level = $val; }
        public function getReportFormat() { return $this->report_format; }
        public function setReportFormat($val) { $this->report_format = $val; }
        public function getFrequency() { return $this->frequency; }
        public function setFrequency($val) { $this->frequency = $val; }
        public function getFrequencyUnit() { return $this->frequency_unit; }
        public function setFrequencyUnit($val) { $this->frequency_unit = $val; }
        public function getActiveFrom() { return $this->active_from; }
        public function setActiveFrom($val) { $this->active_from = $this->setDateTimeFromVal($val); }
        public function getActiveTo() { return $this->active_to; }
        public function setActiveTo($val) { $this->active_to = $this->setDateTimeFromVal($val); }
        public function getLastSent() { return $this->last_sent; }
        public function setLastSent($val) { $this->last_sent = $this->setDateTimeFromVal($val); }
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
                
                $interval_spec = ($this->getFrequencyUnit()=='h')
                        ? 'PT'.$this->getFrequency().'H'
                        : 'P'.$this->getFrequency().strtoupper($this->getFrequencyUnit());
                
                $next = (clone $last)->add(new \DateInterval($interval_spec));
                
                return $now >= $from && $now <= $to && $now >= $next;
        }
}
