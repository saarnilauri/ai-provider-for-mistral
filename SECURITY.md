# Security Policy

## Supported versions

Security fixes are made to the latest release only. If you run an older version,
update to the latest release before reporting an issue.

## Reporting a vulnerability

**Please don't report security vulnerabilities in public issues, pull requests or
discussions.**

Report them privately through GitHub instead:

1. Open the repository's [Security tab](https://github.com/saarnilauri/ai-provider-for-mistral/security).
2. Choose **Report a vulnerability**. You can also go straight to the
   [new advisory form](https://github.com/saarnilauri/ai-provider-for-mistral/security/advisories/new).

Please include:

- the plugin version, and whether you use it as a WordPress plugin or as a Composer
  package;
- the WordPress, PHP and PHP AI Client SDK versions, if relevant;
- what an attacker can do, and what access they need to do it;
- steps to reproduce, or a proof of concept.

Only the maintainer can see the report. You'll get a reply on the report itself, and
the fix and its disclosure are coordinated with you there. Once a fix is released, the
advisory is published, crediting you unless you'd rather stay anonymous.

## Scope

In scope are vulnerabilities in this plugin's own code, for example:

- leaking the Mistral API key, such as into output, logs, caches or URLs;
- requests to Mistral being sent anywhere other than the Mistral API;
- prompts, files or documents being sent somewhere they shouldn't be, or exposed to
  other users of the site;
- missing escaping, sanitization or capability checks in code the plugin adds to
  WordPress.

Out of scope, and better reported to their own projects:

- vulnerabilities in WordPress core, the PHP AI Client SDK or other plugins;
- the Mistral API or service itself, which goes to Mistral AI;
- the content a Mistral model generates;
- anything that requires an attacker who is already a site administrator, or who can
  already edit the site's code, configuration or environment.
