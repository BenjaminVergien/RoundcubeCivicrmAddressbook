# CiviCRM address book for Roundcube

A read-only, search-only address book for Roundcube 1.7. Search by name or email from three characters, including in recipient autocomplete. Results use the primary email, primary phone and CiviCRM contact ID. Roundcube handles personal contacts separately.

## Local development

The parent project's Compose file runs Roundcube and GreenMail. From the project directory in PowerShell:

```powershell
$env:CIVICRM_API4_URL = 'https://crm.example.org/civicrm/ajax/api4'
$env:CIVICRM_API4_KEY = Read-Host 'Development CiviCRM API key'
docker compose -f compose.yaml -f civicrm_addressbook/compose.dev.yaml up -d
```

Open http://localhost:8088, log in and type three characters of a known CRM contact in the recipient field. The plugin source is mounted directly for editing. The CiviCRM source appears once both settings are present. All Roundcube users share the API user's contact visibility. Use a dedicated test account with permission to authenticate with an API key and read the intended contacts.

For another Roundcube installation, place this directory in `plugins/civicrm_addressbook`, copy the sample configuration, supply both settings and append `civicrm_addressbook` to the enabled plugins.

## Provenance

Based on [Jaap Jansma's RoundcubeCivicrmAddressbook](https://github.com/jaapjansma/RoundcubeCivicrmAddressbook). The first local commit contains the original PHP files and README. The APIv4 version uses Contact.get over HTTPS. Group browsing and filing email as activities are outside this first version.
