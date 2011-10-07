<?php /*
    This file is part of CoDevTT.

    CoDev-Timetracking is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    CoDevTT is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with CoDevTT.  If not, see <http://www.gnu.org/licenses/>.
*/ ?>

<?php

require_once '../path.inc.php';
require_once "mysql_config.inc.php";
require_once "mysql_connect.inc.php";
require_once "internal_config.inc.php";
require_once "constants.php";

require_once ('time_tracking.class.php');
require_once ('team.class.php');

require_once ('jpgraph.php');
require_once ('jpgraph_gantt.php');


class GanttActivity {

	public $bugid;
	public $userid;
   public $startTimestamp;
   public $endTimestamp;
	public $color;

   public $progress;
   public $activityIdx;  // index in jpgraph Data structure

   // -----------------------------------------
   public function __construct($bugId, $userId, $startT, $endT, $progress=NULL) {
      $this->bugid = $bugId;
      $this->userid = $userId;
      $this->startTimestamp = $startT;
      $this->endTimestamp = $endT;

      if (NULL != $progress) {
      	$this->progress = $progress;
      } else {
      	// (BI+BS - RAF) / (BI+BS)
         $issue = IssueCache::getInstance()->getIssue($this->bugid);
         $this->progress = $issue->getProgress();
      }
	}

   // -----------------------------------------
   public function setColor($color) {
      $this->color = $color;
   }

   // -----------------------------------------
   public function getJPGraphData($activityIdx) {

      global $statusNames;
      $bug_resolved_status_threshold = Config::getInstance()->getValue(Config::id_bugResolvedStatusThreshold);

      // save this for later, to compute constrains
      $this->activityIdx = $activityIdx;

   	$user = UserCache::getInstance()->getUser($this->userid);
      $issue = IssueCache::getInstance()->getIssue($this->bugid);

   	$formattedActivityName = substr("$this->bugid - $issue->summary", 0, 50);

      $formatedActivityInfo = $user->getName();
      if ($issue->currentStatus < $bug_resolved_status_threshold) {
      	$formatedActivityInfo .= " (".$statusNames[$issue->currentStatus].")";
      }

   	return array($activityIdx,
   	             ACTYPE_NORMAL,
                   $formattedActivityName,
                   date('Y-m-d', $this->startTimestamp),
                   date('Y-m-d', $this->endTimestamp),
                   $formatedActivityInfo);
   }

   // -----------------------------------------
   public function toString() {
   	return "issue $this->bugid  - ".date('Y-m-d', $this->startTimestamp)." - ".date('Y-m-d', $this->endTimestamp)." - ".$this->userid;
   }

      // ----------------------------------------------
   /**
    * QuickSort compare method.
    * returns true if $this has higher priority than $activityB
    *
    * @param GanttActivity $activityB the object to compare to
    */
   function compareTo($activityB) {

   	// the oldest activity should be in front of the list
   	if ($this->endTimestamp > $activityB->endTimestamp) {
           #echo "activity.compareTo FALSE  (".date('Y-m-d', $this->endTimestamp)." > ".date('Y-m-d', $activityB->endTimestamp).")<br/>";
   	   return false;
   	}
           #echo "activity.compareTo   (".date('Y-m-d', $this->endTimestamp)." < ".date('Y-m-d', $activityB->endTimestamp).")<br/>";
        return true;
   }
}


// ==================================================================
/**

1) recupere la liste des taches finies (status >= bug_resolved_status_threshold)
1.1) convertis en GanttActivity. (aucun calcul, les dates debut/fin sont connues) et dispatch dans des userActivityList

2) recupere toutes les taches en cours de l'equipe (status < bug_resolved_status_threshold)

3) trie taches en cours

4) convertis en GanttActivity et dispatch dans les userActivityList en calculant les dates.

5) cree le grath

 */
class GanttManager {

  private $teamid;
  private $startTimestamp;
  private $endTimestamp;

  //
  private $teamActivityList; // $teamActivityList[user][activity]

   // -----------------------------------------
   /**
    * @param $teamId
    * @param $startT  start timestamp. if NULL, then now
    * @param $endT    end timestamp. if NULL, shedule all remaining tasks
    */
   public function __construct($teamId, $startT=NULL, $endT=NULL) {

      //echo "GanttManager($teamId, ".date('Y-m-d', $startT).", ".date('Y-m-d', $endT).")<br/>";
      $this->teamid = $teamId;
      $this->startTimestamp = $startT;
      $this->endTimestamp = $endT;

      $this->activitiesByUser = array();   // $activitiesByUser[user][activity]
      $this->constrainsList   = array();
  }



   // -----------------------------------------
   /**
    * get tasks resolved in the period
    */
   private function getResolvedIssues() {

   	$tt = new TimeTracking($this->startTimestamp, $this->endTimestamp, $this->teamid);
   	$resolvedIssuesList = $tt->getResolvedIssues();

      $sortedList = qsort($resolvedIssuesList);

   	return $sortedList;
   	#return $resolvedIssuesList;

   }

   // -----------------------------------------
   /**
    * get sorted list of current issues
    */
   private function getCurrentIssues() {

   	$teamIssueList = array();

      $members = Team::getMemberList($this->teamid);
      #$projects = Team::getProjectList($this->teamid);

      // --- get all issues
      foreach($members as $uid => $uname) {
         $user = UserCache::getInstance()->getUser($uid);

         if ($user->isTeamDeveloper($this->teamid)) {
      	   $issueList = $user->getAssignedIssues();
      	   $teamIssueList = array_merge($teamIssueList, $issueList);
         }
      }

      // quickSort the list
      $sortedList = qsort($teamIssueList);

      return $sortedList;
   }


   // -----------------------------------------
   /**
    * create a GanttActivity for each issue and dispatch it in $activitiesByUser[user]
    */
   private function dispatchResolvedIssues($resolvedIssuesList) {
   	global $status_acknowledged;
   	global $status_new;
   	global $status_closed;
   	global $gantt_task_grey;

      $bug_resolved_status_threshold = Config::getInstance()->getValue(Config::id_bugResolvedStatusThreshold);

      foreach ($resolvedIssuesList as $issue) {

      	$startDate = $issue->getFirstStatusOccurrence($status_acknowledged);
      	if (NULL == $startDate) { $startDate = $issue->dateSubmission; }

      	$endDate = $issue->getLatestStatusOccurrence($bug_resolved_status_threshold);
      	if (NULL == $endDate) {
            // TODO: no, $status_closed is not the only one ! check for all status > $bug_resolved_status_threshold
      		$endDate = $issue->getLatestStatusOccurrence($status_closed);
      	}

      	$activity = new GanttActivity($issue->bugId, $issue->handlerId, $startDate, $endDate);

      	if (NULL == $this->activitiesByUser[$issue->handlerId]) {
      	   $this->activitiesByUser[$issue->handlerId] = array();
      	}
      	$this->activitiesByUser[$issue->handlerId][] = $activity;

    	   #echo "DEBUG add to activitiesByUser[".$issue->handlerId."]: ".$activity->toString()."  (resolved)<br/>\n";
      }

   }

   // -----------------------------------------
   /**
    * The remainingStartDate (RSD) is NOT the startDate of the issue.
    *
    * The StartDate (except for status=new) is in the past, it's the
    * the date where the user started investigating on the issue.
    *
    * the RSD is a temporary date, used to determinate the endDate,
    * depending on the remaining days to resolve the issue.
    *
    * Note: If status='new' then, StartDate == RSD.
    *
    * There are a fiew things to consider to compute the RSD:
    * - arrivalDate of user's previous activity : nominal case
    * - constrains can postpone the RSD if constrain date > prev arrivalDate
    * - time left in prev arrivalDate (if 0, try next day)
    *
    * An optimization is mandatory because of constrains:
    * if the RSD has been postponed because user1 is waiting for
    * user2 to resolve the constraining activity. then, user1 should
    * work on an other activity instead of hanging around.
    *
    * So the question is : which activity should user1 work on ?
    * - the next assigned activity (highest priority) ?
    * - use a best-fit or worst-fit algorithm ?
    *
    */
   private function findRemainingStartDate($issue, $userDispatchInfo) {

		$user = UserCache::getInstance()->getUser($issue->handlerId);

      $rsd = $userDispatchInfo[0]; // arrivalDate of the user's latest added Activity

      // --- check relationships
      // Note: if issue is constrained, then the constrained issue should already
      //       have an Activity. the contrary would mean that there is a bug in our
      //       sort algorithm...
      $relationships = $issue->getRelationships( BUG_CUSTOM_RELATIONSHIP_CONSTRAINED_BY );

      // find Activity
      foreach ($this->activitiesByUser as $userActivityList) {
         foreach ($userActivityList as $a) {
         	if (in_array($a->bugid, $relationships)) {
         		echo "DEBUG issue $issue->bugId (".date("Y-m-d", $rsd).") is constrained by $a->bugid (".date("Y-m-d", $a->endTimestamp).")<br/>";
	         	if ($a->endTimestamp > $rsd) {
	         	   echo "DEBUG issue $issue->bugId postponed for $a->bugid<br/>";
	         	   $rsd = $a->endTimestamp;
      $userDispatchInfo = array($rsd, $user->getAvailableTime($rsd));

	         	}
         	}
         }
      }

      // ---
		//the RSD is the arrivalDate of the user's latest added Activity
		// but if the availableTime on RemainingStartDate is 0, then search for the next 'free' day
		while ( 0 == $userDispatchInfo[1]) {
			$rsd = $userDispatchInfo[0];
			echo "DEBUG no availableTime on RemainingStartDate ".date("Y-m-d", $rsd)."<br/>";
			$rsd = strtotime("+1 day",$rsd);
         $userDispatchInfo = array($rsd, $user->getAvailableTime($rsd));
		}

		echo "DEBUG issue $issue->bugId : avail 1st Day (".date("Y-m-d", $userDispatchInfo[0]).")= ".$userDispatchInfo[1]."<br/>";
      return $userDispatchInfo;
   }

   // -----------------------------------------
   /**
    * The startDate is the date where the user started investigating on the issue.
    *
    * This date depends on the currentStatus.
    *
    * Generaly, investigation starts when the user sets the status to
    * 'acknowledge' for the first time. But depending on the workflow
    * configuration, the 'ack' status may be skipped or not exist.
    * so we'll search for the first changeStatus to a status which is:
    * new > ourStatus > bug_resolved_status_threshold
    *
    * If status is new then the user did not start investigations.
    *
    * If the issue has been created with a status > $new, then
    * the startDate shall be the date of submission.
    * (because work has already started).
    *
    * Now, what if currentStatus == 'Feedback' ?
    * If status is feedback, then at least a minimum of investigation
    * has been done to decide setting status to feedback, so i'd say
    * that there is no special case for feedback.
    *
    *  STATUS   | START_DATE
    *  new      | RemainingStartDate
    *  feedback | firstDate of changeStatus to status > New
    *  ack      | firstDate of changeStatus to status > New
    *  analyzed | firstDate of changeStatus to status > New
    *  open     | firstDate of changeStatus to status > New
    */
   private function findStartDate($issue, $remainingStartDate) {
   	global $status_new;

		if ($status_new == $issue->currentStatus) {

			// if status is new, we want the startDate to be the same as the endDate of previous activity.
			$startDate = $remainingStartDate;

		} else {

	      $query = "SELECT date_modified FROM `mantis_bug_history_table` ".
	               "WHERE bug_id=$issue->bugId ".
	               "AND field_name = 'status' ".
	               "AND old_value=$status_new ORDER BY id DESC";
	      $result = mysql_query($query) or die("<span style='color:red'>Query FAILED: $query <br/>".mysql_error()."</span>");
	      $startDate  = (0 != mysql_num_rows($result)) ? mysql_result($result, 0) : NULL;

         // this happens, if the issue has been created with a status != 'new'
   	   if (NULL == $startDate) { $startDate = $issue->dateSubmission; }
		}

      return $startDate;
   }


   // -----------------------------------------
   /**
    *  STATUS   | BEGIN                | END
    *  open     | firstAckDate         | previousIssueEndDate + getRemaining()
    *  analyzed | firstAckDate         | previousIssueEndDate + getRemaining()
    *  ack      | firstAckDate         | previousIssueEndDate + getRemaining()
    *  feedback | previousIssueEndDate | previousIssueEndDate + getRemaining()
    *  new      | previousIssueEndDate | previousIssueEndDate + getRemaining()

    */
   private function dispatchCurrentIssues($issueList) {
   	global $status_acknowledged;
   	global $status_new;
   	global $status_feedback;

      $bug_resolved_status_threshold = Config::getInstance()->getValue(Config::id_bugResolvedStatusThreshold);


      $teamDispatchInfo = array(); // $teamDispatchInfo[userid] = array(endTimestamp, $availTimeOnEndTimestamp)
      $today = date2timestamp(date("Y-m-d", time()));


      foreach ($issueList as $issue) {

			$user = UserCache::getInstance()->getUser($issue->handlerId);

	      // --- init user history
			if (NULL == $teamDispatchInfo[$issue->handlerId]) {
				// let's assume 'today' being the endTimestamp of previous activity
			   $teamDispatchInfo[$issue->handlerId] = array($today, $user->getAvailableTime($today));
			}

			// --- find remainingStartDate
         $teamDispatchInfo[$issue->handlerId] = $this->findRemainingStartDate($issue, $teamDispatchInfo[$issue->handlerId]);
         $remainingStartDate = $teamDispatchInfo[$issue->handlerId][0];

			// --- find startDate
			$startDate = $this->findStartDate($issue, $remainingStartDate);

			// --- compute endDate
			// the arrivalDate depends on the dateOfInsertion and the available time on that day
			$teamDispatchInfo[$issue->handlerId] = $issue->computeEstimatedDateOfArrival($teamDispatchInfo[$issue->handlerId][0],
			                                                                             $teamDispatchInfo[$issue->handlerId][1]);
			$endDate = $teamDispatchInfo[$issue->handlerId][0];


			#echo "DEBUG issue $issue->bugId : user $issue->handlerId status $issue->currentStatus startDate ".date("Y-m-d", $startDate)." tmpDate=".date("Y-m-d", $remainingStartDate)." endDate ".date("Y-m-d", $endDate)." RAF=".$issue->getRemaining()."<br/>";
			#echo "DEBUG issue $issue->bugId : left last Day = ".$teamDispatchInfo[$issue->handlerId][1]."<br/>";

			// activitiesByUser
      	$activity = new GanttActivity($issue->bugId, $issue->handlerId, $startDate, $endDate);


      	if (NULL == $this->activitiesByUser[$issue->handlerId]) {
      	   $this->activitiesByUser[$issue->handlerId] = array();
      	}
      	$this->activitiesByUser[$issue->handlerId][] = $activity;

    	   #echo "DEBUG add to activitiesByUser[".$issue->handlerId."]: ".$activity->toString()."  (resolved)<br/>\n";
      }

      return $this->activitiesByUser;
   }

   // -----------------------------------------
   /**
    *
    */
   public function getTeamActivities() {

      $resolvedIssuesList = $this->getResolvedIssues();

      //echo "DEBUG getGanttGraph : dispatchResolvedIssues nbIssues=".count($resolvedIssuesList)."<br/>\n";
      $this->dispatchResolvedIssues($resolvedIssuesList);

      $currentIssuesList = $this->getCurrentIssues();
      $this->dispatchCurrentIssues($currentIssuesList);


      //echo "DEBUG getGanttGraph : display nbUsers=".count($this->activitiesByUser)."<br/>\n";
      $mergedActivities = array();
      foreach($this->activitiesByUser as $userid => $activityList) {
         $user = UserCache::getInstance()->getUser($userid);
         #echo "==== ".$user->getName()." activities: <br/>";
         $mergedActivities = array_merge($mergedActivities, $activityList);

      }

      $sortedList = qsort($mergedActivities);

      return $sortedList;
   }

   private function getConstrains($teamActivities, $issueActivityMapping) {

      $constrains = array();

      foreach($teamActivities as $a) {
         $issue = IssueCache::getInstance()->getIssue($a->bugid);
         $relationships = $issue->getRelationships( BUG_CUSTOM_RELATIONSHIP_CONSTRAINED_BY );
         foreach($relationships as $r) {
             #echo "DEBUG Activity ".$issueActivityMapping[$r]." constrains $a->activityIdx<br/>";

             $constrains[] = array($issueActivityMapping[$r], $a->activityIdx, CONSTRAIN_ENDSTART);
         }
      }
      return $constrains;
   }


   /**
    *
    */
   public function getGanttGraph() {


      $teamActivities = $this->getTeamActivities();

      // ----
      $issueActivityMapping = array();
      $progress = array();
      $data = array();

      $activityIdx = 0;
      foreach($teamActivities as $a) {
         $data[] = $a->getJPGraphData($activityIdx);
         $progress[] = array($activityIdx, $a->progress);

         // mapping to ease constrains building
         $issueActivityMapping[$a->bugid] = $activityIdx;

         ++$activityIdx;
      }

      // ---
      $constrains = $this->getConstrains($teamActivities, $issueActivityMapping);
      // ----
   	$graph = new GanttGraph();

      $team = new Team($this->teamid);
      $graph->title->Set("Team '".$team->name."'");

      // Setup scale
      $graph->ShowHeaders(GANTT_HYEAR | GANTT_HMONTH | GANTT_HDAY | GANTT_HWEEK);
      $graph->scale->week->SetStyle(WEEKSTYLE_FIRSTDAYWNBR);

      // Add the specified activities
      $graph->CreateSimple($data,$constrains,$progress);
#exit;
   	return $graph;
   }

}
?>