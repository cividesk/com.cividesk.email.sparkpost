<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Sparkpost_Upgrader extends CRM_Sparkpost_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Database upgrade for version 1.1.
   *
   * Provides a setting name less prone to naming collisions.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1100() {
    $this->ctx->log->info('Applying update 1100 - Rename apiKey setting');
    $setting = new CRM_Core_BAO_Setting();
    $setting->name = 'apiKey';
    $setting->find();
    if ($setting->count() > 1) {
      CRM_Core_Error::fatal('Could not update setting name due to naming collisions; more than one setting is named apiKey.');
      return FALSE;
    }
    while ($setting->fetch()) {
      $setting->name = 'sparkpost_apiKey';
      $setting->save();
    }

    return TRUE;
  }

  /**
   * Database upgrade for version 1.1.
   *
   * Encrypt SparkPost API key.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1101() {
    $this->ctx->log->info('Applying update 1101 - Encrypt SparkPost API key');

    $key = civicrm_api3('Setting', 'getvalue', array(
      'name' => 'sparkpost_apiKey',
    ));
    // don't try to encrypt the key if none has been set
    if ($key) {
      // The setSettings function will encrypt before saving
      CRM_Sparkpost::setSetting('sparkpost_apiKey', $key);
    }
    return TRUE;
  }

  /**
   * Database upgrade for version 1.5
   *
   * Re-encrypt Sparkpost API key.
   *
   * @return TRUE on Success
   * @throws Exception
   */
  public function upgrade_1500() {
    $this->ctx->log->info('Applying update 1500 - Re-encrypt Sparkpost API key using new crypto service');
    $cryptoRegistry = \Civi\Crypto\CryptoRegistry::createDefaultRegistry();
    $cryptoToken = new \Civi\Crypto\CryptoToken($cryptoRegistry);
    $keys = civicrm_api3('Setting', 'get', [
      'domain_id' => 'all',
      'options' => ['limit' => 0],
    ]);

    foreach ($keys['values'] as $domain => $settings) {
      if (array_key_exists('sparkpost_apiKey', $settings)) {
        $key = CRM_Utils_Crypt::decrypt($settings['sparkpost_apiKey']);
        if (!self::canbeStored($key, $cryptoRegistry) {
          $key = '';
        }
        else {
          $key = $cryptoToken->encrypt($key, 'CRED');
        }
        civicrm_api3('Setting', 'create', [
          'sparkpost_apiKey' => $key,
          'domain_id' => $domain,
        ]);
      }
      return TRUE;
    }
  }

  /**
   * If you decode an old value of smtpPassword, will it be possible to store that
   * password in the updated format?
   *
   * If you actually have encryption enabled, then it's straight-yes. But if you
   * have to write in plain-text, then you're working within the constraints
   * of php-mysqli-utf8mb4, and it does not accept anything > chr(128).
   *
   * Note: This could potentially change in the future if we updated `CryptToken`
   * to put difficult strings into `^CTK?k=plain&t={base64}` format.
   *
   * @param string $oldCipherText
   * @param \Civi\Crypto\CryptoRegistry $registry
   * @return bool
   * @throws \Civi\Crypto\Exception\CryptoException
   */
  protected static function canBeStored($oldCipherText, \Civi\Crypto\CryptoRegistry $registry) {
    $plainText = CRM_Utils_Crypt::decrypt($oldCipherText);
    $activeKey = $registry->findKey('CRED');
    $isPrintable = ctype_print($plainText);
    if ($activeKey['suite'] === 'plain' && !$isPrintable) {
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
