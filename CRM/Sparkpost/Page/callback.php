<?php
/**
 * This extension allows CiviCRM to send emails and process bounces through
 * the SparkPost service.
 *
 * Copyright (c) 2016 IT Bliss, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Support: https://github.com/cividesk/com.cividesk.email.sparkpost/issues
 * Contact: info@cividesk.com
 */

class CRM_Sparkpost_Page_callback extends CRM_Core_Page {

  // Yes, dirty ... but there is no pseudoconstant function and CRM_Mailing_BAO_BouncePattern is useless
  var $_civicrm_bounce_types = array(
    'Away' => 2,    // soft, retry 30 times
    'Relay' => 9,   // soft, retry 3 times
    'Invalid' => 6, // hard, retry 1 time
    'Spam' => 10,   // hard, retry 1 time
  );

  // Source: https://support.sparkpost.com/customer/portal/articles/1929896
  var $_sparkpost_bounce_types = array(
    // Name, Description, Category, CiviCRM equivalent (see above)
     1 => array('Undetermined','The response text could not be identified.','Undetermined', ''),
    10 => array('Invalid Recipient','The recipient is invalid.','Hard', 'Invalid'),
    20 => array('Soft Bounce','The message soft bounced.','Soft', 'Relay'),
    21 => array('DNS Failure','The message bounced due to a DNS failure.','Soft', 'Relay'),
    22 => array('Mailbox Full','The message bounced due to the remote mailbox being over quota.','Soft', 'Away'),
    23 => array('Too Large','The message bounced because it was too large for the recipient.','Soft', 'Away'),
    24 => array('Timeout','The message timed out.','Soft', 'Relay'),
    25 => array('Admin Failure','The message was failed by Momentum\'s configured policies.','Admin', 'Spam'),
    30 => array('Generic Bounce: No RCPT','No recipient could be determined for the message.','Hard', 'Invalid'),
    40 => array('Generic Bounce','The message failed for unspecified reasons.','Soft', 'Relay'),
    50 => array('Mail Block','The message was blocked by the receiver.','Block', 'Spam'),
    51 => array('Spam Block','The message was blocked by the receiver as coming from a known spam source.','Block', 'Spam'),
    52 => array('Spam Content','The message was blocked by the receiver as spam.','Block', 'Spam'),
    53 => array('Prohibited Attachment','The message was blocked by the receiver because it contained an attachment.','Block', 'Spam'),
    54 => array('Relaying Denied','The message was blocked by the receiver because relaying is not allowed.','Block', 'Relay'),
    60 => array('Auto-Reply','The message is an auto-reply/vacation mail.','Soft', 'Away'),
    70 => array('Transient Failure','Message transmission has been temporarily delayed.','Soft', 'Away'),
    80 => array('Subscribe','The message is a subscribe request.','Admin', ''),
    90 => array('Unsubscribe','The message is an unsubscribe request.','Hard', 'Spam'),
   100 => array('Challenge-Response','The message is a challenge-response probe.','Soft', ''),
  );

  function run() {
    // The $_POST variable does not work because this is json data
    $postdata = file_get_contents("php://input");
    $elements = json_decode($postdata);

    foreach ($elements as $element) {
      if ($element->msys && (($event = $element->msys->message_event) || ($event = $element->msys->track_event))) {
        // Sanity checks
        if ( !in_array($event->type, array('bounce', 'spam_complaint', 'policy_rejection', 'open', 'click'))
             || ($event->campaign_id && CRM_Sparkpost::getSetting('campaign') && ($event->campaign_id != CRM_Sparkpost::getSetting('campaign')))
             || (!$event->rcpt_meta || !($civimail_bounce_id = $event->rcpt_meta->{'X-CiviMail-Bounce'}))
           ) {
          continue;
        }

        // Extract CiviMail parameters from header value
        $dao             = new CRM_Core_DAO_MailSettings;
        $dao->domain_id  = CRM_Core_Config::domainID();
        $dao->is_default = TRUE;
        if ( $dao->find(true) ) {
          $rpRegex = '/^' . preg_quote($dao->localpart) . '(b|c|e|o|r|u|m)\.(\d+)\.(\d+)\.([0-9a-f]{16})/';
        } else {
          $rpRegex = '/^(b|c|e|o|r|u|m)\.(\d+)\.(\d+)\.([0-9a-f]{16})/';
        }
        $matches = array();
        if (preg_match($rpRegex, $civimail_bounce_id, $matches)) {
          list($match, $action, $job_id, $event_queue_id, $hash) = $matches;
          
          $jobCLassName = 'CRM_Mailing_DAO_MailingJob';
          if (version_compare('4.4alpha1', CRM_Core_Config::singleton()->civiVersion) > 0) {
            $jobCLassName = 'CRM_Mailing_DAO_Job';
          }
          $mailing_id = CRM_Core_DAO::getFieldValue($jobCLassName, $job_id, 'mailing_id');
          if (!$mailing_id) {
            CRM_Core_Error::debug_var('No mailing found hence skiping in SparkPost extension call back', $matches);
            continue;
          }

          $params = array(
            'job_id' => $job_id,
            'event_queue_id' => $event_queue_id,
            'hash' => $hash,
          );

          // Was SparkPost able to classify the message?
          if (in_array($event->type, array(
            'spam_complaint',
            'policy_rejection'
          ))) {
            $params['bounce_type_id'] = CRM_Utils_Array::value('Spam', $this->_civicrm_bounce_types);
            $params['bounce_reason'] = ($event->reason ? $event->reason : 'Message has been flagged as Spam by the recipient');
          }
          elseif ($event->type == 'bounce') {
            $sparkpost_bounce = CRM_Utils_Array::value($event->bounce_class, $this->_sparkpost_bounce_types);
            $params['bounce_type_id'] = CRM_Utils_Array::value($sparkpost_bounce[3], $this->_civicrm_bounce_types);
            $params['bounce_reason'] = $event->reason;
          } 
          elseif ($event->type == 'open' || $event->type == 'click') {
            switch ($event->type) {
              case 'open':
                if ($action == 'b') {//Civi Mailing do not process as done by CiviCRM
                  break;
                }
                $oe = new CRM_Mailing_Event_BAO_Opened();
                $oe->event_queue_id = $event_queue_id;
                $oe->time_stamp = date('YmdHis', $event->timestamp);
                $oe->save();
                break;

              case 'click':
                if ($action == 'b') {//Civi Mailing do not process as done by CiviCRM
                  break;
                }
                $jobCLassName = 'CRM_Mailing_DAO_MailingJob';
                if (version_compare('4.4alpha1', CRM_Core_Config::singleton()->civiVersion) > 0) {
                  $jobCLassName = 'CRM_Mailing_DAO_Job';
                }
                $tracker = new CRM_Mailing_BAO_TrackableURL();
                $tracker->url = $event->target_link_url;
                $tracker->mailing_id = CRM_Core_DAO::getFieldValue($jobCLassName, $job_id, 'mailing_id');
                if (!$tracker->find(TRUE)) {
                  $tracker->save();
                }
                $open = new CRM_Mailing_Event_BAO_TrackableURLOpen();
                $open->event_queue_id = $event_queue_id;
                $open->trackable_url_id = $tracker->id;
                $open->time_stamp = date('YmdHis', $event->timestamp);
                $open->save();
                break;
            }
          }
          if (CRM_Utils_Array::value('bounce_type_id', $params)) {  
            CRM_Mailing_Event_BAO_Bounce::create($params);
          }
          elseif (in_array($event->type, array(
            'spam_complaint',
            'policy_rejection',
            'bounce'
          ))) {
            // Sparkpost was not, so let CiviCRM have a go at classifying it
            $params['body'] = $event->raw_reason;
            $result = civicrm_api3('Mailing', 'event_bounce', $params);
          }
        }
      }
    }
    CRM_Utils_System::civiExit();
  }
}