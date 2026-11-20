# CiviCRM address book for Roundcube

Roundcube 1.7 users can search CiviCRM contacts by name or email from three characters, including in recipient autocomplete. The read-only address book returns the primary email, primary phone and contact ID. Roundcube keeps personal contacts separately. An optional toolbar action files a selected message as a CiviCRM Email activity.

## Install and configure

1. Place the plugin in `plugins/civicrm_addressbook` and copy `config.inc.php.dist` to `config.inc.php` in that directory.
2. Add `$config['plugins'][] = 'civicrm_addressbook';` after the enabled plugin list in Roundcube's main configuration.
3. Configure the plugin in its own `config.inc.php`. The API URL and API key are required for both modes. All Roundcube users share the API account's contact visibility.

### Address book only

```php
<?php
$config['civicrm_api4_url'] = 'https://crm.example.org/civicrm/ajax/api4';
$config['civicrm_api4_key'] = getenv('CIVICRM_API4_KEY');
$config['civicrm_filing_enabled'] = false;
$config['civicrm_log_searches'] = false;
```

Supply the key to the Roundcube PHP process. Use a dedicated CiviCRM account with API-key authentication and read access to the intended contacts, primary email addresses and phone numbers. The endpoint is the HTTPS APIv4 base URL. For a WordPress installation using query-string routing, an example is `https://crm.example.org/?civiwp=CiviCRM&q=civicrm/ajax/api4`. If the site requires a site key, add `$config['civicrm_site_key'] = getenv('CIVICRM_SITE_KEY');`.

### Enable mail filing

In the preceding configuration, change the filing setting and choose a journal directory:

```php
$config['civicrm_filing_enabled'] = true;
$config['civicrm_filing_dir'] = '/var/lib/roundcube/civicrm-filings';
```

Give the API account permission to create activities. Use persistent storage outside the web root that the Roundcube PHP process can write. The journal path must be an absolute canonical path beneath directories owned by root or the PHP process and not group- or world-writable. Its parent must already exist. The plugin creates a missing journal root with mode `0700`; an existing root must be owned by the PHP process and already have mode `0700`. Filing requires PHP's POSIX extension and stays disabled when the sample configuration has no journal directory.

The plugin checks the IMAP message size before loading the body or contacting CiviCRM. The default filing limit is 25 MiB for the entire encoded message, including headers and attachments. A message exactly at the limit is accepted. This check also applies when all attachments are deselected. To change the local limit, set `$config['civicrm_filing_max_mib'] = 50;` or, with Compose, `$env:CIVICRM_FILING_MAX_MIB = '50'` before running the Compose command. Use a positive whole number of MiB. Activity subjects are separately capped at 4 KiB and escaped details at 4 MiB; messages whose subject, sender or recipient headers, or plain-text body would exceed those caps are rejected before an activity is created. The CiviCRM server and its web server can impose separate request limits.

## Local development

The parent project's Compose file runs Roundcube and GreenMail. From the project directory in PowerShell:

```powershell
$env:CIVICRM_API4_URL = 'https://crm.example.org/civicrm/ajax/api4'
$env:CIVICRM_API4_KEY = (Get-Credential -UserName 'api' -Message 'Enter the development API key as the password').GetNetworkCredential().Password
# If your site requires a site key, use the same prompt for CIVICRM_SITE_KEY.
$env:CIVICRM_FILING_ENABLED = 'true'
docker compose -f compose.yaml -f civicrm_addressbook/compose.dev.yaml up -d
```

Open http://localhost:8088, log in and type three characters of a known CRM contact in the recipient field. The plugin source is mounted directly for editing. The CiviCRM source appears once both settings are present. All Roundcube users share the API user's contact visibility. Use a dedicated test account with permission to authenticate with an API key and read the intended contacts.

To inspect plugin diagnostics from the same directory:

```powershell
docker compose -f compose.yaml -f civicrm_addressbook/compose.dev.yaml logs --since 5m roundcube 2>&1 | Select-String -SimpleMatch '[civicrm_addressbook]'
```

When a lookup fails, match the logged `error_id` to the CiviCRM log entry if available. Enable `civicrm_log_searches` in the plugin configuration to include successful lookup counts during troubleshooting.

## File an existing message

Filing is enabled when a journal directory is configured. For an address-book-only installation, omit `$config['civicrm_filing_dir']` or set `$config['civicrm_filing_enabled'] = false;` in the plugin's `config.inc.php`. With Compose, set `$env:CIVICRM_FILING_ENABLED = 'false'` before running the Compose command above. This disables the filing endpoint, buttons and activity indicators. Contact search and autocomplete only need read access.

Select one message or open it, then choose **File in CRM** (German: **In CRM ablegen**). The dialog initially searches for the sender. Search for another name or address if needed, select the intended contact by address and CRM ID, and confirm. The plugin reads the message from the current user's IMAP mailbox. It creates a completed Email activity with the message date, subject, From, To, Cc and plain-text body. HTML mail is converted to text. The dialog lists regular attachments by filename and size. PDFs are selected initially. Tick other files to include them or clear the selection to file only the message. Inline signature images are skipped. The API user is recorded as the creator. Filing produces no outgoing email.

A CiviCRM logo marks filed messages. For new filings, the logo and **View activity** open the first recorded activity for the message, even if the message is subsequently filed with another contact. Contact lookup and activity creation use APIv4. For selected files the plugin sends their original bytes to the sibling CiviCRM APIv3 REST endpoint using Attachment.create. The API account needs permission to attach files to the activity, and users viewing the files need the corresponding CiviCRM access. Filing reads each regular attachment before creating an activity so reuse can be bound to the archived headers, body and attachment content instead of the sender-supplied `Message-ID`. Filing the same message with the same contact from the same Roundcube account reuses the activity and skips confirmed uploads; selecting an additional attachment on a retry uploads it to that activity. Two recipients filing from separate accounts create separate activities. Colleagues who sign in to one shared mailbox share its filing history. If an upload is unconfirmed, verify the file in CiviCRM before reconciling its pending journal entry. The UI follows the Roundcube locale, with English and German translations.

For production, set `civicrm_filing_dir` to a persistent directory outside the web root, writable only by PHP. Compose uses the Roundcube database volume. Journal roots and account directories use mode `0700`; files use mode `0600`. The plugin rejects links, hard-linked files, unexpected owners, unsafe modes and paths beneath writable ancestors. A file per message/contact pair records the created activity ID. If the remote outcome is unknown, the reservation stays pending. Before clearing a pending reservation, check the contact's activity history to avoid filing the message twice.

Each Roundcube installation should have its own journal root. Entries are stored in a subdirectory for the authenticated Roundcube user ID. Older entries directly in the journal root have no account owner, and journal keys from versions that trusted `Message-ID` cannot be safely reused. After upgrading, old badges disappear and filing an old message again creates a new activity. Existing activities remain available in CiviCRM.

## Provenance

Based on [Jaap Jansma's RoundcubeCivicrmAddressbook](https://github.com/jaapjansma/RoundcubeCivicrmAddressbook). The first local commit contains the original PHP files and README. The APIv4 version uses Contact.get and Activity.create over HTTPS.

The CiviCRM mark in the message list and toolbar is adapted from [Simple Icons](https://github.com/simple-icons/simple-icons/blob/develop/icons/civicrm.svg).
