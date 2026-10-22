# Changelog

## 0.3.0 — 2026-10-22

- **Page settings**: title, description, sharing title and sharing image (Open Graph and Twitter tags), with a link preview. Missing tags are added to the `<head>`; sharing images are saved as JPEG and referenced by absolute address.
- **Video**: `data-pluma="video"` on YouTube and Vimeo iframes (found automatically too). Any YouTube or Vimeo link is turned into an embed by the server; YouTube uses privacy mode; start times are kept.
- **Focal point**: choose what stays visible when an image or background is cropped, with wide and tall previews (`object-position` / `background-position`).
- **Shared parts**: `data-pluma-shared="name"` on a header, menu or footer; saving writes it to every page that has it, with history for each page and relative links adjusted for subfolders.
- **New page**: create a page as a copy of the current one, in the same folder.
- One lock for the whole site during saves (a save can now write several pages).
- The block bar sits below small blocks at the top of the page instead of covering them.

## 0.2.0 — 2026-10-20

- **Blocks**: duplicate, move and delete repeated parts of a page (cards, team members, events, slides, list items, table rows), with undo and redo.
  - Marked with `data-pluma-repeat`, or found automatically (side-by-side siblings with the same tag and classes).
  - Copies are saved from the original's HTML, with their own text and images; ids in copies get a suffix so they stay unique.
  - Works with sliders that clone their slides.
- Saving checks the exact structure the change implies; emptying a text in automatic mode no longer blocks the save.
- Editing an element without an end tag (`<li>`, `<p>`) keeps the line breaks around it.
- Fixed: a `<tr>` without end tags could end up inside the previous row; `<li>`, `<dt>` and `<dd>` now close an open `<p>`, as browsers do.

## 0.1.0 — 2026-10-19

First version (Phase 1).

- One-file install: `pluma.php`, PHP 8.1+, no database.
- First-visit password setup; sign-in with brute-force lockout; signed session cookies.
- Automatic mode (any HTML page) and marked mode (`data-pluma` attributes).
- In-place editing of text, rich text (bold, italic, links), link elements, images and background images.
- Surgical saving: only edited ranges of the HTML file change; conflict detection when a file changed on the server.
- Image uploads resized, converted to WebP, metadata stripped.
- History of every save with preview and restore; the original page is always kept.
- Undo/redo, preview mode, page switcher, subfolder pages.
- English interface.
