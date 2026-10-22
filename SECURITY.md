# Security policy

PlumaEditor writes to live websites, so security reports get priority.

## Reporting a vulnerability

Please **do not open a public issue**. Email **security@plumaeditor.com** with:

- the version of PlumaEditor (shown in the editor menu and at the top of `pluma.php`);
- steps to reproduce, and what an attacker gains;
- your server setup if it matters (Apache/Nginx, PHP version).

You will get an answer within 7 days. Once a fix is released, you will be credited in the changelog unless you prefer otherwise.

## Supported versions

Only the latest release receives security fixes. Updating means replacing `pluma.php` with the new file; your password, history and images in `.pluma/` and `pluma-media/` are kept.

## Scope

In scope: anything that lets someone without the password read private data, change pages or run code; anything that lets edited content inject scripts into pages; data loss in the history or save process.

Known and accepted in 0.1 (see README): first-visitor setup, guessable history file names on servers that serve hidden folders, and the site's own scripts running inside the editor.
