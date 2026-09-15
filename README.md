# Key Infisical

## Table of contents

- Introduction
- Requirements
- Installation
- Configuration
- Development
- Maintainers

## Introduction

A Drupal module providing integration between [Infisical](https://infisical.com) and the [Key](https://www.drupal.org/project/key) module for secrets management.

## Requirements

- Drupal 10 or 11
- [Key](https://www.drupal.org/project/key) module
- An Infisical instance with a [Machine Identity](https://infisical.com/docs/documentation/platform/identities/universal-auth) configured using Universal Auth

## Installation

1. `composer require drupal/key_infisical`
2. Enable the module: `drush en key_infisical`

## Configuration

1. Go to **Configuration > System > Keys** (`/admin/config/system/keys`).
2. Add a new key and select **Infisical** as the key provider.
3. Fill in the provider settings:
   - **Infisical URL** — Your instance URL (defaults to `https://app.infisical.com`).
   - **Client ID** / **Client Secret** — Universal Auth credentials for your Machine Identity.
   - **Project ID** — The Infisical project (workspace) ID.
   - **Environment** — The environment slug (e.g. `dev`, `staging`, `prod`).
   - **Secret Path** — The folder path (e.g. `/` for root).
   - **Secret Name** — The name of the secret to retrieve.
4. Save. The key value will be fetched from Infisical on demand.

When editing an existing key, leaving the **Client Secret** field blank keeps the currently stored secret unchanged.

### Keeping the client secret out of exported configuration

The Client Secret is stored on the key's configuration entity, which means it will appear in plain text in `drush config:export` output (and in any config committed to your codebase). To avoid shipping the secret in configuration, override it per environment in `settings.php` instead of relying on the value saved through the UI:

```php
$config['key.key.my_key']['key_provider_settings']['client_secret'] = getenv('INFISICAL_CLIENT_SECRET');
```

Replace `my_key` with the machine name of the key you configured, and set the `INFISICAL_CLIENT_SECRET` environment variable for each environment.

## Development

Install the development tooling with `composer install`. The `drupal/key`
dependency is served from `https://packages.drupal.org/8` rather than Packagist;
composer.json declares that repository, so a standalone clone resolves without
further setup. Then:

- `composer lint` runs PHP_CodeSniffer against `phpcs.xml.dist` (Drupal and
  DrupalPractice).
- `composer lint:fix` runs `phpcbf`. **Read its diff before keeping it.** Most
  of what it offers to auto-fix in Drupal modules is the "comment must start
  with a capital letter" family, and it applies that blindly to identifiers: a
  doc comment beginning `getSecret() returns ...` is rewritten to
  `GetSecret() returns ...`, silently referring to a method that does not
  exist. It has also been observed mangling ternaries. Fixing violations by
  hand is usually safer.
- PHPStan runs from `phpstan.neon` at level 2. It is advisory in CI
  (`allow_failure: true`) rather than blocking.

## Maintainers

- Pieter Van Leuven - [pietervanleuven](https://www.drupal.org/u/pietervanleuven)
