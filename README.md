# Infisical

A Drupal module providing integration between [Infisical](https://infisical.com) and the [Key](https://www.drupal.org/project/key) module for secrets management.

## Requirements

- Drupal 10 or 11
- [Key](https://www.drupal.org/project/key) module
- An Infisical instance with a [Machine Identity](https://infisical.com/docs/documentation/platform/identities/universal-auth) configured using Universal Auth

## Installation

1. `composer require drupal/infisical`
2. Enable the module: `drush en infisical`

## Usage

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
