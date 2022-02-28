# SparkPost email extension for CiviCRM

This extension allows CiviCRM to send emails and process bounces through the SparkPost service.
It currently is one of the [Top 10](https://stats.civicrm.org/?tab=sites) most used extensions for CiviCRM.

It was designed with the following goals and/or features:

* integrate as seamlessly as possible within CiviCRM, neatly replacing other email options
* be fully documented, with the documentation published on the official CiviCRM documentation website
* be well maintained on at least the LTS and latest versions of CiviCRM
* be trivial to install and configure, even for the novice users
* self-configure when possible and check that everything is appropriately setup
* be nimble and fast, and in particular use the REST API rather than SMTP, and use real-time callbacks
* have a 'service provider' mode in which the same SparkPost account can be used for multiple clients
* accurate processing of bounces with in-depth analysis and translation of all bounce codes

It sends both transactional and CiviMail emails through the SparkPost service. Bounces are processed through a callback (no need for an email account dedicated to bounce processing) but CiviCRM only processes bounces for CiviMail-originated emails. We are planning to extend bounce processing to transactional emails in the short term.

See [bounce processing](bounce-processing.md) for more information.


Opens and click-throughs are still tracked by CiviCRM as there is no added-value in having these tracked by SparkPost.

View this extension in the [Extension Directory](https://civicrm.org/extensions/sparkpost-email-extension-for-civicrm).
