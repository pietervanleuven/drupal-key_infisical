# Changelog

All notable changes to Key Infisical are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Drupal.org release notes are authored on the release node; this file is the
source to write them from.

## [Unreleased]

Nothing has been released yet. `1.0.0` will be the initial release — see
[README.md](README.md) for the full feature set it will ship with.

### Added

- **An `infisical` key provider for the [Key](https://www.drupal.org/project/key)
  module.** A key configured with this provider stores only the coordinates of
  a secret — instance URL, Universal Auth client ID and secret, project ID,
  environment slug, secret path and secret name — and resolves the value from
  Infisical on demand. Nothing is written back to Drupal: the fetched value
  never reaches config, state, or the database.

- **Authentication via a Universal Auth machine identity.** The provider logs
  in against `/api/v1/auth/universal-auth/login` and reads the secret from
  `/api/v3/secrets/raw/{name}`. Access tokens are cached in memory for the
  lifetime of the request, keyed by instance URL and client ID so the same
  identity used against two Infisical instances cannot collide. A `401` on the
  fetch invalidates the cached token, re-authenticates, and retries once.

- **Per-request caching only.** Resolved secret values are held in a plain
  instance property, deliberately not a Drupal cache bin, so that a secret is
  fetched at most once per request without ever being persisted to the cache
  table. Transient failures are not cached, so a blip does not stick for the
  rest of the request.

- **Failures degrade rather than fatal.** `getKeyValue()` catches `\Throwable`
  and returns `NULL`. A key's value is read in many contexts — including while
  rendering a form that merely references the key — so an unhandled exception
  there would surface as a white screen rather than a missing secret. Failures
  are logged to the `key_infisical` channel.

### Security

- **The client secret is never rendered back into the key edit form.** It is a
  `password` element with no default value, required only while no secret is
  stored; submitting the field blank keeps the existing value. The secret is
  still held in the key's configuration entity and will therefore appear in
  `drush config:export` output — README.md documents overriding it per
  environment from `settings.php` instead.

- **A warning is logged when the configured Infisical URL is not HTTPS,** since
  both the client secret and the retrieved secret value travel over that
  connection.
