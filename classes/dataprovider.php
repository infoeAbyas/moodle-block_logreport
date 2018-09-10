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
 * Displays different views of the logs.
 *
 * @package    block_logreport
 * @copyright  2018 onwards Naveen kumar(naveen@eabyas.in)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_logreport;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/tablelib.php');

use report_log_table_log;

/**
 * @class dataprovider
 */
class dataprovider extends report_log_table_log {

    public $search;

    public function __construct($uniqueid = 'report_log', $filterparams = null) {
        parent::__construct($uniqueid, $filterparams);
        $this->filterparams = $filterparams;
    }
    /**
     * Query the db. Store results in the table object for use by build_table.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar. Bar
     * will only be used if there is a fullname column defined for the table.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;
        $joins = array();
        $params = array();
        // If we filter by userid and module id we also need to filter by crud and edulevel to ensure DB index is engaged.
        $useextendeddbindex = !($this->filterparams->logreader instanceof logstore_legacy\log\store)
        && !empty($this->filterparams->userid) && !empty($this->filterparams->modid);

        $groupid = 0;
        if (!empty($this->filterparams->courseid) && $this->filterparams->courseid != SITEID) {
            if (!empty($this->filterparams->groupid)) {
                $groupid = $this->filterparams->groupid;
            }

            $joins[] = "courseid = :courseid";
            $params['courseid'] = $this->filterparams->courseid;
        }

        if (!empty($this->filterparams->siteerrors)) {
            $joins[] = "( action='error' OR action='infected' OR action='failed' )";
        }

        if (!empty($this->filterparams->modid)) {
            list($actionsql, $actionparams) = $this->get_cm_sql();
            $joins[] = $actionsql;
            $params = array_merge($params, $actionparams);
        }

        if (!empty($this->filterparams->action) || $useextendeddbindex) {
            list($actionsql, $actionparams) = $this->get_action_sql();
            $joins[] = $actionsql;
            $params = array_merge($params, $actionparams);
        }

        // Getting all members of a group.
        if ($groupid and empty($this->filterparams->userid)) {
            if ($gusers = groups_get_members($groupid)) {
                $gusers = array_keys($gusers);
                $joins[] = 'userid IN (' . implode(',', $gusers) . ')';
            } else {
                $joins[] = 'userid = 0'; // No users in groups, so we want something that will always be false.
            }
        } else if (!empty($this->filterparams->userid)) {
            $joins[] = "userid = :userid";
            $params['userid'] = $this->filterparams->userid;
        }

        if (!empty($this->filterparams->date)) {
            $joins[] = "timecreated > :date AND timecreated < :enddate";
            $params['date'] = $this->filterparams->date;
            $params['enddate'] = $this->filterparams->date + DAYSECS; // Show logs only for the selected date.
        }

        if (isset($this->filterparams->edulevel) && ($this->filterparams->edulevel >= 0)) {
            $joins[] = "edulevel = :edulevel";
            $params['edulevel'] = $this->filterparams->edulevel;
        } else if ($useextendeddbindex) {
            list($edulevelsql, $edulevelparams) = $DB->get_in_or_equal(array(\core\event\base::LEVEL_OTHER,
                                                                             \core\event\base::LEVEL_PARTICIPATING,
                                                                             \core\event\base::LEVEL_TEACHING),
                                                                        SQL_PARAMS_NAMED, 'edulevel');
            $joins[] = "edulevel " . $edulevelsql;
            $params = array_merge($params, $edulevelparams);
        }

        // Origin.
        if (isset($this->filterparams->origin) && ($this->filterparams->origin != '')) {
            if ($this->filterparams->origin !== '---') {
                // Filter by a single origin.
                $joins[] = "origin = :origin";
                $params['origin'] = $this->filterparams->origin;
            } else {
                // Filter by everything else.
                list($originsql, $originparams) = $DB->get_in_or_equal(array('cli', 'restore', 'ws', 'web'),
                    SQL_PARAMS_NAMED, 'origin', false);
                $joins[] = "origin " . $originsql;
                $params = array_merge($params, $originparams);
            }
        }

        if (!($this->filterparams->logreader instanceof logstore_legacy\log\store)) {
            // Filter out anonymous actions, this is N/A for legacy log because it never stores them.
            $joins[] = "anonymous = 0";
        }
        if ($this->search != '') {
            $joins[] = " eventname like '%$this->search%'";
        }

        $selector = implode(' AND ', $joins);

        if (!$this->is_downloading()) {
            $this->total = $this->filterparams->logreader->get_events_select_count($selector, $params);
            $this->pagesize($pagesize, $total);
        } else {
            $this->pageable(false);
        }

        // Get the users and course data.
        $this->rawdata = $this->filterparams->logreader->get_events_select_iterator($selector, $params,
        $this->filterparams->orderby, $this->get_page_start(), $this->get_page_size());
        // Update list of users which will be displayed on log page.
        $this->update_users_used();

        // Get the events. Same query than before; even if it is not likely, logs from new users
        // may be added since last query so we will need to work around later to prevent problems.
        // In almost most of the cases this will be better than having two opened recordsets.
        $this->rawdata = $this->filterparams->logreader->get_events_select_iterator($selector, $params,
        $this->filterparams->orderby, $this->get_page_start(), $this->get_page_size());
        // Set initial bars.
        if ($useinitialsbar && !$this->is_downloading()) {
            $this->initialbars($total > $pagesize);
        }
    }

    public function get_hits($duration = 'weekly') {
        global $DB;
        $secondcolumn = ", COUNT(userid) AS 'Unique User Logins'";
        switch ($duration) {
            case 'hourly':
                $groupingsql = " GROUP BY DATE_FORMAT(FROM_UNIXTIME(timecreated), '%H') 
                                 ORDER BY timecreated ASC";
                $selectsql = " DATE_FORMAT(FROM_UNIXTIME(timecreated), '%h %p') AS 'Hour' $secondcolumn";
                $wheresql = " WHERE DATEDIFF( NOW(),FROM_UNIXTIME(timecreated) ) < 1";
            break;
            case 'daily':
                $groupingsql = " GROUP BY MONTH(FROM_UNIXTIME(timecreated)), DAY(FROM_UNIXTIME(timecreated))
                                 ORDER BY timecreated ASC";
                $selectsql = " DATE_FORMAT(FROM_UNIXTIME(timecreated), '%D %b %Y') AS 'Day' $secondcolumn";
                $wheresql = " WHERE DATEDIFF( NOW(),FROM_UNIXTIME(timecreated) ) < 30";
            break;
            case 'monthly':
                $groupingsql = " GROUP BY Month ORDER BY timecreated ASC";
                $selectsql = " DATE_FORMAT(FROM_UNIXTIME(timecreated), '%b %Y') AS 'Month' $secondcolumn";
                $wheresql = " WHERE DATEDIFF( NOW(),FROM_UNIXTIME(timecreated) ) < 365";
            break;
        }

        $sql = "SELECT $selectsql
                  FROM {logstore_standard_log}
                  		$wheresql
                  		$groupingsql";

        $hitreport = $DB->get_records_sql_menu($sql);

        return $hitreport;
    }

    public function generate_graphdata() {
        $formats = [ 'hourly', 'daily', 'monthly'];
        foreach ($formats as $format) {
            $chartdata[$format] = $this->get_hits($format);
        }
        return $chartdata;
    }
}