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

class CRM_Sparkpost {
  const SPARKPOST_EXTENSION_SETTINGS = 'SparkPost Extension Settings';
  // Indicates we need to try sending emails out through an alternate method
  const FALLBACK = 1;

  public static function setSetting($setting, $value) {
    // Encrypt API key before storing in database
    if ($setting == 'sparkpost_apiKey') {
      $value = Civi::service('crypto.token')->encrypt($value, 'CRED');
    }
    return Civi::settings()->set($setting, $value);
  }

  public static function getSetting($setting = NULL) {
    // Start with the default values for settings
    $settings = array(
      'sparkpost_useBackupMailer' => false,
      'sparkpost_host' => 'sparkpost.com',
    );
    // Merge the settings defined in DB (no more groups in 4.7, so has to be one by one ...)
    foreach (array('sparkpost_apiKey', 'sparkpost_useBackupMailer', 'sparkpost_campaign', 'sparkpost_ipPool', 'sparkpost_customCallbackUrl', 'sparkpost_host' ) as $name) {
      $value = Civi::settings()->get($name);
      if (!is_null($value)) {
        $settings[$name] = $value;
      }
    }
    // Decrypt API key before returning
    $settings['sparkpost_apiKey'] = Civi::service('crypto.token')->decrypt($settings['sparkpost_apiKey'], ['plain', 'CRED']);
    // And finaly returm what was asked for ...
    if (!empty($setting)) {
      return CRM_Utils_Array::value($setting, $settings);
    }
    else {
      return $settings;
    }
  }

  /**
   * @return array
   *   Array(string $value => string $label).
   */
  public static function getSparkpostHostOptions() {
    return array(
      'sparkpost.com' => ts('Default'),
      'eu.sparkpost.com' => ts('Europe'),
    );
  }

  /**
   * Calls the SparkPost REST API v1
   * @param $path    Method path
   * @param $params  Method parameters (translated as GET arguments)
   * @param $content Method content (translated as POST arguments)
   *
   * @see https://developers.sparkpost.com/api/
   */
  public static function call($path, $params = array(), $content = array()) {
    // Get the API key from the settings
    $authorization = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    if (empty($authorization)) {
      throw new Exception('No API key defined for SparkPost');
    }

    // Deal with the campaign setting
    if (($path =='transmissions') && ($campaign = CRM_Sparkpost::getSetting('sparkpost_campaign'))) {
      $content['campaign_id'] = $campaign;
    }

    // Initialize connection and set headers
    $sparkpost_host = CRM_Sparkpost::getSetting('sparkpost_host');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.$sparkpost_host/api/v1/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $request_headers = array();
    $request_headers[] = 'Content-Type: application/json';
    $request_headers[] = 'Authorization: ' . $authorization;
    $request_headers[] = 'User-Agent: CiviCRM SparkPost extension (com.cividesk.email.sparkpost)';
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

    if (!empty($content)) {
      if (strpos($path, '/') !== false) {
        // ie. webhook/id
        // This is a modify operation so use PUT
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
      } else {
        // ie. webhook, transmission
        // This is a create operation so use POST
        curl_setopt($ch, CURLOPT_POST, TRUE);
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content, JSON_UNESCAPED_SLASHES));
    }
    elseif (substr($path, 0, strlen('suppression-list')) === 'suppression-list') {
      // delete email from sparkpost suppression list
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    }
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
      throw new Exception('Sparkpost curl error: '. curl_error($ch));
    }
    $curl_info = curl_getinfo($ch);
    curl_close($ch);

    // Treat errors if any in the response ...
    $response = json_decode($data);
    if (isset($response->errors) && is_array($response->errors)) {
      // Log this error for debugging purposes
      sparkpost_log('==== ERROR in CRM_Sparkpost::call() ====');
      sparkpost_log(print_r($response, TRUE));
      sparkpost_log(print_r($content, TRUE));
      sparkpost_log(PHP_EOL);

      $error = reset($response->errors);

      // See issue #5: http_code is more dicriminating than $error->message
      // https://support.sparkpost.com/customer/en/portal/articles/2140916-extended-error-codes
      if (!in_array($curl_info['http_code'], array(
        204, // HTTP status of 204 indicates a successful deletion
      ))) {
        switch ($curl_info['http_code']) {
          case 400 :
            switch ($error->code) {
              // Did the email bounce because one of the recipients is on the SparkPost rejection list?
              // https://support.sparkpost.com/customer/portal/articles/2110621-sending-messages-to-recipients-on-the-exclusion-list
              // AFAIK there can be multiple recipients and we don't know which caused the bounce, so cannot really do anything
              case 1901:
                throw new Exception("Sparkpost error: At least one recipient is on the Sparkpost Exclusion List for non-transactional emails.");
              case 1902:
                throw new Exception("Sparkpost error: At least one recipient is on the Sparkpost Exclusion List for transactional emails.");
              case 7001:
                throw new Exception("Sparkpost error: The sending or tracking domain is unconfigured or unverified in Sparkpost.", CRM_Sparkpost::FALLBACK);
            }
            break;
          case 401 :
            throw new Exception("Sparkpost error: Unauthorized. Check that the API key is valid, and allows IP $curl_info[local_ip].", CRM_Sparkpost::FALLBACK);
          case 403 :
            throw new Exception("Sparkpost error: Permission denied. Check that the API key is authorized for request $curl_info[url].", CRM_Sparkpost::FALLBACK);
          case 420 :
            throw new Exception("Sparkpost error: Sending limits exceeded. Check your limits in the Sparkpost console.", CRM_Sparkpost::FALLBACK);
        }
        //If we don't get an error code OR message, this error is a 404 response for checking the suppression list for an email that isn't on it. See https://developers.sparkpost.com/api/suppression-list/#suppression-list-get-retrieve-a-suppression
        //In that case it's an expected workflow and we don't want to return a blank error response to the user just for checking
        if (property_exists($error, 'code') && property_exists($error, 'message')) {
          // Don't have specifics, so throw a generic exception
          throw new Exception("Sparkpost error: HTTP return code $curl_info[http_code], Sparkpost error code $error->code ($error->message). Check https://support.sparkpost.com/customer/en/portal/articles/2140916-extended-error-codes for interpretation.");
        }
      }
    }

    // Return (valid) response
    return $response;
  }
}
