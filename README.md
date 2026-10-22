# PlumaEditor

**Edit your static site in the browser.** One PHP file, no database, no build step.

Copy `pluma.php` next to your `index.html`, open it in the browser, choose a password, and click any text or image on your pages to change it. Changes are written straight into your HTML files — and only the parts you edited change. Everything else stays byte for byte as it was.

PlumaEditor is made for the sites people now get from AI tools, designers or templates: good-looking HTML that is hard to update without going back to whoever made it.

## Install

1. Download `pluma.php` from [plumaeditor.com](https://plumaeditor.com).
2. Upload it to the same folder as your `index.html` (FTP, or your hosting file manager).
3. **Right away**, open `https://your-site.com/pluma.php` and choose a password. Whoever opens it first sets the password, so do this immediately after uploading.

That's it. PlumaEditor creates two folders next to your pages:

| Folder | What it holds | Public? |
| --- | --- | --- |
| `.pluma/` | password hash, settings, history of every page | No — every data file is a PHP file that returns nothing |
| `pluma-media/` | images you upload, already optimised | Yes — your pages show them |

### Requirements

- PHP 8.1 or newer, with the `gd` and `mbstring` extensions (almost every host has them)
- Write permission for PHP in the site folder
- Apache or Nginx; no `.htaccess` needed (it is used as an extra layer on Apache)

## What you can edit

- **Text** — headings, paragraphs, list items, buttons, captions. Bold, italic and links in paragraphs.
- **Links** — text, address (a page of the site, a web address, an email or a phone number) and "open in a new tab".
- **Images** — upload a new one or pick one you uploaded before; edit the description (alt text). Uploads are resized, converted to WebP and stripped of hidden metadata (including GPS location).
- **Background images** on elements you mark with `data-pluma="bg"`.
- **Videos** — paste a YouTube or Vimeo link to replace a video. YouTube plays in privacy mode (`youtube-nocookie.com`).
- **Focal point** — choose the part of an image that must stay visible when the layout crops it (on phones, in wide banners).
- **Page settings** — title, description and the image shown when the link is shared on WhatsApp, LinkedIn or Facebook.
- **Shared parts** — a menu or footer marked `data-pluma-shared` is edited once and updated on every page that has it.
- **New pages** — create a page as a copy of an existing one, in the same folder.
- **Blocks** — repeated parts of a page (service cards, team members, events, slides, list items, table rows) can be duplicated, moved and deleted. Hover a block and use the small bar above it. A copy keeps the design of the original; change its text and image and save.

Every save keeps a compressed copy of the page in the history. You can preview and restore any version, and restoring is itself undoable. The very first version (before PlumaEditor touched the page) is never deleted.

## Automatic mode and marked mode

PlumaEditor works on any HTML page:

- **Automatic mode** (no preparation): headings, paragraphs, list items, captions, buttons, links and images become editable. Navigation menus, forms and scripts are left alone. Siblings that look alike (same tag and same classes, side by side) become blocks.
- **Marked mode** (recommended): as soon as a page contains one `data-pluma` attribute, *only* marked elements are editable. You decide exactly what can change.

```html
<h1 data-pluma="text">Festival Subversivo 2027</h1>
<div data-pluma="rich"><p>Four nights of <strong>strange</strong> films.</p></div>
<img data-pluma="image" data-pluma-label="Poster" src="img/poster.jpg" alt="Poster">
<a data-pluma="link" href="programme.html">See the programme</a>
<article data-pluma-repeat="event">…</article>   <!-- one per event: can be duplicated, moved, deleted -->
<header data-pluma="bg" style="background-image: url('img/hero.jpg')">…</header>
```

See [docs/preparing-a-site.md](docs/preparing-a-site.md) for the full convention and a ready-made prompt that asks an AI tool to generate PlumaEditor-ready sites.

## Security

PlumaEditor edits live files on your server, so it is built to fail safe:

- Password stored with Argon2id (bcrypt as fallback). 5 failed sign-ins from one address in 15 minutes lock that address out for 15 minutes.
- Signed, `HttpOnly`, `SameSite=Strict` session cookie; signing out invalidates every session.
- CSRF token and same-origin check on every change.
- Only `.html`/`.htm` files inside the site folder can be opened or written; hidden folders and links pointing outside are refused.
- Everything you type is cleaned on the server: scripts, event handlers, `style` attributes and `javascript:`/`data:` links never reach your pages.
- Uploads are checked by content and re-encoded; SVG is refused.
- Writes are atomic, and a page is only written after a backup copy exists and the result passes a structural check.

**Known limits of version 0.1**

- Whoever opens `pluma.php` first sets the password. Set it immediately after uploading.
- History copies live in `.pluma/history/` with random names. On servers that serve hidden folders, someone who guesses a file name could read an old version of a page (old versions are public content anyway, but may contain text you later removed).
- Your site's own scripts run inside the editor. A third-party script you include on your pages could, in theory, act inside the editor. Only include scripts you trust — which is true for your live site too.

Found a vulnerability? See [SECURITY.md](SECURITY.md).

## Development

```
src/php/      PHP classes (tokenizer, detector, sanitizer, patcher, auth, history, images, router)
src/assets/   editor.js, editor.css, icon.svg
src/lang/     en.json — every interface string
build/        build.php joins everything into dist/pluma.php
tests/        unit, integration and browser tests + sample sites
```

```bash
php build/build.php              # → dist/pluma.php
php tests/core_test.php          # tokenizer, detection, sanitizer, patcher
php tests/groups_test.php        # repeatable blocks
php tests/integration_test.php   # full HTTP flow on PHP's built-in server
node tests/browser_test.js       # Chromium end to end (needs puppeteer)
node tests/blocks_browser_test.js
```

To try it locally:

```bash
php build/build.php
cp dist/pluma.php tests/sites/auto/
php -S localhost:8000 -t tests/sites/auto
# open http://localhost:8000/pluma.php
```

## Support the project

PlumaEditor is free and open source (MIT). If it saves you time, you can support its development at [plumaeditor.com/support](https://plumaeditor.com/support).

## License

[MIT](LICENSE) © 2026 Rui Sousa and PlumaEditor contributors
