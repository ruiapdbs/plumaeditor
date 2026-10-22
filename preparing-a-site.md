# Preparing a site for PlumaEditor

PlumaEditor works on any HTML page, but a page *prepared* for it gives the person editing exactly the freedom they need — no more, no less. Preparing a page means adding a few `data-pluma` attributes.

As soon as a page has one `data-pluma` attribute, it switches to **marked mode**: only marked elements are editable.

## Attributes

| Attribute | Values | Effect |
| --- | --- | --- |
| `data-pluma` | `text` | Plain text: no formatting, Enter is blocked. For headings, buttons, labels. Existing inline markup (like an accent `<span>`) is kept. |
| `data-pluma` | `rich` | Text with bold, italic and links. On a container (`div`, `section`, `article`…) Enter creates new paragraphs. |
| `data-pluma` | `image` | On an `<img>`: replace the image and edit its description. |
| `data-pluma` | `bg` | Background image set in the element's `style="background-image: url(…)"`. |
| `data-pluma` | `link` | On an `<a>`: edit the link text, address and "open in a new tab". |
| `data-pluma` | `video` | On a YouTube or Vimeo `<iframe>`: replace the video by pasting a link. In automatic mode, YouTube and Vimeo players are found without marks. |
| `data-pluma-shared` | name | A part repeated on several pages (`header`, `menu`, `footer`). Edited on one page, saved on all pages that have a part with the same name. |
| `data-pluma-label` | text | Friendly name shown in the editor ("Team photo"). |
| `data-pluma-id` | `[A-Za-z0-9_-]` | Optional stable name for the element. |
| `data-pluma-ignore` | — | Excludes the element and everything inside it (also in automatic mode). |
| `data-pluma-repeat` | optional name | Marks a block that can be duplicated, moved and deleted. Put it on **each** item (each card, each event). Neighbouring items with the same tag and the same name form one group. |

The attributes stay in the published HTML. Browsers and search engines ignore them.

## Example

```html
<section class="hero" data-pluma="bg" style="background-image: url('img/hero.jpg')">
  <h1 data-pluma="text">Festival Subversivo 2027</h1>
  <div class="intro" data-pluma="rich">
    <p>Four nights of <strong>strange</strong> films.</p>
  </div>
  <a class="button" href="programme.html" data-pluma="link">See the programme</a>
</section>

<img data-pluma="image" data-pluma-label="Poster" src="img/poster.jpg" alt="Festival poster" width="400" height="550">
```

## Blocks

Blocks are repeated parts of a page: cards, team members, events, slides, list items.

- **With marks**: as soon as a page has one `data-pluma-repeat`, only marked items are blocks. A single marked item is enough (for example, the only event so far); the person editing can duplicate it.
- **Without marks**: PlumaEditor looks for runs of sibling elements with the same tag and the same classes, side by side, that contain something editable — three `<li class="card">`, two `<figure>`, rows of a table.

```html
<section class="events">
  <article class="event" data-pluma-repeat="event">
    <h3 data-pluma="text">Opening night</h3>
    <p data-pluma="rich">12 March, 21:00 — Teatro Académico</p>
  </article>
  <article class="event" data-pluma-repeat="event">
    <h3 data-pluma="text">Closing gala</h3>
    <p data-pluma="rich">15 March, 21:30 — Convento São Francisco</p>
  </article>
</section>
```

Rules worth knowing:

- Items of a group must sit side by side, with nothing but spaces or comments between them.
- Blocks don't nest: when a block contains another group (a section with a list of cards), the inner group wins.
- The last item of a group can't be deleted, so there is always one left to duplicate.
- Content that a script generates at runtime is not a block. Sliders that clone their slides are fine: PlumaEditor edits the real slides.

## Shared parts

Mark the header, menu or footer that every page repeats:

```html
<header class="site-head" data-pluma-shared="header">
  <a href="index.html" data-pluma="link">Northlight Films</a>
  <nav>
    <ul>
      <li data-pluma-repeat="menu"><a href="index.html" data-pluma="link">Home</a></li>
      <li data-pluma-repeat="menu"><a href="about.html" data-pluma="link">About</a></li>
    </ul>
  </nav>
</header>
```

- Put the same mark, with the same name, on every page.
- Saving copies the part, exactly as it is on the page you edited, to every other page with that name. Each page keeps a copy of its previous version in the history.
- Relative links are adjusted for pages in subfolders: `about.html` becomes `../about.html` in `blog/post.html`.
- In automatic mode, links inside a shared `<nav>` become editable (other navigation stays untouched).

## Good practice

- Mark the **content**, not the layout: the words, the pictures, the call-to-action links.
- Put `width` and `height` on images. PlumaEditor keeps the proportions right when an image is replaced.
- Mark menus and footers with `data-pluma-shared` so they stay identical on every page.
- Give each page a `<title>` and a `<meta name="description">`; PlumaEditor adds the sharing tags when you set them in Page settings.
- Content that a script generates at runtime can't be edited — PlumaEditor edits the HTML file, not what scripts build.

## Prompt for AI tools

Paste this at the end of your request when you ask an AI tool (Claude, or any other) to build a site:

> Make the site editable with PlumaEditor. Use plain static HTML files (no build step, no framework that renders content with JavaScript). Mark editable content with these attributes:
> - `data-pluma="text"` on headings, button labels and short labels;
> - `data-pluma="rich"` on paragraphs, and on containers whose paragraphs may grow (the container holds the `<p>` elements);
> - `data-pluma="image"` on content images, always with `width`, `height` and a meaningful `alt`;
> - `data-pluma="bg"` on elements with a content background image, set inline as `style="background-image: url('…')"`;
> - `data-pluma="link"` on call-to-action links;
> - `data-pluma-label="…"` with a short human name on images and important blocks;
> - `data-pluma="video"` on YouTube or Vimeo `<iframe>` embeds;
> - `data-pluma-shared="header"` and `data-pluma-shared="footer"` on the header (with the menu) and the footer, identical on every page; mark the menu links with `data-pluma="link"` and each menu item with `data-pluma-repeat="menu"`;
> - `data-pluma-repeat="…"` on every item of repeated content (each card, team member, event, testimonial, slide), with the same name for items of the same kind. Items of one kind must be siblings, side by side.
> Do not mark navigation menus, footers, forms or decorative elements. Keep text in the HTML, not injected by scripts.
