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

/**
 * This class generates form components for the SparkPost settings form
 *
 */
class CRM_Admin_Form_Setting_Sparkpost extends CRM_Admin_Form_Setting {
  protected $_testButtonName;

  /**
   * Build the form object.
   *
   * @return void
   */
  public function buildQuickForm() {
    // Check dependencies and display error messages
    sparkpost_check_dependencies();

    $this->addElement('password', 'apiKey', ts('API Key'), '', TRUE);

    $this->_testButtonName = $this->getButtonName('refresh', 'test');

    $this->addFormRule(array('CRM_Admin_Form_Setting_Sparkpost', 'formRule'));
    parent::buildQuickForm();
    $buttons = $this->getElement('buttons')->getElements();
    $buttons[] = $this->createElement('submit', $this->_testButtonName, ts('Save & Send Test Email'), array('crm-icon' => 'mail-closed'));
    $this->getElement('buttons')->setElements($buttons);
  }

  /**
   * Set default values for the form.
   *
   * @return array
   *   default value for the fields on the form
   */
  public function setDefaultValues() {
    $this->_defaults = CRM_Sparkpost::getSetting();
    return $this->_defaults;
  }

  /**
   * Global validation rules for the form.
   *
   * @param array $fields
   *   Posted values of the form.
   *
   * @return array
   *   list of errors to be posted back to the form
   */
  public static function formRule($fields) {
    if (empty($fields['apiKey'])) {
      $errors['apiKey'] = 'You must enter an API key.';
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Process the form submission.
   *
   *
   * @return void
   */
  public function postProcess() {
    // flush caches so we reload details for future requests
    // CRM-11967
    CRM_Utils_System::flushCache();

    $formValues = $this->controller->exportValues($this->_name);
    CRM_Sparkpost::setSetting('apiKey', $formValues['apiKey']);

    $buttonName = $this->controller->getButtonName();
    // check if test button
    if ($buttonName == $this->_testButtonName) {
      $session = CRM_Core_Session::singleton();

      // Get the logged in user's email address
      $userID = $session->get('userID');
      list($toDisplayName, $toEmail, $toDoNotEmail) = CRM_Contact_BAO_Contact::getContactDetails($userID);
      if (!$toEmail) {
        CRM_Core_Error::statusBounce(ts('Cannot send a test email because your user record does not have a valid email address.'));
      }

      // CRM-4250: Get the default domain email address
      list($domainEmailName, $domainEmailAddress) = CRM_Core_BAO_Domain::getNameAndEmail();
      if (!$domainEmailAddress || $domainEmailAddress == 'info@EXAMPLE.ORG') {
        $fixUrl = CRM_Utils_System::url("civicrm/admin/domain", 'action=update&reset=1');
        CRM_Core_Error::fatal(ts('The site administrator needs to enter a valid \'FROM Email Address\' in <a href="%1">Administer CiviCRM &raquo; Communications &raquo; FROM Email Addresses</a>. The email address used may need to be a valid mail account with your email service provider.', array(1 => $fixUrl)));
      }

      // Test that the sending domain is correctly configured
      $domain = substr(strrchr($domainEmailAddress, "@"), 1);
      try {
        $response = CRM_Sparkpost::call("sending-domains/$domain");
      } catch (Exception $e) {
        CRM_Core_Session::setStatus(ts('Could not check status for domain %1 (Exception %2).',
          array(1 => $domain, 2 => $e->getMessage())), ts('SparkPost errors'), 'error');
        return;
      }
      if (!$response->results || !$response->results->status || !$response->results->status->ownership_verified) {
        $url = 'https://app.sparkpost.com/account/sending-domains';
        CRM_Core_Session::setStatus(ts('The domain \'%1\' is not created or not verified. Please make sure you follow instructions at <a href="%2">%2</a>.',
          array(1 => $domain, 2 => $url)), ts('SparkPost errors'), 'errors');
        return;
      } else {
        CRM_Core_Session::setStatus(ts('The domain %1 is ready to send.', array(1 => $domain)), ts('SparkPost status'), 'info');
      }

      $campaign = CRM_Sparkpost::getSetting('campaign');
      if (empty($campaign)) {
        // Get the id of (potentially) existing webhook
        try {
          $response = CRM_Sparkpost::call("webhooks");
        } catch (Exception $e) {
          CRM_Core_Session::setStatus(ts('Could not list webhooks (%1).', array(1 => $e->getMessage())), ts('SparkPost errors'), 'error');
          return;
        }
        // Define parameters for our webhook
        $my_webhook = array(
          'name' => 'CiviCRM (com.cividesk)',
          'target' => CRM_Utils_System::url('civicrm/sparkpost/callback', NULL, TRUE, NULL, FALSE, TRUE),
          'auth_type' => 'none',
          // Just bounce-related events as click and open tracking are still done by CiviCRM
          'events' => array('bounce', 'spam_complaint', 'policy_rejection', 'open', 'click'),
        );
        // Has this webhook already been created?
        $webhook_id = FALSE;
        foreach ($response->results as $webhook) {
          if ($webhook->name == $my_webhook['name']) {
            $webhook_id = $webhook->id;
          }
        }
        // Install our webhook (or refresh it if already there)
        try {
          $response = CRM_Sparkpost::call('webhooks' . ($webhook_id ? "/$webhook_id" : ''), array(), $my_webhook);
        } catch (Exception $e) {
          CRM_Core_Session::setStatus(ts('Could not install webhook (%1).', array(1 => $e->getMessage())), ts('SparkPost errors'), 'error');
          return;
        }
        if (!$response->results || !$response->results->id) {
          CRM_Core_Session::setStatus(ts('Could not install/refresh webhook.'), ts('SparkPost errors'), 'error');
          return;
        } else {
          CRM_Core_Session::setStatus(ts('Webhook has been installed or refreshed.'), ts('SparkPost status'), 'info');
        }
      }

      if (!trim($toDisplayName)) {
        $toDisplayName = $toEmail;
      }
      $to = '"' . $toDisplayName . '"' . "<$toEmail>";
      $from = '"' . $domainEmailName . '" <' . $domainEmailAddress . '>';
      $testMailStatusMsg = ts('Sending test email. FROM: %1 TO: %2.<br />', array(
        1 => $domainEmailAddress,
        2 => $toEmail,
      ));

      $subject = ts('Test for SparkPost settings');
      $message = ts('Your SparkPost settings are correct');
      $headers = array(
        'From' => $from,
        'To' => $to,
        'Subject' => $subject,
      );
      $mailer = Mail::factory('Sparkpost', array());

      $currentVer = CRM_Core_BAO_Domain::version();
      if (version_compare($currentVer, '4.5') < 0) {
        CRM_Core_Error::ignoreException();
        $result = $mailer->send($toEmail, $headers, $message);
        CRM_Core_Error::setCallback();
      } else {
        $errorScope = CRM_Core_TemporaryErrorScope::ignoreException();
        $result = $mailer->send($toEmail, $headers, $message);
        unset($errorScope);
      }

      if (!is_a($result, 'PEAR_Error')) {
        CRM_Core_Session::setStatus($testMailStatusMsg . ts('Your %1 settings are correct. A test email has been sent to your email address.', array(1 => 'SparkPost')), ts("Mail Sent"), "success");
      }
      else {
        $message = CRM_Utils_Mail::errorMessage($mailer, $result);
        CRM_Core_Session::setStatus($testMailStatusMsg . ts('Oops. Your %1 settings are incorrect. No test mail has been sent.', array(1 => 'SparkPost')) . $message, ts("Mail Not Sent"), "error");
      }
    }
  }
}
