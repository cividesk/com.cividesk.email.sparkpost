# Installation

## Requirements

This extension requires:

* CiviCRM 4.4, 4.6 or higher
* PHP version 5.4 or higher, with the curl extension enabled

## Installation instructions

1. Configure CiviCRM extensions parameters (if not done already)
    1. Go to **Administer > System Settings > Directories**, set the CiviCRM Extensions Directory to a folder that is writable by your web server process
    1. Go to **Administer > System Settings > Resource URLs**, enter the URL to the above directory

1. Install the SparkPost email extension
    1. Download the latest release of the extension into your extensions folder

    !!! tip
        We suggest using: `git clone https://github.com/cividesk/com.cividesk.email.sparkpost.git`

    1. Go to **Administer > Customize Data and Screens > Manage Extensions**, and click **install** for this extension

1. Sign-up for a SparkPost account, then:
    1. Create and verify your sending domain(s) at: https://app.sparkpost.com/domains/list/sending. Within CiviCRM, sending email addresses are managed at:
        1. **Administer > Communications > Organization Address and Contact Info**,
        1. and **Administer > CiviMail > From Email Address**.

        So if you define `info@example.org` as a sending address in CiviCRM, you would need to create and verify the domain `example.org` in SparkPost.

    1. Create an API key at: https://app.sparkpost.com/account/api-keys

        !!! attention
            The API key you create should at a minimum be granted the following permissions:

            * Transmissions (Read/Write)
            * Sending Domains (Read/Write)
            * Event Webhooks (Read/Write)
            * Metrics (Read-only)
            * Suppression Lists (Read/Write)

            However, for the sake of simplicity and in order to account for future updates, we advise you to simply grant all permissions to the API key created.

1. Setup the SparkPost email extension
    1. Go to **Administer > System Settings > Outbound Email** (SparkPost)
    1. Enter the API key created above and click **Save and Send test email**
    1. Check the on-screen messages for any error you would need to resolve
    
## Recommended Additional Configuration

1. In SparkPost > Settings > IP Pools, configure an IP pool. If necessary, upgrade your account so you can do this, as it as
greatly assists in avoiding blacklisting from the bad actions of other SparkPost customers. 
Note the ID of the IP pool, eg 'default'.
1. In CiviCRM > Administer > System Settings > Outbound Email (SparkPost), enter the ID of the IP Pool in the IP Pool field.
1. In SparkPost > Settings > SMTP Settings, turn on SMTP Engagement Tracking.
2. In SparkPost > Settings > Sending Domains, create a second domain for bounces following the SparkPost instructions.
3. In SparkPost > Settings > Tracking Domains, create a third domain for tracking following the SparkPost instructions.
1. In CiviCRM > Administer > System Settings > Outbound Email (SparkPost), set Use backup mailer to Yes, and configure confgure
your backup mailer at civicrm/admin/setting/smtp?reset=1 (eg using mail(), NOT the configuration suggested by Sparkpost to send to 
Sparkpost via SMTP).
