<?php
require('../include/session.inc.php');

/*
   This file is part of CoDev-Timetracking.

   CoDev-Timetracking is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   CoDev-Timetracking is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with CoDev-Timetracking.  If not, see <http://www.gnu.org/licenses/>.
*/
require('../path.inc.php');

// Note: i18n is included by the Controler class, but Ajax dos not use it...
require_once('i18n/i18n.inc.php');

if(Tools::isConnectedUser() && (isset($_GET['action']) || isset($_POST['action']))) {
   if(isset($_GET['action'])) {
      $smartyHelper = new SmartyHelper();
      if($_GET['action'] == 'getActivityIndicator') {
         if(isset($_SESSION['commandsetid'])) {
            $cmdid = $_SESSION['commandsetid'];
            if (0 != $cmdid) {
               $cmdset = CommandSetCache::getInstance()->getCommandSet($cmdid);

               $startTimestamp = Tools::date2timestamp(Tools::getSecureGETStringValue("startdate"));
               $endTimestamp = Tools::date2timestamp(Tools::getSecureGETStringValue("enddate"));
               $data = CommandSetTools::getCommandSetActivity($cmdset, $startTimestamp, $endTimestamp);
               $smartyHelper->assign('activityIndic_data', $data[0]);
               $smartyHelper->assign('startDate', Tools::formatDate("%Y-%m-%d", $data[1]));
               $smartyHelper->assign('endDate', Tools::formatDate("%Y-%m-%d", $data[2]));
               $smartyHelper->assign('workdays', Holidays::getInstance()->getWorkdays($data[1], $data[2]));

               $smartyHelper->display(ActivityIndicator::getSmartySubFilename());
            } else {
               Tools::sendBadRequest("CommandSet equals 0");
            }
         } else {
            Tools::sendBadRequest("CommandSet not set");
         }
      } else if($_GET['action'] == 'getActivityIndicatorData') {
         if(isset($_SESSION['commandsetid'])) {
            $cmdid = $_SESSION['commandsetid'];
            if (0 != $cmdid) {
               $cmdset = CommandSetCache::getInstance()->getCommandSet($cmdid);

               $startTimestamp = Tools::date2timestamp(Tools::getSecureGETStringValue("startdate"));
               $endTimestamp = Tools::date2timestamp(Tools::getSecureGETStringValue("enddate"));
               $data = CommandSetTools::getCommandSetActivity($cmdset, $startTimestamp, $endTimestamp);
               echo $data[0]['jqplotData'];
            } else {
               Tools::sendBadRequest("CommandSet equals 0");
            }
         } else {
            Tools::sendBadRequest("CommandSet not set");
         }
      } else if($_GET['action'] == 'getProgressHistoryIndicator') {
         if(isset($_SESSION['commandsetid'])) {
            $commandsetid = $_SESSION['commandsetid'];
            if (0 != $commandsetid) {
               $commandset = CommandSetCache::getInstance()->getCommandSet($commandsetid);
               $data = CommandSetTools::getCSetProgressHistory($commandset);
               foreach ($data as $smartyKey => $smartyVariable) {
                  $smartyHelper->assign($smartyKey, $smartyVariable);
               }
               $smartyHelper->display(ProgressHistoryIndicator::getSmartyFilename());
            }
         } else {
            Tools::sendBadRequest("Command set not set");
         }
      } else if($_GET['action'] == 'getBudgetDriftHistoryIndicator') {
         if(isset($_SESSION['commandsetid'])) {
            $commandsetid = $_SESSION['commandsetid'];
            if (0 != $commandsetid) {
               $commandset = CommandSetCache::getInstance()->getCommandSet($commandsetid);
               $data = CommandSetTools::getBudgetDriftHistoryIndicator($commandset);
               foreach ($data as $smartyKey => $smartyVariable) {
                  $smartyHelper->assign($smartyKey, $smartyVariable);
               }
               $smartyHelper->display(BudgetDriftHistoryIndicator::getSmartyFilename());
            }
         } else {
            Tools::sendBadRequest("Command not set");
         }
      } else if ($_GET['action'] == 'updateDetailedCharges') {

         $cmdsetid = Tools::getSecureGETIntValue('selectFiltersSrcId');
         $selectedFilters = Tools::getSecureGETStringValue('selectedFilters', '');


         $session_user = UserCache::getInstance()->getUser($_SESSION['userid']);

         $session_user->setCommandSetFilters($selectedFilters, $cmdsetid);

         $cmdSet = CommandSetCache::getInstance()->getCommandSet($cmdsetid);
         $isManager = $session_user->isTeamManager($cmdSet->getTeamid());
         $isObserver = $session_user->isTeamObserver($cmdSet->getTeamid());

         // DetailedChargesIndicator
         $data = CommandSetTools::getDetailedCharges($cmdSet, ($isManager || $isObserver), $selectedFilters);
         foreach ($data as $smartyKey => $smartyVariable) {
            $smartyHelper->assign($smartyKey, $smartyVariable);
         }
         $smartyHelper->display(DetailedChargesIndicator::getSmartySubFilename());

      } else {
         Tools::sendNotFoundAccess();
      }
   }
}
else {
   Tools::sendUnauthorizedAccess();
}

?>
