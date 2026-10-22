<?php

// ---- bootstrap.php -----------------------------------------------

/*
 * PlumaEditor — edit your static site in the browser.
 * https://plumaeditor.com
 *
 * Copy this file next to your index.html and open it in the browser.
 *
 * MIT License · Copyright (c) 2026 Rui Sousa and PlumaEditor contributors
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this
 * software, to deal in the software without restriction, subject to the conditions of the
 * MIT License (full text: https://plumaeditor.com/license). THE SOFTWARE IS PROVIDED "AS IS",
 * WITHOUT WARRANTY OF ANY KIND.
 */

if (!defined('PLUMA_VERSION')) {
    define('PLUMA_VERSION', '0.3.0');
}

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'PlumaEditor needs PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . '.';
    exit;
}

// Never show PHP warnings to visitors: they could reveal server paths.
@ini_set('display_errors', '0');

// ---- Util.php ----------------------------------------------------

/**
 * PlumaEditor — shared helpers.
 */

/** An error whose code can be shown to the user (translated in the interface). */
class PlumaUserError extends Exception
{
    /** @var int */
    public $status;

    public function __construct($code, $status = 400)
    {
        parent::__construct($code);
        $this->status = $status;
    }
}

final class PlumaUtil
{
    public static function randomId($bytes = 3)
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }

    /** Local development host: no HTTPS warning needed. */
    public static function isLocalHost()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
        $host = preg_replace('/:\d+$/', '', $host);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || substr($host, -10) === '.localhost' || substr($host, -5) === '.test';
    }

    public static function clientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    }

    /** Lowercase ASCII slug for file names. */
    public static function slug($s, $max = 40)
    {
        $s = (string) $s;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
        $s = trim(substr(trim($s, '-'), 0, $max), '-');
        return $s === '' ? 'image' : $s;
    }

    /** Write a file atomically, keeping the permissions of the file it replaces. */
    public static function atomicWrite($path, $data)
    {
        $dir = dirname($path);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.pluma-tmp-' . self::randomId(6);
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            @unlink($tmp);
            throw new PlumaUserError('write-failed', 500);
        }
        if (is_file($path)) {
            @chmod($tmp, fileperms($path) & 0777);
        }
        if (!@rename($tmp, $path)) {
            // Some Windows/network filesystems refuse rename over an existing file
            if (@copy($tmp, $path) === false) {
                @unlink($tmp);
                throw new PlumaUserError('write-failed', 500);
            }
            @unlink($tmp);
        }
        clearstatcache(true, $path);
    }
}

// ---- Tokenizer.php -----------------------------------------------

/**
 * PlumaEditor — tolerant HTML tokenizer that records byte offsets.
 *
 * It never rebuilds or reformats the document. It only remembers where every
 * element starts and ends, so edits can be applied surgically to the original
 * bytes.
 */

final class PlumaNode
{
    /** @var string lowercase tag name, '#root' for the document */
    public $tag;
    /** @var int offset of '<' of the start tag */
    public $start = 0;
    /** @var int offset right after '>' of the start tag */
    public $startTagEnd = 0;
    /** @var int offset right after the tag name or the last attribute */
    public $attrsEnd = 0;
    /** @var int content range [contentStart, contentEnd) */
    public $contentStart = 0;
    public $contentEnd = 0;
    /** @var int|null offset right after '>' of the end tag, null if implicit */
    public $endTagEnd = null;
    /** @var array<string,array> attributes keyed by lowercase name */
    public $attrs = [];
    /** @var PlumaNode|null */
    public $parent = null;
    /** @var PlumaNode[] */
    public $children = [];
    /** @var bool */
    public $void = false;

    public function __construct($tag)
    {
        $this->tag = $tag;
    }

    public function attr($name)
    {
        $name = strtolower($name);
        return isset($this->attrs[$name]) ? $this->attrs[$name]['value'] : null;
    }

    public function hasAttr($name)
    {
        return isset($this->attrs[strtolower($name)]);
    }

    /** Offset where the whole element ends (end tag, or content end if implicit). */
    public function outerEnd()
    {
        if ($this->void) {
            return $this->startTagEnd;
        }
        return $this->endTagEnd !== null ? $this->endTagEnd : $this->contentEnd;
    }

    public function hasAncestor($tag)
    {
        for ($p = $this->parent; $p !== null; $p = $p->parent) {
            if ($p->tag === $tag) {
                return true;
            }
        }
        return false;
    }
}

final class PlumaTokenizer
{
    const VOID = [
        'area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1,
        'input' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1,
    ];

    const RAW_TEXT = [
        'script' => 1, 'style' => 1, 'textarea' => 1, 'title' => 1, 'xmp' => 1,
        'iframe' => 1, 'noembed' => 1, 'noframes' => 1, 'plaintext' => 1,
    ];

    /** Start tags that implicitly close an open <p>. */
    const CLOSES_P = [
        'address' => 1, 'article' => 1, 'aside' => 1, 'blockquote' => 1, 'details' => 1, 'div' => 1,
        'dl' => 1, 'fieldset' => 1, 'figcaption' => 1, 'figure' => 1, 'footer' => 1, 'form' => 1,
        'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'header' => 1, 'hgroup' => 1,
        'hr' => 1, 'main' => 1, 'menu' => 1, 'nav' => 1, 'ol' => 1, 'p' => 1, 'pre' => 1,
        'section' => 1, 'table' => 1, 'ul' => 1, 'li' => 1, 'dd' => 1, 'dt' => 1,
    ];

    /** For tag X, an open element in the list is closed when X starts (until a boundary). */
    const IMPLIED = [
        'li' => [['li'], ['ul', 'ol', 'menu']],
        'dt' => [['dt', 'dd'], ['dl']],
        'dd' => [['dt', 'dd'], ['dl']],
        'option' => [['option'], ['select', 'datalist', 'optgroup']],
        'optgroup' => [['optgroup', 'option'], ['select']],
        'tr' => [['tr', 'td', 'th'], ['table', 'thead', 'tbody', 'tfoot']],
        'td' => [['td', 'th'], ['tr', 'table']],
        'th' => [['td', 'th'], ['tr', 'table']],
        'thead' => [['tbody', 'tfoot', 'tr', 'td', 'th'], ['table']],
        'tbody' => [['thead', 'tbody', 'tfoot', 'tr', 'td', 'th'], ['table']],
        'tfoot' => [['thead', 'tbody', 'tr', 'td', 'th'], ['table']],
    ];

    /** @var string */
    private $html;
    /** @var int */
    private $len;

    /** @var PlumaNode[] all element nodes in document order */
    public $nodes = [];

    public static function parse($html)
    {
        $t = new self();
        return $t->run($html);
    }

    /** @return PlumaNode root */
    public function run($html)
    {
        $this->html = $html;
        $this->len = strlen($html);
        $root = new PlumaNode('#root');
        $root->contentStart = 0;
        $root->contentEnd = $this->len;
        $stack = [$root];
        $i = 0;
        $html = $this->html;
        $len = $this->len;

        while ($i < $len) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                break;
            }
            $i = $lt;
            $next = $i + 1 < $len ? $html[$i + 1] : '';

            // Comments
            if (substr_compare($html, '<!--', $i, 4) === 0) {
                $end = strpos($html, '-->', $i + 4);
                $i = $end === false ? $len : $end + 3;
                continue;
            }
            // Doctype, CDATA, processing instructions
            if ($next === '!' || $next === '?') {
                $gt = strpos($html, '>', $i);
                $i = $gt === false ? $len : $gt + 1;
                continue;
            }
            // End tag
            if ($next === '/') {
                if (!preg_match('/\G<\/([a-zA-Z][a-zA-Z0-9:\-]*)[^>]*>/A', $html, $m, 0, $i)) {
                    $i++;
                    continue;
                }
                $name = strtolower($m[1]);
                $endEnd = $i + strlen($m[0]);
                for ($k = count($stack) - 1; $k > 0; $k--) {
                    if ($stack[$k]->tag === $name) {
                        for ($j = count($stack) - 1; $j > $k; $j--) {
                            $stack[$j]->contentEnd = $i;
                            $stack[$j]->endTagEnd = null;
                        }
                        $stack[$k]->contentEnd = $i;
                        $stack[$k]->endTagEnd = $endEnd;
                        array_splice($stack, $k);
                        break;
                    }
                }
                $i = $endEnd;
                continue;
            }
            // Start tag
            if (!preg_match('/\G<([a-zA-Z][a-zA-Z0-9:\-]*)/A', $html, $m, 0, $i)) {
                $i++;
                continue;
            }
            $name = strtolower($m[1]);
            $node = new PlumaNode($name);
            $node->start = $i;
            $p = $i + strlen($m[0]);
            $node->attrsEnd = $p;
            $selfClosing = false;

            // Attributes
            while ($p < $len) {
                // skip whitespace
                while ($p < $len && strpos(" \t\n\r\f", $html[$p]) !== false) {
                    $p++;
                }
                if ($p >= $len) {
                    break;
                }
                $c = $html[$p];
                if ($c === '>') {
                    $p++;
                    break;
                }
                if ($c === '/') {
                    if ($p + 1 < $len && $html[$p + 1] === '>') {
                        $selfClosing = true;
                        $p += 2;
                        break;
                    }
                    $p++;
                    continue;
                }
                $nameStart = $p;
                while ($p < $len && strpos(" \t\n\r\f/>=", $html[$p]) === false) {
                    $p++;
                }
                if ($p === $nameStart) { // stray '=' or similar
                    $p++;
                    continue;
                }
                $attrName = strtolower(substr($html, $nameStart, $p - $nameStart));
                $q = $p;
                while ($q < $len && strpos(" \t\n\r\f", $html[$q]) !== false) {
                    $q++;
                }
                $attr = [
                    'name' => $attrName,
                    'nameStart' => $nameStart,
                    'end' => $p,
                    'value' => '',
                    'valueStart' => null,
                    'valueEnd' => null,
                    'quote' => '',
                    'hasValue' => false,
                ];
                if ($q < $len && $html[$q] === '=') {
                    $q++;
                    while ($q < $len && strpos(" \t\n\r\f", $html[$q]) !== false) {
                        $q++;
                    }
                    if ($q < $len && ($html[$q] === '"' || $html[$q] === "'")) {
                        $quote = $html[$q];
                        $close = strpos($html, $quote, $q + 1);
                        if ($close === false) {
                            $close = $len;
                        }
                        $attr['valueStart'] = $q + 1;
                        $attr['valueEnd'] = $close;
                        $attr['quote'] = $quote;
                        $p = min($len, $close + 1);
                    } else {
                        $vs = $q;
                        while ($q < $len && strpos(" \t\n\r\f>", $html[$q]) === false) {
                            $q++;
                        }
                        $attr['valueStart'] = $vs;
                        $attr['valueEnd'] = $q;
                        $p = $q;
                    }
                    $attr['hasValue'] = true;
                    $raw = substr($html, $attr['valueStart'], $attr['valueEnd'] - $attr['valueStart']);
                    $attr['value'] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $attr['end'] = $p;
                }
                if (!isset($node->attrs[$attrName])) { // first wins, as in browsers
                    $node->attrs[$attrName] = $attr;
                }
                $node->attrsEnd = $attr['end'];
            }
            $node->startTagEnd = $p;
            $node->contentStart = $p;
            $node->contentEnd = $p;

            // Implicit closes
            $this->implyCloses($stack, $name, $i);

            $parent = $stack[count($stack) - 1];
            $node->parent = $parent;
            $parent->children[] = $node;
            $this->nodes[] = $node;

            if (isset(self::VOID[$name])) {
                $node->void = true;
                $i = $p;
                continue;
            }
            if ($selfClosing && !$this->isHtmlContainerContext($stack)) {
                // <svg/> children etc.
                $node->void = true;
                $i = $p;
                continue;
            }
            if ($selfClosing && in_array($name, ['svg', 'math', 'path', 'circle', 'rect', 'line', 'use', 'polygon', 'polyline', 'ellipse', 'stop'], true)) {
                $node->void = true;
                $i = $p;
                continue;
            }
            if (isset(self::RAW_TEXT[$name])) {
                $close = $this->findRawEnd($name, $p);
                $node->contentEnd = $close[0];
                $node->endTagEnd = $close[1];
                $i = $close[1];
                continue;
            }
            $stack[] = $node;
            $i = $p;
        }

        for ($j = count($stack) - 1; $j > 0; $j--) {
            $stack[$j]->contentEnd = $len;
            $stack[$j]->endTagEnd = null;
        }
        return $root;
    }

    /** Inside svg/math, "/>" closes the element. */
    private function isHtmlContainerContext(array $stack)
    {
        for ($k = count($stack) - 1; $k > 0; $k--) {
            if ($stack[$k]->tag === 'svg' || $stack[$k]->tag === 'math') {
                return false;
            }
        }
        return true;
    }

    private function findRawEnd($name, $from)
    {
        $html = $this->html;
        $pos = $from;
        while (true) {
            $found = stripos($html, '</' . $name, $pos);
            if ($found === false) {
                return [$this->len, $this->len];
            }
            $after = $found + 2 + strlen($name);
            $c = $after < $this->len ? $html[$after] : '>';
            if ($c === '>' || strpos(" \t\n\r\f/", $c) !== false) {
                $gt = strpos($html, '>', $after);
                return [$found, $gt === false ? $this->len : $gt + 1];
            }
            $pos = $after;
        }
    }

    private function implyCloses(array &$stack, $name, $at)
    {
        // <p> closed by block-level start tags
        if (isset(self::CLOSES_P[$name])) {
            for ($k = count($stack) - 1; $k > 0; $k--) {
                $t = $stack[$k]->tag;
                if ($t === 'p') {
                    $this->closeFrom($stack, $k, $at);
                    break;
                }
                // p is only closed when in "button scope"
                if (in_array($t, ['button', 'td', 'th', 'table', 'caption', 'marquee', 'object', 'html', 'template', 'svg', 'math'], true)) {
                    break;
                }
                if (!in_array($t, ['a', 'b', 'i', 'em', 'strong', 'span', 'small', 'u', 's', 'font', 'code', 'mark', 'sup', 'sub'], true)) {
                    break;
                }
            }
        }
        if (isset(self::IMPLIED[$name])) {
            // Close the outermost matching element before the boundary:
            // <tr> must close an open <td> AND the <tr> that holds it.
            list($closes, $boundaries) = self::IMPLIED[$name];
            $match = -1;
            for ($k = count($stack) - 1; $k > 0; $k--) {
                $t = $stack[$k]->tag;
                if (in_array($t, $boundaries, true)) {
                    break;
                }
                if (in_array($t, $closes, true)) {
                    $match = $k;
                }
            }
            if ($match > 0) {
                $this->closeFrom($stack, $match, $at);
            }
        }
    }

    private function closeFrom(array &$stack, $k, $at)
    {
        for ($j = count($stack) - 1; $j >= $k; $j--) {
            $stack[$j]->contentEnd = $at;
            $stack[$j]->endTagEnd = null;
        }
        array_splice($stack, $k);
    }
}

// ---- Detector.php ------------------------------------------------

/**
 * PlumaEditor — finds the editable elements of a page.
 *
 * Marked mode: only elements with data-pluma="text|rich|image|bg|link".
 * Automatic mode (no data-pluma anywhere): headings, paragraphs, list items,
 * buttons, links and images, skipping navigation, forms and scripts.
 */

final class PlumaEditable
{
    /** @var string stable key sent to the browser */
    public $key;
    /** @var string text|rich|image|bg|link */
    public $kind;
    /** @var PlumaNode */
    public $node;
    /** @var string */
    public $label;
    /** @var bool element may hold block content (p, ul...) */
    public $block = false;
}

/** A run of sibling elements that can be duplicated, moved and deleted. */
final class PlumaGroup
{
    /** @var string */
    public $id;
    /** @var PlumaRepeatItem[] */
    public $items = [];
    /** @var int */
    public $start;
    /** @var int */
    public $end;
    /** @var string text placed between items */
    public $sep = '';

    /** @return PlumaRepeatItem|null */
    public function item($key)
    {
        foreach ($this->items as $it) {
            if ($it->key === $key) {
                return $it;
            }
        }
        return null;
    }
}

final class PlumaRepeatItem
{
    /** @var string */
    public $key;
    /** @var PlumaNode */
    public $node;
    /** @var int */
    public $start;
    /** @var int */
    public $end;
    /** @var PlumaGroup */
    public $group;
}

final class PlumaDetector
{
    const KINDS = ['text' => 1, 'rich' => 1, 'image' => 1, 'bg' => 1, 'link' => 1, 'video' => 1];

    const INLINE = [
        'a' => 1, 'abbr' => 1, 'b' => 1, 'bdi' => 1, 'bdo' => 1, 'br' => 1, 'cite' => 1, 'code' => 1,
        'data' => 1, 'dfn' => 1, 'em' => 1, 'i' => 1, 'kbd' => 1, 'mark' => 1, 'q' => 1, 's' => 1,
        'samp' => 1, 'small' => 1, 'span' => 1, 'strong' => 1, 'sub' => 1, 'sup' => 1, 'time' => 1,
        'u' => 1, 'var' => 1, 'wbr' => 1, 'del' => 1, 'ins' => 1, 'font' => 1,
    ];

    /** Elements whose content is never inspected in automatic mode. */
    const SKIP = [
        'head' => 1, 'script' => 1, 'style' => 1, 'noscript' => 1, 'template' => 1, 'svg' => 1,
        'math' => 1, 'nav' => 1, 'form' => 1, 'select' => 1, 'textarea' => 1, 'iframe' => 1,
        'object' => 1, 'video' => 1, 'audio' => 1, 'canvas' => 1, 'picture' => 0, 'dialog' => 1,
    ];

    const AUTO_TEXT = [
        'h1' => 'text', 'h2' => 'text', 'h3' => 'text', 'h4' => 'text', 'h5' => 'text', 'h6' => 'text',
        'p' => 'rich', 'li' => 'rich', 'blockquote' => 'rich', 'figcaption' => 'rich', 'dd' => 'rich',
        'dt' => 'text', 'td' => 'rich', 'th' => 'text', 'button' => 'text', 'caption' => 'text',
        'label' => 'text', 'summary' => 'text', 'address' => 'rich', 'cite' => 'text',
    ];

    /** Containers that may receive block content when marked as rich. */
    const BLOCK_CONTAINERS = [
        'div' => 1, 'section' => 1, 'article' => 1, 'aside' => 1, 'main' => 1, 'header' => 1,
        'footer' => 1, 'blockquote' => 1, 'td' => 1, 'th' => 1, 'li' => 1, 'dd' => 1, 'figure' => 1,
        'details' => 1,
    ];

    /** @var string */
    private $html;
    /** @var PlumaEditable[] */
    public $items = [];
    /** @var string marked|auto */
    public $mode = 'auto';

    /**
     * @param string $html
     * @param PlumaNode $root
     * @return PlumaDetector
     */
    public static function detect($html, $root, array $allNodes)
    {
        $d = new self();
        $d->html = $html;
        $d->mode = 'auto';
        foreach ($allNodes as $n) {
            if ($n->hasAttr('data-pluma') && isset(self::KINDS[strtolower(trim($n->attr('data-pluma')))])) {
                $d->mode = 'marked';
                break;
            }
        }
        if ($d->mode === 'marked') {
            $d->walkMarked($root);
        } else {
            $d->walkAuto($root);
        }
        $d->assignKeys();
        $d->repeatMarked = false;
        foreach ($allNodes as $n) {
            if ($n->hasAttr('data-pluma-repeat')) {
                $d->repeatMarked = true;
                break;
            }
        }
        $d->detectGroups($root);
        $d->detectShared($root);
        return $d;
    }

    // ------------------------------------------------------------------ repeatable groups

    /** Never repeated in automatic mode: duplicating them breaks tables, forms or document structure. */
    const NO_REPEAT = [
        'html' => 1, 'head' => 1, 'body' => 1, 'title' => 1, 'base' => 1, 'meta' => 1, 'link' => 1,
        'script' => 1, 'style' => 1, 'noscript' => 1, 'template' => 1, 'br' => 1, 'hr' => 1, 'wbr' => 1,
        'td' => 1, 'th' => 1, 'col' => 1, 'colgroup' => 1, 'caption' => 1, 'thead' => 1, 'tbody' => 1,
        'tfoot' => 1, 'dt' => 1, 'dd' => 1, 'option' => 1, 'optgroup' => 1, 'source' => 1, 'track' => 1,
        'param' => 1, 'area' => 1, 'input' => 1, 'summary' => 1, 'legend' => 1, 'main' => 1,
    ];

    /** Never searched for groups, in either mode. */
    const HARD_SKIP = [
        'head' => 1, 'script' => 1, 'style' => 1, 'template' => 1, 'svg' => 1, 'math' => 1,
        'noscript' => 1, 'select' => 1, 'textarea' => 1, 'iframe' => 1, 'object' => 1,
    ];

    /** @var bool page uses data-pluma-repeat: only marked groups count */
    public $repeatMarked = false;
    /** @var PlumaGroup[] */
    public $groups = [];
    /** @var array<int,string> spl_object_id => kind of editable nodes */
    private $editableKinds = [];
    /** @var int[] sorted start offsets of editable nodes */
    private $editStarts = [];

    /** @var array<string,PlumaNode> shared part name => element (outermost, first per name) */
    public $shared = [];

    private function detectShared(PlumaNode $n)
    {
        foreach ($n->children as $c) {
            if ($c->hasAttr('data-pluma-shared')) {
                $name = trim((string) $c->attr('data-pluma-shared'));
                if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) && !isset($this->shared[$name]) && !$c->void) {
                    $this->shared[$name] = $c;
                }
                continue; // no shared parts inside shared parts
            }
            $this->detectShared($c);
        }
    }

    /** Name of the shared part holding this offset, or null. */
    public function sharedAt($offset)
    {
        foreach ($this->shared as $name => $node) {
            if ($offset >= $node->start && $offset < $node->outerEnd()) {
                return $name;
            }
        }
        return null;
    }

    /** @return PlumaGroup|null */
    public function group($id)
    {
        foreach ($this->groups as $g) {
            if ($g->id === $id) {
                return $g;
            }
        }
        return null;
    }

    private function detectGroups(PlumaNode $root)
    {
        foreach ($this->items as $it) {
            $this->editableKinds[spl_object_id($it->node)] = $it->kind;
            $this->editStarts[] = $it->node->start;
        }
        sort($this->editStarts);
        $runs = [];
        $this->scanGroups($root, $runs, true);
        usort($runs, function ($a, $b) {
            return $a[0]->start <=> $b[0]->start;
        });
        $r = 0;
        foreach ($runs as $gi => $run) {
            $g = new PlumaGroup();
            $g->id = 'g' . $gi;
            foreach ($run as $node) {
                $item = new PlumaRepeatItem();
                $item->key = 'r' . $r++;
                $item->node = $node;
                $item->start = $node->start;
                $item->end = $node->outerEnd();
                $item->group = $g;
                $g->items[] = $item;
            }
            $g->start = $g->items[0]->start;
            $g->end = $g->items[count($g->items) - 1]->end;
            $g->sep = $this->separator($g);
            $this->groups[] = $g;
        }
    }

    /** @return bool true when the subtree holds a group (items never contain groups: innermost wins) */
    private function scanGroups(PlumaNode $n, array &$runs, $isRoot = false, $inShared = false)
    {
        $inShared = $inShared || $n->hasAttr('data-pluma-shared');
        if (!$isRoot) {
            if (isset(self::HARD_SKIP[$n->tag]) || $n->hasAttr('data-pluma-ignore')) {
                return false;
            }
            if (!$this->repeatMarked && !empty(self::SKIP[$n->tag]) && !($inShared && $n->tag === 'nav')) {
                return false;
            }
            $kind = isset($this->editableKinds[spl_object_id($n)]) ? $this->editableKinds[spl_object_id($n)] : null;
            if ($kind === 'text' || $kind === 'rich' || $kind === 'link') {
                return false; // its content is edited as a whole
            }
        }
        $kids = $n->children;
        $has = [];
        $any = false;
        foreach ($kids as $i => $c) {
            $has[$i] = $this->scanGroups($c, $runs, false, $inShared);
            $any = $any || $has[$i];
        }
        $count = count($kids);
        $i = 0;
        while ($i < $count) {
            $sig = $this->signature($kids[$i]);
            if ($sig === null) {
                $i++;
                continue;
            }
            $j = $i + 1;
            while ($j < $count && $this->signature($kids[$j]) === $sig && $this->onlySpace($kids[$j - 1]->outerEnd(), $kids[$j]->start)) {
                $j++;
            }
            $ok = ($j - $i) >= ($this->repeatMarked ? 1 : 2);
            for ($k = $i; $ok && $k < $j; $k++) {
                if ($has[$k] || !$this->containsEditable($kids[$k])) {
                    $ok = false;
                }
            }
            if ($ok) {
                $runs[] = array_slice($kids, $i, $j - $i);
                $any = true;
            }
            $i = $j;
        }
        return $any;
    }

    /** Siblings with the same signature form a group; null = never an item. */
    private function signature(PlumaNode $n)
    {
        if ($n->hasAttr('data-pluma-ignore') || isset(self::HARD_SKIP[$n->tag])) {
            return null;
        }
        if ($this->repeatMarked) {
            return $n->hasAttr('data-pluma-repeat') ? $n->tag . '|' . trim((string) $n->attr('data-pluma-repeat')) : null;
        }
        if (isset(self::NO_REPEAT[$n->tag]) || !empty(self::SKIP[$n->tag])) {
            return null;
        }
        $classes = preg_split('/\s+/', trim((string) $n->attr('class')), -1, PREG_SPLIT_NO_EMPTY);
        sort($classes);
        return $n->tag . '.' . implode('.', $classes);
    }

    private function onlySpace($from, $to)
    {
        if ($to < $from) {
            return false;
        }
        $s = substr($this->html, $from, $to - $from);
        $s = preg_replace('/<!--.*?-->/s', '', $s);
        return trim($s, " \t\r\n\f") === '';
    }

    private function containsEditable(PlumaNode $n)
    {
        $end = $n->outerEnd();
        // binary search for the first editable starting at or after $n->start
        $lo = 0;
        $hi = count($this->editStarts);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->editStarts[$mid] < $n->start) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo < count($this->editStarts) && $this->editStarts[$lo] < $end;
    }

    /** Text placed between items when the group is rebuilt. */
    private function separator(PlumaGroup $g)
    {
        if (count($g->items) >= 2) {
            return substr($this->html, $g->items[0]->end, $g->items[1]->start - $g->items[0]->end);
        }
        $nl = strpos($this->html, "\r\n") !== false ? "\r\n" : "\n";
        $lineStart = strrpos(substr($this->html, 0, $g->start), "\n");
        $indent = substr($this->html, $lineStart === false ? 0 : $lineStart + 1, $g->start - ($lineStart === false ? 0 : $lineStart + 1));
        return trim($indent, " \t") === '' ? $nl . $indent : $nl;
    }

    /** @return PlumaEditable|null */
    public function find($key)
    {
        foreach ($this->items as $it) {
            if ($it->key === $key) {
                return $it;
            }
        }
        return null;
    }

    private function walkMarked(PlumaNode $node)
    {
        foreach ($node->children as $c) {
            if ($c->hasAttr('data-pluma-ignore')) {
                continue;
            }
            $kind = $c->hasAttr('data-pluma') ? strtolower(trim($c->attr('data-pluma'))) : '';
            if (isset(self::KINDS[$kind])) {
                if ($kind === 'image' && $c->tag !== 'img') {
                    $kind = 'bg';
                } elseif ($kind === 'bg' && $c->tag === 'img') {
                    $kind = 'image';
                }
                if ($kind === 'link' && $c->tag !== 'a') {
                    $kind = 'rich';
                }
                if ($kind === 'image' && $c->attr('src') === null) {
                    continue;
                }
                if ($kind === 'video') {
                    if ($c->tag === 'iframe') {
                        $this->add($c, 'video');
                    }
                    continue;
                }
                $it = $this->add($c, $kind);
                if ($kind === 'text' || $kind === 'rich' || $kind === 'link') {
                    continue; // its content belongs to it
                }
                if ($kind === 'image') {
                    continue;
                }
            }
            $this->walkMarked($c);
        }
    }

    private function walkAuto(PlumaNode $node, $inShared = false)
    {
        foreach ($node->children as $c) {
            $t = $c->tag;
            if ($t === 'iframe' && PlumaVideo::isEmbed((string) $c->attr('src')) && !$c->hasAttr('data-pluma-ignore')) {
                $this->add($c, 'video');
                continue;
            }
            $shared = $inShared || $c->hasAttr('data-pluma-shared');
            // a shared menu is meant to be edited: navigation is not skipped inside it
            $skip = !empty(self::SKIP[$t]) && !($shared && $t === 'nav');
            if ($skip || $c->hasAttr('data-pluma-ignore') || $c->hasAttr('contenteditable')) {
                continue;
            }
            if ($t === 'img') {
                if (($c->attr('src') !== null && trim($c->attr('src')) !== '')) {
                    $this->add($c, 'image');
                }
                continue;
            }
            if ($c->void) {
                continue;
            }
            if (isset(self::AUTO_TEXT[$t]) && $this->onlyInline($c) && $this->hasText($c)) {
                $this->add($c, self::AUTO_TEXT[$t]);
                continue;
            }
            if ($t === 'a' && $c->hasAttr('href') && $this->onlyInline($c) && $this->hasText($c)) {
                $this->add($c, 'link');
                continue;
            }
            $this->walkAuto($c, $shared);
        }
    }

    private function add(PlumaNode $n, $kind)
    {
        $it = new PlumaEditable();
        $it->node = $n;
        $it->kind = $kind;
        // Block content (paragraphs, lists) only in containers explicitly marked as rich.
        $it->block = $kind === 'rich' && $this->mode === 'marked' && isset(self::BLOCK_CONTAINERS[$n->tag]);
        $label = $n->attr('data-pluma-label');
        $it->label = $label !== null ? $label : '';
        $this->items[] = $it;
        return $it;
    }

    private function assignKeys()
    {
        $used = [];
        foreach ($this->items as $i => $it) {
            $id = $it->node->attr('data-pluma-id');
            $key = ($id !== null && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id) && !isset($used['id-' . $id]))
                ? 'id-' . $id
                : 'n' . $i;
            $used[$key] = true;
            $it->key = $key;
        }
    }

    /** True when the element contains only text and inline elements. */
    public function onlyInline(PlumaNode $n)
    {
        foreach ($n->children as $c) {
            if (!isset(self::INLINE[$c->tag])) {
                return false;
            }
            if (!$c->void && !$this->onlyInline($c)) {
                return false;
            }
        }
        return true;
    }

    public function hasText(PlumaNode $n)
    {
        $inner = substr($this->html, $n->contentStart, $n->contentEnd - $n->contentStart);
        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text);
        return $text !== '' && $text !== null;
    }
}

// ---- Sanitizer.php -----------------------------------------------

/**
 * PlumaEditor — server-side cleaning of edited HTML fragments.
 * The browser is never trusted: everything that reaches a page passes here.
 */

final class PlumaSanitizer
{
    const INLINE_TAGS = [
        'a' => 1, 'abbr' => 1, 'b' => 1, 'br' => 1, 'cite' => 1, 'code' => 1, 'data' => 1,
        'del' => 1, 'em' => 1, 'i' => 1, 'ins' => 1, 'kbd' => 1, 'mark' => 1, 'q' => 1, 's' => 1,
        'small' => 1, 'span' => 1, 'strong' => 1, 'sub' => 1, 'sup' => 1, 'time' => 1, 'u' => 1,
        'wbr' => 1,
    ];

    const BLOCK_TAGS = [
        'p' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1, 'blockquote' => 1, 'h2' => 1, 'h3' => 1,
        'h4' => 1, 'h5' => 1, 'h6' => 1, 'hr' => 1,
    ];

    /** Removed together with everything inside them. */
    const DROP = [
        'script' => 1, 'style' => 1, 'iframe' => 1, 'object' => 1, 'embed' => 1, 'template' => 1,
        'svg' => 1, 'math' => 1, 'noscript' => 1, 'link' => 1, 'meta' => 1, 'base' => 1, 'form' => 1,
        'input' => 1, 'textarea' => 1, 'select' => 1, 'button' => 1, 'frame' => 1, 'frameset' => 1,
        'applet' => 1, 'title' => 1, 'head' => 1, 'img' => 1, 'video' => 1, 'audio' => 1,
        'source' => 1, 'canvas' => 1, 'dialog' => 1, 'xmp' => 1, 'plaintext' => 1,
    ];

    const GLOBAL_ATTRS = ['class' => 1, 'title' => 1, 'lang' => 1, 'dir' => 1, 'id' => 1];

    const TAG_ATTRS = [
        'a' => ['href' => 1, 'target' => 1, 'rel' => 1],
        'time' => ['datetime' => 1],
        'data' => ['value' => 1],
        'q' => ['cite' => 1],
        'ol' => ['start' => 1, 'reversed' => 1, 'type' => 1],
    ];

    /**
     * @param string $html  fragment from the browser
     * @param bool   $block allow paragraphs and lists
     * @return string
     */
    public static function fragment($html, $block = false)
    {
        $html = (string) $html;
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }
        // Comments, CDATA, doctype, processing instructions: removed
        $html = preg_replace('/<!--.*?(-->|$)/s', '', $html);
        $html = preg_replace('/<[!?][^>]*>?/', '', $html);

        $tok = new PlumaTokenizer();
        $root = $tok->run($html);
        $out = self::children($html, $root, $block);
        return $out;
    }

    private static function children($html, PlumaNode $node, $block)
    {
        $out = '';
        $pos = $node->contentStart;
        foreach ($node->children as $c) {
            $out .= self::text(substr($html, $pos, $c->start - $pos));
            $out .= self::element($html, $c, $block);
            $pos = $c->outerEnd();
        }
        $out .= self::text(substr($html, $pos, max(0, $node->contentEnd - $pos)));
        return $out;
    }

    private static function text($s)
    {
        // Any '<' left in text is literal text, never markup.
        return str_replace('<', '&lt;', $s);
    }

    private static function element($html, PlumaNode $n, $block)
    {
        $t = $n->tag;
        if (isset(self::DROP[$t])) {
            return '';
        }
        $allowed = isset(self::INLINE_TAGS[$t]) || ($block && isset(self::BLOCK_TAGS[$t]));
        if (!$allowed) {
            // Unknown or block element where only inline is allowed: keep its content.
            $inner = $n->void ? '' : self::children($html, $n, $block);
            if (!$block && in_array($t, ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'], true) && trim(strip_tags($inner)) !== '') {
                // A browser wrapped text in a block while editing a heading or button.
                return $inner . ' ';
            }
            return $inner;
        }
        $out = '<' . $t . self::attrs($n);
        if ($n->void) {
            return $out . '>';
        }
        return $out . '>' . self::children($html, $n, $block) . '</' . $t . '>';
    }

    private static function attrs(PlumaNode $n)
    {
        $out = '';
        $extra = isset(self::TAG_ATTRS[$n->tag]) ? self::TAG_ATTRS[$n->tag] : [];
        $target = null;
        $rel = null;
        foreach ($n->attrs as $name => $a) {
            $inert = (bool) preg_match('/^(aria-[a-z\-]+|role|data-(?!pluma)[a-z0-9\-]+)$/', $name);
            if (!$inert && !isset(self::GLOBAL_ATTRS[$name]) && !isset($extra[$name])) {
                continue;
            }
            $v = $a['value'];
            if ($name === 'href' || $name === 'cite') {
                $v = self::url($v);
                if ($v === null) {
                    continue;
                }
            }
            if ($name === 'target') {
                $target = in_array($v, ['_blank', '_self'], true) ? $v : null;
                continue;
            }
            if ($name === 'rel') {
                $rel = $v;
                continue;
            }
            if ($name === 'id' && !preg_match('/^[A-Za-z][A-Za-z0-9_\-:.]{0,63}$/', $v)) {
                continue;
            }
            if ($name === 'class') {
                $v = trim(preg_replace('/\bpluma-[a-z\-]+\b/', '', $v));
                if ($v === '') {
                    continue;
                }
            }
            $out .= ' ' . $name . '="' . self::escAttr($v) . '"';
        }
        if ($n->tag === 'a') {
            if ($target === '_blank') {
                $out .= ' target="_blank"';
                $parts = preg_split('/\s+/', trim((string) $rel), -1, PREG_SPLIT_NO_EMPTY);
                if (!in_array('noopener', $parts, true)) {
                    $parts[] = 'noopener';
                }
                $out .= ' rel="' . self::escAttr(implode(' ', $parts)) . '"';
            } elseif ($rel !== null && $rel !== '') {
                $out .= ' rel="' . self::escAttr($rel) . '"';
            }
        }
        return $out;
    }

    /**
     * Allowed link destinations: http(s), mailto:, tel:, relative paths, fragments.
     * @return string|null
     */
    public static function url($v)
    {
        $v = trim((string) $v);
        $check = preg_replace('/[\x00-\x20\x7F]+/', '', $v);
        $check = strtolower(html_entity_decode($check, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($check === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $check, $m)) {
            if (!in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
                return null;
            }
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
            return null;
        }
        return $v;
    }

    public static function escAttr($v)
    {
        return htmlspecialchars((string) $v, ENT_COMPAT | ENT_HTML5, 'UTF-8');
    }

    /** Plain text for alt attributes and similar. */
    public static function plain($v, $max = 500)
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v));
        return mb_substr($v, 0, $max);
    }
}

// ---- Patcher.php -------------------------------------------------

/**
 * PlumaEditor — applies edits to the original HTML bytes.
 *
 * Everything outside the edited ranges stays byte-for-byte identical.
 */

final class PlumaDocument
{
    /** @var string */
    public $html;
    /** @var PlumaNode */
    public $root;
    /** @var PlumaNode[] */
    public $nodes;
    /** @var PlumaDetector */
    public $detector;
    /** @var string */
    public $hash;

    public static function fromString($html)
    {
        $d = new self();
        $d->html = $html;
        $tok = new PlumaTokenizer();
        $d->root = $tok->run($html);
        $d->nodes = $tok->nodes;
        $d->detector = PlumaDetector::detect($html, $d->root, $d->nodes);
        $d->hash = hash('sha256', $html);
        return $d;
    }
}

final class PlumaEditList
{
    /** @var array<int,array{0:int,1:int,2:string,3:int}> */
    private $edits = [];
    private $seq = 0;

    public function replace($start, $end, $text)
    {
        $this->edits[] = [$start, $end, (string) $text, $this->seq++];
    }

    public function insert($at, $text)
    {
        $this->replace($at, $at, $text);
    }

    public function isEmpty()
    {
        return !$this->edits;
    }

    /** @throws RuntimeException on overlapping edits */
    public function apply($html)
    {
        $edits = $this->edits;
        usort($edits, function ($a, $b) {
            if ($a[0] !== $b[0]) {
                return $a[0] < $b[0] ? -1 : 1;
            }
            // inserts (empty range) first at the same position, then by order of creation
            $ea = $a[1] - $a[0];
            $eb = $b[1] - $b[0];
            if (($ea === 0) !== ($eb === 0)) {
                return $ea === 0 ? -1 : 1;
            }
            return $a[3] < $b[3] ? -1 : 1;
        });
        $out = '';
        $pos = 0;
        foreach ($edits as $e) {
            if ($e[0] < $pos) {
                throw new RuntimeException('overlapping-edits');
            }
            $out .= substr($html, $pos, $e[0] - $pos) . $e[2];
            $pos = $e[1];
        }
        return $out . substr($html, $pos);
    }

    /**
     * Applies only to $html[$start, $end) and returns that slice edited.
     * @throws RuntimeException when an edit falls outside the slice
     */
    public function applySlice($html, $start, $end)
    {
        $shifted = new self();
        foreach ($this->edits as $e) {
            if ($e[0] < $start || $e[1] > $end) {
                throw new RuntimeException('edit-outside-slice');
            }
            $shifted->edits[] = [$e[0] - $start, $e[1] - $start, $e[2], $e[3]];
        }
        $shifted->seq = $this->seq;
        return $shifted->apply(substr($html, $start, $end - $start));
    }

    // ---- attribute helpers ----------------------------------------------

    public function setAttr($html, PlumaNode $n, $name, $value)
    {
        $esc = PlumaSanitizer::escAttr($value);
        if (isset($n->attrs[$name])) {
            $a = $n->attrs[$name];
            if ($a['hasValue'] && $a['quote'] !== '') {
                $inner = $a['quote'] === "'" ? str_replace("'", '&#39;', $esc) : $esc;
                $this->replace($a['valueStart'], $a['valueEnd'], $inner);
            } elseif ($a['hasValue']) {
                $this->replace($a['valueStart'], $a['valueEnd'], '"' . $esc . '"');
            } else {
                $this->insert($a['end'], '="' . $esc . '"');
            }
            return;
        }
        $this->insert($n->attrsEnd, ' ' . $name . '="' . $esc . '"');
    }

    public function removeAttr($html, PlumaNode $n, $name)
    {
        if (!isset($n->attrs[$name])) {
            return;
        }
        $a = $n->attrs[$name];
        $start = $a['nameStart'];
        while ($start > $n->start && strpos(" \t\n\r\f", $html[$start - 1]) !== false) {
            $start--;
        }
        $this->replace($start, $a['end'], '');
    }
}

final class PlumaPatcher
{
    /**
     * @param PlumaDocument $doc
     * @param array $changes list of change objects from the browser
     * @return string new html
     * @throws PlumaUserError
     */
    /**
     * @param PlumaDocument $doc
     * @param array $changes  edits to elements outside rebuilt groups
     * @param array $groups   rebuilt groups: [{group, items:[{src, changes:[...]}, ...]}, ...]
     * @param array|null $expected  out: "kind:tag" of every editable the new page must have, in order
     * @return string new html
     * @throws PlumaUserError
     */
    public static function apply(PlumaDocument $doc, array $changes, array $groups = [], &$expected = null)
    {
        $html = $doc->html;
        $det = $doc->detector;
        $edits = new PlumaEditList();

        // Groups the browser rebuilt (duplicated, moved or deleted items)
        $rebuilt = [];
        $entriesTotal = 0;
        foreach ($groups as $g) {
            if (!is_array($g) || !isset($g['group'], $g['items']) || !is_array($g['items']) || !$g['items']) {
                throw new PlumaUserError('bad-change');
            }
            $grp = $det->group((string) $g['group']);
            if ($grp === null) {
                throw new PlumaUserError('element-not-found');
            }
            if (isset($rebuilt[$grp->id])) {
                throw new PlumaUserError('bad-change');
            }
            $entriesTotal += count($g['items']);
            if ($entriesTotal > 1000) {
                throw new PlumaUserError('bad-change');
            }
            $rebuilt[$grp->id] = [$grp, $g['items']];
        }
        $blocked = [];
        foreach ($rebuilt as $r) {
            $blocked[] = [$r[0]->start, $r[0]->end];
        }

        $emptied = self::collect($doc, $edits, $changes, null, $blocked);

        $groupSeq = [];
        foreach ($rebuilt as $id => $r) {
            list($grp, $entries) = $r;
            $parts = [];
            $seq = [];
            $usedSrc = [];
            foreach ($entries as $e) {
                if (!is_array($e) || !isset($e['src'])) {
                    throw new PlumaUserError('bad-change');
                }
                $item = $grp->item((string) $e['src']);
                if ($item === null) {
                    throw new PlumaUserError('element-not-found');
                }
                $local = new PlumaEditList();
                $itemChanges = isset($e['changes']) && is_array($e['changes']) ? $e['changes'] : [];
                $em = self::collect($doc, $local, $itemChanges, [$item->start, $item->end], []);
                try {
                    $str = $local->applySlice($html, $item->start, $item->end);
                } catch (RuntimeException $ex) {
                    throw new PlumaUserError('bad-change');
                }
                if (isset($usedSrc[$item->key])) {
                    $str = self::freshIds($str); // a copy: its ids must not repeat the original's
                }
                $usedSrc[$item->key] = true;
                $parts[] = $str;
                foreach ($det->items as $it) {
                    if ($it->node->start >= $item->start && $it->node->start < $item->end && !isset($em[$it->key])) {
                        $seq[] = $it->kind . ':' . $it->node->tag;
                    }
                }
            }
            $edits->replace($grp->start, $grp->end, implode($grp->sep, $parts));
            $groupSeq[$id] = $seq;
        }

        // What the saved page must contain, for the structural check
        $expected = [];
        $done = [];
        foreach ($det->items as $it) {
            $inGroup = null;
            foreach ($rebuilt as $id => $r) {
                if ($it->node->start >= $r[0]->start && $it->node->start < $r[0]->end) {
                    $inGroup = $id;
                    break;
                }
            }
            if ($inGroup !== null) {
                if (!isset($done[$inGroup])) {
                    $done[$inGroup] = true;
                    foreach ($groupSeq[$inGroup] as $s) {
                        $expected[] = $s;
                    }
                }
                continue;
            }
            if (!isset($emptied[$it->key])) {
                $expected[] = $it->kind . ':' . $it->node->tag;
            }
        }

        if ($edits->isEmpty()) {
            return $html;
        }
        try {
            return $edits->apply($html);
        } catch (RuntimeException $ex) {
            throw new PlumaUserError('bad-change');
        }
    }

    /**
     * Turns change objects into edits.
     * @param array|null $range  [start, end): every edited element must lie inside it
     * @param array $blocked     ranges no edited element may lie in
     * @return array<string,bool> keys of text elements left without text in automatic mode
     *                            (they are no longer detected once saved)
     */
    private static function collect(PlumaDocument $doc, PlumaEditList $edits, array $changes, $range, array $blocked)
    {
        $html = $doc->html;
        $seen = [];
        $emptied = [];
        if (count($changes) > 2000) {
            throw new PlumaUserError('bad-change');
        }
        foreach ($changes as $ch) {
            if (!is_array($ch) || !isset($ch['key'], $ch['kind'])) {
                throw new PlumaUserError('bad-change');
            }
            $key = (string) $ch['key'];
            if (isset($seen[$key])) {
                throw new PlumaUserError('bad-change');
            }
            $seen[$key] = true;
            $item = $doc->detector->find($key);
            if ($item === null || $item->kind !== $ch['kind']) {
                throw new PlumaUserError('element-not-found');
            }
            $n = $item->node;
            if ($range !== null && ($n->start < $range[0] || $n->outerEnd() > $range[1])) {
                throw new PlumaUserError('bad-change');
            }
            foreach ($blocked as $b) {
                if ($n->start >= $b[0] && $n->start < $b[1]) {
                    throw new PlumaUserError('bad-change');
                }
            }
            switch ($item->kind) {
                case 'text':
                case 'rich':
                    $inner = PlumaSanitizer::fragment(isset($ch['html']) ? $ch['html'] : '', $item->block);
                    self::replaceContent($html, $edits, $n, $inner);
                    if ($doc->detector->mode === 'auto' && !self::hasText($inner)) {
                        $emptied[$key] = true;
                    }
                    break;

                case 'link':
                    if (isset($ch['html'])) {
                        $inner = PlumaSanitizer::fragment($ch['html'], false);
                        self::replaceContent($html, $edits, $n, $inner);
                        if ($doc->detector->mode === 'auto' && !self::hasText($inner)) {
                            $emptied[$key] = true;
                        }
                    }
                    self::linkAttrs($html, $edits, $n, $ch);
                    break;

                case 'image':
                    self::image($html, $doc, $edits, $n, $ch);
                    break;

                case 'bg':
                    self::background($html, $edits, $n, $ch);
                    break;

                case 'video':
                    if (isset($ch['src'])) {
                        $embed = PlumaVideo::embedUrl($ch['src']);
                        if ($embed === null) {
                            throw new PlumaUserError('bad-video');
                        }
                        if ($embed !== $n->attr('src')) {
                            $edits->setAttr($html, $n, 'src', $embed);
                        }
                    }
                    if (array_key_exists('title', $ch)) {
                        $title = PlumaSanitizer::plain($ch['title'], 200);
                        if ($title !== (string) $n->attr('title')) {
                            $edits->setAttr($html, $n, 'title', $title);
                        }
                    }
                    break;
            }
        }
        return $emptied;
    }

    /**
     * An element without an end tag (<li>, <p>) holds the whitespace up to the next tag:
     * keep that whitespace, so the line layout of the file does not change.
     */
    private static function replaceContent($html, PlumaEditList $edits, PlumaNode $n, $inner)
    {
        $end = $n->contentEnd;
        if ($n->endTagEnd === null) {
            $content = substr($html, $n->contentStart, $n->contentEnd - $n->contentStart);
            $trail = strlen($content) - strlen(rtrim($content, " \t\r\n\f"));
            $end -= $trail;
            $inner = rtrim($inner, " \t\r\n\f");
        }
        $edits->replace($n->contentStart, $end, $inner);
    }

    private static function hasText($fragment)
    {
        $text = html_entity_decode(strip_tags($fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) !== '';
    }

    /** In a copied item: ids get a suffix, data-pluma-id is dropped (both must stay unique). */
    private static function freshIds($str)
    {
        $tok = new PlumaTokenizer();
        $tok->run($str);
        $edits = new PlumaEditList();
        $suffix = PlumaUtil::randomId(2);
        foreach ($tok->nodes as $node) {
            $id = $node->attr('id');
            if ($id !== null && $id !== '') {
                $edits->setAttr($str, $node, 'id', $id . '-' . $suffix);
            }
            $edits->removeAttr($str, $node, 'data-pluma-id');
        }
        return $edits->isEmpty() ? $str : $edits->apply($str);
    }

    /** Inline links inside text are handled by the sanitizer; this is for link elements. */
    private static function linkAttrs($html, PlumaEditList $edits, PlumaNode $n, array $ch)
    {
        if (array_key_exists('href', $ch)) {
            $href = PlumaSanitizer::url($ch['href']);
            if ($href === null) {
                throw new PlumaUserError('bad-link');
            }
            $edits->setAttr($html, $n, 'href', $href);
        }
        if (array_key_exists('newTab', $ch)) {
            if ($ch['newTab']) {
                if ($n->attr('target') !== '_blank') {
                    $edits->setAttr($html, $n, 'target', '_blank');
                }
                $rel = (string) $n->attr('rel');
                if (!preg_match('/\bnoopener\b/', $rel)) {
                    $edits->setAttr($html, $n, 'rel', trim($rel . ' noopener'));
                }
            } elseif ($n->hasAttr('target')) {
                $edits->removeAttr($html, $n, 'target');
            }
        }
    }

    private static function checkSrc($src)
    {
        $src = PlumaSanitizer::url($src);
        if ($src === null || $src === '' || preg_match('/^(mailto|tel):/i', $src)) {
            throw new PlumaUserError('bad-image');
        }
        return $src;
    }

    private static function image($html, PlumaDocument $doc, PlumaEditList $edits, PlumaNode $n, array $ch)
    {
        if (isset($ch['src'])) {
            $src = self::checkSrc($ch['src']);
            if ($src !== $n->attr('src')) {
                $edits->setAttr($html, $n, 'src', $src);
                // srcset/sizes would make browsers ignore the new src
                $edits->removeAttr($html, $n, 'srcset');
                $edits->removeAttr($html, $n, 'sizes');
                $edits->removeAttr($html, $n, 'data-src');
                $edits->removeAttr($html, $n, 'data-srcset');
                // <picture><source> would also win over the new image
                if ($n->parent !== null && $n->parent->tag === 'picture') {
                    foreach ($n->parent->children as $sib) {
                        if ($sib->tag === 'source') {
                            $edits->replace($sib->start, $sib->outerEnd(), '');
                        }
                    }
                }
                // Keep proportions when width and height are fixed in the HTML
                $w = isset($ch['width']) ? (int) $ch['width'] : 0;
                $h = isset($ch['height']) ? (int) $ch['height'] : 0;
                $aw = $n->attr('width');
                $ah = $n->attr('height');
                if ($w > 0 && $h > 0 && $aw !== null && $ah !== null && ctype_digit(trim($aw)) && ctype_digit(trim($ah))) {
                    $edits->setAttr($html, $n, 'height', (string) max(1, (int) round((int) $aw * $h / $w)));
                }
            }
        }
        if (array_key_exists('alt', $ch)) {
            $alt = PlumaSanitizer::plain($ch['alt']);
            if ($alt !== $n->attr('alt')) {
                $edits->setAttr($html, $n, 'alt', $alt);
            }
        }
        if (array_key_exists('focus', $ch)) {
            $style = (string) $n->attr('style');
            $new = self::styleProp($style, 'object-position', self::focusValue($ch['focus']));
            self::writeStyle($html, $edits, $n, $style, $new);
        }
    }

    /** "x% y%" from {x, y} in 0–100, or null (centre: no declaration needed). */
    private static function focusValue($f)
    {
        if (!is_array($f) || !isset($f['x'], $f['y']) || !is_numeric($f['x']) || !is_numeric($f['y'])) {
            return null;
        }
        $x = (int) round(max(0, min(100, (float) $f['x'])));
        $y = (int) round(max(0, min(100, (float) $f['y'])));
        return ($x === 50 && $y === 50) ? null : $x . '% ' . $y . '%';
    }

    /** Sets (or removes, with null) one declaration in a style attribute, keeping the others. */
    public static function styleProp($style, $prop, $value)
    {
        $decls = array_filter(array_map('trim', explode(';', $style)), 'strlen');
        $out = [];
        $done = false;
        foreach ($decls as $d) {
            $name = strtolower(trim(strstr($d, ':', true) ?: ''));
            if ($name === $prop) {
                if ($value !== null && !$done) {
                    $out[] = $prop . ': ' . $value;
                }
                $done = true;
                continue;
            }
            $out[] = $d;
        }
        if (!$done && $value !== null) {
            $out[] = $prop . ': ' . $value;
        }
        return implode('; ', $out);
    }

    private static function writeStyle($html, PlumaEditList $edits, PlumaNode $n, $old, $new)
    {
        if ($new === $old || trim($new) === trim(rtrim(trim($old), ';'))) {
            return;
        }
        if ($new === '') {
            $edits->removeAttr($html, $n, 'style');
        } else {
            $edits->setAttr($html, $n, 'style', $new);
        }
    }

    private static function background($html, PlumaEditList $edits, PlumaNode $n, array $ch)
    {
        $style = (string) $n->attr('style');
        $new = $style;
        if (isset($ch['src'])) {
            $src = self::checkSrc($ch['src']);
            $safe = str_replace(["'", '"', '(', ')', '\\', ' '], ['%27', '%22', '%28', '%29', '%5C', '%20'], $src);
            $re = '/(background(?:-image)?\s*:[^;]*?)url\(\s*([\'"]?)(.*?)\2\s*\)/i';
            if (preg_match($re, $new)) {
                $new = preg_replace($re, '$1url(\'' . $safe . '\')', $new, 1);
            } else {
                $new = rtrim(trim($new), ';');
                $new = ($new === '' ? '' : $new . '; ') . "background-image: url('" . $safe . "')";
            }
        }
        if (array_key_exists('focus', $ch)) {
            $new = self::styleProp($new, 'background-position', self::focusValue($ch['focus']));
        }
        if ($new !== $style) {
            self::writeStyle($html, $edits, $n, $style, $new);
        }
    }

    /**
     * Prepares a copy of the page for the editor frame: marks editable elements
     * with data-pluma-key / data-pluma-kind and adds <base> and the page hash.
     */
    public static function frame(PlumaDocument $doc, $baseHref, array $info = [])
    {
        $html = $doc->html;
        $edits = new PlumaEditList();
        foreach ($doc->detector->items as $it) {
            $attrs = ' data-pluma-key="' . $it->key . '" data-pluma-kind="' . $it->kind . '"';
            if ($it->block) {
                $attrs .= ' data-pluma-block="1"';
            }
            $edits->insert($it->node->attrsEnd, $attrs);
        }
        foreach ($doc->detector->groups as $g) {
            foreach ($g->items as $item) {
                $edits->insert($item->node->attrsEnd, ' data-pluma-item="' . $item->key . '" data-pluma-group="' . $g->id . '"');
            }
        }
        foreach ($doc->detector->shared as $name => $node) {
            $count = isset($info['shared'][$name]) ? (int) $info['shared'][$name] : 1;
            $edits->insert($node->attrsEnd, ' data-pluma-shared-pages="' . $count . '"');
        }
        $head = null;
        $hasBase = false;
        foreach ($doc->nodes as $node) {
            if ($node->tag === 'head' && $head === null) {
                $head = $node;
            }
            if ($node->tag === 'base') {
                $hasBase = true;
            }
        }
        $meta = '<meta name="pluma-hash" content="' . $doc->hash . '"><meta name="pluma-mode" content="' . $doc->detector->mode . '">';
        if ($info) {
            $meta .= '<script type="application/json" id="pluma-info">' . json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        if (!$hasBase && $baseHref !== '') {
            $meta = '<base href="' . PlumaSanitizer::escAttr($baseHref) . '">' . $meta;
        }
        if ($head !== null) {
            $edits->insert($head->startTagEnd, $meta);
        } else {
            $at = 0;
            foreach ($doc->nodes as $node) {
                if ($node->tag === 'html') {
                    $at = $node->startTagEnd;
                    break;
                }
            }
            if ($at === 0 && preg_match('/^\s*<!doctype[^>]*>/i', $html, $m)) {
                $at = strlen($m[0]);
            }
            $edits->insert($at, $meta);
        }
        return $edits->apply($html);
    }
}

// ---- Video.php ---------------------------------------------------

/**
 * PlumaEditor — YouTube and Vimeo links to embed addresses.
 *
 * Accepts what people paste (watch pages, short links, Shorts, embed links)
 * and always returns an embed address built by the server from the video id.
 */

final class PlumaVideo
{
    /** Is this iframe address a YouTube or Vimeo player? */
    public static function isEmbed($src)
    {
        return self::parse($src) !== null;
    }

    /**
     * @return array{provider:string,id:string,start:int}|null
     */
    public static function parse($input)
    {
        $s = trim((string) $input);
        if ($s === '' || strlen($s) > 500) {
            return null;
        }
        if (strpos($s, '//') === 0) {
            $s = 'https:' . $s;
        }
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }
        $u = parse_url($s);
        if (!$u || empty($u['host'])) {
            return null;
        }
        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $u['host']));
        $path = isset($u['path']) ? $u['path'] : '';
        $q = [];
        if (isset($u['query'])) {
            parse_str($u['query'], $q);
        }
        $start = 0;
        foreach (['t', 'start'] as $k) {
            if (isset($q[$k]) && is_string($q[$k])) {
                $start = self::seconds($q[$k]);
            }
        }
        if (isset($u['fragment']) && preg_match('/^t=([0-9hms]+)$/', $u['fragment'], $m)) {
            $start = self::seconds($m[1]);
        }

        $yt = ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'];
        if (in_array($host, $yt, true)) {
            $id = null;
            if ($path === '/watch' && isset($q['v']) && is_string($q['v'])) {
                $id = $q['v'];
            } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[2];
            }
            return self::yt($id, $start);
        }
        if ($host === 'youtu.be') {
            return self::yt(ltrim($path, '/'), $start);
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            // vimeo.com/123, vimeo.com/channels/x/123, player.vimeo.com/video/123, vimeo.com/123/abcdef (unlisted hash)
            if (preg_match('#/(\d{3,12})(?:/([0-9a-f]{6,20}))?/?$#', $path, $m)) {
                $hash = isset($m[2]) ? $m[2] : (isset($q['h']) && is_string($q['h']) && preg_match('/^[0-9a-f]{6,20}$/', $q['h']) ? $q['h'] : '');
                return ['provider' => 'vimeo', 'id' => $m[1], 'hash' => $hash, 'start' => $start];
            }
        }
        return null;
    }

    private static function yt($id, $start)
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }
        return ['provider' => 'youtube', 'id' => $id, 'hash' => '', 'start' => $start];
    }

    private static function seconds($v)
    {
        if (ctype_digit($v)) {
            return min((int) $v, 86400);
        }
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $v, $m)) {
            return min((int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0), 86400);
        }
        return 0;
    }

    /** Canonical, privacy-friendly embed address. */
    public static function embedUrl($input)
    {
        $v = self::parse($input);
        if ($v === null) {
            return null;
        }
        if ($v['provider'] === 'youtube') {
            return 'https://www.youtube-nocookie.com/embed/' . $v['id'] . ($v['start'] ? '?start=' . $v['start'] : '');
        }
        $params = ['dnt=1'];
        if ($v['hash'] !== '') {
            array_unshift($params, 'h=' . $v['hash']);
        }
        return 'https://player.vimeo.com/video/' . $v['id'] . '?' . implode('&', $params) . ($v['start'] ? '#t=' . $v['start'] . 's' : '');
    }
}

// ---- Meta.php ----------------------------------------------------

/**
 * PlumaEditor — page title, description and sharing tags (Open Graph, Twitter).
 */

final class PlumaMeta
{
    const MAX_TITLE = 200;
    const MAX_DESC = 400;

    /** @return array{title:string,description:string,shareTitle:string,shareDescription:string,image:string} */
    public static function read(PlumaDocument $doc)
    {
        $t = self::find($doc);
        $title = $t['title'] ? self::text($doc->html, $t['title']) : '';
        $ogTitle = $t['og:title'] ? (string) $t['og:title']->attr('content') : '';
        return [
            'title' => $title,
            'description' => $t['description'] ? trim((string) $t['description']->attr('content')) : '',
            // a share title equal to the page title is not a separate choice
            'shareTitle' => $ogTitle === $title ? '' : $ogTitle,
            'image' => $t['og:image'] ? trim((string) $t['og:image']->attr('content')) : '',
        ];
    }

    /**
     * @param array $meta   keys present are changed: title, description, shareTitle, image ('' removes)
     * @param string $siteUrl absolute address of the site folder, ending in "/"
     * @throws PlumaUserError
     */
    public static function apply(PlumaDocument $doc, PlumaEditList $edits, array $meta, $siteUrl)
    {
        $html = $doc->html;
        $t = self::find($doc);
        $head = $t['head'];
        $ins = self::insertPoint($doc, $head);
        $add = function ($tag) use ($edits, $ins) {
            $edits->insert($ins[0], $ins[1] . $tag . $ins[2]);
        };
        $current = self::read($doc);

        $title = array_key_exists('title', $meta) ? self::clean(PlumaSanitizer::plain($meta['title'], self::MAX_TITLE)) : $current['title'];
        $desc = array_key_exists('description', $meta) ? self::clean(PlumaSanitizer::plain($meta['description'], self::MAX_DESC)) : $current['description'];
        $shareTitle = array_key_exists('shareTitle', $meta) ? self::clean(PlumaSanitizer::plain($meta['shareTitle'], self::MAX_TITLE)) : $current['shareTitle'];

        if (array_key_exists('title', $meta) && $title !== $current['title']) {
            if ($t['title']) {
                $edits->replace($t['title']->contentStart, $t['title']->contentEnd, htmlspecialchars($title, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'));
            } else {
                $add('<title>' . htmlspecialchars($title, ENT_NOQUOTES | ENT_HTML5, 'UTF-8') . '</title>');
            }
        }
        if (array_key_exists('description', $meta) && $desc !== $current['description']) {
            self::setMeta($edits, $html, $t['description'], 'name', 'description', $desc, $add);
        }
        if (array_key_exists('title', $meta) || array_key_exists('shareTitle', $meta)) {
            $og = $shareTitle !== '' ? $shareTitle : $title;
            if ($og !== '' && (!$t['og:title'] || $og !== (string) $t['og:title']->attr('content'))) {
                self::setMeta($edits, $html, $t['og:title'], 'property', 'og:title', $og, $add);
            }
        }
        if (array_key_exists('description', $meta)) {
            if ($desc !== '' && (!$t['og:description'] || $desc !== (string) $t['og:description']->attr('content'))) {
                self::setMeta($edits, $html, $t['og:description'], 'property', 'og:description', $desc, $add);
            }
        }
        if (array_key_exists('image', $meta)) {
            $img = trim((string) $meta['image']);
            if ($img === '') {
                foreach (['og:image', 'twitter:image'] as $k) {
                    if ($t[$k]) {
                        self::removeTag($edits, $html, $t[$k]);
                    }
                }
            } else {
                $abs = self::absolute($img, $siteUrl);
                if ($abs === null) {
                    throw new PlumaUserError('bad-image');
                }
                if (!$t['og:image'] || $abs !== (string) $t['og:image']->attr('content')) {
                    self::setMeta($edits, $html, $t['og:image'], 'property', 'og:image', $abs, $add);
                }
                if ($t['twitter:image'] && $abs !== (string) $t['twitter:image']->attr('content')) {
                    $edits->setAttr($html, $t['twitter:image'], 'content', $abs);
                }
                if (!$t['twitter:card']) {
                    $add('<meta name="twitter:card" content="summary_large_image">');
                }
                if (!$t['og:type']) {
                    $add('<meta property="og:type" content="website">');
                }
            }
        }
    }

    private static function clean($s)
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** A site path ("pluma-media/x.jpg", "img/a.png") or an absolute http(s) address. */
    public static function absolute($img, $siteUrl)
    {
        if (preg_match('#^https?://[^\s"\'<>]+$#i', $img)) {
            return $img;
        }
        $img = ltrim($img, '/');
        if (!preg_match('#^[A-Za-z0-9._\-/]+\.(jpe?g|png|webp|gif)$#i', $img) || strpos($img, '..') !== false) {
            return null;
        }
        return $siteUrl . $img;
    }

    private static function find(PlumaDocument $doc)
    {
        $out = ['head' => null, 'title' => null, 'description' => null, 'og:title' => null, 'og:description' => null,
            'og:image' => null, 'og:type' => null, 'twitter:card' => null, 'twitter:image' => null];
        foreach ($doc->nodes as $n) {
            if ($n->tag === 'head' && !$out['head']) {
                $out['head'] = $n;
            } elseif ($n->tag === 'title' && !$out['title'] && !$n->hasAncestor('svg') && !$n->hasAncestor('body')) {
                $out['title'] = $n;
            } elseif ($n->tag === 'meta') {
                $key = strtolower(trim((string) ($n->attr('property') !== null ? $n->attr('property') : $n->attr('name'))));
                if (array_key_exists($key, $out) && $key !== 'head' && $key !== 'title' && !$out[$key]) {
                    $out[$key] = $n;
                }
            }
        }
        return $out;
    }

    private static function text($html, PlumaNode $n)
    {
        $raw = substr($html, $n->contentStart, $n->contentEnd - $n->contentStart);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function setMeta(PlumaEditList $edits, $html, $node, $attr, $name, $value, callable $add)
    {
        if ($node) {
            $edits->setAttr($html, $node, 'content', $value);
        } else {
            $add('<meta ' . $attr . '="' . $name . '" content="' . PlumaSanitizer::escAttr($value) . '">');
        }
    }

    private static function removeTag(PlumaEditList $edits, $html, PlumaNode $n)
    {
        $start = $n->start;
        $end = $n->outerEnd();
        // take the line with it when the tag is alone on its line
        $ls = strrpos(substr($html, 0, $start), "\n");
        $ls = $ls === false ? 0 : $ls;
        if (trim(substr($html, $ls, $start - $ls)) === '') {
            $start = $ls;
        }
        $edits->replace($start, $end, '');
    }

    /** [offset, prefix, suffix] for new head tags: on their own line, indented like the head's children. */
    private static function insertPoint(PlumaDocument $doc, $head)
    {
        $html = $doc->html;
        $nl = strpos($html, "\r\n") !== false ? "\r\n" : "\n";
        if (!$head) {
            foreach ($doc->nodes as $n) {
                if ($n->tag === 'html') {
                    return [$n->startTagEnd, $nl, ''];
                }
            }
            return [0, '', $nl];
        }
        $indent = '  ';
        if ($head->children) {
            $first = $head->children[0];
            $ls = strrpos(substr($html, 0, $first->start), "\n");
            if ($ls !== false && $ls >= $head->startTagEnd - 1) {
                $ws = substr($html, $ls + 1, $first->start - $ls - 1);
                if (trim($ws) === '') {
                    $indent = $ws;
                }
            }
        }
        // just before </head>, after the last child's line
        $end = $head->contentEnd;
        $tail = substr($html, $head->startTagEnd, $end - $head->startTagEnd);
        $trimmed = rtrim($tail, " \t");
        if (substr($trimmed, -1) === "\n") {
            return [$head->startTagEnd + strlen($trimmed), $indent, $nl];
        }
        return [$end, $nl . $indent, $head->endTagEnd !== null ? $nl : ''];
    }
}

// ---- Shared.php --------------------------------------------------

/**
 * PlumaEditor — shared parts (data-pluma-shared="menu"): edited on one page,
 * written to every page that has a part with the same name.
 */

final class PlumaShared
{
    /** Names of shared parts whose HTML differs between two versions of a page. */
    public static function changedNames(PlumaDocument $before, PlumaDocument $after)
    {
        $out = [];
        foreach ($after->detector->shared as $name => $node) {
            if (!isset($before->detector->shared[$name])) {
                continue;
            }
            $b = $before->detector->shared[$name];
            if (substr($before->html, $b->start, $b->outerEnd() - $b->start) !== substr($after->html, $node->start, $node->outerEnd() - $node->start)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * New contents for every other page holding one of the changed parts.
     * @return array<string,array{path:string,old:string,new:string}> page => contents
     * @throws PlumaUserError
     */
    public static function propagate(PlumaPages $pages, $sourcePage, PlumaDocument $source, array $names)
    {
        $out = [];
        if (!$names) {
            return $out;
        }
        $fromDir = self::dir($sourcePage);
        foreach ($pages->all() as $page) {
            if ($page === $sourcePage) {
                continue;
            }
            $path = $pages->resolve($page);
            $html = (string) file_get_contents($path);
            if (stripos($html, 'data-pluma-shared') === false) {
                continue;
            }
            $doc = PlumaDocument::fromString($html);
            $edits = new PlumaEditList();
            $touched = [];
            foreach ($names as $name) {
                if (!isset($doc->detector->shared[$name])) {
                    continue;
                }
                $target = $doc->detector->shared[$name];
                $src = $source->detector->shared[$name];
                $part = substr($source->html, $src->start, $src->outerEnd() - $src->start);
                $part = self::rewriteUrls($part, $fromDir, self::dir($page));
                if ($part !== substr($html, $target->start, $target->outerEnd() - $target->start)) {
                    $edits->replace($target->start, $target->outerEnd(), $part);
                    $touched[] = $name;
                }
            }
            if ($edits->isEmpty()) {
                continue;
            }
            $new = $edits->apply($html);
            self::verify($doc, PlumaDocument::fromString($new), $touched, $page);
            if (!is_writable($path)) {
                throw new PlumaUserError('shared-readonly', 500);
            }
            $out[$page] = ['path' => $path, 'old' => $html, 'new' => $new];
        }
        return $out;
    }

    /** Outside the replaced parts, the page must keep exactly the same editable elements. */
    private static function verify(PlumaDocument $before, PlumaDocument $after, array $names, $page)
    {
        $seq = function (PlumaDocument $d) use ($names) {
            $s = [];
            foreach ($d->detector->items as $it) {
                $in = false;
                foreach ($names as $name) {
                    $n = isset($d->detector->shared[$name]) ? $d->detector->shared[$name] : null;
                    if ($n && $it->node->start >= $n->start && $it->node->start < $n->outerEnd()) {
                        $in = true;
                        break;
                    }
                }
                if (!$in) {
                    $s[] = $it->kind . ':' . $it->node->tag;
                }
            }
            return $s;
        };
        if ($seq($before) !== $seq($after) || count($after->detector->shared) !== count($before->detector->shared)) {
            throw new PlumaUserError('verify-failed', 500);
        }
    }

    /** How many pages have each shared part (quick scan, no parsing). */
    public static function counts(PlumaPages $pages)
    {
        $counts = [];
        foreach ($pages->all() as $page) {
            $html = @file_get_contents($pages->resolve($page));
            if ($html === false || stripos($html, 'data-pluma-shared') === false) {
                continue;
            }
            if (preg_match_all('/data-pluma-shared\s*=\s*["\']?([A-Za-z0-9_-]{1,40})/i', $html, $m)) {
                foreach (array_unique($m[1]) as $name) {
                    $counts[$name] = (isset($counts[$name]) ? $counts[$name] : 0) + 1;
                }
            }
        }
        return $counts;
    }

    public static function dir($page)
    {
        $d = dirname($page);
        return ($d === '.' || $d === '') ? '' : $d . '/';
    }

    /**
     * Relative links in a part copied from a page in $fromDir to a page in $toDir:
     * "about.html" seen from "blog/" becomes "../about.html".
     */
    public static function rewriteUrls($fragment, $fromDir, $toDir)
    {
        if ($fromDir === $toDir) {
            return $fragment;
        }
        $tok = new PlumaTokenizer();
        $tok->run($fragment);
        $edits = new PlumaEditList();
        foreach ($tok->nodes as $node) {
            foreach (['href', 'src', 'poster'] as $attr) {
                $v = $node->attr($attr);
                if ($v === null) {
                    continue;
                }
                $nv = self::rebase($v, $fromDir, $toDir);
                if ($nv !== $v) {
                    $edits->setAttr($fragment, $node, $attr, $nv);
                }
            }
            $style = $node->attr('style');
            if ($style !== null && stripos($style, 'url(') !== false) {
                $ns = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) use ($fromDir, $toDir) {
                    return 'url(' . $m[1] . self::rebase($m[2], $fromDir, $toDir) . $m[1] . ')';
                }, $style);
                if ($ns !== $style) {
                    $edits->setAttr($fragment, $node, 'style', $ns);
                }
            }
        }
        return $edits->isEmpty() ? $fragment : $edits->apply($fragment);
    }

    public static function rebase($url, $fromDir, $toDir)
    {
        $u = trim($url);
        if ($u === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|//|/|\#|\?)#i', $u)) {
            return $url; // absolute, root-relative, anchors and queries work from anywhere
        }
        $cut = strcspn($u, '?#');
        $path = substr($u, 0, $cut);
        $rest = substr($u, $cut);
        $parts = [];
        foreach (explode('/', $fromDir . $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (!$parts) {
                    return $url; // points above the site folder: leave it alone
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $trailing = substr($path, -1) === '/' ? '/' : '';
        $target = implode('/', $parts);
        $from = array_values(array_filter(explode('/', $toDir), 'strlen'));
        $to = $target === '' ? [] : explode('/', $target);
        $i = 0;
        while ($i < count($from) && $i < count($to) - ($trailing ? 0 : 1) && $from[$i] === $to[$i]) {
            $i++;
        }
        $rel = array_merge(array_fill(0, count($from) - $i, '..'), array_slice($to, $i));
        $out = implode('/', $rel) . ($rel ? $trailing : '');
        return ($out === '' ? './' : $out) . $rest;
    }
}

// ---- Store.php ---------------------------------------------------

/**
 * PlumaEditor — private data in .pluma/.
 *
 * Every data file is a .php file that starts with "<?php exit; ?>", so a
 * direct request returns nothing, on Apache and on Nginx alike.
 */

final class PlumaStore
{
    const GUARD = "<?php exit; ?>\n";

    /** @var string site root (folder of pluma.php) */
    public $root;
    /** @var string private data folder */
    public $data;
    /** @var string public media folder */
    public $media;

    public function __construct($root)
    {
        $this->root = rtrim($root, '/\\');
        $this->data = $this->root . DIRECTORY_SEPARATOR . '.pluma';
        $this->media = $this->root . DIRECTORY_SEPARATOR . 'pluma-media';
    }

    public function isInstalled()
    {
        return is_file($this->data . DIRECTORY_SEPARATOR . 'config.php');
    }

    public function path($name)
    {
        return $this->data . DIRECTORY_SEPARATOR . $name . '.php';
    }

    /** @return array|null */
    public function read($name)
    {
        $p = $this->path($name);
        if (!is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        if ($raw === false) {
            return null;
        }
        if (strncmp($raw, self::GUARD, strlen(self::GUARD)) === 0) {
            $raw = substr($raw, strlen(self::GUARD));
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function write($name, array $data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        PlumaUtil::atomicWrite($this->path($name), self::GUARD . $json . "\n");
    }

    /**
     * Creates the config only if it does not exist yet (the first visitor wins).
     * @return bool false when another request created it first
     */
    public function createConfig(array $data)
    {
        $this->ensureDirs();
        $fh = @fopen($this->path('config'), 'x');
        if ($fh === false) {
            return false;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        fwrite($fh, self::GUARD . $json . "\n");
        fclose($fh);
        return true;
    }

    public function ensureDirs()
    {
        if (!is_dir($this->data)) {
            if (!@mkdir($this->data, 0755, true)) {
                throw new PlumaUserError('cannot-create-data', 500);
            }
        }
        $idx = $this->data . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($idx)) {
            @file_put_contents($idx, self::GUARD);
        }
        $ht = $this->data . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# PlumaEditor private data\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        foreach (['history'] as $sub) {
            $d = $this->data . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
        }
        if (!is_dir($this->media)) {
            if (!@mkdir($this->media, 0755, true)) {
                throw new PlumaUserError('cannot-create-media', 500);
            }
        }
        $ht = $this->media . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            // Uploaded files are images only; never run anything from here.
            @file_put_contents($ht, "# PlumaEditor media: images only\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n</FilesMatch>\nOptions -Indexes\n");
        }
    }

    /** Append to the private error log (kept under 200 KB). */
    public function log($message)
    {
        if (!is_dir($this->data)) {
            return;
        }
        $p = $this->data . DIRECTORY_SEPARATOR . 'errors.log.php';
        if (is_file($p) && filesize($p) > 200000) {
            @rename($p, $p . '.old.php');
        }
        if (!is_file($p)) {
            @file_put_contents($p, self::GUARD);
        }
        $line = '[' . gmdate('c') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n";
        @file_put_contents($p, $line, FILE_APPEND | LOCK_EX);
    }
}

// ---- Auth.php ----------------------------------------------------

/**
 * PlumaEditor — password, signed session cookie, CSRF and brute-force limits.
 *
 * Sessions are signed cookies (HMAC), not PHP session files: shared hosts often
 * clean session files early, and a signed cookie needs no storage. Logging out
 * bumps an "epoch" in the config, which invalidates every existing cookie.
 */

final class PlumaAuth
{
    const COOKIE = 'pluma_auth';
    const IDLE = 7200;          // 2 hours
    const REMEMBER = 2592000;   // 30 days
    const MAX_FAILS = 5;
    const WINDOW = 900;         // 15 minutes
    const MIN_PASSWORD = 10;

    /** @var PlumaStore */
    private $store;
    /** @var array|null */
    private $config;
    /** @var string|null nonce of the current valid session */
    private $nonce = null;

    public function __construct(PlumaStore $store)
    {
        $this->store = $store;
        $this->config = $store->read('config');
    }

    public static function hashPassword($password)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $h = @password_hash($password, PASSWORD_ARGON2ID);
            if (is_string($h)) {
                return $h;
            }
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** @throws PlumaUserError */
    public function setup($password, $confirm)
    {
        $password = (string) $password;
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new PlumaUserError('password-too-short');
        }
        if (!hash_equals($password, (string) $confirm)) {
            throw new PlumaUserError('password-mismatch');
        }
        $config = [
            'version' => PLUMA_VERSION,
            'hash' => self::hashPassword($password),
            'secret' => bin2hex(random_bytes(32)),
            'epoch' => 1,
            'created' => gmdate('c'),
        ];
        if (!$this->store->createConfig($config)) {
            throw new PlumaUserError('already-installed', 409);
        }
        $this->config = $config;
        $this->issue(false);
    }

    /** @throws PlumaUserError */
    public function login($password, $remember)
    {
        $ip = hash('sha256', PlumaUtil::clientIp());
        $sec = $this->store->read('security');
        if (!is_array($sec)) {
            $sec = ['fails' => []];
        }
        $now = time();
        $fails = isset($sec['fails'][$ip]) ? array_values(array_filter($sec['fails'][$ip], function ($t) use ($now) {
            return $t > $now - self::WINDOW;
        })) : [];
        if (count($fails) >= self::MAX_FAILS) {
            throw new PlumaUserError('too-many-attempts', 429);
        }
        if (!password_verify((string) $password, $this->config['hash'])) {
            $fails[] = $now;
            $sec['fails'][$ip] = $fails;
            // forget old entries from other addresses
            foreach ($sec['fails'] as $k => $list) {
                if (!$list || max($list) < $now - self::WINDOW) {
                    unset($sec['fails'][$k]);
                }
            }
            $this->store->write('security', $sec);
            // growing delay slows down guessing
            usleep(min(4, count($fails)) * 400000);
            throw new PlumaUserError('wrong-password', 401);
        }
        unset($sec['fails'][$ip]);
        $this->store->write('security', $sec);
        if (password_needs_rehash($this->config['hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->config['hash'] = self::hashPassword((string) $password);
            $this->store->write('config', $this->config);
        }
        $this->issue((bool) $remember);
    }

    public function logout()
    {
        $this->config['epoch'] = (int) $this->config['epoch'] + 1;
        $this->store->write('config', $this->config);
        $this->setCookie('', time() - 3600);
    }

    /** Validates the cookie; slides the expiry forward. */
    public function check()
    {
        if (!$this->config || empty($_COOKIE[self::COOKIE])) {
            return false;
        }
        $parts = explode('.', (string) $_COOKIE[self::COOKIE]);
        if (count($parts) !== 4) {
            return false;
        }
        list($exp, $remember, $nonce, $sig) = $parts;
        if (!ctype_digit($exp) || ($remember !== '0' && $remember !== '1') || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }
        if (!hash_equals($this->sign($exp . '|' . $remember . '|' . $nonce), $sig)) {
            return false;
        }
        if ((int) $exp < time()) {
            return false;
        }
        $this->nonce = $nonce;
        $life = $remember === '1' ? self::REMEMBER : self::IDLE;
        // Refresh at most every 5 minutes
        if ((int) $exp - time() < $life - 300) {
            $this->issue($remember === '1', $nonce);
        }
        return true;
    }

    public function csrfToken()
    {
        return $this->nonce === null ? '' : $this->sign('csrf|' . $this->nonce);
    }

    public function checkCsrf($token)
    {
        return $this->nonce !== null && is_string($token) && hash_equals($this->csrfToken(), $token);
    }

    private function issue($remember, $nonce = null)
    {
        $nonce = $nonce !== null ? $nonce : bin2hex(random_bytes(16));
        $exp = time() + ($remember ? self::REMEMBER : self::IDLE);
        $payload = $exp . '|' . ($remember ? '1' : '0') . '|' . $nonce;
        $value = $exp . '.' . ($remember ? '1' : '0') . '.' . $nonce . '.' . $this->sign($payload);
        $this->setCookie($value, $remember ? $exp : 0);
        $this->nonce = $nonce;
    }

    private function sign($payload)
    {
        // Key changes with the password hash and the epoch: new password or logout => old cookies die.
        $key = $this->config['secret'] . '|' . $this->config['hash'] . '|' . $this->config['epoch'];
        return hash_hmac('sha256', $payload, $key);
    }

    private function setCookie($value, $expires)
    {
        $path = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/';
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }
        $opts = [
            'expires' => $expires,
            'path' => $path,
            'secure' => PlumaUtil::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE, $value, $opts);
        } else {
            setcookie(self::COOKIE, $value, $expires, $path . '; samesite=Strict', '', $opts['secure'], true);
        }
        $_COOKIE[self::COOKIE] = $value;
    }
}

// ---- History.php -------------------------------------------------

/**
 * PlumaEditor — compressed copies of every page before each save.
 *
 * .pluma/history/<page>/2026-10-19T17-34-05-x9f2a1.html.gz
 * The very first copy is named original-<random>.html.gz and is never pruned.
 */

final class PlumaHistory
{
    const KEEP_LAST = 50;
    const KEEP_DAYS = 30;

    /** @var PlumaStore */
    private $store;

    public function __construct(PlumaStore $store)
    {
        $this->store = $store;
    }

    private function dir($page)
    {
        return $this->store->data . DIRECTORY_SEPARATOR . 'history' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page);
    }

    public function save($page, $content)
    {
        $dir = $this->dir($page);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new PlumaUserError('history-failed', 500);
        }
        $hasOriginal = (bool) glob($dir . DIRECTORY_SEPARATOR . 'original-*.html.gz');
        $name = $hasOriginal
            ? gmdate('Y-m-d\TH-i-s') . '-' . PlumaUtil::randomId(3) . '.html.gz'
            : 'original-' . PlumaUtil::randomId(3) . '.html.gz';
        $gz = gzencode($content, 9);
        if ($gz === false || @file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $gz, LOCK_EX) === false) {
            throw new PlumaUserError('history-failed', 500);
        }
        return $this->idFromName($name);
    }

    /** @return array<int,array{id:string,time:int,original:bool,size:int}> newest first */
    public function versions($page)
    {
        $dir = $this->dir($page);
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.html.gz') ?: [] as $f) {
            $name = basename($f);
            $id = $this->idFromName($name);
            if (!self::validId($id)) {
                continue;
            }
            $original = strpos($id, 'original-') === 0;
            $time = $original ? filemtime($f) : self::timeFromId($id);
            $out[] = ['id' => $id, 'time' => $time, 'original' => $original, 'size' => filesize($f)];
        }
        usort($out, function ($a, $b) {
            if ($a['original'] !== $b['original']) {
                return $a['original'] ? 1 : -1; // original always last
            }
            return $b['time'] <=> $a['time'] ?: strcmp($b['id'], $a['id']);
        });
        return $out;
    }

    /** @return string|null */
    public function get($page, $id)
    {
        if (!self::validId($id)) {
            return null;
        }
        $f = $this->dir($page) . DIRECTORY_SEPARATOR . $id . '.html.gz';
        if (!is_file($f)) {
            return null;
        }
        $data = @gzdecode(file_get_contents($f));
        return $data === false ? null : $data;
    }

    public function prune($page)
    {
        $versions = array_values(array_filter($this->versions($page), function ($v) {
            return !$v['original'];
        }));
        $keepDays = [];
        $limit = time() - self::KEEP_DAYS * 86400;
        foreach ($versions as $i => $v) {
            if ($i < self::KEEP_LAST) {
                continue;
            }
            $day = gmdate('Y-m-d', $v['time']);
            if ($v['time'] >= $limit && !isset($keepDays[$day])) {
                $keepDays[$day] = true; // newest copy of that day
                continue;
            }
            @unlink($this->dir($page) . DIRECTORY_SEPARATOR . $v['id'] . '.html.gz');
        }
    }

    public static function validId($id)
    {
        return is_string($id) && (bool) preg_match('/^(original-[a-f0-9]{6}|\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-[a-f0-9]{6})$/', $id);
    }

    private function idFromName($name)
    {
        return substr($name, 0, -strlen('.html.gz'));
    }

    private static function timeFromId($id)
    {
        $d = DateTime::createFromFormat('Y-m-d\TH-i-s', substr($id, 0, 19), new DateTimeZone('UTC'));
        return $d ? $d->getTimestamp() : 0;
    }
}

// ---- Images.php --------------------------------------------------

/**
 * PlumaEditor — image uploads.
 *
 * Every upload is checked by content, decoded and re-encoded by GD (which
 * strips hidden payloads and EXIF data, GPS included), resized and saved as
 * WebP when the server supports it.
 */

final class PlumaImages
{
    const MAX_BYTES = 10485760; // 10 MB
    const MAX_WIDTH = 1920;
    const MAX_PIXELS = 40000000; // refuse absurd dimensions (decompression bombs)
    const QUALITY = 82;

    /** @var PlumaStore */
    private $store;

    public function __construct(PlumaStore $store)
    {
        $this->store = $store;
    }

    /** Effective upload limit in bytes (the smallest of ours and the server's). */
    public static function uploadLimit()
    {
        $limits = [self::MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = self::iniBytes(ini_get($k));
            if ($v > 0) {
                $limits[] = $v;
            }
        }
        return (int) min($limits);
    }

    private static function iniBytes($v)
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }
        return (int) $n;
    }

    public static function webpSupported()
    {
        if (!function_exists('imagewebp')) {
            return false;
        }
        $info = gd_info();
        return !empty($info['WebP Support']);
    }

    /**
     * @param array $file entry from $_FILES
     * @param int $renderedWidth width the image is shown at on the page (CSS px)
     * @return array{url:string,width:int,height:int}
     * @throws PlumaUserError
     */
    public function upload(array $file, $renderedWidth, $format = 'auto')
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new PlumaUserError('upload-failed');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new PlumaUserError('image-too-large', 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new PlumaUserError('upload-failed');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new PlumaUserError('image-too-large', 413);
        }
        return $this->process($file['tmp_name'], isset($file['name']) ? (string) $file['name'] : 'image', $renderedWidth, $format);
    }

    /** Separate from upload() so it can be tested without HTTP. */
    /** @param string $format 'auto' (WebP when possible) or 'jpeg' (sharing images: every social network reads JPEG) */
    public function process($path, $originalName, $renderedWidth, $format = 'auto')
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new PlumaUserError('gd-missing', 500);
        }
        $info = @getimagesize($path);
        if ($info === false) {
            throw new PlumaUserError('not-an-image');
        }
        $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        if (!isset($types[$info[2]])) {
            throw new PlumaUserError('image-type-not-allowed');
        }
        list($w, $h) = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw new PlumaUserError('image-too-large', 413);
        }
        @ini_set('memory_limit', '256M');
        $img = @imagecreatefromstring(file_get_contents($path));
        if (!$img) {
            throw new PlumaUserError('not-an-image');
        }

        // Phone photos: apply EXIF rotation before the metadata is dropped
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
            $angles = [3 => 180, 6 => -90, 8 => 90];
            if (isset($angles[$o])) {
                $rot = imagerotate($img, $angles[$o], 0);
                if ($rot) {
                    imagedestroy($img);
                    $img = $rot;
                }
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);

        $alpha = $this->hasAlpha($img, $info[2]);
        $target = self::MAX_WIDTH;
        $rw = (int) $renderedWidth;
        if ($rw > 0) {
            $target = min(self::MAX_WIDTH, max(320, $rw * 2));
        }
        if ($w > $target) {
            $nh = max(1, (int) round($h * $target / $w));
            $dst = imagecreatetruecolor($target, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $target, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
            $w = $target;
            $h = $nh;
        } elseif (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $this->store->ensureDirs();
        $base = PlumaUtil::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '-' . PlumaUtil::randomId(3);
        if ($format === 'jpeg') {
            $ext = 'jpg';
            if ($alpha) { // flatten transparency on white
                $flat = imagecreatetruecolor($w, $h);
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagealphablending($flat, true);
                imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                imagedestroy($img);
                $img = $flat;
            }
        } elseif (self::webpSupported()) {
            $ext = 'webp';
        } elseif ($alpha) {
            $ext = 'png';
        } else {
            $ext = 'jpg';
        }
        $file = $base . '.' . $ext;
        $dest = $this->store->media . DIRECTORY_SEPARATOR . $file;
        $tmp = $dest . '.tmp';
        $ok = false;
        if ($ext === 'webp') {
            $ok = imagewebp($img, $tmp, self::QUALITY);
        } elseif ($ext === 'png') {
            $ok = imagepng($img, $tmp, 8);
        } else {
            imageinterlace($img, true);
            $ok = imagejpeg($img, $tmp, self::QUALITY);
        }
        imagedestroy($img);
        if (!$ok || !@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new PlumaUserError('write-failed', 500);
        }
        @chmod($dest, 0644);
        return ['url' => 'pluma-media/' . $file, 'width' => $w, 'height' => $h];
    }

    private function hasAlpha($img, $type)
    {
        if ($type !== IMAGETYPE_PNG && $type !== IMAGETYPE_GIF && (!defined('IMAGETYPE_WEBP') || $type !== IMAGETYPE_WEBP)) {
            return false;
        }
        if (!imageistruecolor($img)) {
            return imagecolortransparent($img) >= 0;
        }
        // sample a grid of pixels
        $w = imagesx($img);
        $h = imagesy($img);
        $sx = max(1, (int) ($w / 40));
        $sy = max(1, (int) ($h / 40));
        for ($y = 0; $y < $h; $y += $sy) {
            for ($x = 0; $x < $w; $x += $sx) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<int,array{url:string,name:string,width:int,height:int,time:int}> newest first */
    public function library()
    {
        $out = [];
        if (!is_dir($this->store->media)) {
            return $out;
        }
        foreach (scandir($this->store->media) ?: [] as $name) {
            if (!preg_match('/^[a-z0-9\-]+\.(webp|jpe?g|png|gif)$/i', $name)) {
                continue;
            }
            $f = $this->store->media . DIRECTORY_SEPARATOR . $name;
            $size = @getimagesize($f);
            $out[] = [
                'url' => 'pluma-media/' . basename($f),
                'name' => basename($f),
                'width' => $size ? $size[0] : 0,
                'height' => $size ? $size[1] : 0,
                'time' => filemtime($f),
            ];
        }
        usort($out, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        return array_slice($out, 0, 300);
    }
}

// ---- Pages.php ---------------------------------------------------

/**
 * PlumaEditor — finding pages and validating page paths.
 */

final class PlumaPages
{
    const SKIP_DIRS = ['pluma-media' => 1, 'node_modules' => 1, 'vendor' => 1, 'cgi-bin' => 1];
    const MAX_DEPTH = 4;
    const MAX_PAGES = 1000;

    /** @var string */
    private $root;

    public function __construct($root)
    {
        $this->root = rtrim($root, '/\\');
    }

    /** @return string[] relative paths with forward slashes, index.html first */
    public function all()
    {
        $out = [];
        $this->scan($this->root, '', 0, $out);
        usort($out, function ($a, $b) {
            $da = substr_count($a, '/');
            $db = substr_count($b, '/');
            $ia = (int) (basename($a) === 'index.html' || basename($a) === 'index.htm');
            $ib = (int) (basename($b) === 'index.html' || basename($b) === 'index.htm');
            if ($da !== $db) {
                return $da - $db;
            }
            if ($ia !== $ib) {
                return $ib - $ia;
            }
            return strnatcasecmp($a, $b);
        });
        return $out;
    }

    private function scan($dir, $rel, $depth, array &$out)
    {
        if ($depth > self::MAX_DEPTH || count($out) >= self::MAX_PAGES) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '' || $e[0] === '.' || count($out) >= self::MAX_PAGES) {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $e;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (!isset(self::SKIP_DIRS[strtolower($e)])) {
                    $this->scan($full, $rel . $e . '/', $depth + 1, $out);
                }
                continue;
            }
            if (preg_match('/\.html?$/i', $e) && is_file($full)) {
                $out[] = $rel . $e;
            }
        }
    }

    /**
     * Returns the absolute path of a page, or throws.
     * Only .html/.htm files inside the site folder; no hidden segments, no links out.
     * @throws PlumaUserError
     */
    public function resolve($page)
    {
        $page = (string) $page;
        if ($page === '' || strlen($page) > 512 || strpos($page, "\0") !== false || strpos($page, '\\') !== false) {
            throw new PlumaUserError('page-not-found', 404);
        }
        if (!preg_match('/\.html?$/i', $page)) {
            throw new PlumaUserError('page-not-found', 404);
        }
        $segments = explode('/', $page);
        foreach ($segments as $s) {
            if ($s === '' || $s[0] === '.' ) {
                throw new PlumaUserError('page-not-found', 404);
            }
        }
        if (isset(self::SKIP_DIRS[strtolower($segments[0])]) && count($segments) > 1) {
            throw new PlumaUserError('page-not-found', 404);
        }
        if (count($segments) > self::MAX_DEPTH + 1) {
            throw new PlumaUserError('page-not-found', 404);
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page);
        $real = realpath($path);
        $rootReal = realpath($this->root);
        if ($real === false || $rootReal === false || !is_file($real)) {
            throw new PlumaUserError('page-not-found', 404);
        }
        if (strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
            throw new PlumaUserError('page-not-found', 404);
        }
        return $real;
    }

    /** "blog/post.html" => "blog/", "index.html" => "" */
    public static function baseHref($page)
    {
        $dir = dirname($page);
        return ($dir === '.' || $dir === '') ? '' : $dir . '/';
    }
}

// ---- Views.php ---------------------------------------------------

/**
 * PlumaEditor — server-rendered pages: setup, sign-in, environment check, app shell.
 */

final class PlumaViews
{
    /** @var array */
    private static $strings = null;

    public static function strings()
    {
        if (self::$strings === null) {
            $json = PlumaAssets::get('en.json');
            self::$strings = $json ? (array) json_decode($json, true) : [];
        }
        return self::$strings;
    }

    public static function t($key, array $vars = [])
    {
        $s = self::strings();
        $text = isset($s[$key]) ? $s[$key] : $key;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    private static function script()
    {
        return PlumaUtil::h(basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'pluma.php'));
    }

    private static function head($title)
    {
        $s = self::script();
        $v = PLUMA_VERSION;
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . PlumaUtil::h($title) . '</title>'
            . '<link rel="icon" href="' . $s . '?a=asset&amp;f=icon.svg&amp;v=' . $v . '" type="image/svg+xml">'
            . '<link rel="stylesheet" href="' . $s . '?a=asset&amp;f=editor.css&amp;v=' . $v . '">'
            . '</head>';
    }

    private static function brand()
    {
        return '<div class="pl-brand"><span class="pl-logo" aria-hidden="true">' . PlumaAssets::get('icon.svg') . '</span><span>Pluma<b>Editor</b></span></div>';
    }

    private static function card($title, $body)
    {
        return self::head($title . ' — PlumaEditor')
            . '<body class="pl-auth"><main class="pl-card">' . self::brand() . $body . '</main>'
            . '<p class="pl-foot">PlumaEditor ' . PLUMA_VERSION . ' · <a href="https://plumaeditor.com" target="_blank" rel="noopener">plumaeditor.com</a></p>'
            . '</body></html>';
    }

    public static function error($code)
    {
        if (!$code) {
            return '';
        }
        return '<p class="pl-error" role="alert">' . PlumaUtil::h(self::t('err.' . $code)) . '</p>';
    }

    public static function setup($error = '')
    {
        $s = self::script();
        $warn = (PlumaUtil::isHttps() || PlumaUtil::isLocalHost()) ? '' : '<p class="pl-warn">' . PlumaUtil::h(self::t('setup.http')) . '</p>';
        $body = '<h1>' . PlumaUtil::h(self::t('setup.title')) . '</h1>'
            . '<p class="pl-muted">' . PlumaUtil::h(self::t('setup.intro')) . '</p>'
            . $warn . self::error($error)
            . '<form method="post" action="' . $s . '?a=setup" class="pl-form">'
            . '<label>' . PlumaUtil::h(self::t('setup.password')) . '<input type="password" name="password" autocomplete="new-password" minlength="10" required autofocus></label>'
            . '<label>' . PlumaUtil::h(self::t('setup.confirm')) . '<input type="password" name="confirm" autocomplete="new-password" minlength="10" required></label>'
            . '<p class="pl-hint">' . PlumaUtil::h(self::t('setup.hint')) . '</p>'
            . '<button type="submit" class="pl-btn pl-btn-primary pl-wide">' . PlumaUtil::h(self::t('setup.submit')) . '</button>'
            . '</form>';
        return self::card(self::t('setup.title'), $body);
    }

    public static function login($error = '')
    {
        $s = self::script();
        $warn = (PlumaUtil::isHttps() || PlumaUtil::isLocalHost()) ? '' : '<p class="pl-warn">' . PlumaUtil::h(self::t('setup.http')) . '</p>';
        $body = '<h1>' . PlumaUtil::h(self::t('login.title')) . '</h1>'
            . $warn . self::error($error)
            . '<form method="post" action="' . $s . '?a=login" class="pl-form">'
            . '<label>' . PlumaUtil::h(self::t('login.password')) . '<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
            . '<label class="pl-check"><input type="checkbox" name="remember" value="1"> ' . PlumaUtil::h(self::t('login.remember')) . '</label>'
            . '<button type="submit" class="pl-btn pl-btn-primary pl-wide">' . PlumaUtil::h(self::t('login.submit')) . '</button>'
            . '</form>'
            . '<details class="pl-details"><summary>' . PlumaUtil::h(self::t('login.forgot')) . '</summary><p>' . PlumaUtil::h(self::t('login.reset')) . '</p></details>';
        return self::card(self::t('login.title'), $body);
    }

    public static function environment(array $problems)
    {
        $items = '';
        foreach ($problems as $p) {
            $items .= '<li>' . PlumaUtil::h($p) . '</li>';
        }
        $body = '<h1>' . PlumaUtil::h(self::t('env.title')) . '</h1>'
            . '<p class="pl-muted">' . PlumaUtil::h(self::t('env.intro')) . '</p>'
            . '<ul class="pl-problems">' . $items . '</ul>';
        return self::card(self::t('env.title'), $body);
    }

    public static function app(array $config)
    {
        $s = self::script();
        $v = PLUMA_VERSION;
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return self::head('PlumaEditor')
            . '<body class="pl-app">'
            . '<script type="application/json" id="pl-config">' . $json . '</script>'
            . '<div id="pl-root"></div>'
            . '<script src="' . $s . '?a=asset&amp;f=editor.js&amp;v=' . $v . '"></script>'
            . '</body></html>';
    }
}

// ---- App.php -----------------------------------------------------

/**
 * PlumaEditor — request router.
 */

final class PlumaApp
{
    /** @var PlumaStore */
    private $store;
    /** @var PlumaPages */
    private $pages;
    /** @var PlumaAuth|null */
    private $auth = null;

    public static function run($root)
    {
        $app = new self();
        $app->store = new PlumaStore($root);
        $app->pages = new PlumaPages($root);
        try {
            $app->dispatch();
        } catch (PlumaUserError $e) {
            $app->fail($e->getMessage(), $e->status);
        } catch (Throwable $e) {
            $app->store->log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $app->fail('generic', 500);
        }
    }

    private function isApi()
    {
        $a = isset($_GET['a']) ? $_GET['a'] : '';
        return !in_array($a, ['', 'setup', 'login', 'frame', 'version'], true);
    }

    private function fail($code, $status)
    {
        if (headers_sent()) {
            return;
        }
        if ($this->isApi()) {
            PlumaUtil::json(['error' => $code], $status);
            return;
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>PlumaEditor</title><p style="font-family:system-ui;padding:2em">'
            . PlumaUtil::h(PlumaViews::t('err.' . $code)) . '</p>';
    }

    private function securityHeaders()
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    }

    /** Refuses cross-site POSTs (Origin, or Referer when Origin is missing). */
    private function checkOrigin()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        $src = '';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $src = $_SERVER['HTTP_ORIGIN'];
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $src = $_SERVER['HTTP_REFERER'];
        }
        if ($src === '') {
            return; // non-browser client; API calls still need the CSRF token
        }
        $p = parse_url($src);
        $h = isset($p['host']) ? strtolower($p['host']) : '';
        if (isset($p['port'])) {
            $h .= ':' . $p['port'];
        }
        if ($h !== $host && $h . ':80' !== $host && $h . ':443' !== $host) {
            throw new PlumaUserError('bad-origin', 403);
        }
    }

    private function requirePost()
    {
        if ($this->method() !== 'POST') {
            throw new PlumaUserError('not-found', 405);
        }
        $this->checkOrigin();
    }

    private function requireToken()
    {
        $t = isset($_SERVER['HTTP_X_PLUMA_TOKEN']) ? $_SERVER['HTTP_X_PLUMA_TOKEN'] : '';
        if (!$this->auth->checkCsrf($t)) {
            throw new PlumaUserError('bad-token', 403);
        }
    }

    private function jsonBody()
    {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 4000000) {
            throw new PlumaUserError('bad-change', 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new PlumaUserError('bad-change');
        }
        return $data;
    }

    private function redirect($query = '')
    {
        $s = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'pluma.php');
        header('Location: ' . $s . $query, true, 303);
    }

    /** @return string[] problems in plain language */
    private function environmentProblems()
    {
        $p = [];
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $p[] = PlumaViews::t('env.php', ['v' => PHP_VERSION]);
        }
        if (!function_exists('imagecreatefromstring')) {
            $p[] = PlumaViews::t('env.gd');
        }
        if (!function_exists('mb_substr')) {
            $p[] = PlumaViews::t('env.mbstring');
        }
        if (!is_dir($this->store->data) && !is_writable($this->store->root)) {
            $p[] = PlumaViews::t('env.writable');
        }
        $all = $this->pages->all();
        if (!$all) {
            $p[] = PlumaViews::t('env.pages');
        }
        return $p;
    }

    private function readonlyPages(array $all)
    {
        $ro = [];
        foreach ($all as $page) {
            $f = $this->store->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page);
            if (!is_writable($f)) {
                $ro[] = $page;
            }
        }
        return $ro;
    }

    private function html($body)
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
    }

    private function dispatch()
    {
        $a = isset($_GET['a']) ? (string) $_GET['a'] : '';
        $this->securityHeaders();

        if ($a === 'asset') {
            $this->asset(isset($_GET['f']) ? (string) $_GET['f'] : '');
            return;
        }

        if (!$this->store->isInstalled()) {
            $problems = $this->environmentProblems();
            if ($problems) {
                $this->html(PlumaViews::environment($problems));
                return;
            }
            if ($a === 'setup' && $this->method() === 'POST') {
                $this->checkOrigin();
                $this->auth = new PlumaAuth($this->store);
                try {
                    $this->auth->setup(isset($_POST['password']) ? $_POST['password'] : '', isset($_POST['confirm']) ? $_POST['confirm'] : '');
                } catch (PlumaUserError $e) {
                    http_response_code($e->status);
                    $this->html(PlumaViews::setup($e->getMessage()));
                    return;
                }
                $this->redirect();
                return;
            }
            if ($this->isApi()) {
                throw new PlumaUserError('bad-token', 401);
            }
            $this->html(PlumaViews::setup());
            return;
        }

        $this->auth = new PlumaAuth($this->store);

        if ($a === 'setup') {
            $this->redirect();
            return;
        }
        if ($a === 'login' && $this->method() === 'POST') {
            $this->checkOrigin();
            try {
                $this->auth->login(isset($_POST['password']) ? $_POST['password'] : '', !empty($_POST['remember']));
            } catch (PlumaUserError $e) {
                http_response_code($e->status);
                $this->html(PlumaViews::login($e->getMessage()));
                return;
            }
            $this->redirect();
            return;
        }

        if (!$this->auth->check()) {
            if ($this->isApi()) {
                throw new PlumaUserError('bad-token', 401);
            }
            if ($a === 'frame' || $a === 'version') {
                throw new PlumaUserError('bad-token', 401);
            }
            $this->html(PlumaViews::login());
            return;
        }

        switch ($a) {
            case '':
            case 'login':
                $this->shell();
                return;
            case 'frame':
                $this->frame();
                return;
            case 'pages':
                PlumaUtil::json(['pages' => $this->pages->all()]);
                return;
            case 'save':
                $this->requirePost();
                $this->requireToken();
                $this->save();
                return;
            case 'upload':
                $this->requirePost();
                $this->requireToken();
                $this->upload();
                return;
            case 'media':
                PlumaUtil::json(['images' => (new PlumaImages($this->store))->library()]);
                return;
            case 'history':
                $page = isset($_GET['p']) ? (string) $_GET['p'] : '';
                $this->pages->resolve($page);
                PlumaUtil::json(['versions' => (new PlumaHistory($this->store))->versions($page)]);
                return;
            case 'version':
                $this->version();
                return;
            case 'restore':
                $this->requirePost();
                $this->requireToken();
                $this->restore();
                return;
            case 'newpage':
                $this->requirePost();
                $this->requireToken();
                $this->newPage();
                return;
            case 'logout':
                $this->requirePost();
                $this->requireToken();
                $this->auth->logout();
                PlumaUtil::json(['ok' => true]);
                return;
        }
        throw new PlumaUserError('not-found', 404);
    }

    private function asset($name)
    {
        $types = [
            'editor.js' => 'application/javascript; charset=utf-8',
            'editor.css' => 'text/css; charset=utf-8',
            'icon.svg' => 'image/svg+xml',
        ];
        if (!isset($types[$name])) {
            throw new PlumaUserError('not-found', 404);
        }
        $body = PlumaAssets::get($name);
        $etag = '"' . substr(sha1(PLUMA_VERSION . $body), 0, 16) . '"';
        header('Content-Type: ' . $types[$name]);
        header('ETag: ' . $etag);
        header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            return;
        }
        echo $body;
    }

    private function shell()
    {
        $all = $this->pages->all();
        $config = [
            'version' => PLUMA_VERSION,
            'script' => basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'pluma.php'),
            'token' => $this->auth->csrfToken(),
            'pages' => $all,
            'readonly' => $this->readonlyPages($all),
            'maxUpload' => PlumaImages::uploadLimit(),
            'https' => PlumaUtil::isHttps() || PlumaUtil::isLocalHost(),
            'strings' => PlumaViews::strings(),
            'support' => 'https://plumaeditor.com/support',
            'help' => 'https://plumaeditor.com/docs',
        ];
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; frame-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'");
        $this->html(PlumaViews::app($config));
    }

    private function frame()
    {
        $page = isset($_GET['p']) ? (string) $_GET['p'] : '';
        $path = $this->pages->resolve($page);
        $doc = PlumaDocument::fromString((string) file_get_contents($path));
        $info = [
            'meta' => PlumaMeta::read($doc),
            'siteUrl' => $this->siteUrl(),
            'shared' => $doc->detector->shared ? array_intersect_key(PlumaShared::counts($this->pages), $doc->detector->shared) : [],
        ];
        $this->html(PlumaPatcher::frame($doc, PlumaPages::baseHref($page), $info));
    }

    /** Absolute address of the site folder (where pluma.php lives), ending in "/". */
    private function siteUrl()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']) : 'localhost';
        $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/pluma.php'));
        $dir = rtrim($dir, '/') . '/';
        return (PlumaUtil::isHttps() ? 'https' : 'http') . '://' . $host . $dir;
    }

    private function version()
    {
        $page = isset($_GET['p']) ? (string) $_GET['p'] : '';
        $this->pages->resolve($page);
        $html = (new PlumaHistory($this->store))->get($page, isset($_GET['v']) ? (string) $_GET['v'] : '');
        if ($html === null) {
            throw new PlumaUserError('version-not-found', 404);
        }
        $doc = PlumaDocument::fromString($html);
        // read-only preview: same base handling as the editor frame
        $this->html(PlumaPatcher::frame($doc, PlumaPages::baseHref($page)));
    }

    /** One lock for the whole site: a save can write several pages (shared parts). */
    private function lock($page = '')
    {
        $this->store->ensureDirs();
        $fh = @fopen($this->store->data . DIRECTORY_SEPARATOR . 'lock.php', 'c');
        if ($fh) {
            flock($fh, LOCK_EX);
        }
        return $fh;
    }

    private function unlock($fh)
    {
        if ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function save()
    {
        $in = $this->jsonBody();
        $page = isset($in['page']) ? (string) $in['page'] : '';
        $hash = isset($in['hash']) ? (string) $in['hash'] : '';
        $changes = isset($in['changes']) && is_array($in['changes']) ? $in['changes'] : null;
        $groups = isset($in['groups']) && is_array($in['groups']) ? $in['groups'] : [];
        $meta = isset($in['meta']) && is_array($in['meta']) ? $in['meta'] : null;
        if ($changes === null || count($changes) > 2000 || count($groups) > 200) {
            throw new PlumaUserError('bad-change');
        }
        $path = $this->pages->resolve($page);
        $fh = $this->lock();
        try {
            $current = (string) file_get_contents($path);
            if (!hash_equals(hash('sha256', $current), $hash)) {
                throw new PlumaUserError('conflict', 409);
            }
            $doc = PlumaDocument::fromString($current);
            $expected = [];
            $new = PlumaPatcher::apply($doc, $changes, $groups, $expected);
            if ($meta) {
                $mid = PlumaDocument::fromString($new);
                $edits = new PlumaEditList();
                PlumaMeta::apply($mid, $edits, $meta, $this->siteUrl());
                if (!$edits->isEmpty()) {
                    $new = $edits->apply($new);
                }
            }
            if ($new === $current) {
                PlumaUtil::json(['ok' => true, 'hash' => $doc->hash, 'changed' => false]);
                return;
            }
            $this->verify($doc, $new, $expected, $page);

            // Shared parts changed here are written to every page that has them
            $after = PlumaDocument::fromString($new);
            $others = PlumaShared::propagate($this->pages, $page, $after, PlumaShared::changedNames($doc, $after));

            $history = new PlumaHistory($this->store);
            $history->save($page, $current);
            PlumaUtil::atomicWrite($path, $new);
            $history->prune($page);
            foreach ($others as $other => $o) {
                $history->save($other, $o['old']);
                PlumaUtil::atomicWrite($o['path'], $o['new']);
                $history->prune($other);
            }
            PlumaUtil::json([
                'ok' => true,
                'hash' => hash('sha256', $new),
                'changed' => true,
                'structure' => (bool) $groups,
                'sharedPages' => array_keys($others),
            ]);
        } finally {
            $this->unlock($fh);
        }
    }

    /** A new page: a copy of an existing one, in the same folder (relative links keep working). */
    private function newPage()
    {
        $in = $this->jsonBody();
        $from = isset($in['from']) ? (string) $in['from'] : '';
        $name = isset($in['name']) ? trim((string) $in['name']) : '';
        $src = $this->pages->resolve($from);
        if (!preg_match('/\.html?$/i', $name)) {
            $name .= '.html';
        }
        // letters, digits, - and _ only: no dots in the name (on some servers "x.php.html" would run as PHP)
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,80}\.html?$/', $name)) {
            throw new PlumaUserError('bad-page-name');
        }
        $dir = PlumaShared::dir($from);
        $rel = $dir . $name;
        $dest = dirname($src) . DIRECTORY_SEPARATOR . $name;
        $fh = $this->lock();
        try {
            if (file_exists($dest)) {
                throw new PlumaUserError('page-exists', 409);
            }
            $out = @fopen($dest, 'x');
            if ($out === false) {
                throw new PlumaUserError('write-failed', 500);
            }
            fwrite($out, (string) file_get_contents($src));
            fclose($out);
            @chmod($dest, fileperms($src) & 0777);
        } finally {
            $this->unlock($fh);
        }
        $this->pages->resolve($rel); // confirms the new page is reachable
        PlumaUtil::json(['ok' => true, 'page' => $rel, 'pages' => $this->pages->all()]);
    }

    /**
     * The saved page must contain exactly the editable elements the change implies, in order:
     * the same as before, with rebuilt groups expanded and emptied texts removed.
     * Anything else means the edit broke the page structure, and nothing is written.
     */
    private function verify(PlumaDocument $before, $html, array $expected, $page)
    {
        $after = PlumaDocument::fromString($html);
        $b = [];
        foreach ($after->detector->items as $it) {
            $b[] = $it->kind . ':' . $it->node->tag;
        }
        if ($expected !== $b || $before->detector->mode !== $after->detector->mode) {
            $this->store->log('verify-failed on ' . $page . ': expected ' . count($expected) . ' editables, got ' . count($b));
            throw new PlumaUserError('verify-failed', 500);
        }
    }

    private function upload()
    {
        if (empty($_FILES['file'])) {
            // post_max_size exceeded empties $_FILES and $_POST
            throw new PlumaUserError('image-too-large', 413);
        }
        $width = isset($_POST['width']) ? (int) $_POST['width'] : 0;
        $format = isset($_POST['format']) && $_POST['format'] === 'jpeg' ? 'jpeg' : 'auto';
        $result = (new PlumaImages($this->store))->upload($_FILES['file'], $width, $format);
        PlumaUtil::json($result);
    }

    private function restore()
    {
        $in = $this->jsonBody();
        $page = isset($in['page']) ? (string) $in['page'] : '';
        $id = isset($in['version']) ? (string) $in['version'] : '';
        $path = $this->pages->resolve($page);
        $history = new PlumaHistory($this->store);
        $fh = $this->lock($page);
        try {
            $old = $history->get($page, $id);
            if ($old === null) {
                throw new PlumaUserError('version-not-found', 404);
            }
            $current = (string) file_get_contents($path);
            if ($current !== $old) {
                $history->save($page, $current);
                PlumaUtil::atomicWrite($path, $old);
                $history->prune($page);
            }
            PlumaUtil::json(['ok' => true, 'hash' => hash('sha256', $old)]);
        } finally {
            $this->unlock($fh);
        }
    }
}

// ---- assets -----------------------------------------------------

final class PlumaAssets
{
    public static function get($name)
    {
        switch ($name) {
            case 'editor.js':
                return base64_decode('LyogUGx1bWFFZGl0b3Ig4oCUIGVkaXRvciBpbnRlcmZhY2UuIE5vIGRlcGVuZGVuY2llcy4gKi8KKGZ1bmN0aW9uICgpIHsKICAndXNlIHN0cmljdCc7CgogIHZhciBjZmcgPSBKU09OLnBhcnNlKGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwbC1jb25maWcnKS50ZXh0Q29udGVudCk7CiAgdmFyIFMgPSBjZmcuc3RyaW5ncyB8fCB7fTsKICB2YXIgU0NSSVBUID0gY2ZnLnNjcmlwdCB8fCAncGx1bWEucGhwJzsKCiAgZnVuY3Rpb24gdChrZXksIHZhcnMpIHsKICAgIHZhciBzID0gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKFMsIGtleSkgPyBTW2tleV0gOiBrZXk7CiAgICBpZiAodmFycykgT2JqZWN0LmtleXModmFycykuZm9yRWFjaChmdW5jdGlvbiAoaykgeyBzID0gcy5zcGxpdCgneycgKyBrICsgJ30nKS5qb2luKHZhcnNba10pOyB9KTsKICAgIHJldHVybiBzOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIGljb25zCiAgdmFyIElDT04gPSB7CiAgICB1bmRvOiAnPHBhdGggZD0iTTkgMTQgNCA5bDUtNSIvPjxwYXRoIGQ9Ik00IDloMTAuNWE1LjUgNS41IDAgMCAxIDAgMTFIMTEiLz4nLAogICAgcmVkbzogJzxwYXRoIGQ9Im0xNSAxNCA1LTUtNS01Ii8+PHBhdGggZD0iTTIwIDlIOS41YTUuNSA1LjUgMCAwIDAgMCAxMUgxMyIvPicsCiAgICBleWU6ICc8cGF0aCBkPSJNMiAxMnMzLjUtNyAxMC03IDEwIDcgMTAgNy0zLjUgNy0xMCA3UzIgMTIgMiAxMnoiLz48Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSIzIi8+JywKICAgIGNsb2NrOiAnPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iOSIvPjxwYXRoIGQ9Ik0xMiA3djVsMyAyIi8+JywKICAgIG1vcmU6ICc8Y2lyY2xlIGN4PSI1IiBjeT0iMTIiIHI9IjEuNSIvPjxjaXJjbGUgY3g9IjEyIiBjeT0iMTIiIHI9IjEuNSIvPjxjaXJjbGUgY3g9IjE5IiBjeT0iMTIiIHI9IjEuNSIvPicsCiAgICBib2xkOiAnPHBhdGggZD0iTTcgNWg2YTMuNSAzLjUgMCAwIDEgMCA3SDd6Ii8+PHBhdGggZD0iTTcgMTJoN2EzLjUgMy41IDAgMCAxIDAgN0g3eiIvPicsCiAgICBpdGFsaWM6ICc8cGF0aCBkPSJNMTkgNGgtOU0xNCAyMEg1TTE1IDQgOSAyMCIvPicsCiAgICBsaW5rOiAnPHBhdGggZD0iTTEwIDEzYTUgNSAwIDAgMCA3LjUuNWwzLTNhNSA1IDAgMCAwLTctN2wtMS43IDEuNyIvPjxwYXRoIGQ9Ik0xNCAxMWE1IDUgMCAwIDAtNy41LS41bC0zIDNhNSA1IDAgMCAwIDcgN2wxLjctMS43Ii8+JywKICAgIHg6ICc8cGF0aCBkPSJNMTggNiA2IDE4TTYgNmwxMiAxMiIvPicsCiAgICB1cGxvYWQ6ICc8cGF0aCBkPSJNMTIgMTZWNE03IDlsNS01IDUgNSIvPjxwYXRoIGQ9Ik0yMCAxNnYzYTEgMSAwIDAgMS0xIDFINWExIDEgMCAwIDEtMS0xdi0zIi8+JywKICAgIGV4dGVybmFsOiAnPHBhdGggZD0iTTE0IDRoNnY2TTIwIDRsLTkgOSIvPjxwYXRoIGQ9Ik0xOSAxNHY1YTEgMSAwIDAgMS0xIDFINWExIDEgMCAwIDEtMS0xVjZhMSAxIDAgMCAxIDEtMWg1Ii8+JywKICAgIGhlbHA6ICc8Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSI5Ii8+PHBhdGggZD0iTTkuNSA5YTIuNSAyLjUgMCAxIDEgMy41IDIuM2MtLjYuMy0xIC45LTEgMS42VjE0Ii8+PHBhdGggZD0iTTEyIDE3aC4wMSIvPicsCiAgICBoZWFydDogJzxwYXRoIGQ9Ik0xMiAyMHMtNy00LjQtNy0xMGE0IDQgMCAwIDEgNy0yLjZBNCA0IDAgMCAxIDE5IDEwYzAgNS42LTcgMTAtNyAxMHoiLz4nLAogICAgb3V0OiAnPHBhdGggZD0iTTE1IDRoNGExIDEgMCAwIDEgMSAxdjE0YTEgMSAwIDAgMS0xIDFoLTQiLz48cGF0aCBkPSJNMTAgMTdsLTUtNSA1LTVNNSAxMmgxMSIvPicsCiAgICBjb3B5OiAnPHJlY3QgeD0iOCIgeT0iOCIgd2lkdGg9IjEyIiBoZWlnaHQ9IjEyIiByeD0iMiIvPjxwYXRoIGQ9Ik0xNiA4VjVhMSAxIDAgMCAwLTEtMUg1YTEgMSAwIDAgMC0xIDF2MTBhMSAxIDAgMCAwIDEgMWgzIi8+JywKICAgIHVwOiAnPHBhdGggZD0iTTEyIDE5VjVNNiAxMWw2LTYgNiA2Ii8+JywKICAgIGRvd246ICc8cGF0aCBkPSJNMTIgNXYxNE02IDEzbDYgNiA2LTYiLz4nLAogICAgbGVmdDogJzxwYXRoIGQ9Ik0xOSAxMkg1TTExIDZsLTYgNiA2IDYiLz4nLAogICAgcmlnaHQ6ICc8cGF0aCBkPSJNNSAxMmgxNE0xMyA2bDYgNi02IDYiLz4nLAogICAgcGFnZTogJzxwYXRoIGQ9Ik0xNCAzSDZhMSAxIDAgMCAwLTEgMXYxNmExIDEgMCAwIDAgMSAxaDEyYTEgMSAwIDAgMCAxLTFWOHoiLz48cGF0aCBkPSJNMTQgM3Y1aDVNOSAxM2g2TTkgMTdoNCIvPicsCiAgICBwbHVzOiAnPHBhdGggZD0iTTEyIDV2MTRNNSAxMmgxNCIvPicsCiAgICBwbGF5OiAnPHBhdGggZD0iTTggNXYxNGwxMS03eiIvPicsCiAgICB0cmFzaDogJzxwYXRoIGQ9Ik00IDdoMTZNMTAgMTF2Nk0xNCAxMXY2Ii8+PHBhdGggZD0iTTYgN2wxIDEyYTEgMSAwIDAgMCAxIDFoOGExIDEgMCAwIDAgMS0xbDEtMTJNOSA3VjRoNnYzIi8+JwogIH07CiAgZnVuY3Rpb24gaWNvbihuYW1lKSB7CiAgICByZXR1cm4gJzxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9ImN1cnJlbnRDb2xvciIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGFyaWEtaGlkZGVuPSJ0cnVlIj4nICsgSUNPTltuYW1lXSArICc8L3N2Zz4nOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIERPTSBoZWxwZXIKICBmdW5jdGlvbiBoKHRhZywgYXR0cnMsIGNoaWxkcmVuKSB7CiAgICB2YXIgZWwgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KHRhZyk7CiAgICBpZiAoYXR0cnMpIHsKICAgICAgT2JqZWN0LmtleXMoYXR0cnMpLmZvckVhY2goZnVuY3Rpb24gKGspIHsKICAgICAgICB2YXIgdiA9IGF0dHJzW2tdOwogICAgICAgIGlmICh2ID09PSBudWxsIHx8IHYgPT09IHVuZGVmaW5lZCB8fCB2ID09PSBmYWxzZSkgcmV0dXJuOwogICAgICAgIGlmIChrID09PSAnY2xhc3MnKSBlbC5jbGFzc05hbWUgPSB2OwogICAgICAgIGVsc2UgaWYgKGsgPT09ICdodG1sJykgZWwuaW5uZXJIVE1MID0gdjsKICAgICAgICBlbHNlIGlmIChrID09PSAndGV4dCcpIGVsLnRleHRDb250ZW50ID0gdjsKICAgICAgICBlbHNlIGlmIChrLnNsaWNlKDAsIDIpID09PSAnb24nKSBlbC5hZGRFdmVudExpc3RlbmVyKGsuc2xpY2UoMiksIHYpOwogICAgICAgIGVsc2UgZWwuc2V0QXR0cmlidXRlKGssIHYgPT09IHRydWUgPyAnJyA6IHYpOwogICAgICB9KTsKICAgIH0KICAgIChjaGlsZHJlbiB8fCBbXSkuZm9yRWFjaChmdW5jdGlvbiAoYykgewogICAgICBpZiAoYyA9PT0gbnVsbCB8fCBjID09PSB1bmRlZmluZWQgfHwgYyA9PT0gZmFsc2UpIHJldHVybjsKICAgICAgZWwuYXBwZW5kQ2hpbGQodHlwZW9mIGMgPT09ICdzdHJpbmcnID8gZG9jdW1lbnQuY3JlYXRlVGV4dE5vZGUoYykgOiBjKTsKICAgIH0pOwogICAgcmV0dXJuIGVsOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIEFQSQogIGZ1bmN0aW9uIHVybChhLCBwYXJhbXMpIHsKICAgIHZhciBxID0gJz9hPScgKyBlbmNvZGVVUklDb21wb25lbnQoYSk7CiAgICBPYmplY3Qua2V5cyhwYXJhbXMgfHwge30pLmZvckVhY2goZnVuY3Rpb24gKGspIHsgcSArPSAnJicgKyBrICsgJz0nICsgZW5jb2RlVVJJQ29tcG9uZW50KHBhcmFtc1trXSk7IH0pOwogICAgcmV0dXJuIFNDUklQVCArIHE7CiAgfQoKICBmdW5jdGlvbiByZXF1ZXN0KGEsIG9wdHMpIHsKICAgIG9wdHMgPSBvcHRzIHx8IHt9OwogICAgdmFyIGluaXQgPSB7IG1ldGhvZDogb3B0cy5tZXRob2QgfHwgJ0dFVCcsIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nLCBoZWFkZXJzOiB7ICdYLVBsdW1hLVRva2VuJzogY2ZnLnRva2VuIH0gfTsKICAgIGlmIChvcHRzLmpzb24gIT09IHVuZGVmaW5lZCkgewogICAgICBpbml0Lm1ldGhvZCA9ICdQT1NUJzsKICAgICAgaW5pdC5oZWFkZXJzWydDb250ZW50LVR5cGUnXSA9ICdhcHBsaWNhdGlvbi9qc29uJzsKICAgICAgaW5pdC5ib2R5ID0gSlNPTi5zdHJpbmdpZnkob3B0cy5qc29uKTsKICAgIH0gZWxzZSBpZiAob3B0cy5mb3JtKSB7CiAgICAgIGluaXQubWV0aG9kID0gJ1BPU1QnOwogICAgICBpbml0LmJvZHkgPSBvcHRzLmZvcm07CiAgICB9CiAgICByZXR1cm4gZmV0Y2godXJsKGEsIG9wdHMucGFyYW1zKSwgaW5pdCkudGhlbihmdW5jdGlvbiAocmVzKSB7CiAgICAgIHJldHVybiByZXMudGV4dCgpLnRoZW4oZnVuY3Rpb24gKHR4dCkgewogICAgICAgIHZhciBkYXRhID0gbnVsbDsKICAgICAgICB0cnkgeyBkYXRhID0gSlNPTi5wYXJzZSh0eHQpOyB9IGNhdGNoIChlKSB7IC8qIG5vdCBqc29uICovIH0KICAgICAgICBpZiAoIXJlcy5vaykgewogICAgICAgICAgdmFyIGNvZGUgPSBkYXRhICYmIGRhdGEuZXJyb3IgPyBkYXRhLmVycm9yIDogKHJlcy5zdGF0dXMgPT09IDQxMyA/ICdpbWFnZS10b28tbGFyZ2UnIDogJ2dlbmVyaWMnKTsKICAgICAgICAgIHZhciBlcnIgPSBuZXcgRXJyb3IoY29kZSk7CiAgICAgICAgICBlcnIuY29kZSA9IGNvZGU7CiAgICAgICAgICBlcnIuc3RhdHVzID0gcmVzLnN0YXR1czsKICAgICAgICAgIHRocm93IGVycjsKICAgICAgICB9CiAgICAgICAgcmV0dXJuIGRhdGE7CiAgICAgIH0pOwogICAgfSwgZnVuY3Rpb24gKCkgewogICAgICB2YXIgZXJyID0gbmV3IEVycm9yKCduZXR3b3JrJyk7CiAgICAgIGVyci5jb2RlID0gJ25ldHdvcmsnOwogICAgICB0aHJvdyBlcnI7CiAgICB9KTsKICB9CgogIGZ1bmN0aW9uIGVycm9yVGV4dChlcnIpIHsKICAgIHZhciBjb2RlID0gZXJyICYmIGVyci5jb2RlID8gZXJyLmNvZGUgOiAnZ2VuZXJpYyc7CiAgICBpZiAoY29kZSA9PT0gJ25ldHdvcmsnKSByZXR1cm4gdCgnZXJyLm5ldHdvcmsnKTsKICAgIGlmIChlcnIgJiYgZXJyLnN0YXR1cyA9PT0gNDAxKSByZXR1cm4gdCgnZXJyLnNlc3Npb24nKTsKICAgIHZhciBrID0gJ2Vyci4nICsgY29kZTsKICAgIHJldHVybiBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwoUywgaykgPyBTW2tdIDogdCgnZXJyLmdlbmVyaWMnKTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSB0b2FzdHMgJiBtb2RhbHMKICB2YXIgdG9hc3RzID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLXRvYXN0cycsIHJvbGU6ICdzdGF0dXMnLCAnYXJpYS1saXZlJzogJ3BvbGl0ZScgfSk7CiAgZnVuY3Rpb24gdG9hc3QobXNnLCBraW5kLCBtcykgewogICAgdmFyIGVsID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLXRvYXN0JyArIChraW5kID8gJyBpcy0nICsga2luZCA6ICcnKSwgdGV4dDogbXNnIH0pOwogICAgdG9hc3RzLmFwcGVuZENoaWxkKGVsKTsKICAgIHNldFRpbWVvdXQoZnVuY3Rpb24gKCkgeyBlbC5yZW1vdmUoKTsgfSwgbXMgfHwgKGtpbmQgPT09ICdlcnJvcicgPyA2MDAwIDogMjYwMCkpOwogIH0KCiAgZnVuY3Rpb24gbW9kYWwob3B0cykgewogICAgcmV0dXJuIG5ldyBQcm9taXNlKGZ1bmN0aW9uIChyZXNvbHZlKSB7CiAgICAgIHZhciBwcmV2ID0gZG9jdW1lbnQuYWN0aXZlRWxlbWVudDsKICAgICAgZnVuY3Rpb24gY2xvc2UodikgewogICAgICAgIG92ZXJsYXkucmVtb3ZlKCk7CiAgICAgICAgZG9jdW1lbnQucmVtb3ZlRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsIG9uS2V5LCB0cnVlKTsKICAgICAgICBpZiAocHJldiAmJiBwcmV2LmZvY3VzKSBwcmV2LmZvY3VzKCk7CiAgICAgICAgcmVzb2x2ZSh2KTsKICAgICAgfQogICAgICBmdW5jdGlvbiBvbktleShlKSB7IGlmIChlLmtleSA9PT0gJ0VzY2FwZScpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyBjbG9zZShmYWxzZSk7IH0gfQogICAgICB2YXIgYWN0aW9ucyA9IChvcHRzLmFjdGlvbnMgfHwgW10pLm1hcChmdW5jdGlvbiAoYSkgewogICAgICAgIHJldHVybiBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtYnRuJyArIChhLnByaW1hcnkgPyAnIHBsLWJ0bi1wcmltYXJ5JyA6ICcnKSArIChhLmRhbmdlciA/ICcgcGwtYnRuLWRhbmdlcicgOiAnJyksIHR5cGU6ICdidXR0b24nLCB0ZXh0OiBhLmxhYmVsLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IGNsb3NlKGEudmFsdWUpOyB9IH0pOwogICAgICB9KTsKICAgICAgdmFyIGJveCA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1tb2RhbCcsIHJvbGU6ICdkaWFsb2cnLCAnYXJpYS1tb2RhbCc6ICd0cnVlJywgJ2FyaWEtbGFiZWwnOiBvcHRzLnRpdGxlIH0sIFsKICAgICAgICBoKCdoMicsIHsgdGV4dDogb3B0cy50aXRsZSB9KSwKICAgICAgICBvcHRzLmJvZHkgPyBoKCdwJywgeyB0ZXh0OiBvcHRzLmJvZHkgfSkgOiBudWxsLAogICAgICAgIG9wdHMuZXh0cmEgfHwgbnVsbCwKICAgICAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtbW9kYWwtYWN0aW9ucycgfSwgYWN0aW9ucykKICAgICAgXSk7CiAgICAgIHZhciBvdmVybGF5ID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLW92ZXJsYXknLCBvbmNsaWNrOiBmdW5jdGlvbiAoZSkgeyBpZiAoZS50YXJnZXQgPT09IG92ZXJsYXkgJiYgIW9wdHMuc3RpY2t5KSBjbG9zZShmYWxzZSk7IH0gfSwgW2JveF0pOwogICAgICBkb2N1bWVudC5ib2R5LmFwcGVuZENoaWxkKG92ZXJsYXkpOwogICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdrZXlkb3duJywgb25LZXksIHRydWUpOwogICAgICB2YXIgZm9jdXNCdG4gPSBib3gucXVlcnlTZWxlY3RvcignLnBsLWJ0bi1wcmltYXJ5JykgfHwgYm94LnF1ZXJ5U2VsZWN0b3IoJ2J1dHRvbicpOwogICAgICBpZiAoZm9jdXNCdG4pIGZvY3VzQnRuLmZvY3VzKCk7CiAgICB9KTsKICB9CgogIGZ1bmN0aW9uIGNvbmZpcm1Cb3godGl0bGUsIGJvZHksIG9rTGFiZWwsIGRhbmdlcikgewogICAgcmV0dXJuIG1vZGFsKHsgdGl0bGU6IHRpdGxlLCBib2R5OiBib2R5LCBhY3Rpb25zOiBbCiAgICAgIHsgbGFiZWw6IHQoJ2xpbmsuY2FuY2VsJyksIHZhbHVlOiBmYWxzZSB9LAogICAgICB7IGxhYmVsOiBva0xhYmVsLCB2YWx1ZTogdHJ1ZSwgcHJpbWFyeTogIWRhbmdlciwgZGFuZ2VyOiBkYW5nZXIgfQogICAgXSB9KTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSBzdGF0ZQogIHZhciBzdGF0ZSA9IHsKICAgIHBhZ2U6IG51bGwsCiAgICBoYXNoOiBudWxsLAogICAgbW9kZTogJ2F1dG8nLAogICAgZG9jOiBudWxsLAogICAgcHJpc3RpbmU6IHt9LCAgIC8vIGtleSAtPiBzbmFwc2hvdCB3aGVuIHRoZSBwYWdlIHdhcyBsb2FkZWQgb3IgbGFzdCBzYXZlZAogICAgbm9kZXM6IHt9LCAgICAgIC8vIGtleSAtPiB0aGUgY29weSBvZiB0aGUgZWxlbWVudCB0aGUgdXNlciB3b3JrZWQgb24KICAgIGRpcnR5OiB7fSwgICAgICAvLyBrZXkgLT4gdHJ1ZQogICAgbWV0YToge30sICAgICAgIC8vIGtleSAtPiBleHRyYSBpbmZvIGZvciBpbWFnZXMge3dpZHRoLGhlaWdodH0KICAgIGdyb3Vwczoge30sICAgICAvLyBncm91cCBpZCAtPiB7IG9yaWc6IFtub2Rlc10sIGN1cnJlbnQ6IFtub2Rlc10sIG1hcmtlciwgc2VwIH0KICAgIGluZm86IG51bGwsICAgICAvLyBmcm9tIHRoZSBzZXJ2ZXI6IHsgbWV0YSwgc2l0ZVVybCwgc2hhcmVkIH0KICAgIHBhZ2VNZXRhOiBudWxsLCAvLyB7IG9yaWc6IHsuLi59LCBjdXI6IHsuLi59IH0gdGl0bGUsIGRlc2NyaXB0aW9uLCBzaGFyZVRpdGxlLCBpbWFnZQogICAgc2hhcmVkV2FybmVkOiB7fSwKICAgIHVuZG86IFtdLAogICAgcmVkbzogW10sCiAgICBlZGl0aW5nOiBudWxsLCAgLy8geyBub2RlLCBrZXksIGtpbmQsIGJlZm9yZSB9CiAgICBwcmV2aWV3OiBmYWxzZSwKICAgIHNhdmluZzogZmFsc2UsCiAgICBsb2FkaW5nOiBmYWxzZQogIH07CgogIC8qKiBpdGVtIG5vZGUgLT4geyBncm91cCwgc3JjIH0gd2hlcmUgc3JjIGlzIHRoZSBzZXJ2ZXIga2V5IG9mIHRoZSBpdGVtIGl0IHdhcyBjb3BpZWQgZnJvbSAqLwogIHZhciBpdGVtSW5mbyA9IG5ldyBXZWFrTWFwKCk7CiAgdmFyIGNvcHlDb3VudGVyID0gMDsKCiAgdmFyIEFUVFJTID0gWydzcmMnLCAnYWx0JywgJ3NyY3NldCcsICdzaXplcycsICdocmVmJywgJ3RhcmdldCcsICdyZWwnLCAnc3R5bGUnLCAnd2lkdGgnLCAnaGVpZ2h0JywgJ3RpdGxlJ107CgogIGZ1bmN0aW9uIHBpY3R1cmVTb3VyY2VzKG5vZGUpIHsKICAgIHJldHVybiBub2RlLnBhcmVudE5vZGUgJiYgbm9kZS5wYXJlbnROb2RlLnRhZ05hbWUgPT09ICdQSUNUVVJFJwogICAgICA/IEFycmF5LnByb3RvdHlwZS5zbGljZS5jYWxsKG5vZGUucGFyZW50Tm9kZS5xdWVyeVNlbGVjdG9yQWxsKCdzb3VyY2UnKSkKICAgICAgOiBbXTsKICB9CgogIGZ1bmN0aW9uIHNuYXBzaG90KG5vZGUpIHsKICAgIHZhciBzID0geyBodG1sOiBub2RlLmlubmVySFRNTCwgYXR0cnM6IHt9LCBzb3VyY2VzOiBwaWN0dXJlU291cmNlcyhub2RlKSB9OwogICAgQVRUUlMuZm9yRWFjaChmdW5jdGlvbiAoYSkgeyBzLmF0dHJzW2FdID0gbm9kZS5oYXNBdHRyaWJ1dGUoYSkgPyBub2RlLmdldEF0dHJpYnV0ZShhKSA6IG51bGw7IH0pOwogICAgcmV0dXJuIHM7CiAgfQoKICBmdW5jdGlvbiByZXN0b3JlKG5vZGUsIHMpIHsKICAgIGlmIChub2RlLmlubmVySFRNTCAhPT0gcy5odG1sKSBub2RlLmlubmVySFRNTCA9IHMuaHRtbDsKICAgIEFUVFJTLmZvckVhY2goZnVuY3Rpb24gKGEpIHsKICAgICAgdmFyIHYgPSBzLmF0dHJzW2FdOwogICAgICBpZiAodiA9PT0gbnVsbCkgeyBpZiAobm9kZS5oYXNBdHRyaWJ1dGUoYSkpIG5vZGUucmVtb3ZlQXR0cmlidXRlKGEpOyB9CiAgICAgIGVsc2UgaWYgKG5vZGUuZ2V0QXR0cmlidXRlKGEpICE9PSB2KSBub2RlLnNldEF0dHJpYnV0ZShhLCB2KTsKICAgIH0pOwogICAgdmFyIG5vdyA9IHBpY3R1cmVTb3VyY2VzKG5vZGUpOwogICAgbm93LmZvckVhY2goZnVuY3Rpb24gKHNyYykgeyBpZiAocy5zb3VyY2VzLmluZGV4T2Yoc3JjKSA9PT0gLTEpIHNyYy5yZW1vdmUoKTsgfSk7CiAgICBzLnNvdXJjZXMuZm9yRWFjaChmdW5jdGlvbiAoc3JjKSB7IGlmICghc3JjLnBhcmVudE5vZGUpIG5vZGUucGFyZW50Tm9kZS5pbnNlcnRCZWZvcmUoc3JjLCBub2RlKTsgfSk7CiAgfQoKICBmdW5jdGlvbiBzYW1lU25hcChhLCBiKSB7CiAgICBpZiAoYS5odG1sICE9PSBiLmh0bWwgfHwgYS5zb3VyY2VzLmxlbmd0aCAhPT0gYi5zb3VyY2VzLmxlbmd0aCkgcmV0dXJuIGZhbHNlOwogICAgZm9yICh2YXIgaSA9IDA7IGkgPCBBVFRSUy5sZW5ndGg7IGkrKykgaWYgKGEuYXR0cnNbQVRUUlNbaV1dICE9PSBiLmF0dHJzW0FUVFJTW2ldXSkgcmV0dXJuIGZhbHNlOwogICAgcmV0dXJuIHRydWU7CiAgfQoKICBmdW5jdGlvbiBub2RlRm9yKGtleSkgewogICAgdmFyIG4gPSBzdGF0ZS5ub2Rlc1trZXldOwogICAgaWYgKG4gJiYgbi5pc0Nvbm5lY3RlZCkgcmV0dXJuIG47CiAgICByZXR1cm4gc3RhdGUuZG9jID8gc3RhdGUuZG9jLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLXBsdW1hLWtleT0iJyArIGtleSArICciXScpIDogbnVsbDsKICB9CgogIC8qKiBDYXJvdXNlbHMgb2Z0ZW4gY2xvbmUgc2xpZGVzOiBrZWVwIGV2ZXJ5IGNvcHkgb2YgYW4gZWxlbWVudCBzaG93aW5nIHRoZSBzYW1lIGNvbnRlbnQuICovCiAgZnVuY3Rpb24gc3luY0Nsb25lcyhrZXkpIHsKICAgIHZhciBzcmMgPSBub2RlRm9yKGtleSk7CiAgICBpZiAoIXNyYyB8fCAhc3RhdGUuZG9jKSByZXR1cm47CiAgICB2YXIgc25hcCA9IHNuYXBzaG90KHNyYyk7CiAgICBBcnJheS5wcm90b3R5cGUuZm9yRWFjaC5jYWxsKHN0YXRlLmRvYy5xdWVyeVNlbGVjdG9yQWxsKCdbZGF0YS1wbHVtYS1rZXk9IicgKyBrZXkgKyAnIl0nKSwgZnVuY3Rpb24gKG4pIHsKICAgICAgaWYgKG4gPT09IHNyYykgcmV0dXJuOwogICAgICBpZiAobi5pbm5lckhUTUwgIT09IHNuYXAuaHRtbCkgbi5pbm5lckhUTUwgPSBzbmFwLmh0bWw7CiAgICAgIEFUVFJTLmZvckVhY2goZnVuY3Rpb24gKGEpIHsKICAgICAgICB2YXIgdiA9IHNuYXAuYXR0cnNbYV07CiAgICAgICAgaWYgKHYgPT09IG51bGwpIG4ucmVtb3ZlQXR0cmlidXRlKGEpOwogICAgICAgIGVsc2UgaWYgKG4uZ2V0QXR0cmlidXRlKGEpICE9PSB2KSBuLnNldEF0dHJpYnV0ZShhLCB2KTsKICAgICAgfSk7CiAgICB9KTsKICB9CgogIC8qKiBLZXlzIG9mIGVkaXRhYmxlcyBpbnNpZGUgY29waWVkIGJsb2NrcyBsb29rIGxpa2UgImMzLm4xMiI6IG4xMiBpcyB0aGUgb3JpZ2luYWwgZWxlbWVudC4gKi8KICBmdW5jdGlvbiBvcmlnT2Yoa2V5KSB7CiAgICB2YXIgaSA9IGtleS5pbmRleE9mKCcuJyk7CiAgICByZXR1cm4gaSA9PT0gLTEgPyBrZXkgOiBrZXkuc2xpY2UoaSArIDEpOwogIH0KCiAgZnVuY3Rpb24gcHJpc3RpbmVGb3Ioa2V5KSB7CiAgICByZXR1cm4gc3RhdGUucHJpc3RpbmVba2V5XSB8fCBzdGF0ZS5wcmlzdGluZVtvcmlnT2Yoa2V5KV0gfHwgbnVsbDsKICB9CgogIGZ1bmN0aW9uIHJlZnJlc2hEaXJ0eShrZXkpIHsKICAgIHN5bmNDbG9uZXMoa2V5KTsKICAgIHZhciBub2RlID0gbm9kZUZvcihrZXkpOwogICAgdmFyIGJhc2UgPSBwcmlzdGluZUZvcihrZXkpOwogICAgaWYgKCFub2RlIHx8ICFiYXNlKSByZXR1cm47CiAgICB2YXIgY3VyID0gc25hcHNob3Qobm9kZSk7CiAgICBpZiAoc2FtZVNuYXAoY3VyLCBiYXNlKSkgewogICAgICBkZWxldGUgc3RhdGUuZGlydHlba2V5XTsKICAgICAgbm9kZS5yZW1vdmVBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtZGlydHknKTsKICAgIH0gZWxzZSB7CiAgICAgIHN0YXRlLmRpcnR5W2tleV0gPSB0cnVlOwogICAgICBub2RlLnNldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1kaXJ0eScsICcnKTsKICAgIH0KICAgIHVwZGF0ZVN0YXR1cygpOwogIH0KCiAgZnVuY3Rpb24gbWFya0RpcnR5KGtleSkgewogICAgdmFyIG5vZGUgPSBub2RlRm9yKGtleSk7CiAgICBzdGF0ZS5kaXJ0eVtrZXldID0gdHJ1ZTsKICAgIGlmIChub2RlKSBub2RlLnNldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1kaXJ0eScsICcnKTsKICAgIHVwZGF0ZVN0YXR1cygpOwogIH0KCiAgZnVuY3Rpb24gZ3JvdXBEaXJ0eShnKSB7CiAgICBpZiAoZy5jdXJyZW50Lmxlbmd0aCAhPT0gZy5vcmlnLmxlbmd0aCkgcmV0dXJuIHRydWU7CiAgICBmb3IgKHZhciBpID0gMDsgaSA8IGcuY3VycmVudC5sZW5ndGg7IGkrKykgaWYgKGcuY3VycmVudFtpXSAhPT0gZy5vcmlnW2ldKSByZXR1cm4gdHJ1ZTsKICAgIHJldHVybiBmYWxzZTsKICB9CgogIGZ1bmN0aW9uIGRpcnR5R3JvdXBzKCkgewogICAgcmV0dXJuIE9iamVjdC5rZXlzKHN0YXRlLmdyb3VwcykubWFwKGZ1bmN0aW9uIChpZCkgeyByZXR1cm4gc3RhdGUuZ3JvdXBzW2lkXTsgfSkuZmlsdGVyKGdyb3VwRGlydHkpOwogIH0KCiAgZnVuY3Rpb24gZGlydHlDb3VudCgpIHsKICAgIC8vIGVsZW1lbnRzIHJlbW92ZWQgd2l0aCB0aGVpciBibG9jayBubyBsb25nZXIgY291bnQ7IHRoZSBibG9jayBjaGFuZ2UgZG9lcwogICAgdmFyIG4gPSBPYmplY3Qua2V5cyhzdGF0ZS5kaXJ0eSkuZmlsdGVyKGZ1bmN0aW9uIChrKSB7IHJldHVybiBub2RlRm9yKGspOyB9KS5sZW5ndGg7CiAgICByZXR1cm4gbiArIGRpcnR5R3JvdXBzKCkubGVuZ3RoICsgKG1ldGFEaXJ0eSgpID8gMSA6IDApOwogIH0KCiAgZnVuY3Rpb24gbWV0YUNoYW5nZXMoKSB7CiAgICB2YXIgcG0gPSBzdGF0ZS5wYWdlTWV0YTsKICAgIGlmICghcG0pIHJldHVybiBudWxsOwogICAgdmFyIG91dCA9IHt9OwogICAgdmFyIGFueSA9IGZhbHNlOwogICAgWyd0aXRsZScsICdkZXNjcmlwdGlvbicsICdzaGFyZVRpdGxlJywgJ2ltYWdlJ10uZm9yRWFjaChmdW5jdGlvbiAoaykgewogICAgICBpZiAocG0uY3VyW2tdICE9PSBwbS5vcmlnW2tdKSB7IG91dFtrXSA9IHBtLmN1cltrXTsgYW55ID0gdHJ1ZTsgfQogICAgfSk7CiAgICByZXR1cm4gYW55ID8gb3V0IDogbnVsbDsKICB9CgogIGZ1bmN0aW9uIG1ldGFEaXJ0eSgpIHsgcmV0dXJuICEhbWV0YUNoYW5nZXMoKTsgfQoKICBmdW5jdGlvbiBwdXNoVW5kbyhlbnRyeSkgewogICAgaWYgKHNhbWVTbmFwKGVudHJ5LmJlZm9yZSwgZW50cnkuYWZ0ZXIpKSByZXR1cm47CiAgICBzdGF0ZS51bmRvLnB1c2goZW50cnkpOwogICAgaWYgKHN0YXRlLnVuZG8ubGVuZ3RoID4gMjAwKSBzdGF0ZS51bmRvLnNoaWZ0KCk7CiAgICBzdGF0ZS5yZWRvID0gW107CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSBsYXlvdXQKICB2YXIgcm9vdCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwbC1yb290Jyk7CgogIHZhciBwYWdlU2VsZWN0ID0gaCgnc2VsZWN0JywgeyBjbGFzczogJ3BsLXNlbGVjdCcsICdhcmlhLWxhYmVsJzogdCgnYmFyLnBhZ2UnKSwgb25jaGFuZ2U6IGZ1bmN0aW9uICgpIHsgc3dpdGNoUGFnZShwYWdlU2VsZWN0LnZhbHVlKTsgfSB9KTsKICB2YXIgYnRuTmV3UGFnZSA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCduZXdwYWdlLnRpdGxlJyksICdhcmlhLWxhYmVsJzogdCgnbmV3cGFnZS50aXRsZScpLCBodG1sOiBpY29uKCdwbHVzJyksIG9uY2xpY2s6IGZ1bmN0aW9uICgpIHsgb3Blbk5ld1BhZ2UoKTsgfSB9KTsKICB2YXIgYnRuU2V0dGluZ3MgPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtaWNvbicsIHR5cGU6ICdidXR0b24nLCB0aXRsZTogdCgnbWV0YS50aXRsZScpLCAnYXJpYS1sYWJlbCc6IHQoJ21ldGEudGl0bGUnKSwgaHRtbDogaWNvbigncGFnZScpLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IG9wZW5QYWdlU2V0dGluZ3MoKTsgfSB9KTsKCiAgdmFyIG1vZGVCYWRnZSA9IGgoJ3NwYW4nLCB7IGNsYXNzOiAncGwtbW9kZScsIHRhYmluZGV4OiAnMCcgfSk7CiAgdmFyIHN0YXR1c1RleHQgPSBoKCdzcGFuJywgeyBjbGFzczogJ3BsLXN0YXR1cy10ZXh0JywgdGV4dDogdCgnYmFyLnNhdmVkJykgfSk7CiAgdmFyIHN0YXR1cyA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1zdGF0dXMnIH0sIFtoKCdzcGFuJywgeyBjbGFzczogJ3BsLWRvdCcgfSksIHN0YXR1c1RleHRdKTsKICB2YXIgYnRuVW5kbyA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdiYXIudW5kbycpICsgJyAoQ3RybCtaKScsICdhcmlhLWxhYmVsJzogdCgnYmFyLnVuZG8nKSwgaHRtbDogaWNvbigndW5kbycpLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IHVuZG8oKTsgfSB9KTsKICB2YXIgYnRuUmVkbyA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdiYXIucmVkbycpICsgJyAoQ3RybCtTaGlmdCtaKScsICdhcmlhLWxhYmVsJzogdCgnYmFyLnJlZG8nKSwgaHRtbDogaWNvbigncmVkbycpLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IHJlZG8oKTsgfSB9KTsKICB2YXIgYnRuUHJldmlldyA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdiYXIucHJldmlldycpLCAnYXJpYS1sYWJlbCc6IHQoJ2Jhci5wcmV2aWV3JyksICdhcmlhLXByZXNzZWQnOiAnZmFsc2UnLCBodG1sOiBpY29uKCdleWUnKSwgb25jbGljazogZnVuY3Rpb24gKCkgeyB0b2dnbGVQcmV2aWV3KCk7IH0gfSk7CiAgdmFyIGJ0bkhpc3RvcnkgPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtaWNvbicsIHR5cGU6ICdidXR0b24nLCB0aXRsZTogdCgnYmFyLmhpc3RvcnknKSwgJ2FyaWEtbGFiZWwnOiB0KCdiYXIuaGlzdG9yeScpLCBodG1sOiBpY29uKCdjbG9jaycpLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IG9wZW5IaXN0b3J5KCk7IH0gfSk7CiAgdmFyIGJ0blNhdmUgPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtYnRuIHBsLWJ0bi1wcmltYXJ5JywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdiYXIuc2F2ZScpICsgJyAoQ3RybCtTKScsIG9uY2xpY2s6IGZ1bmN0aW9uICgpIHsgc2F2ZSgpOyB9IH0sIFt0KCdiYXIuc2F2ZScpXSk7CgogIHZhciBtZW51ID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLW1lbnUnLCBoaWRkZW46IHRydWUsIHJvbGU6ICdtZW51JyB9KTsKICB2YXIgYnRuTWVudSA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdiYXIubWVudScpLCAnYXJpYS1sYWJlbCc6IHQoJ2Jhci5tZW51JyksICdhcmlhLWhhc3BvcHVwJzogJ3RydWUnLCAnYXJpYS1leHBhbmRlZCc6ICdmYWxzZScsIGh0bWw6IGljb24oJ21vcmUnKSwgb25jbGljazogZnVuY3Rpb24gKGUpIHsgZS5zdG9wUHJvcGFnYXRpb24oKTsgdG9nZ2xlTWVudSgpOyB9IH0pOwogIGZ1bmN0aW9uIG1lbnVJdGVtKGljLCBsYWJlbCwgb25jbGljaywgaHJlZikgewogICAgdmFyIGVsID0gaHJlZgogICAgICA/IGgoJ2EnLCB7IGhyZWY6IGhyZWYsIHRhcmdldDogJ19ibGFuaycsIHJlbDogJ25vb3BlbmVyJywgcm9sZTogJ21lbnVpdGVtJywgaHRtbDogaWNvbihpYykgfSkKICAgICAgOiBoKCdidXR0b24nLCB7IHR5cGU6ICdidXR0b24nLCByb2xlOiAnbWVudWl0ZW0nLCBodG1sOiBpY29uKGljKSwgb25jbGljazogb25jbGljayB9KTsKICAgIGVsLmFwcGVuZENoaWxkKGRvY3VtZW50LmNyZWF0ZVRleHROb2RlKGxhYmVsKSk7CiAgICBpZiAoaHJlZikgZWwuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCBmdW5jdGlvbiAoKSB7IHRvZ2dsZU1lbnUoZmFsc2UpOyB9KTsKICAgIHJldHVybiBlbDsKICB9CiAgdmFyIGxpdmVMaW5rID0gbWVudUl0ZW0oJ2V4dGVybmFsJywgdCgnYmFyLnZpZXcnKSwgbnVsbCwgJyMnKTsKICBtZW51LmFwcGVuZENoaWxkKGxpdmVMaW5rKTsKICBtZW51LmFwcGVuZENoaWxkKG1lbnVJdGVtKCdoZWxwJywgdCgnYmFyLmhlbHAnKSwgbnVsbCwgY2ZnLmhlbHApKTsKICBtZW51LmFwcGVuZENoaWxkKG1lbnVJdGVtKCdoZWFydCcsIHQoJ2Jhci5zdXBwb3J0JyksIG51bGwsIGNmZy5zdXBwb3J0KSk7CiAgbWVudS5hcHBlbmRDaGlsZChoKCdocicpKTsKICBtZW51LmFwcGVuZENoaWxkKG1lbnVJdGVtKCdvdXQnLCB0KCdiYXIubG9nb3V0JyksIGZ1bmN0aW9uICgpIHsgbG9nb3V0KCk7IH0pKTsKICBtZW51LmFwcGVuZENoaWxkKGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1tZW51LW1ldGEnLCB0ZXh0OiAnUGx1bWFFZGl0b3IgJyArIGNmZy52ZXJzaW9uIH0pKTsKICBmdW5jdGlvbiB0b2dnbGVNZW51KGZvcmNlKSB7CiAgICB2YXIgc2hvdyA9IGZvcmNlID09PSB1bmRlZmluZWQgPyBtZW51LmhpZGRlbiA6IGZvcmNlOwogICAgbWVudS5oaWRkZW4gPSAhc2hvdzsKICAgIGJ0bk1lbnUuc2V0QXR0cmlidXRlKCdhcmlhLWV4cGFuZGVkJywgc2hvdyA/ICd0cnVlJyA6ICdmYWxzZScpOwogIH0KICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uIChlKSB7IGlmICghbWVudS5oaWRkZW4gJiYgIW1lbnUuY29udGFpbnMoZS50YXJnZXQpKSB0b2dnbGVNZW51KGZhbHNlKTsgfSk7CgogIHZhciBiYXIgPSBoKCdoZWFkZXInLCB7IGNsYXNzOiAncGwtYmFyJyB9LCBbCiAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtYnJhbmQnLCBodG1sOiAnPHNwYW4gY2xhc3M9InBsLWxvZ28iIGFyaWEtaGlkZGVuPSJ0cnVlIj48L3NwYW4+PHNwYW4+UGx1bWE8Yj5FZGl0b3I8L2I+PC9zcGFuPicgfSksCiAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtc2VwJyB9KSwKICAgIGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1wYWdlcGljaycgfSwgW3BhZ2VTZWxlY3QsIGJ0bk5ld1BhZ2UsIG1vZGVCYWRnZV0pLAogICAgc3RhdHVzLAogICAgaCgnZGl2JywgeyBjbGFzczogJ3BsLWFjdGlvbnMnIH0sIFtidG5VbmRvLCBidG5SZWRvLCBoKCdkaXYnLCB7IGNsYXNzOiAncGwtc2VwJyB9KSwgYnRuU2V0dGluZ3MsIGJ0blByZXZpZXcsIGJ0bkhpc3RvcnksIGJ0blNhdmUsIGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1tZW51d3JhcCcgfSwgW2J0bk1lbnUsIG1lbnVdKV0pCiAgXSk7CiAgZmV0Y2godXJsKCdhc3NldCcsIHsgZjogJ2ljb24uc3ZnJywgdjogY2ZnLnZlcnNpb24gfSkpLnRoZW4oZnVuY3Rpb24gKHIpIHsgcmV0dXJuIHIudGV4dCgpOyB9KS50aGVuKGZ1bmN0aW9uIChzdmcpIHsKICAgIHZhciBzbG90ID0gYmFyLnF1ZXJ5U2VsZWN0b3IoJy5wbC1sb2dvJyk7CiAgICBpZiAoc2xvdCAmJiBzdmcuaW5kZXhPZignPHN2ZycpID09PSAwKSBzbG90LmlubmVySFRNTCA9IHN2ZzsKICB9KS5jYXRjaChmdW5jdGlvbiAoKSB7fSk7CgogIHZhciBmcmFtZSA9IGgoJ2lmcmFtZScsIHsgY2xhc3M6ICdwbC1mcmFtZScsIHRpdGxlOiB0KCdiYXIucGFnZScpIH0pOwogIHZhciBsb2FkaW5nID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLWxvYWRpbmcnLCB0ZXh0OiAn4oCmJyB9KTsKICB2YXIgc3RhZ2UgPSBoKCdtYWluJywgeyBjbGFzczogJ3BsLXN0YWdlJyB9LCBbZnJhbWUsIGxvYWRpbmddKTsKICB2YXIgaGludENoaXAgPSBoKCdkaXYnLCB7IGNsYXNzOiAncGwtaGludC1jaGlwJywgJ2FyaWEtaGlkZGVuJzogJ3RydWUnIH0pOwoKICByb290LmFwcGVuZENoaWxkKGJhcik7CiAgcm9vdC5hcHBlbmRDaGlsZChzdGFnZSk7CiAgcm9vdC5hcHBlbmRDaGlsZChoaW50Q2hpcCk7CiAgZG9jdW1lbnQuYm9keS5hcHBlbmRDaGlsZCh0b2FzdHMpOwoKICBmdW5jdGlvbiB1cGRhdGVTdGF0dXMoKSB7CiAgICB2YXIgbiA9IGRpcnR5Q291bnQoKTsKICAgIHN0YXR1cy5jbGFzc0xpc3QudG9nZ2xlKCdpcy1kaXJ0eScsIG4gPiAwICYmICFzdGF0ZS5zYXZpbmcpOwogICAgc3RhdHVzLmNsYXNzTGlzdC50b2dnbGUoJ2lzLXNhdmluZycsIHN0YXRlLnNhdmluZyk7CiAgICBzdGF0dXNUZXh0LnRleHRDb250ZW50ID0gc3RhdGUuc2F2aW5nID8gdCgnYmFyLnNhdmluZycpIDogKG4gPiAwID8gdCgnYmFyLnVuc2F2ZWQnKSA6IHQoJ2Jhci5zYXZlZCcpKTsKICAgIGJ0blNhdmUuZGlzYWJsZWQgPSBzdGF0ZS5zYXZpbmcgfHwgKG4gPT09IDAgJiYgIXN0YXRlLmVkaXRpbmcpOwogICAgYnRuVW5kby5kaXNhYmxlZCA9IHN0YXRlLnVuZG8ubGVuZ3RoID09PSAwICYmICFzdGF0ZS5lZGl0aW5nOwogICAgYnRuUmVkby5kaXNhYmxlZCA9IHN0YXRlLnJlZG8ubGVuZ3RoID09PSAwOwogICAgdmFyIHJvID0gc3RhdGUucGFnZSAmJiBjZmcucmVhZG9ubHkuaW5kZXhPZihzdGF0ZS5wYWdlKSAhPT0gLTE7CiAgICBpZiAocm8pIGJ0blNhdmUuZGlzYWJsZWQgPSB0cnVlOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIGZyYW1lIGxvYWRpbmcKICB2YXIgc2Nyb2xsVG9SZXN0b3JlID0gbnVsbDsKICB2YXIgb3BlblNldHRpbmdzQWZ0ZXJMb2FkID0gZmFsc2U7CiAgdmFyIGJvdW5jZXMgPSBbXTsKCiAgZnVuY3Rpb24gbG9hZFBhZ2UocGFnZSwga2VlcFNjcm9sbCkgewogICAgY2xvc2VEcmF3ZXIoKTsKICAgIHN0b3BFZGl0KCk7CiAgICBoaWRlVG9vbGJhcigpOwogICAgc3RhdGUubG9hZGluZyA9IHRydWU7CiAgICBzdGF0ZS5wYWdlID0gcGFnZTsKICAgIHN0YXRlLmRvYyA9IG51bGw7CiAgICBzdGF0ZS5wcmlzdGluZSA9IHt9OwogICAgc3RhdGUubm9kZXMgPSB7fTsKICAgIHN0YXRlLmRpcnR5ID0ge307CiAgICBzdGF0ZS5tZXRhID0ge307CiAgICBzdGF0ZS5ncm91cHMgPSB7fTsKICAgIHN0YXRlLmluZm8gPSBudWxsOwogICAgc3RhdGUucGFnZU1ldGEgPSBudWxsOwogICAgc3RhdGUuc2hhcmVkV2FybmVkID0ge307CiAgICBzdGF0ZS51bmRvID0gW107CiAgICBzdGF0ZS5yZWRvID0gW107CiAgICBoaWRlSXRlbUJhcih0cnVlKTsKICAgIHBhZ2VTZWxlY3QudmFsdWUgPSBwYWdlOwogICAgbGl2ZUxpbmsuc2V0QXR0cmlidXRlKCdocmVmJywgbmV3IFVSTChwYWdlLCBsb2NhdGlvbi5ocmVmKS5ocmVmKTsKICAgIGxvYWRpbmcuaGlkZGVuID0gZmFsc2U7CiAgICBpZiAoa2VlcFNjcm9sbCAmJiBmcmFtZS5jb250ZW50V2luZG93KSB7CiAgICAgIHRyeSB7IHNjcm9sbFRvUmVzdG9yZSA9IGZyYW1lLmNvbnRlbnRXaW5kb3cuc2Nyb2xsWTsgfSBjYXRjaCAoZSkgeyBzY3JvbGxUb1Jlc3RvcmUgPSBudWxsOyB9CiAgICB9IGVsc2UgewogICAgICBzY3JvbGxUb1Jlc3RvcmUgPSBudWxsOwogICAgfQogICAgdHJ5IHsgc2Vzc2lvblN0b3JhZ2Uuc2V0SXRlbSgncGx1bWEtcGFnZScsIHBhZ2UpOyB9IGNhdGNoIChlKSB7IC8qIGlnbm9yZSAqLyB9CiAgICBmcmFtZS5zcmMgPSB1cmwoJ2ZyYW1lJywgeyBwOiBwYWdlLCByOiBEYXRlLm5vdygpIH0pOwogICAgdXBkYXRlU3RhdHVzKCk7CiAgfQoKICBmcmFtZS5hZGRFdmVudExpc3RlbmVyKCdsb2FkJywgZnVuY3Rpb24gKCkgewogICAgdmFyIGRvYzsKICAgIHRyeSB7IGRvYyA9IGZyYW1lLmNvbnRlbnREb2N1bWVudDsgfSBjYXRjaCAoZSkgeyBkb2MgPSBudWxsOyB9CiAgICBpZiAoIWRvYykgcmV0dXJuOwogICAgdmFyIG1ldGEgPSBkb2MucXVlcnlTZWxlY3RvcignbWV0YVtuYW1lPSJwbHVtYS1oYXNoIl0nKTsKICAgIGlmICghbWV0YSkgewogICAgICBpZiAoc3RhdGUubG9hZGluZykgewogICAgICAgIC8vIFRoZSBlZGl0b3IgdmlldyBpdHNlbGYgZmFpbGVkIHRvIGxvYWQgKHNlc3Npb24gZXhwaXJlZCwgcGFnZSByZW1vdmVk4oCmKQogICAgICAgIHN0YXRlLmxvYWRpbmcgPSBmYWxzZTsKICAgICAgICBsb2FkaW5nLmhpZGRlbiA9IHRydWU7CiAgICAgICAgdG9hc3QodCgnZXJyLnNlc3Npb24nKSwgJ2Vycm9yJywgMTAwMDApOwogICAgICAgIHJldHVybjsKICAgICAgfQogICAgICAvLyBBIHNjcmlwdCBvciBsaW5rIGluc2lkZSB0aGUgcGFnZSBuYXZpZ2F0ZWQgYXdheTogYnJpbmcgdGhlIGVkaXRvciB2aWV3IGJhY2sgKGJ1dCBuZXZlciBsb29wKS4KICAgICAgc3RhdGUuZG9jID0gbnVsbDsKICAgICAgdmFyIG5vdyA9IERhdGUubm93KCk7CiAgICAgIGJvdW5jZXMgPSBib3VuY2VzLmZpbHRlcihmdW5jdGlvbiAodHMpIHsgcmV0dXJuIG5vdyAtIHRzIDwgMTAwMDA7IH0pOwogICAgICBib3VuY2VzLnB1c2gobm93KTsKICAgICAgaWYgKGJvdW5jZXMubGVuZ3RoID4gMikgeyBsb2FkaW5nLmhpZGRlbiA9IHRydWU7IHRvYXN0KHQoJ2Vyci5nZW5lcmljJyksICdlcnJvcicpOyByZXR1cm47IH0KICAgICAgbG9hZFBhZ2Uoc3RhdGUucGFnZSwgZmFsc2UpOwogICAgICByZXR1cm47CiAgICB9CiAgICBzdGF0ZS5sb2FkaW5nID0gZmFsc2U7CiAgICBzdGF0ZS5kb2MgPSBkb2M7CiAgICBzdGF0ZS5oYXNoID0gbWV0YS5nZXRBdHRyaWJ1dGUoJ2NvbnRlbnQnKTsKICAgIHZhciBtb2RlTWV0YSA9IGRvYy5xdWVyeVNlbGVjdG9yKCdtZXRhW25hbWU9InBsdW1hLW1vZGUiXScpOwogICAgc3RhdGUubW9kZSA9IG1vZGVNZXRhID8gbW9kZU1ldGEuZ2V0QXR0cmlidXRlKCdjb250ZW50JykgOiAnYXV0byc7CiAgICBtb2RlQmFkZ2UudGV4dENvbnRlbnQgPSBzdGF0ZS5tb2RlID09PSAnbWFya2VkJyA/ICdNYXJrZWQnIDogJ0F1dG8nOwogICAgbW9kZUJhZGdlLnRpdGxlID0gdCgnYmFyLm1vZGUuJyArIHN0YXRlLm1vZGUpOwogICAgbG9hZGluZy5oaWRkZW4gPSB0cnVlOwogICAgc2V0dXBGcmFtZShkb2MpOwogICAgdmFyIG5vZGVzID0gZG9jLnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLXBsdW1hLWtleV0nKTsKICAgIEFycmF5LnByb3RvdHlwZS5mb3JFYWNoLmNhbGwobm9kZXMsIGZ1bmN0aW9uIChuKSB7CiAgICAgIHZhciBrZXkgPSBuLmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1rZXknKTsKICAgICAgaWYgKCFzdGF0ZS5wcmlzdGluZVtrZXldKSBzdGF0ZS5wcmlzdGluZVtrZXldID0gc25hcHNob3Qobik7CiAgICB9KTsKICAgIHNldHVwR3JvdXBzKGRvYyk7CiAgICB2YXIgaW5mb0VsID0gZG9jLmdldEVsZW1lbnRCeUlkKCdwbHVtYS1pbmZvJyk7CiAgICB0cnkgeyBzdGF0ZS5pbmZvID0gaW5mb0VsID8gSlNPTi5wYXJzZShpbmZvRWwudGV4dENvbnRlbnQpIDogbnVsbDsgfSBjYXRjaCAoZSkgeyBzdGF0ZS5pbmZvID0gbnVsbDsgfQogICAgaWYgKHN0YXRlLmluZm8gJiYgc3RhdGUuaW5mby5tZXRhKSB7CiAgICAgIHN0YXRlLnBhZ2VNZXRhID0geyBvcmlnOiBPYmplY3QuYXNzaWduKHt9LCBzdGF0ZS5pbmZvLm1ldGEpLCBjdXI6IE9iamVjdC5hc3NpZ24oe30sIHN0YXRlLmluZm8ubWV0YSkgfTsKICAgIH0KICAgIGlmIChvcGVuU2V0dGluZ3NBZnRlckxvYWQpIHsgb3BlblNldHRpbmdzQWZ0ZXJMb2FkID0gZmFsc2U7IHNldFRpbWVvdXQob3BlblBhZ2VTZXR0aW5ncywgNTApOyB9CiAgICBpZiAoc2Nyb2xsVG9SZXN0b3JlICE9PSBudWxsKSB7CiAgICAgIHRyeSB7IGZyYW1lLmNvbnRlbnRXaW5kb3cuc2Nyb2xsVG8oMCwgc2Nyb2xsVG9SZXN0b3JlKTsgfSBjYXRjaCAoZSkgeyAvKiBpZ25vcmUgKi8gfQogICAgfQogICAgaWYgKCFub2Rlcy5sZW5ndGgpIHRvYXN0KHQoJ2hpbnQubm9uZScpLCBudWxsLCA3MDAwKTsKICAgIGlmIChjZmcucmVhZG9ubHkuaW5kZXhPZihzdGF0ZS5wYWdlKSAhPT0gLTEpIHRvYXN0KHQoJ2Vyci53cml0ZS1mYWlsZWQnKSwgJ2Vycm9yJyk7CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9KTsKCiAgdmFyIEZSQU1FX0NTUyA9IFsKICAgICdodG1sOm5vdCgucGx1bWEtcHJldmlldykgW2RhdGEtcGx1bWEta2V5XXtjdXJzb3I6cG9pbnRlcn0nLAogICAgJ2h0bWw6bm90KC5wbHVtYS1wcmV2aWV3KSBbZGF0YS1wbHVtYS1raW5kPSJ0ZXh0Il0saHRtbDpub3QoLnBsdW1hLXByZXZpZXcpIFtkYXRhLXBsdW1hLWtpbmQ9InJpY2giXXtjdXJzb3I6dGV4dH0nLAogICAgJ2h0bWw6bm90KC5wbHVtYS1wcmV2aWV3KSBbZGF0YS1wbHVtYS1rZXldOmhvdmVye291dGxpbmU6MnB4IGRhc2hlZCByZ2JhKDM5LDgwLDE0MywuNTUpIWltcG9ydGFudDtvdXRsaW5lLW9mZnNldDozcHghaW1wb3J0YW50fScsCiAgICAnaHRtbDpub3QoLnBsdW1hLXByZXZpZXcpIFtkYXRhLXBsdW1hLWRpcnR5XXtvdXRsaW5lOjJweCBkYXNoZWQgcmdiYSgyMjQsMTYxLDAsLjgpIWltcG9ydGFudDtvdXRsaW5lLW9mZnNldDozcHghaW1wb3J0YW50fScsCiAgICAnaHRtbDpub3QoLnBsdW1hLXByZXZpZXcpIFtkYXRhLXBsdW1hLWtleV1bY29udGVudGVkaXRhYmxlXXtvdXRsaW5lOjJweCBzb2xpZCAjMjc1MDhmIWltcG9ydGFudDtvdXRsaW5lLW9mZnNldDozcHghaW1wb3J0YW50O2N1cnNvcjp0ZXh0fScsCiAgICAnW2RhdGEtcGx1bWEta2V5XVtjb250ZW50ZWRpdGFibGVdOmZvY3Vze291dGxpbmUtY29sb3I6IzI3NTA4ZiFpbXBvcnRhbnR9JywKICAgICdodG1sOm5vdCgucGx1bWEtcHJldmlldykgaW1nW2RhdGEtcGx1bWEta2V5XTpob3ZlcntmaWx0ZXI6YnJpZ2h0bmVzcyguOTIpfScsCiAgICAnaHRtbDpub3QoLnBsdW1hLXByZXZpZXcpIFtkYXRhLXBsdW1hLWhsXXtvdXRsaW5lOjFweCBzb2xpZCByZ2JhKDM5LDgwLDE0MywuNDUpIWltcG9ydGFudDtvdXRsaW5lLW9mZnNldDo2cHghaW1wb3J0YW50fScsCiAgICAnQGtleWZyYW1lcyBwbHVtYS1mbGFzaHtmcm9te2JveC1zaGFkb3c6MCAwIDAgNnB4IHJnYmEoMzksODAsMTQzLC4zNSl9dG97Ym94LXNoYWRvdzowIDAgMCA2cHggcmdiYSgzOSw4MCwxNDMsMCl9fScsCiAgICAnW2RhdGEtcGx1bWEtbmV3XXthbmltYXRpb246cGx1bWEtZmxhc2ggMS40cyBlYXNlLW91dH0nLAogICAgJ2h0bWw6bm90KC5wbHVtYS1wcmV2aWV3KSBpZnJhbWVbZGF0YS1wbHVtYS1raW5kPSJ2aWRlbyJde3BvaW50ZXItZXZlbnRzOm5vbmV9JywKICAgICdodG1sOm5vdCgucGx1bWEtcHJldmlldykgW2RhdGEtcGx1bWEtdmhvdmVyXXtvdXRsaW5lOjJweCBkYXNoZWQgcmdiYSgzOSw4MCwxNDMsLjc1KSFpbXBvcnRhbnQ7b3V0bGluZS1vZmZzZXQ6M3B4IWltcG9ydGFudDtjdXJzb3I6cG9pbnRlcn0nCiAgXS5qb2luKCdcbicpOwoKICBmdW5jdGlvbiBzZXR1cEZyYW1lKGRvYykgewogICAgdmFyIHN0eWxlID0gZG9jLmNyZWF0ZUVsZW1lbnQoJ3N0eWxlJyk7CiAgICBzdHlsZS5zZXRBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtdWknLCAnJyk7CiAgICBzdHlsZS50ZXh0Q29udGVudCA9IEZSQU1FX0NTUzsKICAgIChkb2MuaGVhZCB8fCBkb2MuZG9jdW1lbnRFbGVtZW50KS5hcHBlbmRDaGlsZChzdHlsZSk7CiAgICBpZiAoc3RhdGUucHJldmlldykgZG9jLmRvY3VtZW50RWxlbWVudC5jbGFzc0xpc3QuYWRkKCdwbHVtYS1wcmV2aWV3Jyk7CgogICAgZG9jLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgb25GcmFtZUNsaWNrLCB0cnVlKTsKICAgIGRvYy5hZGRFdmVudExpc3RlbmVyKCdtb3VzZW92ZXInLCBvbkZyYW1lSG92ZXIsIHRydWUpOwogICAgZG9jLmFkZEV2ZW50TGlzdGVuZXIoJ21vdXNlbW92ZScsIGZ1bmN0aW9uIChlKSB7IGlmIChob3ZlcmVkVmlkZW8gfHwgdmlkZW9BdChlLmNsaWVudFgsIGUuY2xpZW50WSkpIG9uRnJhbWVIb3ZlcihlKTsgfSwgdHJ1ZSk7CiAgICBkb2MuYWRkRXZlbnRMaXN0ZW5lcignbW91c2VvdXQnLCBmdW5jdGlvbiAoKSB7IGhpbnRDaGlwLmNsYXNzTGlzdC5yZW1vdmUoJ2lzLW9uJyk7IH0sIHRydWUpOwogICAgZG9jLmFkZEV2ZW50TGlzdGVuZXIoJ3N1Ym1pdCcsIGZ1bmN0aW9uIChlKSB7IGUucHJldmVudERlZmF1bHQoKTsgfSwgdHJ1ZSk7CiAgICBkb2MuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsIG9uS2V5RG93biwgdHJ1ZSk7CiAgICBkb2MuYWRkRXZlbnRMaXN0ZW5lcignZHJhZ292ZXInLCBmdW5jdGlvbiAoZSkgeyBlLnByZXZlbnREZWZhdWx0KCk7IH0sIHRydWUpOwogICAgZG9jLmFkZEV2ZW50TGlzdGVuZXIoJ2Ryb3AnLCBmdW5jdGlvbiAoZSkgeyBlLnByZXZlbnREZWZhdWx0KCk7IH0sIHRydWUpOwogICAgZG9jLmFkZEV2ZW50TGlzdGVuZXIoJ3NlbGVjdGlvbmNoYW5nZScsIGZ1bmN0aW9uICgpIHsgaWYgKHN0YXRlLmVkaXRpbmcpIHVwZGF0ZVRvb2xiYXJTdGF0ZSgpOyB9KTsKICAgIHZhciB3aW4gPSBkb2MuZGVmYXVsdFZpZXc7CiAgICB3aW4uYWRkRXZlbnRMaXN0ZW5lcignc2Nyb2xsJywgZnVuY3Rpb24gKCkgeyBwb3NpdGlvblRvb2xiYXIoKTsgcG9zaXRpb25JdGVtQmFyKCk7IH0sIHsgcGFzc2l2ZTogdHJ1ZSB9KTsKICAgIHdpbi5hZGRFdmVudExpc3RlbmVyKCdyZXNpemUnLCBmdW5jdGlvbiAoKSB7IHBvc2l0aW9uVG9vbGJhcigpOyBwb3NpdGlvbkl0ZW1CYXIoKTsgfSk7CiAgICBkb2MuZG9jdW1lbnRFbGVtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ21vdXNlbGVhdmUnLCBmdW5jdGlvbiAoKSB7IHNjaGVkdWxlSGlkZUl0ZW1CYXIoKTsgfSk7CiAgICB0cnkgeyB3aW4ub3BlbiA9IGZ1bmN0aW9uICgpIHsgcmV0dXJuIG51bGw7IH07IH0gY2F0Y2ggKGUpIHsgLyogaWdub3JlICovIH0KICB9CgogIC8qKiBWaWRlb3MgbGV0IG5vIGNsaWNrcyB0aHJvdWdoIHdoaWxlIGVkaXRpbmc7IGZpbmQgb25lIHVuZGVyIHRoZSBwb2ludGVyIGJ5IHBvc2l0aW9uLiAqLwogIGZ1bmN0aW9uIHZpZGVvQXQoeCwgeSkgewogICAgaWYgKCFzdGF0ZS5kb2MpIHJldHVybiBudWxsOwogICAgdmFyIHZpZHMgPSBzdGF0ZS5kb2MucXVlcnlTZWxlY3RvckFsbCgnaWZyYW1lW2RhdGEtcGx1bWEta2luZD0idmlkZW8iXScpOwogICAgZm9yICh2YXIgaSA9IDA7IGkgPCB2aWRzLmxlbmd0aDsgaSsrKSB7CiAgICAgIHZhciByID0gdmlkc1tpXS5nZXRCb3VuZGluZ0NsaWVudFJlY3QoKTsKICAgICAgaWYgKHggPj0gci5sZWZ0ICYmIHggPD0gci5yaWdodCAmJiB5ID49IHIudG9wICYmIHkgPD0gci5ib3R0b20pIHJldHVybiB2aWRzW2ldOwogICAgfQogICAgcmV0dXJuIG51bGw7CiAgfQoKICBmdW5jdGlvbiBzaGFyZWRDb3VudChub2RlKSB7CiAgICB2YXIgcGFydCA9IG5vZGUgJiYgbm9kZS5jbG9zZXN0ID8gbm9kZS5jbG9zZXN0KCdbZGF0YS1wbHVtYS1zaGFyZWRdJykgOiBudWxsOwogICAgcmV0dXJuIHBhcnQgPyBwYXJzZUludChwYXJ0LmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1zaGFyZWQtcGFnZXMnKSB8fCAnMScsIDEwKSA6IDA7CiAgfQoKICBmdW5jdGlvbiBrZXllZEZyb20odGFyZ2V0KSB7CiAgICBpZiAoIXRhcmdldCB8fCAhdGFyZ2V0LmNsb3Nlc3QpIHRhcmdldCA9IHRhcmdldCAmJiB0YXJnZXQucGFyZW50RWxlbWVudDsKICAgIHJldHVybiB0YXJnZXQgJiYgdGFyZ2V0LmNsb3Nlc3QgPyB0YXJnZXQuY2xvc2VzdCgnW2RhdGEtcGx1bWEta2V5XScpIDogbnVsbDsKICB9CgogIHZhciBob3ZlcmVkVmlkZW8gPSBudWxsOwogIGZ1bmN0aW9uIG9uRnJhbWVIb3ZlcihlKSB7CiAgICBpZiAoc3RhdGUucHJldmlldykgcmV0dXJuOwogICAgdmFyIHZpZCA9IHZpZGVvQXQoZS5jbGllbnRYLCBlLmNsaWVudFkpOwogICAgaWYgKGhvdmVyZWRWaWRlbyAmJiBob3ZlcmVkVmlkZW8gIT09IHZpZCkgaG92ZXJlZFZpZGVvLnJlbW92ZUF0dHJpYnV0ZSgnZGF0YS1wbHVtYS12aG92ZXInKTsKICAgIGhvdmVyZWRWaWRlbyA9IHZpZDsKICAgIGlmICh2aWQpIHZpZC5zZXRBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtdmhvdmVyJywgJycpOwogICAgc2hvd0l0ZW1CYXIoaXRlbUZyb20odmlkIHx8IGUudGFyZ2V0KSk7CiAgICB2YXIgbm9kZSA9IHZpZCB8fCBrZXllZEZyb20oZS50YXJnZXQpOwogICAgaWYgKCFub2RlKSB7IGhpbnRDaGlwLmNsYXNzTGlzdC5yZW1vdmUoJ2lzLW9uJyk7IHJldHVybjsgfQogICAgdmFyIGtpbmQgPSBub2RlLmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1raW5kJyk7CiAgICB2YXIgbiA9IHNoYXJlZENvdW50KG5vZGUpOwogICAgaGludENoaXAudGV4dENvbnRlbnQgPSB0KCdoaW50LicgKyBraW5kKSArIChuID4gMSA/ICcgwrcgJyArIHQoJ2hpbnQuc2hhcmVkJywgeyBuOiBuIH0pIDogJycpOwogICAgaGludENoaXAuY2xhc3NMaXN0LmFkZCgnaXMtb24nKTsKICB9CgogIGZ1bmN0aW9uIHJlc29sdmVQYWdlKGhyZWYpIHsKICAgIC8vIFJldHVybnMgYSBzaXRlIHBhZ2UgKHJlbGF0aXZlIHRvIHRoZSBzaXRlIHJvb3QpIGZvciBpbnRlcm5hbCAuaHRtbCBsaW5rcywgb3IgbnVsbC4KICAgIHRyeSB7CiAgICAgIHZhciBhYnMgPSBuZXcgVVJMKGhyZWYsIHN0YXRlLmRvYy5iYXNlVVJJKTsKICAgICAgdmFyIHNpdGVSb290ID0gbmV3IFVSTCgnLi8nLCBsb2NhdGlvbi5ocmVmKTsKICAgICAgaWYgKGFicy5vcmlnaW4gIT09IHNpdGVSb290Lm9yaWdpbiB8fCBhYnMucGF0aG5hbWUuaW5kZXhPZihzaXRlUm9vdC5wYXRobmFtZSkgIT09IDApIHJldHVybiBudWxsOwogICAgICB2YXIgcmVsID0gZGVjb2RlVVJJQ29tcG9uZW50KGFicy5wYXRobmFtZS5zbGljZShzaXRlUm9vdC5wYXRobmFtZS5sZW5ndGgpKTsKICAgICAgaWYgKHJlbCA9PT0gJycgfHwgcmVsLnNsaWNlKC0xKSA9PT0gJy8nKSByZWwgKz0gJ2luZGV4Lmh0bWwnOwogICAgICByZXR1cm4gY2ZnLnBhZ2VzLmluZGV4T2YocmVsKSAhPT0gLTEgPyByZWwgOiBudWxsOwogICAgfSBjYXRjaCAoZSkgeyByZXR1cm4gbnVsbDsgfQogIH0KCiAgZnVuY3Rpb24gb25GcmFtZUNsaWNrKGUpIHsKICAgIHZhciB0YXJnZXQgPSBlLnRhcmdldDsKICAgIGlmICghc3RhdGUucHJldmlldykgewogICAgICB2YXIgdmlkID0gdmlkZW9BdChlLmNsaWVudFgsIGUuY2xpZW50WSk7CiAgICAgIGlmICh2aWQpIHsKICAgICAgICBlLnByZXZlbnREZWZhdWx0KCk7CiAgICAgICAgZS5zdG9wUHJvcGFnYXRpb24oKTsKICAgICAgICBpZiAoc3RhdGUuZWRpdGluZykgc3RvcEVkaXQoKTsKICAgICAgICB2YXIgdmsgPSB2aWQuZ2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScpOwogICAgICAgIHN0YXRlLm5vZGVzW3ZrXSA9IHZpZDsKICAgICAgICBvcGVuVmlkZW9QYW5lbCh2aWQsIHZrKTsKICAgICAgICByZXR1cm47CiAgICAgIH0KICAgIH0KICAgIHZhciBhbmNob3IgPSB0YXJnZXQuY2xvc2VzdCA/IHRhcmdldC5jbG9zZXN0KCdhJykgOiBudWxsOwogICAgdmFyIG5vZGUgPSBrZXllZEZyb20odGFyZ2V0KTsKCiAgICAvLyBJbi1wYWdlIGFuY2hvcnM6IHNjcm9sbCBvdXJzZWx2ZXMgKGEgPGJhc2U+IHRhZyB3b3VsZCBvdGhlcndpc2UgbmF2aWdhdGUgYXdheSkuCiAgICBpZiAoYW5jaG9yICYmICEoc3RhdGUuZWRpdGluZyAmJiBzdGF0ZS5lZGl0aW5nLm5vZGUuY29udGFpbnMoYW5jaG9yKSkpIHsKICAgICAgdmFyIGhyZWYgPSBhbmNob3IuZ2V0QXR0cmlidXRlKCdocmVmJykgfHwgJyc7CiAgICAgIGlmICgoZS5jdHJsS2V5IHx8IGUubWV0YUtleSkgJiYgaHJlZi5jaGFyQXQoMCkgIT09ICcjJykgewogICAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgICBlLnN0b3BQcm9wYWdhdGlvbigpOwogICAgICAgIHZhciBwID0gcmVzb2x2ZVBhZ2UoaHJlZik7CiAgICAgICAgaWYgKHApIHN3aXRjaFBhZ2UocCk7CiAgICAgICAgcmV0dXJuOwogICAgICB9CiAgICAgIGlmIChocmVmLmNoYXJBdCgwKSA9PT0gJyMnICYmIChzdGF0ZS5wcmV2aWV3IHx8ICFub2RlKSkgewogICAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgICBlLnN0b3BQcm9wYWdhdGlvbigpOwogICAgICAgIHZhciBpZCA9IGRlY29kZVVSSUNvbXBvbmVudChocmVmLnNsaWNlKDEpKTsKICAgICAgICB2YXIgZGVzdCA9IGlkID8gc3RhdGUuZG9jLmdldEVsZW1lbnRCeUlkKGlkKSA6IHN0YXRlLmRvYy5ib2R5OwogICAgICAgIGlmIChkZXN0KSBkZXN0LnNjcm9sbEludG9WaWV3KHsgYmVoYXZpb3I6ICdzbW9vdGgnLCBibG9jazogJ3N0YXJ0JyB9KTsKICAgICAgICByZXR1cm47CiAgICAgIH0KICAgIH0KCiAgICBpZiAoc3RhdGUucHJldmlldykgewogICAgICBpZiAoYW5jaG9yIHx8IHRhcmdldC5jbG9zZXN0KCdidXR0b24saW5wdXRbdHlwZT1zdWJtaXRdJykpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyBlLnN0b3BQcm9wYWdhdGlvbigpOyB9CiAgICAgIHJldHVybjsKICAgIH0KCiAgICBpZiAoIW5vZGUpIHsKICAgICAgaWYgKGFuY2hvciB8fCB0YXJnZXQuY2xvc2VzdCgnYnV0dG9uLGlucHV0W3R5cGU9c3VibWl0XSxsYWJlbCcpKSB7IGUucHJldmVudERlZmF1bHQoKTsgZS5zdG9wUHJvcGFnYXRpb24oKTsgfQogICAgICBpZiAoc3RhdGUuZWRpdGluZykgc3RvcEVkaXQoKTsKICAgICAgcmV0dXJuOwogICAgfQoKICAgIHZhciBraW5kID0gbm9kZS5nZXRBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEta2luZCcpOwogICAgdmFyIGtleSA9IG5vZGUuZ2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScpOwogICAgaWYgKCEoc3RhdGUuZWRpdGluZyAmJiBzdGF0ZS5lZGl0aW5nLm5vZGUgPT09IG5vZGUpKSBzdGF0ZS5ub2Rlc1trZXldID0gbm9kZTsKCiAgICBpZiAoc3RhdGUuZWRpdGluZyAmJiBzdGF0ZS5lZGl0aW5nLm5vZGUgPT09IG5vZGUpIHsKICAgICAgLy8gQ2xpY2tpbmcgaW5zaWRlIHRoZSBlbGVtZW50IGJlaW5nIGVkaXRlZDogcGxhY2UgdGhlIGNhcmV0OyBsaW5rcyBpbnNpZGUgb3BlbiB0aGUgbGluayBwYW5lbAogICAgICBpZiAoYW5jaG9yICYmIG5vZGUuY29udGFpbnMoYW5jaG9yKSkgewogICAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgICBpZiAoYW5jaG9yICE9PSBub2RlKSBvcGVuTGlua1BhbmVsKHsgYW5jaG9yOiBhbmNob3IsIGlubGluZTogdHJ1ZSB9KTsKICAgICAgfQogICAgICByZXR1cm47CiAgICB9CgogICAgZS5wcmV2ZW50RGVmYXVsdCgpOwogICAgZS5zdG9wUHJvcGFnYXRpb24oKTsKICAgIGlmIChzdGF0ZS5lZGl0aW5nKSBzdG9wRWRpdCgpOwoKICAgIGlmIChraW5kID09PSAndGV4dCcgfHwga2luZCA9PT0gJ3JpY2gnKSB7CiAgICAgIHN0YXJ0RWRpdChub2RlLCBrZXksIGtpbmQsIGUuY2xpZW50WCwgZS5jbGllbnRZKTsKICAgIH0gZWxzZSBpZiAoa2luZCA9PT0gJ2xpbmsnKSB7CiAgICAgIG9wZW5MaW5rUGFuZWwoeyBhbmNob3I6IG5vZGUsIGtleToga2V5IH0pOwogICAgfSBlbHNlIGlmIChraW5kID09PSAnaW1hZ2UnIHx8IGtpbmQgPT09ICdiZycpIHsKICAgICAgb3BlbkltYWdlUGFuZWwobm9kZSwga2V5LCBraW5kKTsKICAgIH0KICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSB0ZXh0IGVkaXRpbmcKICBmdW5jdGlvbiBwbGFjZUNhcmV0KGRvYywgeCwgeSkgewogICAgdmFyIHJhbmdlID0gbnVsbDsKICAgIGlmIChkb2MuY2FyZXRSYW5nZUZyb21Qb2ludCkgcmFuZ2UgPSBkb2MuY2FyZXRSYW5nZUZyb21Qb2ludCh4LCB5KTsKICAgIGVsc2UgaWYgKGRvYy5jYXJldFBvc2l0aW9uRnJvbVBvaW50KSB7CiAgICAgIHZhciBwb3MgPSBkb2MuY2FyZXRQb3NpdGlvbkZyb21Qb2ludCh4LCB5KTsKICAgICAgaWYgKHBvcykgeyByYW5nZSA9IGRvYy5jcmVhdGVSYW5nZSgpOyByYW5nZS5zZXRTdGFydChwb3Mub2Zmc2V0Tm9kZSwgcG9zLm9mZnNldCk7IHJhbmdlLmNvbGxhcHNlKHRydWUpOyB9CiAgICB9CiAgICBpZiAocmFuZ2UpIHsKICAgICAgdmFyIHNlbCA9IGRvYy5nZXRTZWxlY3Rpb24oKTsKICAgICAgc2VsLnJlbW92ZUFsbFJhbmdlcygpOwogICAgICBzZWwuYWRkUmFuZ2UocmFuZ2UpOwogICAgfQogIH0KCiAgZnVuY3Rpb24gd2FyblNoYXJlZChub2RlKSB7CiAgICB2YXIgcGFydCA9IG5vZGUuY2xvc2VzdCA/IG5vZGUuY2xvc2VzdCgnW2RhdGEtcGx1bWEtc2hhcmVkXScpIDogbnVsbDsKICAgIGlmICghcGFydCkgcmV0dXJuOwogICAgdmFyIG5hbWUgPSBwYXJ0LmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1zaGFyZWQnKTsKICAgIHZhciBuID0gc2hhcmVkQ291bnQobm9kZSk7CiAgICBpZiAobiA+IDEgJiYgIXN0YXRlLnNoYXJlZFdhcm5lZFtuYW1lXSkgewogICAgICBzdGF0ZS5zaGFyZWRXYXJuZWRbbmFtZV0gPSB0cnVlOwogICAgICB0b2FzdCh0KCdzaGFyZWQuZWRpdGluZycsIHsgbjogbiB9KSwgbnVsbCwgNTAwMCk7CiAgICB9CiAgfQoKICBmdW5jdGlvbiBzdGFydEVkaXQobm9kZSwga2V5LCBraW5kLCB4LCB5KSB7CiAgICBjbG9zZURyYXdlcigpOwogICAgd2FyblNoYXJlZChub2RlKTsKICAgIHZhciBkb2MgPSBzdGF0ZS5kb2M7CiAgICBzdGF0ZS5lZGl0aW5nID0geyBub2RlOiBub2RlLCBrZXk6IGtleSwga2luZDoga2luZCwgYmVmb3JlOiBzbmFwc2hvdChub2RlKSwgYmxvY2s6IG5vZGUuaGFzQXR0cmlidXRlKCdkYXRhLXBsdW1hLWJsb2NrJykgfTsKICAgIG5vZGUuc2V0QXR0cmlidXRlKCdjb250ZW50ZWRpdGFibGUnLCAndHJ1ZScpOwogICAgbm9kZS5zZXRBdHRyaWJ1dGUoJ3NwZWxsY2hlY2snLCAndHJ1ZScpOwogICAgdHJ5IHsgZG9jLmV4ZWNDb21tYW5kKCdkZWZhdWx0UGFyYWdyYXBoU2VwYXJhdG9yJywgZmFsc2UsICdwJyk7IH0gY2F0Y2ggKGUpIHsgLyogaWdub3JlICovIH0KICAgIHRyeSB7IGRvYy5leGVjQ29tbWFuZCgnc3R5bGVXaXRoQ1NTJywgZmFsc2UsIGZhbHNlKTsgfSBjYXRjaCAoZSkgeyAvKiBpZ25vcmUgKi8gfQogICAgbm9kZS5mb2N1cyh7IHByZXZlbnRTY3JvbGw6IHRydWUgfSk7CiAgICBpZiAoeCAhPT0gdW5kZWZpbmVkKSBwbGFjZUNhcmV0KGRvYywgeCwgeSk7CiAgICBub2RlLmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0Jywgb25JbnB1dCk7CiAgICBub2RlLmFkZEV2ZW50TGlzdGVuZXIoJ3Bhc3RlJywgb25QYXN0ZSk7CiAgICBzaG93VG9vbGJhcigpOwogICAgdXBkYXRlU3RhdHVzKCk7CiAgfQoKICBmdW5jdGlvbiBzdG9wRWRpdCgpIHsKICAgIHZhciBlZCA9IHN0YXRlLmVkaXRpbmc7CiAgICBpZiAoIWVkKSByZXR1cm47CiAgICBzdGF0ZS5lZGl0aW5nID0gbnVsbDsKICAgIHZhciBub2RlID0gZWQubm9kZTsKICAgIG5vZGUucmVtb3ZlRXZlbnRMaXN0ZW5lcignaW5wdXQnLCBvbklucHV0KTsKICAgIG5vZGUucmVtb3ZlRXZlbnRMaXN0ZW5lcigncGFzdGUnLCBvblBhc3RlKTsKICAgIG5vZGUucmVtb3ZlQXR0cmlidXRlKCdjb250ZW50ZWRpdGFibGUnKTsKICAgIG5vZGUucmVtb3ZlQXR0cmlidXRlKCdzcGVsbGNoZWNrJyk7CiAgICAvLyBCcm93c2VycyBsZWF2ZSBhIGxvbmUgPGJyPiBpbiBlbXB0aWVkIGVsZW1lbnRzCiAgICBpZiAobm9kZS5pbm5lckhUTUwgPT09ICc8YnI+JyAmJiBlZC5iZWZvcmUuaHRtbCAhPT0gJzxicj4nKSBub2RlLmlubmVySFRNTCA9ICcnOwogICAgLy8gUmVtb3ZlIGxpbmtzIGxlZnQgaGFsZi1jcmVhdGVkIGJ5IHRoZSB0b29sYmFyCiAgICBBcnJheS5wcm90b3R5cGUuZm9yRWFjaC5jYWxsKG5vZGUucXVlcnlTZWxlY3RvckFsbCgnYVtocmVmPSIjcGx1bWEtbmV3LWxpbmsiXScpLCB1bndyYXApOwogICAgdmFyIGFmdGVyID0gc25hcHNob3Qobm9kZSk7CiAgICBwdXNoVW5kbyh7IGtleTogZWQua2V5LCBiZWZvcmU6IGVkLmJlZm9yZSwgYWZ0ZXI6IGFmdGVyIH0pOwogICAgcmVmcmVzaERpcnR5KGVkLmtleSk7CiAgICBoaWRlVG9vbGJhcigpOwogICAgdHJ5IHsgc3RhdGUuZG9jLmdldFNlbGVjdGlvbigpLnJlbW92ZUFsbFJhbmdlcygpOyB9IGNhdGNoIChlKSB7IC8qIGlnbm9yZSAqLyB9CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9CgogIGZ1bmN0aW9uIG9uSW5wdXQoKSB7CiAgICBpZiAoc3RhdGUuZWRpdGluZykgbWFya0RpcnR5KHN0YXRlLmVkaXRpbmcua2V5KTsKICAgIHBvc2l0aW9uVG9vbGJhcigpOwogIH0KCiAgZnVuY3Rpb24gb25QYXN0ZShlKSB7CiAgICBlLnByZXZlbnREZWZhdWx0KCk7CiAgICB2YXIgdGV4dCA9IChlLmNsaXBib2FyZERhdGEgfHwgd2luZG93LmNsaXBib2FyZERhdGEpLmdldERhdGEoJ3RleHQvcGxhaW4nKSB8fCAnJzsKICAgIHZhciBlZCA9IHN0YXRlLmVkaXRpbmc7CiAgICBpZiAoIWVkKSByZXR1cm47CiAgICBpZiAoIWVkLmJsb2NrKSB0ZXh0ID0gdGV4dC5yZXBsYWNlKC9ccypccj9cblxzKi9nLCAnICcpOwogICAgc3RhdGUuZG9jLmV4ZWNDb21tYW5kKCdpbnNlcnRUZXh0JywgZmFsc2UsIHRleHQpOwogIH0KCiAgZnVuY3Rpb24gdW53cmFwKGVsKSB7CiAgICB2YXIgcGFyZW50ID0gZWwucGFyZW50Tm9kZTsKICAgIHdoaWxlIChlbC5maXJzdENoaWxkKSBwYXJlbnQuaW5zZXJ0QmVmb3JlKGVsLmZpcnN0Q2hpbGQsIGVsKTsKICAgIHBhcmVudC5yZW1vdmVDaGlsZChlbCk7CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0ga2V5Ym9hcmQKICBmdW5jdGlvbiBvbktleURvd24oZSkgewogICAgdmFyIG1vZCA9IGUuY3RybEtleSB8fCBlLm1ldGFLZXk7CiAgICB2YXIgayA9IChlLmtleSB8fCAnJykudG9Mb3dlckNhc2UoKTsKICAgIGlmIChtb2QgJiYgayA9PT0gJ3MnKSB7CiAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgc2F2ZSgpOwogICAgICByZXR1cm47CiAgICB9CiAgICB2YXIgZWQgPSBzdGF0ZS5lZGl0aW5nOwogICAgaWYgKGVkICYmIGUudGFyZ2V0ICYmIGVkLm5vZGUuY29udGFpbnMoZS50YXJnZXQpKSB7CiAgICAgIGlmIChrID09PSAnZXNjYXBlJykgeyBlLnByZXZlbnREZWZhdWx0KCk7IHN0b3BFZGl0KCk7IHJldHVybjsgfQogICAgICBpZiAoayA9PT0gJ2VudGVyJykgewogICAgICAgIGlmIChlZC5raW5kID09PSAncmljaCcgJiYgZWQuYmxvY2sgJiYgIWUuc2hpZnRLZXkpIHJldHVybjsgLy8gbmV3IHBhcmFncmFwaAogICAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgICBpZiAoZWQua2luZCA9PT0gJ3JpY2gnICYmIGUuc2hpZnRLZXkpIHN0YXRlLmRvYy5leGVjQ29tbWFuZCgnaW5zZXJ0TGluZUJyZWFrJyk7CiAgICAgICAgcmV0dXJuOwogICAgICB9CiAgICAgIGlmIChtb2QgJiYgKGsgPT09ICdiJyB8fCBrID09PSAnaScgfHwgayA9PT0gJ3UnKSkgewogICAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgICBpZiAoZWQua2luZCA9PT0gJ3JpY2gnICYmIGsgIT09ICd1Jykgc3RhdGUuZG9jLmV4ZWNDb21tYW5kKGsgPT09ICdiJyA/ICdib2xkJyA6ICdpdGFsaWMnKTsKICAgICAgICByZXR1cm47CiAgICAgIH0KICAgICAgaWYgKG1vZCAmJiBrID09PSAnaycpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyBpZiAoZWQua2luZCA9PT0gJ3JpY2gnKSBsaW5rRnJvbVRvb2xiYXIoKTsgcmV0dXJuOyB9CiAgICAgIHJldHVybjsgLy8gbmF0aXZlIHVuZG8gaW5zaWRlIHRoZSBlbGVtZW50CiAgICB9CiAgICBpZiAobW9kICYmIGsgPT09ICd6JyAmJiAhaXNUeXBpbmcoZS50YXJnZXQpKSB7CiAgICAgIGUucHJldmVudERlZmF1bHQoKTsKICAgICAgaWYgKGUuc2hpZnRLZXkpIHJlZG8oKTsgZWxzZSB1bmRvKCk7CiAgICAgIHJldHVybjsKICAgIH0KICAgIGlmIChtb2QgJiYgayA9PT0gJ3knICYmICFpc1R5cGluZyhlLnRhcmdldCkpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyByZWRvKCk7IH0KICB9CgogIGZ1bmN0aW9uIGlzVHlwaW5nKGVsKSB7CiAgICByZXR1cm4gZWwgJiYgKGVsLnRhZ05hbWUgPT09ICdJTlBVVCcgfHwgZWwudGFnTmFtZSA9PT0gJ1RFWFRBUkVBJyB8fCBlbC5pc0NvbnRlbnRFZGl0YWJsZSk7CiAgfQoKICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdrZXlkb3duJywgb25LZXlEb3duLCB0cnVlKTsKCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIGZsb2F0aW5nIHRvb2xiYXIKICB2YXIgdGJCb2xkID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWljb24nLCB0eXBlOiAnYnV0dG9uJywgdGl0bGU6IHQoJ3RiLmJvbGQnKSArICcgKEN0cmwrQiknLCAnYXJpYS1sYWJlbCc6IHQoJ3RiLmJvbGQnKSwgaHRtbDogaWNvbignYm9sZCcpIH0pOwogIHZhciB0Ykl0YWxpYyA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCd0Yi5pdGFsaWMnKSArICcgKEN0cmwrSSknLCAnYXJpYS1sYWJlbCc6IHQoJ3RiLml0YWxpYycpLCBodG1sOiBpY29uKCdpdGFsaWMnKSB9KTsKICB2YXIgdGJMaW5rID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWljb24nLCB0eXBlOiAnYnV0dG9uJywgdGl0bGU6IHQoJ3RiLmxpbmsnKSArICcgKEN0cmwrSyknLCAnYXJpYS1sYWJlbCc6IHQoJ3RiLmxpbmsnKSwgaHRtbDogaWNvbignbGluaycpIH0pOwogIHZhciB0YlNlcCA9IGgoJ3NwYW4nLCB7IGNsYXNzOiAncGwtdHNlcCcgfSk7CiAgdmFyIHRiRG9uZSA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1kb25lJywgdHlwZTogJ2J1dHRvbicsIHRleHQ6IHQoJ3RiLmRvbmUnKSB9KTsKICB2YXIgdG9vbGJhciA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC10b29sYmFyJywgaGlkZGVuOiB0cnVlLCByb2xlOiAndG9vbGJhcicgfSwgW3RiQm9sZCwgdGJJdGFsaWMsIHRiTGluaywgdGJTZXAsIHRiRG9uZV0pOwogIHJvb3QuYXBwZW5kQ2hpbGQodG9vbGJhcik7CgogIC8vIEtlZXAgdGhlIHNlbGVjdGlvbiBpbiB0aGUgZnJhbWUgd2hlbiBjbGlja2luZyB0b29sYmFyIGJ1dHRvbnMKICB0b29sYmFyLmFkZEV2ZW50TGlzdGVuZXIoJ21vdXNlZG93bicsIGZ1bmN0aW9uIChlKSB7IGUucHJldmVudERlZmF1bHQoKTsgfSk7CiAgdGJCb2xkLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZnVuY3Rpb24gKCkgeyBzdGF0ZS5kb2MuZXhlY0NvbW1hbmQoJ2JvbGQnKTsgb25JbnB1dCgpOyB1cGRhdGVUb29sYmFyU3RhdGUoKTsgfSk7CiAgdGJJdGFsaWMuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCBmdW5jdGlvbiAoKSB7IHN0YXRlLmRvYy5leGVjQ29tbWFuZCgnaXRhbGljJyk7IG9uSW5wdXQoKTsgdXBkYXRlVG9vbGJhclN0YXRlKCk7IH0pOwogIHRiTGluay5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uICgpIHsgbGlua0Zyb21Ub29sYmFyKCk7IH0pOwogIHRiRG9uZS5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uICgpIHsgc3RvcEVkaXQoKTsgfSk7CgogIGZ1bmN0aW9uIHNob3dUb29sYmFyKCkgewogICAgdmFyIHJpY2ggPSBzdGF0ZS5lZGl0aW5nICYmIHN0YXRlLmVkaXRpbmcua2luZCA9PT0gJ3JpY2gnOwogICAgdGJCb2xkLmhpZGRlbiA9ICFyaWNoOwogICAgdGJJdGFsaWMuaGlkZGVuID0gIXJpY2g7CiAgICB0YkxpbmsuaGlkZGVuID0gIXJpY2g7CiAgICB0YlNlcC5oaWRkZW4gPSAhcmljaDsKICAgIHRvb2xiYXIuaGlkZGVuID0gZmFsc2U7CiAgICBwb3NpdGlvblRvb2xiYXIoKTsKICAgIHVwZGF0ZVRvb2xiYXJTdGF0ZSgpOwogIH0KICBmdW5jdGlvbiBoaWRlVG9vbGJhcigpIHsgdG9vbGJhci5oaWRkZW4gPSB0cnVlOyB9CgogIGZ1bmN0aW9uIHBvc2l0aW9uVG9vbGJhcigpIHsKICAgIGlmICh0b29sYmFyLmhpZGRlbiB8fCAhc3RhdGUuZWRpdGluZykgcmV0dXJuOwogICAgdmFyIGZyID0gZnJhbWUuZ2V0Qm91bmRpbmdDbGllbnRSZWN0KCk7CiAgICB2YXIgciA9IHN0YXRlLmVkaXRpbmcubm9kZS5nZXRCb3VuZGluZ0NsaWVudFJlY3QoKTsKICAgIHZhciB0dyA9IHRvb2xiYXIub2Zmc2V0V2lkdGg7CiAgICB2YXIgdGggPSB0b29sYmFyLm9mZnNldEhlaWdodDsKICAgIHZhciB0b3AgPSBmci50b3AgKyByLnRvcCAtIHRoIC0gMTI7CiAgICBpZiAodG9wIDwgZnIudG9wICsgNCkgdG9wID0gTWF0aC5taW4oZnIudG9wICsgci5ib3R0b20gKyAxMiwgd2luZG93LmlubmVySGVpZ2h0IC0gdGggLSA4KTsKICAgIHZhciBsZWZ0ID0gZnIubGVmdCArIHIubGVmdDsKICAgIGxlZnQgPSBNYXRoLm1heCg4LCBNYXRoLm1pbihsZWZ0LCB3aW5kb3cuaW5uZXJXaWR0aCAtIHR3IC0gOCkpOwogICAgdG9vbGJhci5zdHlsZS50b3AgPSBNYXRoLnJvdW5kKHRvcCkgKyAncHgnOwogICAgdG9vbGJhci5zdHlsZS5sZWZ0ID0gTWF0aC5yb3VuZChsZWZ0KSArICdweCc7CiAgfQoKICBmdW5jdGlvbiBzZWxlY3Rpb25BbmNob3IoKSB7CiAgICB2YXIgZWQgPSBzdGF0ZS5lZGl0aW5nOwogICAgaWYgKCFlZCkgcmV0dXJuIG51bGw7CiAgICB2YXIgc2VsID0gc3RhdGUuZG9jLmdldFNlbGVjdGlvbigpOwogICAgaWYgKCFzZWwgfHwgIXNlbC5yYW5nZUNvdW50KSByZXR1cm4gbnVsbDsKICAgIHZhciBuID0gc2VsLmFuY2hvck5vZGU7CiAgICB2YXIgZWwgPSBuICYmIG4ubm9kZVR5cGUgPT09IDEgPyBuIDogbiAmJiBuLnBhcmVudEVsZW1lbnQ7CiAgICB2YXIgYSA9IGVsICYmIGVsLmNsb3Nlc3QgPyBlbC5jbG9zZXN0KCdhJykgOiBudWxsOwogICAgcmV0dXJuIGEgJiYgZWQubm9kZS5jb250YWlucyhhKSAmJiBhICE9PSBlZC5ub2RlID8gYSA6IG51bGw7CiAgfQoKICBmdW5jdGlvbiB1cGRhdGVUb29sYmFyU3RhdGUoKSB7CiAgICBpZiAoIXN0YXRlLmRvYyB8fCB0b29sYmFyLmhpZGRlbikgcmV0dXJuOwogICAgdHJ5IHsKICAgICAgdGJCb2xkLnNldEF0dHJpYnV0ZSgnYXJpYS1wcmVzc2VkJywgc3RhdGUuZG9jLnF1ZXJ5Q29tbWFuZFN0YXRlKCdib2xkJykgPyAndHJ1ZScgOiAnZmFsc2UnKTsKICAgICAgdGJJdGFsaWMuc2V0QXR0cmlidXRlKCdhcmlhLXByZXNzZWQnLCBzdGF0ZS5kb2MucXVlcnlDb21tYW5kU3RhdGUoJ2l0YWxpYycpID8gJ3RydWUnIDogJ2ZhbHNlJyk7CiAgICB9IGNhdGNoIChlKSB7IC8qIGlnbm9yZSAqLyB9CiAgICB0Ykxpbmsuc2V0QXR0cmlidXRlKCdhcmlhLXByZXNzZWQnLCBzZWxlY3Rpb25BbmNob3IoKSA/ICd0cnVlJyA6ICdmYWxzZScpOwogIH0KCiAgZnVuY3Rpb24gbGlua0Zyb21Ub29sYmFyKCkgewogICAgdmFyIGEgPSBzZWxlY3Rpb25BbmNob3IoKTsKICAgIGlmIChhKSB7IG9wZW5MaW5rUGFuZWwoeyBhbmNob3I6IGEsIGlubGluZTogdHJ1ZSB9KTsgcmV0dXJuOyB9CiAgICB2YXIgc2VsID0gc3RhdGUuZG9jLmdldFNlbGVjdGlvbigpOwogICAgaWYgKCFzZWwgfHwgc2VsLmlzQ29sbGFwc2VkKSB7IHRvYXN0KHQoJ2xpbmsuc2VsZWN0JykpOyByZXR1cm47IH0KICAgIHN0YXRlLmRvYy5leGVjQ29tbWFuZCgnY3JlYXRlTGluaycsIGZhbHNlLCAnI3BsdW1hLW5ldy1saW5rJyk7CiAgICB2YXIgY3JlYXRlZCA9IHN0YXRlLmVkaXRpbmcubm9kZS5xdWVyeVNlbGVjdG9yKCdhW2hyZWY9IiNwbHVtYS1uZXctbGluayJdJyk7CiAgICBpZiAoY3JlYXRlZCkgb3BlbkxpbmtQYW5lbCh7IGFuY2hvcjogY3JlYXRlZCwgaW5saW5lOiB0cnVlLCBpc05ldzogdHJ1ZSB9KTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSByZXBlYXRhYmxlIGJsb2NrcwogIGZ1bmN0aW9uIHNldHVwR3JvdXBzKGRvYykgewogICAgdmFyIHNlZW4gPSB7fTsKICAgIEFycmF5LnByb3RvdHlwZS5mb3JFYWNoLmNhbGwoZG9jLnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLXBsdW1hLWl0ZW1dJyksIGZ1bmN0aW9uIChuKSB7CiAgICAgIHZhciByID0gbi5nZXRBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtaXRlbScpOwogICAgICAvLyBTbGlkZXJzIGNsb25lIHRoZWlyIHNsaWRlcyBhdCBsb2FkOiB0aGUgZmlyc3QgY29weSBpbiB0aGUgcGFnZSBpcyB0aGUgcmVhbCBvbmUuCiAgICAgIGlmIChzZWVuW3JdKSByZXR1cm47CiAgICAgIHNlZW5bcl0gPSB0cnVlOwogICAgICB2YXIgZ2lkID0gbi5nZXRBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtZ3JvdXAnKTsKICAgICAgdmFyIGcgPSBzdGF0ZS5ncm91cHNbZ2lkXTsKICAgICAgaWYgKCFnKSBnID0gc3RhdGUuZ3JvdXBzW2dpZF0gPSB7IGlkOiBnaWQsIG9yaWc6IFtdIH07CiAgICAgIGcub3JpZy5wdXNoKG4pOwogICAgICBpdGVtSW5mby5zZXQobiwgeyBncm91cDogZ2lkLCBzcmM6IHIgfSk7CiAgICB9KTsKICAgIE9iamVjdC5rZXlzKHN0YXRlLmdyb3VwcykuZm9yRWFjaChmdW5jdGlvbiAoZ2lkKSB7CiAgICAgIHZhciBnID0gc3RhdGUuZ3JvdXBzW2dpZF07CiAgICAgIHZhciBsYXN0ID0gZy5vcmlnW2cub3JpZy5sZW5ndGggLSAxXTsKICAgICAgZy5tYXJrZXIgPSBkb2MuY3JlYXRlQ29tbWVudCgncGx1bWEtZ3JvdXAgJyArIGdpZCk7CiAgICAgIGxhc3QucGFyZW50Tm9kZS5pbnNlcnRCZWZvcmUoZy5tYXJrZXIsIGxhc3QubmV4dFNpYmxpbmcpOwogICAgICBnLnNlcCA9ICdcbic7CiAgICAgIGlmIChnLm9yaWcubGVuZ3RoID4gMSkgewogICAgICAgIHZhciB0ZXh0ID0gJyc7CiAgICAgICAgZm9yICh2YXIgbiA9IGcub3JpZ1swXS5uZXh0U2libGluZzsgbiAmJiBuICE9PSBnLm9yaWdbMV07IG4gPSBuLm5leHRTaWJsaW5nKSB7CiAgICAgICAgICBpZiAobi5ub2RlVHlwZSA9PT0gMykgdGV4dCArPSBuLm5vZGVWYWx1ZTsKICAgICAgICB9CiAgICAgICAgZy5zZXAgPSB0ZXh0OwogICAgICB9CiAgICAgIGcuY3VycmVudCA9IGcub3JpZy5zbGljZSgpOwogICAgfSk7CiAgfQoKICBmdW5jdGlvbiBpdGVtRnJvbSh0YXJnZXQpIHsKICAgIHZhciBlbCA9IHRhcmdldCAmJiB0YXJnZXQubm9kZVR5cGUgPT09IDEgPyB0YXJnZXQgOiB0YXJnZXQgJiYgdGFyZ2V0LnBhcmVudEVsZW1lbnQ7CiAgICB3aGlsZSAoZWwgJiYgZWwuY2xvc2VzdCkgewogICAgICB2YXIgaXRlbSA9IGVsLmNsb3Nlc3QoJ1tkYXRhLXBsdW1hLWl0ZW1dJyk7CiAgICAgIGlmICghaXRlbSkgcmV0dXJuIG51bGw7CiAgICAgIGlmIChpdGVtSW5mby5oYXMoaXRlbSkgJiYgaXRlbS5pc0Nvbm5lY3RlZCkgcmV0dXJuIGl0ZW07CiAgICAgIGVsID0gaXRlbS5wYXJlbnRFbGVtZW50OyAvLyBhIHNsaWRlcidzIGNsb25lOiBsb29rIGZ1cnRoZXIgb3V0CiAgICB9CiAgICByZXR1cm4gbnVsbDsKICB9CgogIC8qKiBQdXRzIHRoZSBncm91cCdzIGl0ZW1zIGluIHRoZSBwYWdlIGluIHRoaXMgb3JkZXIgKHJlbW92ZWQgaXRlbXMgc3RheSBpbiBtZW1vcnkgZm9yIHVuZG8pLiAqLwogIGZ1bmN0aW9uIHNldE9yZGVyKGcsIG5vZGVzKSB7CiAgICB2YXIgcGFyZW50ID0gZy5tYXJrZXIucGFyZW50Tm9kZTsKICAgIHZhciBmaXJzdCA9IGcuY3VycmVudFswXTsKICAgIGlmIChmaXJzdCAmJiBmaXJzdC5wYXJlbnROb2RlID09PSBwYXJlbnQpIHsKICAgICAgdmFyIG4gPSBmaXJzdDsKICAgICAgd2hpbGUgKG4gJiYgbiAhPT0gZy5tYXJrZXIpIHsKICAgICAgICB2YXIgbmV4dCA9IG4ubmV4dFNpYmxpbmc7CiAgICAgICAgcGFyZW50LnJlbW92ZUNoaWxkKG4pOwogICAgICAgIG4gPSBuZXh0OwogICAgICB9CiAgICB9CiAgICB2YXIgZG9jID0gcGFyZW50Lm93bmVyRG9jdW1lbnQ7CiAgICBub2Rlcy5mb3JFYWNoKGZ1bmN0aW9uIChub2RlLCBpKSB7CiAgICAgIGlmIChpID4gMCkgcGFyZW50Lmluc2VydEJlZm9yZShkb2MuY3JlYXRlVGV4dE5vZGUoZy5zZXApLCBnLm1hcmtlcik7CiAgICAgIHBhcmVudC5pbnNlcnRCZWZvcmUobm9kZSwgZy5tYXJrZXIpOwogICAgfSk7CiAgICBnLmN1cnJlbnQgPSBub2Rlcy5zbGljZSgpOwogIH0KCiAgZnVuY3Rpb24gcHVzaE9yZGVyVW5kbyhnLCBiZWZvcmUpIHsKICAgIHN0YXRlLnVuZG8ucHVzaCh7IHR5cGU6ICdvcmRlcicsIGdyb3VwOiBnLmlkLCBiZWZvcmU6IGJlZm9yZSwgYWZ0ZXI6IGcuY3VycmVudC5zbGljZSgpIH0pOwogICAgaWYgKHN0YXRlLnVuZG8ubGVuZ3RoID4gMjAwKSBzdGF0ZS51bmRvLnNoaWZ0KCk7CiAgICBzdGF0ZS5yZWRvID0gW107CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9CgogIGZ1bmN0aW9uIGZsYXNoKG5vZGUpIHsKICAgIG5vZGUuc2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLW5ldycsICcnKTsKICAgIHNldFRpbWVvdXQoZnVuY3Rpb24gKCkgeyBub2RlLnJlbW92ZUF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1uZXcnKTsgfSwgMTUwMCk7CiAgICB2YXIgciA9IG5vZGUuZ2V0Qm91bmRpbmdDbGllbnRSZWN0KCk7CiAgICB2YXIgdmggPSBzdGF0ZS5kb2MuZGVmYXVsdFZpZXcuaW5uZXJIZWlnaHQ7CiAgICBpZiAoci50b3AgPCAwIHx8IHIuYm90dG9tID4gdmgpIG5vZGUuc2Nyb2xsSW50b1ZpZXcoeyBiZWhhdmlvcjogJ3Ntb290aCcsIGJsb2NrOiAnbmVhcmVzdCcgfSk7CiAgfQoKICBmdW5jdGlvbiBkdXBsaWNhdGVJdGVtKG5vZGUpIHsKICAgIHZhciBpbmZvID0gaXRlbUluZm8uZ2V0KG5vZGUpOwogICAgdmFyIGcgPSBpbmZvICYmIHN0YXRlLmdyb3Vwc1tpbmZvLmdyb3VwXTsKICAgIGlmICghZykgcmV0dXJuOwogICAgd2FyblNoYXJlZChub2RlKTsKICAgIHN0b3BFZGl0KCk7CiAgICBjbG9zZURyYXdlcigpOwogICAgdmFyIGJlZm9yZSA9IGcuY3VycmVudC5zbGljZSgpOwogICAgdmFyIGNvcHkgPSBub2RlLmNsb25lTm9kZSh0cnVlKTsKICAgIHZhciBjaWQgPSAnYycgKyAoKytjb3B5Q291bnRlcik7CiAgICB2YXIgYWxsID0gW2NvcHldLmNvbmNhdChBcnJheS5wcm90b3R5cGUuc2xpY2UuY2FsbChjb3B5LnF1ZXJ5U2VsZWN0b3JBbGwoJyonKSkpOwogICAgYWxsLmZvckVhY2goZnVuY3Rpb24gKGVsKSB7CiAgICAgIGVsLnJlbW92ZUF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1kaXJ0eScpOwogICAgICBlbC5yZW1vdmVBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEtaGwnKTsKICAgICAgZWwucmVtb3ZlQXR0cmlidXRlKCdkYXRhLXBsdW1hLW5ldycpOwogICAgICBlbC5yZW1vdmVBdHRyaWJ1dGUoJ2NvbnRlbnRlZGl0YWJsZScpOwogICAgICBlbC5yZW1vdmVBdHRyaWJ1dGUoJ3NwZWxsY2hlY2snKTsKICAgICAgdmFyIGtleSA9IGVsLmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1rZXknKTsKICAgICAgaWYgKGtleSkgewogICAgICAgIHZhciBvcmlnID0gb3JpZ09mKGtleSk7CiAgICAgICAgdmFyIG5ld0tleSA9IGNpZCArICcuJyArIG9yaWc7CiAgICAgICAgZWwuc2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScsIG5ld0tleSk7CiAgICAgICAgaWYgKHN0YXRlLm1ldGFba2V5XSkgc3RhdGUubWV0YVtuZXdLZXldID0gSlNPTi5wYXJzZShKU09OLnN0cmluZ2lmeShzdGF0ZS5tZXRhW2tleV0pKTsKICAgICAgfQogICAgfSk7CiAgICBjb3B5LnNldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1pdGVtJywgY2lkKTsKICAgIGl0ZW1JbmZvLnNldChjb3B5LCB7IGdyb3VwOiBnLmlkLCBzcmM6IGluZm8uc3JjIH0pOwogICAgdmFyIG9yZGVyID0gZy5jdXJyZW50LnNsaWNlKCk7CiAgICBvcmRlci5zcGxpY2Uob3JkZXIuaW5kZXhPZihub2RlKSArIDEsIDAsIGNvcHkpOwogICAgc2V0T3JkZXIoZywgb3JkZXIpOwogICAgcHVzaE9yZGVyVW5kbyhnLCBiZWZvcmUpOwogICAgLy8gdGhlIGNvcHkgZGlmZmVycyBmcm9tIHRoZSBvcmlnaW5hbCB3aGVuIHRoZSBvcmlnaW5hbCBoYWQgdW5zYXZlZCBlZGl0cwogICAgYWxsLmZvckVhY2goZnVuY3Rpb24gKGVsKSB7IHZhciBrID0gZWwuZ2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScpOyBpZiAoaykgcmVmcmVzaERpcnR5KGspOyB9KTsKICAgIGZsYXNoKGNvcHkpOwogICAgc2hvd0l0ZW1CYXIoY29weSwgdHJ1ZSk7CiAgfQoKICBmdW5jdGlvbiBtb3ZlSXRlbShub2RlLCBkZWx0YSkgewogICAgdmFyIGluZm8gPSBpdGVtSW5mby5nZXQobm9kZSk7CiAgICB2YXIgZyA9IGluZm8gJiYgc3RhdGUuZ3JvdXBzW2luZm8uZ3JvdXBdOwogICAgaWYgKCFnKSByZXR1cm47CiAgICB3YXJuU2hhcmVkKG5vZGUpOwogICAgdmFyIGkgPSBnLmN1cnJlbnQuaW5kZXhPZihub2RlKTsKICAgIHZhciBqID0gaSArIGRlbHRhOwogICAgaWYgKGkgPT09IC0xIHx8IGogPCAwIHx8IGogPj0gZy5jdXJyZW50Lmxlbmd0aCkgcmV0dXJuOwogICAgc3RvcEVkaXQoKTsKICAgIHZhciBiZWZvcmUgPSBnLmN1cnJlbnQuc2xpY2UoKTsKICAgIHZhciBvcmRlciA9IGcuY3VycmVudC5zbGljZSgpOwogICAgb3JkZXIuc3BsaWNlKGksIDEpOwogICAgb3JkZXIuc3BsaWNlKGosIDAsIG5vZGUpOwogICAgc2V0T3JkZXIoZywgb3JkZXIpOwogICAgcHVzaE9yZGVyVW5kbyhnLCBiZWZvcmUpOwogICAgZmxhc2gobm9kZSk7CiAgICBzaG93SXRlbUJhcihub2RlLCB0cnVlKTsKICB9CgogIGZ1bmN0aW9uIHJlbW92ZUl0ZW0obm9kZSkgewogICAgdmFyIGluZm8gPSBpdGVtSW5mby5nZXQobm9kZSk7CiAgICB2YXIgZyA9IGluZm8gJiYgc3RhdGUuZ3JvdXBzW2luZm8uZ3JvdXBdOwogICAgaWYgKCFnKSByZXR1cm47CiAgICB3YXJuU2hhcmVkKG5vZGUpOwogICAgaWYgKGcuY3VycmVudC5sZW5ndGggPD0gMSkgeyB0b2FzdCh0KCdpdGVtLmxhc3QnKSk7IHJldHVybjsgfQogICAgc3RvcEVkaXQoKTsKICAgIGNsb3NlRHJhd2VyKCk7CiAgICB2YXIgYmVmb3JlID0gZy5jdXJyZW50LnNsaWNlKCk7CiAgICBzZXRPcmRlcihnLCBnLmN1cnJlbnQuZmlsdGVyKGZ1bmN0aW9uIChuKSB7IHJldHVybiBuICE9PSBub2RlOyB9KSk7CiAgICBwdXNoT3JkZXJVbmRvKGcsIGJlZm9yZSk7CiAgICBoaWRlSXRlbUJhcih0cnVlKTsKICAgIHRvYXN0KHQoJ2l0ZW0uZGVsZXRlZCcpKTsKICB9CgogIC8vIC0tLS0gdGhlIGZsb2F0aW5nIGJsb2NrIGJhcgogIHZhciBpYkR1cCA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsIHRpdGxlOiB0KCdpdGVtLmR1cGxpY2F0ZScpLCAnYXJpYS1sYWJlbCc6IHQoJ2l0ZW0uZHVwbGljYXRlJyksIGh0bWw6IGljb24oJ2NvcHknKSB9KTsKICB2YXIgaWJQcmV2ID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWljb24nLCB0eXBlOiAnYnV0dG9uJyB9KTsKICB2YXIgaWJOZXh0ID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWljb24nLCB0eXBlOiAnYnV0dG9uJyB9KTsKICB2YXIgaWJEZWwgPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtaWNvbiBwbC1pY29uLWRhbmdlcicsIHR5cGU6ICdidXR0b24nLCB0aXRsZTogdCgnaXRlbS5kZWxldGUnKSwgJ2FyaWEtbGFiZWwnOiB0KCdpdGVtLmRlbGV0ZScpLCBodG1sOiBpY29uKCd0cmFzaCcpIH0pOwogIHZhciBpYkxhYmVsID0gaCgnc3BhbicsIHsgY2xhc3M6ICdwbC1pdGVtYmFyLWxhYmVsJywgdGV4dDogdCgnaXRlbS5ibG9jaycpIH0pOwogIHZhciBpdGVtQmFyID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLWl0ZW1iYXInLCBoaWRkZW46IHRydWUsIHJvbGU6ICd0b29sYmFyJywgJ2FyaWEtbGFiZWwnOiB0KCdpdGVtLmJsb2NrJykgfSwgW2liTGFiZWwsIGliRHVwLCBpYlByZXYsIGliTmV4dCwgaWJEZWxdKTsKICByb290LmFwcGVuZENoaWxkKGl0ZW1CYXIpOwogIHZhciBiYXJJdGVtID0gbnVsbDsKICB2YXIgYmFySGlkZVRpbWVyID0gbnVsbDsKCiAgaXRlbUJhci5hZGRFdmVudExpc3RlbmVyKCdtb3VzZWVudGVyJywgZnVuY3Rpb24gKCkgeyBjbGVhclRpbWVvdXQoYmFySGlkZVRpbWVyKTsgfSk7CiAgaXRlbUJhci5hZGRFdmVudExpc3RlbmVyKCdtb3VzZWxlYXZlJywgZnVuY3Rpb24gKCkgeyBzY2hlZHVsZUhpZGVJdGVtQmFyKCk7IH0pOwogIGl0ZW1CYXIuYWRkRXZlbnRMaXN0ZW5lcignbW91c2Vkb3duJywgZnVuY3Rpb24gKGUpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyB9KTsKICBpYkR1cC5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uICgpIHsgaWYgKGJhckl0ZW0pIGR1cGxpY2F0ZUl0ZW0oYmFySXRlbSk7IH0pOwogIGliUHJldi5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uICgpIHsgaWYgKGJhckl0ZW0pIG1vdmVJdGVtKGJhckl0ZW0sIC0xKTsgfSk7CiAgaWJOZXh0LmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZnVuY3Rpb24gKCkgeyBpZiAoYmFySXRlbSkgbW92ZUl0ZW0oYmFySXRlbSwgMSk7IH0pOwogIGliRGVsLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZnVuY3Rpb24gKCkgeyBpZiAoYmFySXRlbSkgcmVtb3ZlSXRlbShiYXJJdGVtKTsgfSk7CgogIC8qKiBTaWRlIGJ5IHNpZGUgKGNhcmRzIGluIGEgcm93KSBvciBzdGFja2VkIChhIGxpc3QpPyBEZWNpZGVzIHRoZSBhcnJvdyBpY29ucy4gKi8KICBmdW5jdGlvbiBob3Jpem9udGFsKGcpIHsKICAgIHZhciBhID0gZy5jdXJyZW50WzBdOwogICAgdmFyIGIgPSBnLmN1cnJlbnRbMV07CiAgICBpZiAoIWEgfHwgIWIpIHJldHVybiBmYWxzZTsKICAgIHZhciByYSA9IGEuZ2V0Qm91bmRpbmdDbGllbnRSZWN0KCk7CiAgICB2YXIgcmIgPSBiLmdldEJvdW5kaW5nQ2xpZW50UmVjdCgpOwogICAgcmV0dXJuIE1hdGguYWJzKHJhLnRvcCAtIHJiLnRvcCkgPCBNYXRoLm1heCg4LCByYS5oZWlnaHQgLyAyKSAmJiBNYXRoLmFicyhyYS5sZWZ0IC0gcmIubGVmdCkgPiA0OwogIH0KCiAgZnVuY3Rpb24gc2hvd0l0ZW1CYXIobm9kZSwgZm9yY2UpIHsKICAgIGNsZWFyVGltZW91dChiYXJIaWRlVGltZXIpOwogICAgaWYgKCFub2RlIHx8IHN0YXRlLnByZXZpZXcpIHsgaWYgKCFmb3JjZSkgc2NoZWR1bGVIaWRlSXRlbUJhcigpOyByZXR1cm47IH0KICAgIGlmIChub2RlID09PSBiYXJJdGVtICYmICFmb3JjZSAmJiAhaXRlbUJhci5oaWRkZW4pIHJldHVybjsKICAgIGlmIChiYXJJdGVtICYmIGJhckl0ZW0gIT09IG5vZGUpIGJhckl0ZW0ucmVtb3ZlQXR0cmlidXRlKCdkYXRhLXBsdW1hLWhsJyk7CiAgICBiYXJJdGVtID0gbm9kZTsKICAgIGliTGFiZWwudGV4dENvbnRlbnQgPSBzaGFyZWRDb3VudChub2RlKSA+IDEgPyB0KCdpdGVtLnNoYXJlZCcpIDogdCgnaXRlbS5ibG9jaycpOwogICAgdmFyIGcgPSBzdGF0ZS5ncm91cHNbaXRlbUluZm8uZ2V0KG5vZGUpLmdyb3VwXTsKICAgIHZhciBpID0gZy5jdXJyZW50LmluZGV4T2Yobm9kZSk7CiAgICB2YXIgc2lkZSA9IGhvcml6b250YWwoZyk7CiAgICBpYlByZXYuaW5uZXJIVE1MID0gaWNvbihzaWRlID8gJ2xlZnQnIDogJ3VwJyk7CiAgICBpYk5leHQuaW5uZXJIVE1MID0gaWNvbihzaWRlID8gJ3JpZ2h0JyA6ICdkb3duJyk7CiAgICBpYlByZXYudGl0bGUgPSB0KHNpZGUgPyAnaXRlbS5sZWZ0JyA6ICdpdGVtLnVwJyk7CiAgICBpYk5leHQudGl0bGUgPSB0KHNpZGUgPyAnaXRlbS5yaWdodCcgOiAnaXRlbS5kb3duJyk7CiAgICBpYlByZXYuc2V0QXR0cmlidXRlKCdhcmlhLWxhYmVsJywgaWJQcmV2LnRpdGxlKTsKICAgIGliTmV4dC5zZXRBdHRyaWJ1dGUoJ2FyaWEtbGFiZWwnLCBpYk5leHQudGl0bGUpOwogICAgaWJQcmV2LmRpc2FibGVkID0gaSA8PSAwOwogICAgaWJOZXh0LmRpc2FibGVkID0gaSA9PT0gLTEgfHwgaSA+PSBnLmN1cnJlbnQubGVuZ3RoIC0gMTsKICAgIGliRGVsLmRpc2FibGVkID0gZy5jdXJyZW50Lmxlbmd0aCA8PSAxOwogICAgaWJEZWwudGl0bGUgPSBnLmN1cnJlbnQubGVuZ3RoIDw9IDEgPyB0KCdpdGVtLmxhc3QnKSA6IHQoJ2l0ZW0uZGVsZXRlJyk7CiAgICAvLyBhbiBvdXRsaW5lIGFyb3VuZCB0aGUgd2hvbGUgYmxvY2sgKG5vdCB3aGVuIHRoZSBibG9jayBpcyBpdHNlbGYgYSB0ZXh0LCB3aGljaCBoYXMgaXRzIG93bikKICAgIGlmICghbm9kZS5oYXNBdHRyaWJ1dGUoJ2RhdGEtcGx1bWEta2V5JykpIG5vZGUuc2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWhsJywgJycpOwogICAgaXRlbUJhci5oaWRkZW4gPSBmYWxzZTsKICAgIHBvc2l0aW9uSXRlbUJhcigpOwogIH0KCiAgZnVuY3Rpb24gc2NoZWR1bGVIaWRlSXRlbUJhcigpIHsKICAgIGNsZWFyVGltZW91dChiYXJIaWRlVGltZXIpOwogICAgYmFySGlkZVRpbWVyID0gc2V0VGltZW91dChmdW5jdGlvbiAoKSB7IGhpZGVJdGVtQmFyKCk7IH0sIDM1MCk7CiAgfQoKICBmdW5jdGlvbiBoaWRlSXRlbUJhcihub3cpIHsKICAgIGNsZWFyVGltZW91dChiYXJIaWRlVGltZXIpOwogICAgaWYgKGJhckl0ZW0pIGJhckl0ZW0ucmVtb3ZlQXR0cmlidXRlKCdkYXRhLXBsdW1hLWhsJyk7CiAgICBiYXJJdGVtID0gbnVsbDsKICAgIGl0ZW1CYXIuaGlkZGVuID0gdHJ1ZTsKICB9CgogIGZ1bmN0aW9uIHBvc2l0aW9uSXRlbUJhcigpIHsKICAgIGlmIChpdGVtQmFyLmhpZGRlbiB8fCAhYmFySXRlbSkgcmV0dXJuOwogICAgaWYgKCFiYXJJdGVtLmlzQ29ubmVjdGVkKSB7IGhpZGVJdGVtQmFyKHRydWUpOyByZXR1cm47IH0KICAgIHZhciBmciA9IGZyYW1lLmdldEJvdW5kaW5nQ2xpZW50UmVjdCgpOwogICAgdmFyIHIgPSBiYXJJdGVtLmdldEJvdW5kaW5nQ2xpZW50UmVjdCgpOwogICAgdmFyIGJ3ID0gaXRlbUJhci5vZmZzZXRXaWR0aDsKICAgIHZhciBiaCA9IGl0ZW1CYXIub2Zmc2V0SGVpZ2h0OwogICAgLy8gYWJvdmUgdGhlIGJsb2NrJ3MgdG9wLXJpZ2h0IGNvcm5lcjsgaW5zaWRlIGl0IHdoZW4gdGhlcmUgaXMgbm8gcm9vbSBhYm92ZQogICAgdmFyIHRvcCA9IGZyLnRvcCArIHIudG9wIC0gYmggLSA4OwogICAgaWYgKHRvcCA8IGZyLnRvcCArIDQpIHRvcCA9IGZyLnRvcCArIHIuYm90dG9tICsgODsgLy8gbm8gcm9vbSBhYm92ZTogYmVsb3cgdGhlIGJsb2NrLCBuZXZlciBvdmVyIGl0CiAgICB2YXIgbGVmdCA9IGZyLmxlZnQgKyByLnJpZ2h0IC0gYnc7CiAgICBsZWZ0ID0gTWF0aC5tYXgoZnIubGVmdCArIDQsIE1hdGgubWluKGxlZnQsIGZyLnJpZ2h0IC0gYncgLSA0KSk7CiAgICBpZiAoci5ib3R0b20gPCAwIHx8IHIudG9wID4gZnIuaGVpZ2h0KSB7IGl0ZW1CYXIuc3R5bGUudmlzaWJpbGl0eSA9ICdoaWRkZW4nOyByZXR1cm47IH0KICAgIGl0ZW1CYXIuc3R5bGUudmlzaWJpbGl0eSA9ICcnOwogICAgaXRlbUJhci5zdHlsZS50b3AgPSBNYXRoLnJvdW5kKHRvcCkgKyAncHgnOwogICAgaXRlbUJhci5zdHlsZS5sZWZ0ID0gTWF0aC5yb3VuZChsZWZ0KSArICdweCc7CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gZHJhd2VyCiAgdmFyIGRyYXdlciA9IG51bGw7CiAgdmFyIGRyYXdlck9uQ2xvc2UgPSBudWxsOwoKICBmdW5jdGlvbiBvcGVuRHJhd2VyKHRpdGxlLCBib2R5LCBmb290LCBvbkNsb3NlKSB7CiAgICBjbG9zZURyYXdlcigpOwogICAgZHJhd2VyT25DbG9zZSA9IG9uQ2xvc2UgfHwgbnVsbDsKICAgIHZhciBjbG9zZSA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1pY29uJywgdHlwZTogJ2J1dHRvbicsICdhcmlhLWxhYmVsJzogdCgnY2xvc2UnKSwgdGl0bGU6IHQoJ2Nsb3NlJyksIGh0bWw6IGljb24oJ3gnKSwgb25jbGljazogZnVuY3Rpb24gKCkgeyBjbG9zZURyYXdlcigpOyB9IH0pOwogICAgZHJhd2VyID0gaCgnYXNpZGUnLCB7IGNsYXNzOiAncGwtZHJhd2VyJywgcm9sZTogJ2RpYWxvZycsICdhcmlhLWxhYmVsJzogdGl0bGUgfSwgWwogICAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtZHJhd2VyLWhlYWQnIH0sIFtoKCdoMicsIHsgdGV4dDogdGl0bGUgfSksIGNsb3NlXSksCiAgICAgIGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1kcmF3ZXItYm9keScgfSwgYm9keSksCiAgICAgIGZvb3QgPyBoKCdkaXYnLCB7IGNsYXNzOiAncGwtZHJhd2VyLWZvb3QnIH0sIGZvb3QpIDogbnVsbAogICAgXSk7CiAgICBkb2N1bWVudC5ib2R5LmFwcGVuZENoaWxkKGRyYXdlcik7CiAgICBkcmF3ZXIuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsIGZ1bmN0aW9uIChlKSB7CiAgICAgIGlmIChlLmtleSA9PT0gJ0VzY2FwZScpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyBjbG9zZURyYXdlcigpOyB9CiAgICB9KTsKICAgIHJldHVybiBkcmF3ZXI7CiAgfQoKICBmdW5jdGlvbiBjbG9zZURyYXdlcigpIHsKICAgIGlmICghZHJhd2VyKSByZXR1cm47CiAgICB2YXIgY2IgPSBkcmF3ZXJPbkNsb3NlOwogICAgZHJhd2VyT25DbG9zZSA9IG51bGw7CiAgICBkcmF3ZXIucmVtb3ZlKCk7CiAgICBkcmF3ZXIgPSBudWxsOwogICAgaWYgKGNiKSBjYigpOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIGxpbmtzCiAgZnVuY3Rpb24gcGFnZURpcigpIHsKICAgIHZhciBpID0gc3RhdGUucGFnZS5sYXN0SW5kZXhPZignLycpOwogICAgcmV0dXJuIGkgPT09IC0xID8gJycgOiBzdGF0ZS5wYWdlLnNsaWNlKDAsIGkgKyAxKTsKICB9CgogIC8qKiBSZWxhdGl2ZSBwYXRoIGZyb20gdGhlIGN1cnJlbnQgcGFnZSB0byBhbm90aGVyIHBhZ2Ugb3IgZmlsZSBvZiB0aGUgc2l0ZS4gKi8KICBmdW5jdGlvbiByZWxGcm9tUGFnZSh0YXJnZXQpIHsKICAgIHZhciBmcm9tID0gcGFnZURpcigpLnNwbGl0KCcvJykuZmlsdGVyKEJvb2xlYW4pOwogICAgdmFyIHRvID0gdGFyZ2V0LnNwbGl0KCcvJyk7CiAgICB2YXIgZmlsZSA9IHRvLnBvcCgpOwogICAgdmFyIGkgPSAwOwogICAgd2hpbGUgKGkgPCBmcm9tLmxlbmd0aCAmJiBpIDwgdG8ubGVuZ3RoICYmIGZyb21baV0gPT09IHRvW2ldKSBpKys7CiAgICB2YXIgdXAgPSBmcm9tLnNsaWNlKGkpLm1hcChmdW5jdGlvbiAoKSB7IHJldHVybiAnLi4nOyB9KTsKICAgIHJldHVybiB1cC5jb25jYXQodG8uc2xpY2UoaSkpLmNvbmNhdChbZmlsZV0pLmpvaW4oJy8nKTsKICB9CgogIGZ1bmN0aW9uIG5vcm1hbGl6ZUhyZWYodikgewogICAgdiA9ICh2IHx8ICcnKS50cmltKCk7CiAgICBpZiAoIXYpIHJldHVybiB7IGhyZWY6ICcnIH07CiAgICB2YXIgd2FybiA9IGZhbHNlOwogICAgaWYgKC9eW15cc0BcLzpdK0BbXlxzQFwvOl0rXC5bYS16XXsyLH0kL2kudGVzdCh2KSkgcmV0dXJuIHsgaHJlZjogJ21haWx0bzonICsgdiB9OwogICAgaWYgKC9eXCs/W1xkXVtcZFxzXC0oKS5dezUsfSQvLnRlc3QodikpIHJldHVybiB7IGhyZWY6ICd0ZWw6JyArIHYucmVwbGFjZSgvW15cZCtdL2csICcnKSB9OwogICAgaWYgKC9ed3d3XC4vaS50ZXN0KHYpKSB2ID0gJ2h0dHBzOi8vJyArIHY7CiAgICB2YXIgc2NoZW1lID0gL14oW2Etel1bYS16MC05Ky5cLV0qKTovaS5leGVjKHYucmVwbGFjZSgvW1xzXHgwMC1ceDFmXSsvZywgJycpKTsKICAgIGlmIChzY2hlbWUgJiYgWydodHRwJywgJ2h0dHBzJywgJ21haWx0bycsICd0ZWwnXS5pbmRleE9mKHNjaGVtZVsxXS50b0xvd2VyQ2FzZSgpKSA9PT0gLTEpIHJldHVybiB7IGVycm9yOiB0cnVlIH07CiAgICBpZiAoL1xzLy50ZXN0KHYpKSB3YXJuID0gdHJ1ZTsKICAgIGlmICghc2NoZW1lICYmIC9eW2EtejAtOS1dK1wuW2Etel17Mix9KFwvfCQpL2kudGVzdCh2KSAmJiAhL1wuaHRtbD8kL2kudGVzdCh2KSkgd2FybiA9IHRydWU7IC8vICJleGFtcGxlLmNvbSIgd2l0aG91dCBodHRwczovLwogICAgcmV0dXJuIHsgaHJlZjogdiwgd2Fybjogd2FybiB9OwogIH0KCiAgZnVuY3Rpb24gb3BlbkxpbmtQYW5lbChvcHRzKSB7CiAgICB2YXIgYSA9IG9wdHMuYW5jaG9yOwogICAgdmFyIGlubGluZSA9ICEhb3B0cy5pbmxpbmU7CiAgICB2YXIga2V5ID0gaW5saW5lID8gKHN0YXRlLmVkaXRpbmcgPyBzdGF0ZS5lZGl0aW5nLmtleSA6IG51bGwpIDogb3B0cy5rZXk7CiAgICB2YXIgYmVmb3JlID0gIWlubGluZSA/IHNuYXBzaG90KGEpIDogbnVsbDsKICAgIGlmICghaW5saW5lKSB3YXJuU2hhcmVkKGEpOwoKICAgIHZhciB0ZXh0SW5wdXQgPSBoKCdpbnB1dCcsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHR5cGU6ICd0ZXh0JywgdmFsdWU6IGEudGV4dENvbnRlbnQucmVwbGFjZSgvXHMrL2csICcgJykudHJpbSgpIH0pOwogICAgdmFyIGhyZWZWYWwgPSBhLmdldEF0dHJpYnV0ZSgnaHJlZicpIHx8ICcnOwogICAgaWYgKGhyZWZWYWwgPT09ICcjcGx1bWEtbmV3LWxpbmsnKSBocmVmVmFsID0gJyc7CiAgICB2YXIgbGlzdElkID0gJ3BsLXBhZ2VzLWxpc3QnOwogICAgdmFyIGRhdGFsaXN0ID0gaCgnZGF0YWxpc3QnLCB7IGlkOiBsaXN0SWQgfSwgY2ZnLnBhZ2VzLm1hcChmdW5jdGlvbiAocCkgeyByZXR1cm4gaCgnb3B0aW9uJywgeyB2YWx1ZTogcmVsRnJvbVBhZ2UocCkgfSk7IH0pKTsKICAgIHZhciBocmVmSW5wdXQgPSBoKCdpbnB1dCcsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHR5cGU6ICd0ZXh0JywgdmFsdWU6IGhyZWZWYWwsIGxpc3Q6IGxpc3RJZCwgcGxhY2Vob2xkZXI6ICdodHRwczovL+KApiDCtyBwYWdlLmh0bWwgwrcgbmFtZUBlbWFpbC5jb20nLCBzcGVsbGNoZWNrOiAnZmFsc2UnLCBhdXRvY2FwaXRhbGl6ZTogJ29mZicgfSk7CiAgICB2YXIgbmV3VGFiID0gaCgnaW5wdXQnLCB7IHR5cGU6ICdjaGVja2JveCcgfSk7CiAgICBuZXdUYWIuY2hlY2tlZCA9IGEuZ2V0QXR0cmlidXRlKCd0YXJnZXQnKSA9PT0gJ19ibGFuayc7CiAgICB2YXIgd2FybiA9IGgoJ3AnLCB7IGNsYXNzOiAncGwtbm90ZScsIGhpZGRlbjogdHJ1ZSwgdGV4dDogdCgnbGluay53YXJuJykgfSk7CiAgICBocmVmSW5wdXQuYWRkRXZlbnRMaXN0ZW5lcignaW5wdXQnLCBmdW5jdGlvbiAoKSB7CiAgICAgIHZhciBuID0gbm9ybWFsaXplSHJlZihocmVmSW5wdXQudmFsdWUpOwogICAgICB3YXJuLmhpZGRlbiA9ICEobi53YXJuIHx8IG4uZXJyb3IpOwogICAgICB3YXJuLnRleHRDb250ZW50ID0gbi5lcnJvciA/IHQoJ2Vyci5iYWQtbGluaycpIDogdCgnbGluay53YXJuJyk7CiAgICB9KTsKCiAgICB2YXIgYXBwbGllZCA9IGZhbHNlOwogICAgZnVuY3Rpb24gYXBwbHkoKSB7CiAgICAgIHZhciBuID0gbm9ybWFsaXplSHJlZihocmVmSW5wdXQudmFsdWUpOwogICAgICBpZiAobi5lcnJvcikgeyB3YXJuLmhpZGRlbiA9IGZhbHNlOyB3YXJuLnRleHRDb250ZW50ID0gdCgnZXJyLmJhZC1saW5rJyk7IGhyZWZJbnB1dC5mb2N1cygpOyByZXR1cm47IH0KICAgICAgdmFyIHRleHQgPSB0ZXh0SW5wdXQudmFsdWUucmVwbGFjZSgvXHMrL2csICcgJykudHJpbSgpOwogICAgICB2YXIgb2xkVGV4dCA9IGEudGV4dENvbnRlbnQucmVwbGFjZSgvXHMrL2csICcgJykudHJpbSgpOwogICAgICBpZiAodGV4dCAmJiB0ZXh0ICE9PSBvbGRUZXh0KSBhLnRleHRDb250ZW50ID0gdGV4dDsgLy8gcmVwbGFjZXMgaW5uZXIgbWFya3VwIG9ubHkgd2hlbiB0aGUgdGV4dCBjaGFuZ2VkCiAgICAgIGlmIChuLmhyZWYgPT09ICcnKSBhLnJlbW92ZUF0dHJpYnV0ZSgnaHJlZicpOyBlbHNlIGEuc2V0QXR0cmlidXRlKCdocmVmJywgbi5ocmVmKTsKICAgICAgaWYgKG5ld1RhYi5jaGVja2VkKSB7CiAgICAgICAgYS5zZXRBdHRyaWJ1dGUoJ3RhcmdldCcsICdfYmxhbmsnKTsKICAgICAgICB2YXIgcmVsID0gKGEuZ2V0QXR0cmlidXRlKCdyZWwnKSB8fCAnJykuc3BsaXQoL1xzKy8pLmZpbHRlcihCb29sZWFuKTsKICAgICAgICBpZiAocmVsLmluZGV4T2YoJ25vb3BlbmVyJykgPT09IC0xKSByZWwucHVzaCgnbm9vcGVuZXInKTsKICAgICAgICBhLnNldEF0dHJpYnV0ZSgncmVsJywgcmVsLmpvaW4oJyAnKSk7CiAgICAgIH0gZWxzZSBpZiAoYS5nZXRBdHRyaWJ1dGUoJ3RhcmdldCcpID09PSAnX2JsYW5rJykgewogICAgICAgIGEucmVtb3ZlQXR0cmlidXRlKCd0YXJnZXQnKTsKICAgICAgICB2YXIgcmVsMiA9IChhLmdldEF0dHJpYnV0ZSgncmVsJykgfHwgJycpLnNwbGl0KC9ccysvKS5maWx0ZXIoZnVuY3Rpb24gKHIpIHsgcmV0dXJuIHIgJiYgciAhPT0gJ25vb3BlbmVyJyAmJiByICE9PSAnbm9yZWZlcnJlcic7IH0pOwogICAgICAgIGlmIChyZWwyLmxlbmd0aCkgYS5zZXRBdHRyaWJ1dGUoJ3JlbCcsIHJlbDIuam9pbignICcpKTsgZWxzZSBhLnJlbW92ZUF0dHJpYnV0ZSgncmVsJyk7CiAgICAgIH0KICAgICAgYXBwbGllZCA9IHRydWU7CiAgICAgIGlmICghaW5saW5lKSB7CiAgICAgICAgcHVzaFVuZG8oeyBrZXk6IGtleSwgYmVmb3JlOiBiZWZvcmUsIGFmdGVyOiBzbmFwc2hvdChhKSB9KTsKICAgICAgICByZWZyZXNoRGlydHkoa2V5KTsKICAgICAgfSBlbHNlIGlmIChrZXkpIHsKICAgICAgICBtYXJrRGlydHkoa2V5KTsKICAgICAgfQogICAgICBjbG9zZURyYXdlcigpOwogICAgfQogICAgZnVuY3Rpb24gcmVtb3ZlTGluaygpIHsKICAgICAgYXBwbGllZCA9IHRydWU7CiAgICAgIHVud3JhcChhKTsKICAgICAgaWYgKGtleSkgbWFya0RpcnR5KGtleSk7CiAgICAgIGNsb3NlRHJhd2VyKCk7CiAgICB9CgogICAgdmFyIGJvZHkgPSBbCiAgICAgIGgoJ2xhYmVsJywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnbGluay50ZXh0JykgfSksIHRleHRJbnB1dF0pLAogICAgICBoKCdsYWJlbCcsIHsgY2xhc3M6ICdwbC1maWVsZCcgfSwgW2goJ3NwYW4nLCB7IHRleHQ6IHQoJ2xpbmsudXJsJykgfSksIGhyZWZJbnB1dCwgaCgnc21hbGwnLCB7IHRleHQ6IHQoJ2xpbmsudXJsLmhpbnQnKSB9KSwgZGF0YWxpc3RdKSwKICAgICAgaCgnbGFiZWwnLCB7IGNsYXNzOiAncGwtcm93JyB9LCBbbmV3VGFiLCB0KCdsaW5rLm5ld3RhYicpXSksCiAgICAgIHdhcm4KICAgIF07CiAgICB2YXIgZm9vdCA9IFtdOwogICAgaWYgKGlubGluZSAmJiAhb3B0cy5pc05ldykgZm9vdC5wdXNoKGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1idG4gcGwtYnRuLWdob3N0IHBsLWJ0bi1kYW5nZXInLCB0eXBlOiAnYnV0dG9uJywgdGV4dDogdCgnbGluay5yZW1vdmUnKSwgb25jbGljazogcmVtb3ZlTGluaywgc3R5bGU6ICdtYXJnaW4tcmlnaHQ6YXV0bycgfSkpOwogICAgZm9vdC5wdXNoKGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1idG4nLCB0eXBlOiAnYnV0dG9uJywgdGV4dDogdCgnbGluay5jYW5jZWwnKSwgb25jbGljazogZnVuY3Rpb24gKCkgeyBjbG9zZURyYXdlcigpOyB9IH0pKTsKICAgIGZvb3QucHVzaChoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtYnRuIHBsLWJ0bi1wcmltYXJ5JywgdHlwZTogJ2J1dHRvbicsIHRleHQ6IHQoJ2xpbmsuYXBwbHknKSwgb25jbGljazogYXBwbHkgfSkpOwogICAgb3BlbkRyYXdlcih0KCdsaW5rLnRpdGxlJyksIGJvZHksIGZvb3QsIGZ1bmN0aW9uICgpIHsKICAgICAgaWYgKCFhcHBsaWVkICYmIG9wdHMuaXNOZXcgJiYgYS5wYXJlbnROb2RlKSB1bndyYXAoYSk7CiAgICAgIGlmIChpbmxpbmUgJiYgc3RhdGUuZWRpdGluZykgc3RhdGUuZWRpdGluZy5ub2RlLmZvY3VzKHsgcHJldmVudFNjcm9sbDogdHJ1ZSB9KTsKICAgIH0pOwogICAgW3RleHRJbnB1dCwgaHJlZklucHV0XS5mb3JFYWNoKGZ1bmN0aW9uIChpbnApIHsKICAgICAgaW5wLmFkZEV2ZW50TGlzdGVuZXIoJ2tleWRvd24nLCBmdW5jdGlvbiAoZSkgeyBpZiAoZS5rZXkgPT09ICdFbnRlcicpIHsgZS5wcmV2ZW50RGVmYXVsdCgpOyBhcHBseSgpOyB9IH0pOwogICAgfSk7CiAgICAoaHJlZlZhbCA/IHRleHRJbnB1dCA6IGhyZWZJbnB1dCkuZm9jdXMoKTsKICAgIGlmICghaHJlZlZhbCkgaHJlZklucHV0LmZvY3VzKCk7CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gaW1hZ2VzCiAgZnVuY3Rpb24gY3VycmVudEltYWdlVXJsKG5vZGUsIGtpbmQpIHsKICAgIGlmIChraW5kID09PSAnaW1hZ2UnKSByZXR1cm4gbm9kZS5jdXJyZW50U3JjIHx8IG5vZGUuc3JjOwogICAgdmFyIGJnID0gc3RhdGUuZG9jLmRlZmF1bHRWaWV3LmdldENvbXB1dGVkU3R5bGUobm9kZSkuYmFja2dyb3VuZEltYWdlIHx8ICcnOwogICAgdmFyIG0gPSAvdXJsXChbIiddPyguKj8pWyInXT9cKS8uZXhlYyhiZyk7CiAgICByZXR1cm4gbSA/IG1bMV0gOiAnJzsKICB9CgogIGZ1bmN0aW9uIGxvYWRCaXRtYXAoZmlsZSkgewogICAgaWYgKHdpbmRvdy5jcmVhdGVJbWFnZUJpdG1hcCkgewogICAgICByZXR1cm4gY3JlYXRlSW1hZ2VCaXRtYXAoZmlsZSwgeyBpbWFnZU9yaWVudGF0aW9uOiAnZnJvbS1pbWFnZScgfSkuY2F0Y2goZnVuY3Rpb24gKCkgeyByZXR1cm4gY3JlYXRlSW1hZ2VCaXRtYXAoZmlsZSk7IH0pOwogICAgfQogICAgcmV0dXJuIG5ldyBQcm9taXNlKGZ1bmN0aW9uIChyZXNvbHZlLCByZWplY3QpIHsKICAgICAgdmFyIGltZyA9IG5ldyBJbWFnZSgpOwogICAgICBpbWcub25sb2FkID0gZnVuY3Rpb24gKCkgeyByZXNvbHZlKGltZyk7IH07CiAgICAgIGltZy5vbmVycm9yID0gcmVqZWN0OwogICAgICBpbWcuc3JjID0gVVJMLmNyZWF0ZU9iamVjdFVSTChmaWxlKTsKICAgIH0pOwogIH0KCiAgLyoqIFNocmlua3MgYmlnIHBob3RvcyBiZWZvcmUgdXBsb2FkIHNvIHRoZXkgZml0IHRoZSBzZXJ2ZXIgbGltaXQgYW5kIHVwbG9hZCBmYXN0LiAqLwogIGZ1bmN0aW9uIHByZXBhcmVGaWxlKGZpbGUpIHsKICAgIHZhciBsaW1pdCA9IE1hdGgubWF4KDIwMDAwMCwgY2ZnLm1heFVwbG9hZCAqIDAuOTIpOwogICAgdmFyIGFjY2VwdGVkID0gL15pbWFnZVwvKGpwZWd8cG5nfHdlYnB8Z2lmKSQvLnRlc3QoZmlsZS50eXBlKTsKICAgIHJldHVybiBsb2FkQml0bWFwKGZpbGUpLnRoZW4oZnVuY3Rpb24gKGJtcCkgewogICAgICB2YXIgdyA9IGJtcC53aWR0aDsKICAgICAgdmFyIGhndCA9IGJtcC5oZWlnaHQ7CiAgICAgIGlmIChhY2NlcHRlZCAmJiBmaWxlLnNpemUgPD0gbGltaXQgJiYgTWF0aC5tYXgodywgaGd0KSA8PSAyNTYwKSByZXR1cm4gZmlsZTsKICAgICAgdmFyIHNjYWxlID0gTWF0aC5taW4oMSwgMjU2MCAvIE1hdGgubWF4KHcsIGhndCkpOwogICAgICB2YXIgY2FudmFzID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnY2FudmFzJyk7CiAgICAgIGNhbnZhcy53aWR0aCA9IE1hdGgucm91bmQodyAqIHNjYWxlKTsKICAgICAgY2FudmFzLmhlaWdodCA9IE1hdGgucm91bmQoaGd0ICogc2NhbGUpOwogICAgICBjYW52YXMuZ2V0Q29udGV4dCgnMmQnKS5kcmF3SW1hZ2UoYm1wLCAwLCAwLCBjYW52YXMud2lkdGgsIGNhbnZhcy5oZWlnaHQpOwogICAgICB2YXIgdHlwZSA9IGZpbGUudHlwZSA9PT0gJ2ltYWdlL3BuZycgPyAnaW1hZ2Uvd2VicCcgOiAnaW1hZ2UvanBlZyc7CiAgICAgIHZhciBxdWFsaXR5ID0gMC45OwogICAgICBmdW5jdGlvbiBlbmNvZGUoKSB7CiAgICAgICAgcmV0dXJuIG5ldyBQcm9taXNlKGZ1bmN0aW9uIChyZXNvbHZlKSB7IGNhbnZhcy50b0Jsb2IocmVzb2x2ZSwgdHlwZSwgcXVhbGl0eSk7IH0pLnRoZW4oZnVuY3Rpb24gKGJsb2IpIHsKICAgICAgICAgIGlmICghYmxvYikgeyB0eXBlID0gJ2ltYWdlL2pwZWcnOyByZXR1cm4gZW5jb2RlKCk7IH0KICAgICAgICAgIGlmIChibG9iLnNpemUgPiBsaW1pdCAmJiBxdWFsaXR5ID4gMC41KSB7IHF1YWxpdHkgLT0gMC4xNTsgcmV0dXJuIGVuY29kZSgpOyB9CiAgICAgICAgICB2YXIgbmFtZSA9IGZpbGUubmFtZS5yZXBsYWNlKC9cLlteLl0rJC8sICcnKSArICh0eXBlID09PSAnaW1hZ2Uvd2VicCcgPyAnLndlYnAnIDogJy5qcGcnKTsKICAgICAgICAgIHJldHVybiBuZXcgRmlsZShbYmxvYl0sIG5hbWUsIHsgdHlwZTogdHlwZSB9KTsKICAgICAgICB9KTsKICAgICAgfQogICAgICByZXR1cm4gZW5jb2RlKCk7CiAgICB9LCBmdW5jdGlvbiAoKSB7CiAgICAgIC8vIEJyb3dzZXIgY2FuJ3QgZGVjb2RlIGl0IChlLmcuIEhFSUMgb24gc29tZSBzeXN0ZW1zKTogbGV0IHRoZSBzZXJ2ZXIganVkZ2UKICAgICAgcmV0dXJuIGZpbGU7CiAgICB9KTsKICB9CgogIGZ1bmN0aW9uIG9wZW5JbWFnZVBhbmVsKG5vZGUsIGtleSwga2luZCkgewogICAgd2FyblNoYXJlZChub2RlKTsKICAgIHZhciBiZWZvcmUgPSBzbmFwc2hvdChub2RlKTsKICAgIHZhciBjaG9zZW4gPSBudWxsOyAvLyB7IHVybCwgd2lkdGgsIGhlaWdodCB9CiAgICB2YXIgcHJldmlldyA9IGgoJ2ltZycsIHsgc3JjOiBjdXJyZW50SW1hZ2VVcmwobm9kZSwga2luZCksIGFsdDogJycgfSk7CiAgICB2YXIgc3RhcnRGb2N1cyA9IHBvc1RvRm9jdXMoa2luZCA9PT0gJ2ltYWdlJwogICAgICA/IHN0YXRlLmRvYy5kZWZhdWx0Vmlldy5nZXRDb21wdXRlZFN0eWxlKG5vZGUpLm9iamVjdFBvc2l0aW9uCiAgICAgIDogc3RhdGUuZG9jLmRlZmF1bHRWaWV3LmdldENvbXB1dGVkU3R5bGUobm9kZSkuYmFja2dyb3VuZFBvc2l0aW9uKTsKICAgIHZhciBmb2N1cyA9IHsgeDogc3RhcnRGb2N1cy54LCB5OiBzdGFydEZvY3VzLnkgfTsKICAgIHZhciBkb3QgPSBoKCdzcGFuJywgeyBjbGFzczogJ3BsLWZvY3VzLWRvdCcsICdhcmlhLWhpZGRlbic6ICd0cnVlJyB9KTsKICAgIHZhciB3cmFwID0gaCgnc3BhbicsIHsgY2xhc3M6ICdwbC1mb2N1cy13cmFwJywgdGl0bGU6IHQoJ2ltZy5mb2N1cy5oaW50JykgfSwgW3ByZXZpZXcsIGRvdF0pOwogICAgdmFyIGN1cnJlbnQgPSBoKCdkaXYnLCB7IGNsYXNzOiAncGwtY3VycmVudCcgfSwgW3dyYXBdKTsKICAgIHZhciBjcm9wV2lkZSA9IGgoJ3NwYW4nLCB7IGNsYXNzOiAncGwtY3JvcCBwbC1jcm9wLXdpZGUnIH0pOwogICAgdmFyIGNyb3BUYWxsID0gaCgnc3BhbicsIHsgY2xhc3M6ICdwbC1jcm9wIHBsLWNyb3AtdGFsbCcgfSk7CiAgICB2YXIgY3JvcHMgPSBoKCdkaXYnLCB7IGNsYXNzOiAncGwtY3JvcHMnIH0sIFsKICAgICAgaCgnZmlndXJlJywge30sIFtjcm9wV2lkZSwgaCgnZmlnY2FwdGlvbicsIHsgdGV4dDogdCgnaW1nLmNyb3Aud2lkZScpIH0pXSksCiAgICAgIGgoJ2ZpZ3VyZScsIHt9LCBbY3JvcFRhbGwsIGgoJ2ZpZ2NhcHRpb24nLCB7IHRleHQ6IHQoJ2ltZy5jcm9wLnRhbGwnKSB9KV0pCiAgICBdKTsKICAgIGZ1bmN0aW9uIHNob3dGb2N1cygpIHsKICAgICAgZG90LnN0eWxlLmxlZnQgPSBmb2N1cy54ICsgJyUnOwogICAgICBkb3Quc3R5bGUudG9wID0gZm9jdXMueSArICclJzsKICAgICAgW2Nyb3BXaWRlLCBjcm9wVGFsbF0uZm9yRWFjaChmdW5jdGlvbiAoYykgewogICAgICAgIGMuc3R5bGUuYmFja2dyb3VuZEltYWdlID0gJ3VybCgiJyArIHByZXZpZXcuc3JjLnJlcGxhY2UoLyIvZywgJyUyMicpICsgJyIpJzsKICAgICAgICBjLnN0eWxlLmJhY2tncm91bmRQb3NpdGlvbiA9IGZvY3VzLnggKyAnJSAnICsgZm9jdXMueSArICclJzsKICAgICAgfSk7CiAgICB9CiAgICB3cmFwLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZnVuY3Rpb24gKGUpIHsKICAgICAgdmFyIHIgPSBwcmV2aWV3LmdldEJvdW5kaW5nQ2xpZW50UmVjdCgpOwogICAgICBmb2N1cy54ID0gTWF0aC5yb3VuZChNYXRoLm1heCgwLCBNYXRoLm1pbigxMDAsIChlLmNsaWVudFggLSByLmxlZnQpIC8gci53aWR0aCAqIDEwMCkpKTsKICAgICAgZm9jdXMueSA9IE1hdGgucm91bmQoTWF0aC5tYXgoMCwgTWF0aC5taW4oMTAwLCAoZS5jbGllbnRZIC0gci50b3ApIC8gci5oZWlnaHQgKiAxMDApKSk7CiAgICAgIHNob3dGb2N1cygpOwogICAgfSk7CiAgICBwcmV2aWV3LmFkZEV2ZW50TGlzdGVuZXIoJ2xvYWQnLCBzaG93Rm9jdXMpOwogICAgc2hvd0ZvY3VzKCk7CiAgICB2YXIgZmlsZUlucHV0ID0gaCgnaW5wdXQnLCB7IHR5cGU6ICdmaWxlJywgYWNjZXB0OiAnaW1hZ2UvanBlZyxpbWFnZS9wbmcsaW1hZ2Uvd2VicCxpbWFnZS9naWYnLCBjbGFzczogJ3BsLXNyJyB9KTsKICAgIHZhciBwcm9ncmVzcyA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1wcm9ncmVzcycsIGhpZGRlbjogdHJ1ZSB9LCBbaCgnaScpXSk7CiAgICB2YXIgdXBsb2FkQnRuID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0bicsIHR5cGU6ICdidXR0b24nLCBodG1sOiBpY29uKCd1cGxvYWQnKSwgb25jbGljazogZnVuY3Rpb24gKCkgeyBmaWxlSW5wdXQuY2xpY2soKTsgfSB9KTsKICAgIHVwbG9hZEJ0bi5hcHBlbmRDaGlsZChkb2N1bWVudC5jcmVhdGVUZXh0Tm9kZSh0KCdpbWcudXBsb2FkJykpKTsKICAgIHZhciBkcm9wID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLWRyb3AnIH0sIFt1cGxvYWRCdG4sIGgoJ3NtYWxsJywgeyB0ZXh0OiB0KCdpbWcuZHJvcCcpIH0pLCBwcm9ncmVzcywgZmlsZUlucHV0XSk7CiAgICB2YXIgZ3JpZCA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1ncmlkJyB9KTsKICAgIHZhciBhbHRJbnB1dCA9IGtpbmQgPT09ICdpbWFnZScgPyBoKCd0ZXh0YXJlYScsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHJvd3M6ICcyJyB9KSA6IG51bGw7CiAgICBpZiAoYWx0SW5wdXQpIGFsdElucHV0LnZhbHVlID0gbm9kZS5nZXRBdHRyaWJ1dGUoJ2FsdCcpIHx8ICcnOwogICAgdmFyIGFwcGx5QnRuID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0biBwbC1idG4tcHJpbWFyeScsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdpbWcuYXBwbHknKSB9KTsKCiAgICBmdW5jdGlvbiBjaG9vc2UoaW1nLCB0aHVtYikgewogICAgICBjaG9zZW4gPSBpbWc7CiAgICAgIHByZXZpZXcuc3JjID0gbmV3IFVSTChpbWcudXJsLCBuZXcgVVJMKCcuLycsIGxvY2F0aW9uLmhyZWYpKS5ocmVmOwogICAgICBBcnJheS5wcm90b3R5cGUuZm9yRWFjaC5jYWxsKGdyaWQucXVlcnlTZWxlY3RvckFsbCgnLnBsLXRodW1iJyksIGZ1bmN0aW9uIChiKSB7IGIuc2V0QXR0cmlidXRlKCdhcmlhLXByZXNzZWQnLCBiID09PSB0aHVtYiA/ICd0cnVlJyA6ICdmYWxzZScpOyB9KTsKICAgIH0KCiAgICBmdW5jdGlvbiBhZGRUaHVtYihpbWcsIHByZXBlbmQpIHsKICAgICAgdmFyIGIgPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtdGh1bWInLCB0eXBlOiAnYnV0dG9uJywgdGl0bGU6IGltZy5uYW1lIHx8IGltZy51cmwsICdhcmlhLXByZXNzZWQnOiAnZmFsc2UnIH0sIFtoKCdpbWcnLCB7IHNyYzogaW1nLnVybCwgYWx0OiAnJywgbG9hZGluZzogJ2xhenknIH0pXSk7CiAgICAgIGIuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCBmdW5jdGlvbiAoKSB7IGNob29zZShpbWcsIGIpOyB9KTsKICAgICAgaWYgKHByZXBlbmQgJiYgZ3JpZC5maXJzdENoaWxkKSBncmlkLmluc2VydEJlZm9yZShiLCBncmlkLmZpcnN0Q2hpbGQpOyBlbHNlIGdyaWQuYXBwZW5kQ2hpbGQoYik7CiAgICAgIHJldHVybiBiOwogICAgfQoKICAgIGZ1bmN0aW9uIHVwbG9hZChmaWxlKSB7CiAgICAgIGlmICghZmlsZSkgcmV0dXJuOwogICAgICBwcm9ncmVzcy5oaWRkZW4gPSBmYWxzZTsKICAgICAgdXBsb2FkQnRuLmRpc2FibGVkID0gdHJ1ZTsKICAgICAgcHJlcGFyZUZpbGUoZmlsZSkudGhlbihmdW5jdGlvbiAoZikgewogICAgICAgIHZhciBmZCA9IG5ldyBGb3JtRGF0YSgpOwogICAgICAgIGZkLmFwcGVuZCgnZmlsZScsIGYsIGYubmFtZSk7CiAgICAgICAgZmQuYXBwZW5kKCd3aWR0aCcsIFN0cmluZyhNYXRoLnJvdW5kKG5vZGUuZ2V0Qm91bmRpbmdDbGllbnRSZWN0KCkud2lkdGgpKSk7CiAgICAgICAgcmV0dXJuIHJlcXVlc3QoJ3VwbG9hZCcsIHsgZm9ybTogZmQgfSk7CiAgICAgIH0pLnRoZW4oZnVuY3Rpb24gKHJlcykgewogICAgICAgIHZhciBlbXB0eSA9IGdyaWQucXVlcnlTZWxlY3RvcignLnBsLWVtcHR5Jyk7CiAgICAgICAgaWYgKGVtcHR5KSBlbXB0eS5yZW1vdmUoKTsKICAgICAgICByZXMubmFtZSA9IHJlcy51cmwuc3BsaXQoJy8nKS5wb3AoKTsKICAgICAgICB2YXIgYiA9IGFkZFRodW1iKHJlcywgdHJ1ZSk7CiAgICAgICAgY2hvb3NlKHJlcywgYik7CiAgICAgIH0pLmNhdGNoKGZ1bmN0aW9uIChlcnIpIHsKICAgICAgICB0b2FzdChlcnJvclRleHQoZXJyKSwgJ2Vycm9yJyk7CiAgICAgIH0pLnRoZW4oZnVuY3Rpb24gKCkgewogICAgICAgIHByb2dyZXNzLmhpZGRlbiA9IHRydWU7CiAgICAgICAgdXBsb2FkQnRuLmRpc2FibGVkID0gZmFsc2U7CiAgICAgICAgZmlsZUlucHV0LnZhbHVlID0gJyc7CiAgICAgIH0pOwogICAgfQoKICAgIGZpbGVJbnB1dC5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7IHVwbG9hZChmaWxlSW5wdXQuZmlsZXNbMF0pOyB9KTsKICAgIFsnZHJhZ2VudGVyJywgJ2RyYWdvdmVyJ10uZm9yRWFjaChmdW5jdGlvbiAoZXYpIHsgZHJvcC5hZGRFdmVudExpc3RlbmVyKGV2LCBmdW5jdGlvbiAoZSkgeyBlLnByZXZlbnREZWZhdWx0KCk7IGRyb3AuY2xhc3NMaXN0LmFkZCgnaXMtb3ZlcicpOyB9KTsgfSk7CiAgICBbJ2RyYWdsZWF2ZScsICdkcm9wJ10uZm9yRWFjaChmdW5jdGlvbiAoZXYpIHsgZHJvcC5hZGRFdmVudExpc3RlbmVyKGV2LCBmdW5jdGlvbiAoZSkgeyBlLnByZXZlbnREZWZhdWx0KCk7IGRyb3AuY2xhc3NMaXN0LnJlbW92ZSgnaXMtb3ZlcicpOyB9KTsgfSk7CiAgICBkcm9wLmFkZEV2ZW50TGlzdGVuZXIoJ2Ryb3AnLCBmdW5jdGlvbiAoZSkgeyBpZiAoZS5kYXRhVHJhbnNmZXIuZmlsZXNbMF0pIHVwbG9hZChlLmRhdGFUcmFuc2Zlci5maWxlc1swXSk7IH0pOwoKICAgIHJlcXVlc3QoJ21lZGlhJykudGhlbihmdW5jdGlvbiAocmVzKSB7CiAgICAgIGlmICghcmVzLmltYWdlcy5sZW5ndGgpIGdyaWQuYXBwZW5kQ2hpbGQoaCgncCcsIHsgY2xhc3M6ICdwbC1lbXB0eScsIHRleHQ6IHQoJ2ltZy5saWJyYXJ5LmVtcHR5JyksIHN0eWxlOiAnZ3JpZC1jb2x1bW46MS8tMScgfSkpOwogICAgICByZXMuaW1hZ2VzLmZvckVhY2goZnVuY3Rpb24gKGltZykgeyBhZGRUaHVtYihpbWcpOyB9KTsKICAgIH0pLmNhdGNoKGZ1bmN0aW9uICgpIHsgLyogbGlicmFyeSBpcyBvcHRpb25hbCAqLyB9KTsKCiAgICBhcHBseUJ0bi5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGZ1bmN0aW9uICgpIHsKICAgICAgdmFyIHNyYyA9IGNob3NlbiA/IHJlbEZyb21QYWdlKGNob3Nlbi51cmwpIDogbnVsbDsKICAgICAgaWYgKGtpbmQgPT09ICdpbWFnZScpIHsKICAgICAgICBpZiAoY2hvc2VuKSB7CiAgICAgICAgICBub2RlLnNldEF0dHJpYnV0ZSgnc3JjJywgc3JjKTsKICAgICAgICAgIG5vZGUucmVtb3ZlQXR0cmlidXRlKCdzcmNzZXQnKTsKICAgICAgICAgIG5vZGUucmVtb3ZlQXR0cmlidXRlKCdzaXplcycpOwogICAgICAgICAgbm9kZS5yZW1vdmVBdHRyaWJ1dGUoJ2RhdGEtc3JjJyk7CiAgICAgICAgICBub2RlLnJlbW92ZUF0dHJpYnV0ZSgnZGF0YS1zcmNzZXQnKTsKICAgICAgICAgIGlmIChub2RlLnBhcmVudE5vZGUgJiYgbm9kZS5wYXJlbnROb2RlLnRhZ05hbWUgPT09ICdQSUNUVVJFJykgewogICAgICAgICAgICBBcnJheS5wcm90b3R5cGUuZm9yRWFjaC5jYWxsKG5vZGUucGFyZW50Tm9kZS5xdWVyeVNlbGVjdG9yQWxsKCdzb3VyY2UnKSwgZnVuY3Rpb24gKHMpIHsgcy5yZW1vdmUoKTsgfSk7CiAgICAgICAgICB9CiAgICAgICAgICBzdGF0ZS5tZXRhW2tleV0gPSB7IHdpZHRoOiBjaG9zZW4ud2lkdGgsIGhlaWdodDogY2hvc2VuLmhlaWdodCB9OwogICAgICAgICAgaWYgKG5vZGUuaGFzQXR0cmlidXRlKCd3aWR0aCcpICYmIG5vZGUuaGFzQXR0cmlidXRlKCdoZWlnaHQnKSAmJiAvXlxkKyQvLnRlc3Qobm9kZS5nZXRBdHRyaWJ1dGUoJ3dpZHRoJykpICYmIGNob3Nlbi53aWR0aCkgewogICAgICAgICAgICBub2RlLnNldEF0dHJpYnV0ZSgnaGVpZ2h0JywgU3RyaW5nKE1hdGgubWF4KDEsIE1hdGgucm91bmQocGFyc2VJbnQobm9kZS5nZXRBdHRyaWJ1dGUoJ3dpZHRoJyksIDEwKSAqIGNob3Nlbi5oZWlnaHQgLyBjaG9zZW4ud2lkdGgpKSkpOwogICAgICAgICAgfQogICAgICAgIH0KICAgICAgICB2YXIgYWx0ID0gYWx0SW5wdXQudmFsdWUucmVwbGFjZSgvXHMrL2csICcgJykudHJpbSgpOwogICAgICAgIGlmIChhbHQgIT09IChub2RlLmdldEF0dHJpYnV0ZSgnYWx0JykgfHwgJycpKSBub2RlLnNldEF0dHJpYnV0ZSgnYWx0JywgYWx0KTsKICAgICAgfSBlbHNlIGlmIChjaG9zZW4pIHsKICAgICAgICBub2RlLnN0eWxlLmJhY2tncm91bmRJbWFnZSA9ICd1cmwoIicgKyBzcmMucmVwbGFjZSgvIi9nLCAnJTIyJykgKyAnIiknOwogICAgICB9CiAgICAgIGlmIChmb2N1cy54ICE9PSBzdGFydEZvY3VzLnggfHwgZm9jdXMueSAhPT0gc3RhcnRGb2N1cy55KSB7CiAgICAgICAgdmFyIHYgPSAoZm9jdXMueCA9PT0gNTAgJiYgZm9jdXMueSA9PT0gNTApID8gJycgOiBmb2N1cy54ICsgJyUgJyArIGZvY3VzLnkgKyAnJSc7CiAgICAgICAgaWYgKGtpbmQgPT09ICdpbWFnZScpIG5vZGUuc3R5bGUub2JqZWN0UG9zaXRpb24gPSB2OyBlbHNlIG5vZGUuc3R5bGUuYmFja2dyb3VuZFBvc2l0aW9uID0gdjsKICAgICAgICBpZiAoIW5vZGUuZ2V0QXR0cmlidXRlKCdzdHlsZScpKSBub2RlLnJlbW92ZUF0dHJpYnV0ZSgnc3R5bGUnKTsKICAgICAgfQogICAgICBwdXNoVW5kbyh7IGtleToga2V5LCBiZWZvcmU6IGJlZm9yZSwgYWZ0ZXI6IHNuYXBzaG90KG5vZGUpIH0pOwogICAgICByZWZyZXNoRGlydHkoa2V5KTsKICAgICAgY2xvc2VEcmF3ZXIoKTsKICAgIH0pOwoKICAgIHZhciBib2R5ID0gWwogICAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtZmllbGQnIH0sIFtoKCdzcGFuJywgeyB0ZXh0OiB0KCdpbWcuY3VycmVudCcpIH0pLCBjdXJyZW50LCBoKCdzbWFsbCcsIHsgdGV4dDogdCgnaW1nLmZvY3VzLmhpbnQnKSB9KSwgY3JvcHNdKSwKICAgICAgZHJvcCwKICAgICAgYWx0SW5wdXQgPyBoKCdsYWJlbCcsIHsgY2xhc3M6ICdwbC1maWVsZCcgfSwgW2goJ3NwYW4nLCB7IHRleHQ6IHQoJ2ltZy5hbHQnKSB9KSwgYWx0SW5wdXQsIGgoJ3NtYWxsJywgeyB0ZXh0OiB0KCdpbWcuYWx0LmhpbnQnKSB9KV0pIDogbnVsbCwKICAgICAgaCgnZGl2JywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnaW1nLmxpYnJhcnknKSB9KSwgZ3JpZF0pCiAgICBdOwogICAgb3BlbkRyYXdlcih0KGtpbmQgPT09ICdiZycgPyAnaW1nLmJnLnRpdGxlJyA6ICdpbWcudGl0bGUnKSwgYm9keSwgWwogICAgICBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtYnRuJywgdHlwZTogJ2J1dHRvbicsIHRleHQ6IHQoJ2ltZy5jYW5jZWwnKSwgb25jbGljazogZnVuY3Rpb24gKCkgeyBjbG9zZURyYXdlcigpOyB9IH0pLAogICAgICBhcHBseUJ0bgogICAgXSk7CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gdmlkZW8KICAvKiogU2FtZSBydWxlcyBhcyB0aGUgc2VydmVyICh3aGljaCBoYXMgdGhlIGxhc3Qgd29yZCk6IFlvdVR1YmUgYW5kIFZpbWVvIGxpbmtzIHRvIGVtYmVkIGFkZHJlc3Nlcy4gKi8KICBmdW5jdGlvbiBwYXJzZVZpZGVvKGlucHV0KSB7CiAgICB2YXIgdiA9IFN0cmluZyhpbnB1dCB8fCAnJykudHJpbSgpOwogICAgaWYgKCF2KSByZXR1cm4gbnVsbDsKICAgIGlmICghL15odHRwcz86XC9cLy9pLnRlc3QodikpIHYgPSAnaHR0cHM6Ly8nICsgdi5yZXBsYWNlKC9eXC9cLy8sICcnKTsKICAgIHZhciB1OwogICAgdHJ5IHsgdSA9IG5ldyBVUkwodik7IH0gY2F0Y2ggKGUpIHsgcmV0dXJuIG51bGw7IH0KICAgIHZhciBob3N0ID0gdS5ob3N0bmFtZS5yZXBsYWNlKC9eKHd3d1wufG1cLikvLCAnJykudG9Mb3dlckNhc2UoKTsKICAgIHZhciBtOwogICAgdmFyIHNlY3MgPSBmdW5jdGlvbiAoeCkgewogICAgICBpZiAoIXgpIHJldHVybiAwOwogICAgICBpZiAoL15cZCskLy50ZXN0KHgpKSByZXR1cm4gTWF0aC5taW4ocGFyc2VJbnQoeCwgMTApLCA4NjQwMCk7CiAgICAgIHZhciBwID0gL14oPzooXGQrKWgpPyg/OihcZCspbSk/KD86KFxkKylzKT8kLy5leGVjKHgpOwogICAgICByZXR1cm4gcCA/IE1hdGgubWluKCgrcFsxXSB8fCAwKSAqIDM2MDAgKyAoK3BbMl0gfHwgMCkgKiA2MCArICgrcFszXSB8fCAwKSwgODY0MDApIDogMDsKICAgIH07CiAgICB2YXIgc3RhcnQgPSBzZWNzKHUuc2VhcmNoUGFyYW1zLmdldCgndCcpIHx8IHUuc2VhcmNoUGFyYW1zLmdldCgnc3RhcnQnKSB8fCAoKG0gPSAvXiN0PShbMC05aG1zXSspJC8uZXhlYyh1Lmhhc2gpKSA/IG1bMV0gOiAnJykpOwogICAgdmFyIHl0RW1iZWQgPSBmdW5jdGlvbiAoaWQpIHsgcmV0dXJuICdodHRwczovL3d3dy55b3V0dWJlLW5vY29va2llLmNvbS9lbWJlZC8nICsgaWQgKyAoc3RhcnQgPyAnP3N0YXJ0PScgKyBzdGFydCA6ICcnKTsgfTsKICAgIGlmICgvXih5b3V0dWJlXC5jb218eW91dHViZS1ub2Nvb2tpZVwuY29tfG11c2ljXC55b3V0dWJlXC5jb20pJC8udGVzdChob3N0KSkgewogICAgICB2YXIgaWQgPSB1LnBhdGhuYW1lID09PSAnL3dhdGNoJyA/IHUuc2VhcmNoUGFyYW1zLmdldCgndicpIDogKChtID0gL15cLyhlbWJlZHxzaG9ydHN8bGl2ZXx2KVwvKFteLz9dKykvLmV4ZWModS5wYXRobmFtZSkpID8gbVsyXSA6IG51bGwpOwogICAgICByZXR1cm4gL15bQS1aYS16MC05Xy1dezExfSQvLnRlc3QoaWQgfHwgJycpID8geyBwcm92aWRlcjogJ3lvdXR1YmUnLCBpZDogaWQsIGVtYmVkOiB5dEVtYmVkKGlkKSB9IDogbnVsbDsKICAgIH0KICAgIGlmIChob3N0ID09PSAneW91dHUuYmUnKSB7CiAgICAgIHZhciB5aWQgPSB1LnBhdGhuYW1lLnNsaWNlKDEpOwogICAgICByZXR1cm4gL15bQS1aYS16MC05Xy1dezExfSQvLnRlc3QoeWlkKSA/IHsgcHJvdmlkZXI6ICd5b3V0dWJlJywgaWQ6IHlpZCwgZW1iZWQ6IHl0RW1iZWQoeWlkKSB9IDogbnVsbDsKICAgIH0KICAgIGlmICgoaG9zdCA9PT0gJ3ZpbWVvLmNvbScgfHwgaG9zdCA9PT0gJ3BsYXllci52aW1lby5jb20nKSAmJiAobSA9IC9cLyhcZHszLDEyfSkoPzpcLyhbMC05YS1mXXs2LDIwfSkpP1wvPyQvLmV4ZWModS5wYXRobmFtZSkpKSB7CiAgICAgIHZhciBoYXNoID0gbVsyXSB8fCB1LnNlYXJjaFBhcmFtcy5nZXQoJ2gnKSB8fCAnJzsKICAgICAgcmV0dXJuIHsgcHJvdmlkZXI6ICd2aW1lbycsIGlkOiBtWzFdLCBlbWJlZDogJ2h0dHBzOi8vcGxheWVyLnZpbWVvLmNvbS92aWRlby8nICsgbVsxXSArICc/JyArIChoYXNoID8gJ2g9JyArIGhhc2ggKyAnJicgOiAnJykgKyAnZG50PTEnICsgKHN0YXJ0ID8gJyN0PScgKyBzdGFydCArICdzJyA6ICcnKSB9OwogICAgfQogICAgcmV0dXJuIG51bGw7CiAgfQoKICBmdW5jdGlvbiBvcGVuVmlkZW9QYW5lbChub2RlLCBrZXkpIHsKICAgIHdhcm5TaGFyZWQobm9kZSk7CiAgICB2YXIgYmVmb3JlID0gc25hcHNob3Qobm9kZSk7CiAgICB2YXIgdXJsSW5wdXQgPSBoKCdpbnB1dCcsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHR5cGU6ICd1cmwnLCB2YWx1ZTogbm9kZS5nZXRBdHRyaWJ1dGUoJ3NyYycpIHx8ICcnLCBwbGFjZWhvbGRlcjogJ2h0dHBzOi8vd3d3LnlvdXR1YmUuY29tL3dhdGNoP3Y94oCmJywgc3BlbGxjaGVjazogJ2ZhbHNlJywgYXV0b2NhcGl0YWxpemU6ICdvZmYnIH0pOwogICAgdmFyIHRpdGxlSW5wdXQgPSBoKCdpbnB1dCcsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHR5cGU6ICd0ZXh0JywgdmFsdWU6IG5vZGUuZ2V0QXR0cmlidXRlKCd0aXRsZScpIHx8ICcnIH0pOwogICAgdmFyIHRodW1iID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLXZpZGVvLXRodW1iJyB9KTsKICAgIHZhciBub3RlID0gaCgncCcsIHsgY2xhc3M6ICdwbC1ub3RlJywgaGlkZGVuOiB0cnVlLCB0ZXh0OiB0KCdlcnIuYmFkLXZpZGVvJykgfSk7CiAgICBmdW5jdGlvbiBzaG93VGh1bWIoKSB7CiAgICAgIHZhciB2ID0gcGFyc2VWaWRlbyh1cmxJbnB1dC52YWx1ZSk7CiAgICAgIG5vdGUuaGlkZGVuID0gISF2IHx8ICF1cmxJbnB1dC52YWx1ZS50cmltKCk7CiAgICAgIHRodW1iLmlubmVySFRNTCA9ICcnOwogICAgICBpZiAodiAmJiB2LnByb3ZpZGVyID09PSAneW91dHViZScpIHsKICAgICAgICB0aHVtYi5hcHBlbmRDaGlsZChoKCdpbWcnLCB7IHNyYzogJ2h0dHBzOi8vaS55dGltZy5jb20vdmkvJyArIHYuaWQgKyAnL2hxZGVmYXVsdC5qcGcnLCBhbHQ6ICcnIH0pKTsKICAgICAgfSBlbHNlIGlmICh2KSB7CiAgICAgICAgdGh1bWIuYXBwZW5kQ2hpbGQoaCgnc3BhbicsIHsgdGV4dDogJ1ZpbWVvIMK3ICcgKyB2LmlkIH0pKTsKICAgICAgfQogICAgICBpZiAodikgdGh1bWIuYXBwZW5kQ2hpbGQoaCgnc3BhbicsIHsgY2xhc3M6ICdwbC12aWRlby1wbGF5JywgaHRtbDogaWNvbigncGxheScpIH0pKTsKICAgIH0KICAgIHVybElucHV0LmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0Jywgc2hvd1RodW1iKTsKICAgIGZ1bmN0aW9uIGFwcGx5KCkgewogICAgICB2YXIgdiA9IHBhcnNlVmlkZW8odXJsSW5wdXQudmFsdWUpOwogICAgICBpZiAoIXYpIHsgbm90ZS5oaWRkZW4gPSBmYWxzZTsgdXJsSW5wdXQuZm9jdXMoKTsgcmV0dXJuOyB9CiAgICAgIGlmIChub2RlLmdldEF0dHJpYnV0ZSgnc3JjJykgIT09IHYuZW1iZWQpIG5vZGUuc2V0QXR0cmlidXRlKCdzcmMnLCB2LmVtYmVkKTsKICAgICAgdmFyIHRpdGxlID0gdGl0bGVJbnB1dC52YWx1ZS5yZXBsYWNlKC9ccysvZywgJyAnKS50cmltKCk7CiAgICAgIGlmICh0aXRsZSAhPT0gKG5vZGUuZ2V0QXR0cmlidXRlKCd0aXRsZScpIHx8ICcnKSkgeyBpZiAodGl0bGUpIG5vZGUuc2V0QXR0cmlidXRlKCd0aXRsZScsIHRpdGxlKTsgZWxzZSBub2RlLnJlbW92ZUF0dHJpYnV0ZSgndGl0bGUnKTsgfQogICAgICBwdXNoVW5kbyh7IGtleToga2V5LCBiZWZvcmU6IGJlZm9yZSwgYWZ0ZXI6IHNuYXBzaG90KG5vZGUpIH0pOwogICAgICByZWZyZXNoRGlydHkoa2V5KTsKICAgICAgY2xvc2VEcmF3ZXIoKTsKICAgIH0KICAgIFt1cmxJbnB1dCwgdGl0bGVJbnB1dF0uZm9yRWFjaChmdW5jdGlvbiAoaW5wKSB7CiAgICAgIGlucC5hZGRFdmVudExpc3RlbmVyKCdrZXlkb3duJywgZnVuY3Rpb24gKGUpIHsgaWYgKGUua2V5ID09PSAnRW50ZXInKSB7IGUucHJldmVudERlZmF1bHQoKTsgYXBwbHkoKTsgfSB9KTsKICAgIH0pOwogICAgb3BlbkRyYXdlcih0KCd2aWRlby50aXRsZScpLCBbCiAgICAgIHRodW1iLAogICAgICBoKCdsYWJlbCcsIHsgY2xhc3M6ICdwbC1maWVsZCcgfSwgW2goJ3NwYW4nLCB7IHRleHQ6IHQoJ3ZpZGVvLnVybCcpIH0pLCB1cmxJbnB1dCwgaCgnc21hbGwnLCB7IHRleHQ6IHQoJ3ZpZGVvLnVybC5oaW50JykgfSldKSwKICAgICAgbm90ZSwKICAgICAgaCgnbGFiZWwnLCB7IGNsYXNzOiAncGwtZmllbGQnIH0sIFtoKCdzcGFuJywgeyB0ZXh0OiB0KCd2aWRlby5sYWJlbCcpIH0pLCB0aXRsZUlucHV0LCBoKCdzbWFsbCcsIHsgdGV4dDogdCgndmlkZW8ubGFiZWwuaGludCcpIH0pXSkKICAgIF0sIFsKICAgICAgaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0bicsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdpbWcuY2FuY2VsJyksIG9uY2xpY2s6IGZ1bmN0aW9uICgpIHsgY2xvc2VEcmF3ZXIoKTsgfSB9KSwKICAgICAgaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0biBwbC1idG4tcHJpbWFyeScsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdpbWcuYXBwbHknKSwgb25jbGljazogYXBwbHkgfSkKICAgIF0pOwogICAgc2hvd1RodW1iKCk7CiAgICB1cmxJbnB1dC5mb2N1cygpOwogICAgdXJsSW5wdXQuc2VsZWN0KCk7CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gcGFnZSBzZXR0aW5ncyAoU0VPIGFuZCBzaGFyaW5nKQogIGZ1bmN0aW9uIGFic29sdXRlSW1hZ2UodikgewogICAgaWYgKCF2KSByZXR1cm4gJyc7CiAgICBpZiAoL15odHRwcz86XC9cLy9pLnRlc3QodikpIHJldHVybiB2OwogICAgcmV0dXJuICgoc3RhdGUuaW5mbyAmJiBzdGF0ZS5pbmZvLnNpdGVVcmwpIHx8IG5ldyBVUkwoJy4vJywgbG9jYXRpb24uaHJlZikuaHJlZikgKyB2LnJlcGxhY2UoL15cLy8sICcnKTsKICB9CgogIGZ1bmN0aW9uIGNvdW50ZXIoaW5wdXQsIGlkZWFsKSB7CiAgICB2YXIgYyA9IGgoJ3NtYWxsJywgeyBjbGFzczogJ3BsLWNvdW50JyB9KTsKICAgIGZ1bmN0aW9uIHVwZCgpIHsKICAgICAgdmFyIG4gPSBpbnB1dC52YWx1ZS5sZW5ndGg7CiAgICAgIGMudGV4dENvbnRlbnQgPSB0KCdtZXRhLmNvdW50JywgeyBuOiBuLCBpZGVhbDogaWRlYWwgfSk7CiAgICAgIGMuY2xhc3NMaXN0LnRvZ2dsZSgnaXMtbG9uZycsIG4gPiBpZGVhbCk7CiAgICB9CiAgICBpbnB1dC5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIHVwZCk7CiAgICB1cGQoKTsKICAgIHJldHVybiBjOwogIH0KCiAgZnVuY3Rpb24gb3BlblBhZ2VTZXR0aW5ncygpIHsKICAgIHN0b3BFZGl0KCk7CiAgICB2YXIgcG0gPSBzdGF0ZS5wYWdlTWV0YTsKICAgIGlmICghcG0pIHsgdG9hc3QodCgnZXJyLmdlbmVyaWMnKSwgJ2Vycm9yJyk7IHJldHVybjsgfQogICAgdmFyIGN1ciA9IHBtLmN1cjsKICAgIHZhciBpbWFnZSA9IGN1ci5pbWFnZTsKICAgIHZhciB0aXRsZSA9IGgoJ2lucHV0JywgeyBjbGFzczogJ3BsLWlucHV0JywgdHlwZTogJ3RleHQnLCB2YWx1ZTogY3VyLnRpdGxlLCBtYXhsZW5ndGg6ICcyMDAnIH0pOwogICAgdmFyIGRlc2MgPSBoKCd0ZXh0YXJlYScsIHsgY2xhc3M6ICdwbC1pbnB1dCcsIHJvd3M6ICczJywgbWF4bGVuZ3RoOiAnNDAwJyB9KTsKICAgIGRlc2MudmFsdWUgPSBjdXIuZGVzY3JpcHRpb247CiAgICB2YXIgc2hhcmVUaXRsZSA9IGgoJ2lucHV0JywgeyBjbGFzczogJ3BsLWlucHV0JywgdHlwZTogJ3RleHQnLCB2YWx1ZTogY3VyLnNoYXJlVGl0bGUsIG1heGxlbmd0aDogJzIwMCcsIHBsYWNlaG9sZGVyOiBjdXIudGl0bGUgfSk7CiAgICB0aXRsZS5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIGZ1bmN0aW9uICgpIHsgc2hhcmVUaXRsZS5wbGFjZWhvbGRlciA9IHRpdGxlLnZhbHVlOyBjYXJkKCk7IH0pOwoKICAgIHZhciBob3N0ID0gJyc7CiAgICB0cnkgeyBob3N0ID0gbmV3IFVSTCgoc3RhdGUuaW5mbyAmJiBzdGF0ZS5pbmZvLnNpdGVVcmwpIHx8IGxvY2F0aW9uLmhyZWYpLmhvc3Q7IH0gY2F0Y2ggKGUpIHsgLyogaWdub3JlICovIH0KICAgIHZhciBjSW1nID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLXNoYXJlLWltZycgfSk7CiAgICB2YXIgY1RpdGxlID0gaCgnYicpOwogICAgdmFyIGNEZXNjID0gaCgnc3BhbicpOwogICAgdmFyIHNoYXJlQ2FyZCA9IGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1zaGFyZS1jYXJkJywgJ2FyaWEtaGlkZGVuJzogJ3RydWUnIH0sIFtjSW1nLCBoKCdkaXYnLCB7IGNsYXNzOiAncGwtc2hhcmUtdGV4dCcgfSwgW2goJ3NtYWxsJywgeyB0ZXh0OiBob3N0IH0pLCBjVGl0bGUsIGNEZXNjXSldKTsKICAgIGZ1bmN0aW9uIGNhcmQoKSB7CiAgICAgIGNJbWcuc3R5bGUuYmFja2dyb3VuZEltYWdlID0gaW1hZ2UgPyAndXJsKCInICsgYWJzb2x1dGVJbWFnZShpbWFnZSkucmVwbGFjZSgvIi9nLCAnJTIyJykgKyAnIiknIDogJyc7CiAgICAgIGNJbWcuY2xhc3NMaXN0LnRvZ2dsZSgnaXMtZW1wdHknLCAhaW1hZ2UpOwogICAgICBjSW1nLnRleHRDb250ZW50ID0gaW1hZ2UgPyAnJyA6IHQoJ21ldGEubm9pbWFnZScpOwogICAgICBjVGl0bGUudGV4dENvbnRlbnQgPSBzaGFyZVRpdGxlLnZhbHVlLnRyaW0oKSB8fCB0aXRsZS52YWx1ZS50cmltKCkgfHwgdCgnbWV0YS51bnRpdGxlZCcpOwogICAgICBjRGVzYy50ZXh0Q29udGVudCA9IGRlc2MudmFsdWUudHJpbSgpOwogICAgfQogICAgW2Rlc2MsIHNoYXJlVGl0bGVdLmZvckVhY2goZnVuY3Rpb24gKGVsKSB7IGVsLmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0JywgY2FyZCk7IH0pOwoKICAgIHZhciBmaWxlSW5wdXQgPSBoKCdpbnB1dCcsIHsgdHlwZTogJ2ZpbGUnLCBhY2NlcHQ6ICdpbWFnZS9qcGVnLGltYWdlL3BuZyxpbWFnZS93ZWJwJywgY2xhc3M6ICdwbC1zcicgfSk7CiAgICB2YXIgcHJvZ3Jlc3MgPSBoKCdkaXYnLCB7IGNsYXNzOiAncGwtcHJvZ3Jlc3MnLCBoaWRkZW46IHRydWUgfSwgW2goJ2knKV0pOwogICAgdmFyIHVwbG9hZEJ0biA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC1idG4nLCB0eXBlOiAnYnV0dG9uJywgaHRtbDogaWNvbigndXBsb2FkJyksIG9uY2xpY2s6IGZ1bmN0aW9uICgpIHsgZmlsZUlucHV0LmNsaWNrKCk7IH0gfSk7CiAgICB1cGxvYWRCdG4uYXBwZW5kQ2hpbGQoZG9jdW1lbnQuY3JlYXRlVGV4dE5vZGUodCgnaW1nLnVwbG9hZCcpKSk7CiAgICB2YXIgcmVtb3ZlQnRuID0gaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0biBwbC1idG4tZ2hvc3QgcGwtYnRuLWRhbmdlcicsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdtZXRhLmltYWdlLnJlbW92ZScpLCBvbmNsaWNrOiBmdW5jdGlvbiAoKSB7IGltYWdlID0gJyc7IGNhcmQoKTsgcmVtb3ZlQnRuLmhpZGRlbiA9IHRydWU7IH0gfSk7CiAgICByZW1vdmVCdG4uaGlkZGVuID0gIWltYWdlOwogICAgdmFyIGdyaWQgPSBoKCdkaXYnLCB7IGNsYXNzOiAncGwtZ3JpZCcgfSk7CiAgICBmdW5jdGlvbiBwaWNrKHVybCkgeyBpbWFnZSA9IHVybDsgY2FyZCgpOyByZW1vdmVCdG4uaGlkZGVuID0gZmFsc2U7IH0KICAgIGZpbGVJbnB1dC5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLCBmdW5jdGlvbiAoKSB7CiAgICAgIHZhciBmaWxlID0gZmlsZUlucHV0LmZpbGVzWzBdOwogICAgICBpZiAoIWZpbGUpIHJldHVybjsKICAgICAgcHJvZ3Jlc3MuaGlkZGVuID0gZmFsc2U7CiAgICAgIHVwbG9hZEJ0bi5kaXNhYmxlZCA9IHRydWU7CiAgICAgIHByZXBhcmVGaWxlKGZpbGUpLnRoZW4oZnVuY3Rpb24gKGYpIHsKICAgICAgICB2YXIgZmQgPSBuZXcgRm9ybURhdGEoKTsKICAgICAgICBmZC5hcHBlbmQoJ2ZpbGUnLCBmLCBmLm5hbWUpOwogICAgICAgIGZkLmFwcGVuZCgnd2lkdGgnLCAnNjAwJyk7CiAgICAgICAgZmQuYXBwZW5kKCdmb3JtYXQnLCAnanBlZycpOwogICAgICAgIHJldHVybiByZXF1ZXN0KCd1cGxvYWQnLCB7IGZvcm06IGZkIH0pOwogICAgICB9KS50aGVuKGZ1bmN0aW9uIChyZXMpIHsgcGljayhyZXMudXJsKTsgfSkuY2F0Y2goZnVuY3Rpb24gKGVycikgeyB0b2FzdChlcnJvclRleHQoZXJyKSwgJ2Vycm9yJyk7IH0pLnRoZW4oZnVuY3Rpb24gKCkgewogICAgICAgIHByb2dyZXNzLmhpZGRlbiA9IHRydWU7CiAgICAgICAgdXBsb2FkQnRuLmRpc2FibGVkID0gZmFsc2U7CiAgICAgICAgZmlsZUlucHV0LnZhbHVlID0gJyc7CiAgICAgIH0pOwogICAgfSk7CiAgICByZXF1ZXN0KCdtZWRpYScpLnRoZW4oZnVuY3Rpb24gKHJlcykgewogICAgICByZXMuaW1hZ2VzLnNsaWNlKDAsIDEyKS5mb3JFYWNoKGZ1bmN0aW9uIChpbWcpIHsKICAgICAgICB2YXIgYiA9IGgoJ2J1dHRvbicsIHsgY2xhc3M6ICdwbC10aHVtYicsIHR5cGU6ICdidXR0b24nLCB0aXRsZTogaW1nLm5hbWUgfSwgW2goJ2ltZycsIHsgc3JjOiBpbWcudXJsLCBhbHQ6ICcnLCBsb2FkaW5nOiAnbGF6eScgfSldKTsKICAgICAgICBiLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZnVuY3Rpb24gKCkgeyBwaWNrKGltZy51cmwpOyB9KTsKICAgICAgICBncmlkLmFwcGVuZENoaWxkKGIpOwogICAgICB9KTsKICAgIH0pLmNhdGNoKGZ1bmN0aW9uICgpIHt9KTsKICAgIGNhcmQoKTsKCiAgICBmdW5jdGlvbiBhcHBseSgpIHsKICAgICAgdmFyIGJlZm9yZSA9IE9iamVjdC5hc3NpZ24oe30sIHBtLmN1cik7CiAgICAgIHBtLmN1ciA9IHsKICAgICAgICB0aXRsZTogdGl0bGUudmFsdWUucmVwbGFjZSgvXHMrL2csICcgJykudHJpbSgpLAogICAgICAgIGRlc2NyaXB0aW9uOiBkZXNjLnZhbHVlLnJlcGxhY2UoL1xzKy9nLCAnICcpLnRyaW0oKSwKICAgICAgICBzaGFyZVRpdGxlOiBzaGFyZVRpdGxlLnZhbHVlLnJlcGxhY2UoL1xzKy9nLCAnICcpLnRyaW0oKSwKICAgICAgICBpbWFnZTogaW1hZ2UKICAgICAgfTsKICAgICAgaWYgKEpTT04uc3RyaW5naWZ5KGJlZm9yZSkgIT09IEpTT04uc3RyaW5naWZ5KHBtLmN1cikpIHsKICAgICAgICBzdGF0ZS51bmRvLnB1c2goeyB0eXBlOiAnbWV0YScsIGJlZm9yZTogYmVmb3JlLCBhZnRlcjogT2JqZWN0LmFzc2lnbih7fSwgcG0uY3VyKSB9KTsKICAgICAgICBzdGF0ZS5yZWRvID0gW107CiAgICAgIH0KICAgICAgdXBkYXRlU3RhdHVzKCk7CiAgICAgIGNsb3NlRHJhd2VyKCk7CiAgICB9CgogICAgb3BlbkRyYXdlcih0KCdtZXRhLnRpdGxlJyksIFsKICAgICAgaCgncCcsIHsgY2xhc3M6ICdwbC1tdXRlZCcsIHRleHQ6IHQoJ21ldGEuaW50cm8nKSwgc3R5bGU6ICdtYXJnaW46MCcgfSksCiAgICAgIGgoJ2xhYmVsJywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnbWV0YS5wYWdlLnRpdGxlJykgfSksIHRpdGxlLCBjb3VudGVyKHRpdGxlLCA2MCksIGgoJ3NtYWxsJywgeyB0ZXh0OiB0KCdtZXRhLnBhZ2UudGl0bGUuaGludCcpIH0pXSksCiAgICAgIGgoJ2xhYmVsJywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnbWV0YS5kZXNjcmlwdGlvbicpIH0pLCBkZXNjLCBjb3VudGVyKGRlc2MsIDE1NSksIGgoJ3NtYWxsJywgeyB0ZXh0OiB0KCdtZXRhLmRlc2NyaXB0aW9uLmhpbnQnKSB9KV0pLAogICAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtZmllbGQnIH0sIFtoKCdzcGFuJywgeyB0ZXh0OiB0KCdtZXRhLnByZXZpZXcnKSB9KSwgc2hhcmVDYXJkXSksCiAgICAgIGgoJ2xhYmVsJywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnbWV0YS5zaGFyZS50aXRsZScpIH0pLCBzaGFyZVRpdGxlLCBoKCdzbWFsbCcsIHsgdGV4dDogdCgnbWV0YS5zaGFyZS50aXRsZS5oaW50JykgfSldKSwKICAgICAgaCgnZGl2JywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbCiAgICAgICAgaCgnc3BhbicsIHsgdGV4dDogdCgnbWV0YS5pbWFnZScpIH0pLAogICAgICAgIGgoJ2RpdicsIHsgY2xhc3M6ICdwbC1yb3cnIH0sIFt1cGxvYWRCdG4sIHJlbW92ZUJ0bl0pLAogICAgICAgIHByb2dyZXNzLCBmaWxlSW5wdXQsCiAgICAgICAgaCgnc21hbGwnLCB7IHRleHQ6IHQoJ21ldGEuaW1hZ2UuaGludCcpIH0pLAogICAgICAgIGdyaWQKICAgICAgXSkKICAgIF0sIFsKICAgICAgaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0bicsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdpbWcuY2FuY2VsJyksIG9uY2xpY2s6IGZ1bmN0aW9uICgpIHsgY2xvc2VEcmF3ZXIoKTsgfSB9KSwKICAgICAgaCgnYnV0dG9uJywgeyBjbGFzczogJ3BsLWJ0biBwbC1idG4tcHJpbWFyeScsIHR5cGU6ICdidXR0b24nLCB0ZXh0OiB0KCdpbWcuYXBwbHknKSwgb25jbGljazogYXBwbHkgfSkKICAgIF0pOwogICAgdGl0bGUuZm9jdXMoKTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSBuZXcgcGFnZQogIGZ1bmN0aW9uIHNsdWdOYW1lKHYpIHsKICAgIHZhciBzID0gU3RyaW5nKHYgfHwgJycpLm5vcm1hbGl6ZSgnTkZEJykucmVwbGFjZSgvW1x1MDMwMC1cdTAzNmZdL2csICcnKS50b0xvd2VyQ2FzZSgpCiAgICAgIC5yZXBsYWNlKC9cLmh0bWw/JC8sICcnKS5yZXBsYWNlKC9bXmEtejAtOV8tXSsvZywgJy0nKS5yZXBsYWNlKC9eLSt8LSskL2csICcnKS5zbGljZSgwLCA4MCk7CiAgICByZXR1cm4gcyA/IHMgKyAnLmh0bWwnIDogJyc7CiAgfQoKICBmdW5jdGlvbiBmaWxsUGFnZXMoKSB7CiAgICBwYWdlU2VsZWN0LmlubmVySFRNTCA9ICcnOwogICAgY2ZnLnBhZ2VzLmZvckVhY2goZnVuY3Rpb24gKHApIHsKICAgICAgdmFyIHJvID0gY2ZnLnJlYWRvbmx5LmluZGV4T2YocCkgIT09IC0xOwogICAgICBwYWdlU2VsZWN0LmFwcGVuZENoaWxkKGgoJ29wdGlvbicsIHsgdmFsdWU6IHAsIHRleHQ6IHAgKyAocm8gPyAnIChyZWFkLW9ubHkpJyA6ICcnKSB9KSk7CiAgICB9KTsKICAgIGlmIChzdGF0ZS5wYWdlKSBwYWdlU2VsZWN0LnZhbHVlID0gc3RhdGUucGFnZTsKICB9CgogIGZ1bmN0aW9uIG9wZW5OZXdQYWdlKCkgewogICAgdmFyIGZyb20gPSBzdGF0ZS5wYWdlOwogICAgdmFyIGRpciA9IHBhZ2VEaXIoKTsKICAgIHZhciBpbnB1dCA9IGgoJ2lucHV0JywgeyBjbGFzczogJ3BsLWlucHV0JywgdHlwZTogJ3RleHQnLCBwbGFjZWhvbGRlcjogdCgnbmV3cGFnZS5wbGFjZWhvbGRlcicpLCBzcGVsbGNoZWNrOiAnZmFsc2UnLCBhdXRvY2FwaXRhbGl6ZTogJ29mZicgfSk7CiAgICB2YXIgcHJldmlldyA9IGgoJ3NtYWxsJywgeyBjbGFzczogJ3BsLW11dGVkJyB9KTsKICAgIGZ1bmN0aW9uIHVwZCgpIHsgdmFyIG4gPSBzbHVnTmFtZShpbnB1dC52YWx1ZSk7IHByZXZpZXcudGV4dENvbnRlbnQgPSBuID8gdCgnbmV3cGFnZS5maWxlJywgeyBmaWxlOiBkaXIgKyBuIH0pIDogJyc7IH0KICAgIGlucHV0LmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0JywgdXBkKTsKICAgIHZhciBleHRyYSA9IGgoJ2xhYmVsJywgeyBjbGFzczogJ3BsLWZpZWxkJyB9LCBbaCgnc3BhbicsIHsgdGV4dDogdCgnbmV3cGFnZS5uYW1lJykgfSksIGlucHV0LCBwcmV2aWV3XSk7CiAgICB2YXIgZ28gPSBmdW5jdGlvbiAoKSB7CiAgICAgIG1vZGFsKHsgdGl0bGU6IHQoJ25ld3BhZ2UudGl0bGUnKSwgYm9keTogdCgnbmV3cGFnZS5pbnRybycsIHsgcGFnZTogZnJvbSB9KSwgZXh0cmE6IGV4dHJhLCBhY3Rpb25zOiBbCiAgICAgICAgeyBsYWJlbDogdCgnbGluay5jYW5jZWwnKSwgdmFsdWU6IGZhbHNlIH0sCiAgICAgICAgeyBsYWJlbDogdCgnbmV3cGFnZS5jcmVhdGUnKSwgdmFsdWU6IHRydWUsIHByaW1hcnk6IHRydWUgfQogICAgICBdIH0pLnRoZW4oZnVuY3Rpb24gKG9rKSB7CiAgICAgICAgaWYgKCFvaykgcmV0dXJuOwogICAgICAgIHZhciBuYW1lID0gc2x1Z05hbWUoaW5wdXQudmFsdWUpOwogICAgICAgIGlmICghbmFtZSkgeyB0b2FzdCh0KCdlcnIuYmFkLXBhZ2UtbmFtZScpLCAnZXJyb3InKTsgcmV0dXJuOyB9CiAgICAgICAgcmVxdWVzdCgnbmV3cGFnZScsIHsganNvbjogeyBmcm9tOiBmcm9tLCBuYW1lOiBuYW1lIH0gfSkudGhlbihmdW5jdGlvbiAocmVzKSB7CiAgICAgICAgICBjZmcucGFnZXMgPSByZXMucGFnZXM7CiAgICAgICAgICBmaWxsUGFnZXMoKTsKICAgICAgICAgIHRvYXN0KHQoJ25ld3BhZ2UuY3JlYXRlZCcsIHsgZmlsZTogcmVzLnBhZ2UgfSksICdvaycpOwogICAgICAgICAgb3BlblNldHRpbmdzQWZ0ZXJMb2FkID0gdHJ1ZTsKICAgICAgICAgIHN0YXRlLmRpcnR5ID0ge307CiAgICAgICAgICBsb2FkUGFnZShyZXMucGFnZSk7CiAgICAgICAgfSkuY2F0Y2goZnVuY3Rpb24gKGVycikgeyB0b2FzdChlcnJvclRleHQoZXJyKSwgJ2Vycm9yJyk7IH0pOwogICAgICB9KTsKICAgICAgc2V0VGltZW91dChmdW5jdGlvbiAoKSB7IGlucHV0LmZvY3VzKCk7IH0sIDMwKTsKICAgICAgaW5wdXQuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsIGZ1bmN0aW9uIChlKSB7CiAgICAgICAgaWYgKGUua2V5ID09PSAnRW50ZXInKSB7IGUucHJldmVudERlZmF1bHQoKTsgdmFyIGIgPSBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKCcucGwtbW9kYWwgLnBsLWJ0bi1wcmltYXJ5Jyk7IGlmIChiKSBiLmNsaWNrKCk7IH0KICAgICAgfSk7CiAgICB9OwogICAgaWYgKGRpcnR5Q291bnQoKSB8fCBzdGF0ZS5lZGl0aW5nKSB7CiAgICAgIGNvbmZpcm1Cb3godCgnYmFyLnVuc2F2ZWQnKSwgdCgnY29uZmlybS5sZWF2ZScpLCB0KCdjb25maXJtLmRpc2NhcmQnKSwgdHJ1ZSkudGhlbihmdW5jdGlvbiAob2spIHsgaWYgKG9rKSBnbygpOyB9KTsKICAgIH0gZWxzZSB7CiAgICAgIGdvKCk7CiAgICB9CiAgfQoKICAvLyAtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0gdW5kbyAvIHJlZG8KICBmdW5jdGlvbiB1bmRvKCkgewogICAgaWYgKHN0YXRlLmVkaXRpbmcpIHsKICAgICAgLy8gaW5zaWRlIGEgdGV4dCBlZGl0OiB1bmRvIHRoZSB3aG9sZSBlZGl0IG9mIHRoYXQgZWxlbWVudAogICAgICB2YXIgZWQgPSBzdGF0ZS5lZGl0aW5nOwogICAgICBzdG9wRWRpdCgpOwogICAgICBpZiAoIXN0YXRlLnVuZG8ubGVuZ3RoIHx8IHN0YXRlLnVuZG9bc3RhdGUudW5kby5sZW5ndGggLSAxXS5rZXkgIT09IGVkLmtleSkgcmV0dXJuOwogICAgfQogICAgdmFyIGVudHJ5ID0gc3RhdGUudW5kby5wb3AoKTsKICAgIGlmICghZW50cnkpIHJldHVybjsKICAgIHN0YXRlLnJlZG8ucHVzaChlbnRyeSk7CiAgICBpZiAoZW50cnkudHlwZSA9PT0gJ21ldGEnKSB7CiAgICAgIGlmIChzdGF0ZS5wYWdlTWV0YSkgc3RhdGUucGFnZU1ldGEuY3VyID0gT2JqZWN0LmFzc2lnbih7fSwgZW50cnkuYmVmb3JlKTsKICAgICAgY2xvc2VEcmF3ZXIoKTsKICAgICAgdXBkYXRlU3RhdHVzKCk7CiAgICAgIHJldHVybjsKICAgIH0KICAgIGlmIChlbnRyeS50eXBlID09PSAnb3JkZXInKSB7CiAgICAgIGNsb3NlRHJhd2VyKCk7CiAgICAgIGhpZGVJdGVtQmFyKHRydWUpOwogICAgICBzZXRPcmRlcihzdGF0ZS5ncm91cHNbZW50cnkuZ3JvdXBdLCBlbnRyeS5iZWZvcmUpOwogICAgICB1cGRhdGVTdGF0dXMoKTsKICAgICAgcmV0dXJuOwogICAgfQogICAgdmFyIG5vZGUgPSBub2RlRm9yKGVudHJ5LmtleSk7CiAgICBpZiAobm9kZSkgcmVzdG9yZShub2RlLCBlbnRyeS5iZWZvcmUpOwogICAgcmVmcmVzaERpcnR5KGVudHJ5LmtleSk7CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9CgogIGZ1bmN0aW9uIHJlZG8oKSB7CiAgICB2YXIgZW50cnkgPSBzdGF0ZS5yZWRvLnBvcCgpOwogICAgaWYgKCFlbnRyeSkgcmV0dXJuOwogICAgc3RvcEVkaXQoKTsKICAgIHN0YXRlLnVuZG8ucHVzaChlbnRyeSk7CiAgICBpZiAoZW50cnkudHlwZSA9PT0gJ21ldGEnKSB7CiAgICAgIGlmIChzdGF0ZS5wYWdlTWV0YSkgc3RhdGUucGFnZU1ldGEuY3VyID0gT2JqZWN0LmFzc2lnbih7fSwgZW50cnkuYWZ0ZXIpOwogICAgICBjbG9zZURyYXdlcigpOwogICAgICB1cGRhdGVTdGF0dXMoKTsKICAgICAgcmV0dXJuOwogICAgfQogICAgaWYgKGVudHJ5LnR5cGUgPT09ICdvcmRlcicpIHsKICAgICAgY2xvc2VEcmF3ZXIoKTsKICAgICAgaGlkZUl0ZW1CYXIodHJ1ZSk7CiAgICAgIHNldE9yZGVyKHN0YXRlLmdyb3Vwc1tlbnRyeS5ncm91cF0sIGVudHJ5LmFmdGVyKTsKICAgICAgdXBkYXRlU3RhdHVzKCk7CiAgICAgIHJldHVybjsKICAgIH0KICAgIHZhciBub2RlID0gbm9kZUZvcihlbnRyeS5rZXkpOwogICAgaWYgKG5vZGUpIHJlc3RvcmUobm9kZSwgZW50cnkuYWZ0ZXIpOwogICAgcmVmcmVzaERpcnR5KGVudHJ5LmtleSk7CiAgICB1cGRhdGVTdGF0dXMoKTsKICB9CgogIC8vIC0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLSBzYXZlCiAgZnVuY3Rpb24gY2xlYW5Jbm5lcihub2RlKSB7CiAgICB2YXIgY2xvbmUgPSBub2RlLmNsb25lTm9kZSh0cnVlKTsKICAgIEFycmF5LnByb3RvdHlwZS5mb3JFYWNoLmNhbGwoY2xvbmUucXVlcnlTZWxlY3RvckFsbCgnW2NvbnRlbnRlZGl0YWJsZV0sW3NwZWxsY2hlY2tdLFtkYXRhLXBsdW1hLWRpcnR5XScpLCBmdW5jdGlvbiAoZWwpIHsKICAgICAgZWwucmVtb3ZlQXR0cmlidXRlKCdjb250ZW50ZWRpdGFibGUnKTsKICAgICAgZWwucmVtb3ZlQXR0cmlidXRlKCdzcGVsbGNoZWNrJyk7CiAgICAgIGVsLnJlbW92ZUF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1kaXJ0eScpOwogICAgfSk7CiAgICB2YXIgaHRtbCA9IGNsb25lLmlubmVySFRNTDsKICAgIGlmIChodG1sID09PSAnPGJyPicpIGh0bWwgPSAnJzsKICAgIHJldHVybiBodG1sOwogIH0KCiAgLyoqIE9uZSBkZWNsYXJhdGlvbiBvZiBhIHN0eWxlIGF0dHJpYnV0ZSwgYXMgdGhlIGJyb3dzZXIgcmVhZHMgaXQgKCcnIHdoZW4gYWJzZW50KS4gKi8KICBmdW5jdGlvbiBzdHlsZVZhbHVlKHN0eWxlVGV4dCwgcHJvcCkgewogICAgdmFyIGVsID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7CiAgICBlbC5zZXRBdHRyaWJ1dGUoJ3N0eWxlJywgc3R5bGVUZXh0IHx8ICcnKTsKICAgIHJldHVybiBlbC5zdHlsZVtwcm9wXSB8fCAnJzsKICB9CgogIGZ1bmN0aW9uIHBvc1RvRm9jdXModikgewogICAgdmFyIHdvcmRzID0geyBsZWZ0OiAwLCB0b3A6IDAsIGNlbnRlcjogNTAsIHJpZ2h0OiAxMDAsIGJvdHRvbTogMTAwIH07CiAgICB2YXIgcGFydHMgPSBTdHJpbmcodiB8fCAnJykudHJpbSgpLnNwbGl0KC9ccysvKTsKICAgIHZhciBudW0gPSBmdW5jdGlvbiAocCwgZCkgewogICAgICBpZiAocCA9PT0gdW5kZWZpbmVkKSByZXR1cm4gZDsKICAgICAgaWYgKE9iamVjdC5wcm90b3R5cGUuaGFzT3duUHJvcGVydHkuY2FsbCh3b3JkcywgcCkpIHJldHVybiB3b3Jkc1twXTsKICAgICAgdmFyIG4gPSBwYXJzZUZsb2F0KHApOwogICAgICByZXR1cm4gaXNOYU4obikgPyBkIDogbjsKICAgIH07CiAgICB2YXIgeCA9IG51bShwYXJ0c1swXSwgNTApOwogICAgdmFyIHkgPSBudW0ocGFydHNbMV0sIDUwKTsKICAgIGlmIChwYXJ0c1swXSA9PT0gJ3RvcCcgfHwgcGFydHNbMF0gPT09ICdib3R0b20nKSB7IHkgPSB4OyB4ID0gbnVtKHBhcnRzWzFdLCA1MCk7IH0KICAgIHJldHVybiB7IHg6IE1hdGgucm91bmQoeCksIHk6IE1hdGgucm91bmQoeSkgfTsKICB9CgogIGZ1bmN0aW9uIGNoYW5nZUZvcihub2RlLCBzZXJ2ZXJLZXksIGtleSkgewogICAgdmFyIGtpbmQgPSBub2RlLmdldEF0dHJpYnV0ZSgnZGF0YS1wbHVtYS1raW5kJyk7CiAgICB2YXIgY2ggPSB7IGtleTogc2VydmVyS2V5LCBraW5kOiBraW5kIH07CiAgICB2YXIgYmFzZSA9IHByaXN0aW5lRm9yKGtleSk7CiAgICB2YXIgYmFzZVN0eWxlID0gYmFzZSA/IGJhc2UuYXR0cnMuc3R5bGUgOiBudWxsOwogICAgdmFyIHN0eWxlID0gbm9kZS5nZXRBdHRyaWJ1dGUoJ3N0eWxlJyk7CiAgICBpZiAoa2luZCA9PT0gJ3RleHQnIHx8IGtpbmQgPT09ICdyaWNoJykgewogICAgICBjaC5odG1sID0gY2xlYW5Jbm5lcihub2RlKTsKICAgIH0gZWxzZSBpZiAoa2luZCA9PT0gJ2xpbmsnKSB7CiAgICAgIGNoLmh0bWwgPSBjbGVhbklubmVyKG5vZGUpOwogICAgICBjaC5ocmVmID0gbm9kZS5nZXRBdHRyaWJ1dGUoJ2hyZWYnKSB8fCAnJzsKICAgICAgY2gubmV3VGFiID0gbm9kZS5nZXRBdHRyaWJ1dGUoJ3RhcmdldCcpID09PSAnX2JsYW5rJzsKICAgIH0gZWxzZSBpZiAoa2luZCA9PT0gJ2ltYWdlJykgewogICAgICBjaC5zcmMgPSBub2RlLmdldEF0dHJpYnV0ZSgnc3JjJyk7CiAgICAgIGNoLmFsdCA9IG5vZGUuZ2V0QXR0cmlidXRlKCdhbHQnKSB8fCAnJzsKICAgICAgaWYgKHN0YXRlLm1ldGFba2V5XSAmJiBzdGF0ZS5tZXRhW2tleV0ud2lkdGgpIHsgY2gud2lkdGggPSBzdGF0ZS5tZXRhW2tleV0ud2lkdGg7IGNoLmhlaWdodCA9IHN0YXRlLm1ldGFba2V5XS5oZWlnaHQ7IH0KICAgICAgdmFyIHBvcyA9IHN0eWxlVmFsdWUoc3R5bGUsICdvYmplY3RQb3NpdGlvbicpOwogICAgICBpZiAocG9zICE9PSBzdHlsZVZhbHVlKGJhc2VTdHlsZSwgJ29iamVjdFBvc2l0aW9uJykpIGNoLmZvY3VzID0gcG9zID8gcG9zVG9Gb2N1cyhwb3MpIDogbnVsbDsKICAgIH0gZWxzZSBpZiAoa2luZCA9PT0gJ2JnJykgewogICAgICB2YXIgaW1nID0gc3R5bGVWYWx1ZShzdHlsZSwgJ2JhY2tncm91bmRJbWFnZScpOwogICAgICBpZiAoaW1nICE9PSBzdHlsZVZhbHVlKGJhc2VTdHlsZSwgJ2JhY2tncm91bmRJbWFnZScpKSB7CiAgICAgICAgdmFyIG0gPSAvdXJsXChccypbIiddPyguKj8pWyInXT9ccypcKS8uZXhlYyhpbWcpOwogICAgICAgIGlmIChtKSBjaC5zcmMgPSBtWzFdOwogICAgICB9CiAgICAgIHZhciBicG9zID0gc3R5bGVWYWx1ZShzdHlsZSwgJ2JhY2tncm91bmRQb3NpdGlvbicpOwogICAgICBpZiAoYnBvcyAhPT0gc3R5bGVWYWx1ZShiYXNlU3R5bGUsICdiYWNrZ3JvdW5kUG9zaXRpb24nKSkgY2guZm9jdXMgPSBicG9zID8gcG9zVG9Gb2N1cyhicG9zKSA6IG51bGw7CiAgICAgIGlmICghY2guc3JjICYmICEoJ2ZvY3VzJyBpbiBjaCkpIHJldHVybiBudWxsOwogICAgfSBlbHNlIGlmIChraW5kID09PSAndmlkZW8nKSB7CiAgICAgIGNoLnNyYyA9IG5vZGUuZ2V0QXR0cmlidXRlKCdzcmMnKSB8fCAnJzsKICAgICAgY2gudGl0bGUgPSBub2RlLmdldEF0dHJpYnV0ZSgndGl0bGUnKSB8fCAnJzsKICAgIH0KICAgIHJldHVybiBjaDsKICB9CgogIC8qKiBFZGl0YWJsZXMgb2YgYSBibG9jaywgdGhlIGJsb2NrIGl0c2VsZiBpbmNsdWRlZCB3aGVuIGl0IGlzIG9uZS4gKi8KICBmdW5jdGlvbiBlZGl0YWJsZXNJbihpdGVtKSB7CiAgICB2YXIgbGlzdCA9IGl0ZW0uaGFzQXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScpID8gW2l0ZW1dIDogW107CiAgICByZXR1cm4gbGlzdC5jb25jYXQoQXJyYXkucHJvdG90eXBlLnNsaWNlLmNhbGwoaXRlbS5xdWVyeVNlbGVjdG9yQWxsKCdbZGF0YS1wbHVtYS1rZXldJykpKTsKICB9CgogIGZ1bmN0aW9uIGNvbGxlY3RQYXlsb2FkKCkgewogICAgdmFyIHJlYnVpbHQgPSBkaXJ0eUdyb3VwcygpOwogICAgdmFyIGluUmVidWlsdCA9IGZ1bmN0aW9uIChub2RlKSB7CiAgICAgIHZhciBpdGVtID0gaXRlbUZyb20obm9kZSk7CiAgICAgIHJldHVybiBpdGVtICYmIHJlYnVpbHQuc29tZShmdW5jdGlvbiAoZykgeyByZXR1cm4gZy5jdXJyZW50LmluZGV4T2YoaXRlbSkgIT09IC0xIHx8IGcub3JpZy5pbmRleE9mKGl0ZW0pICE9PSAtMTsgfSk7CiAgICB9OwogICAgdmFyIGNoYW5nZXMgPSBbXTsKICAgIE9iamVjdC5rZXlzKHN0YXRlLmRpcnR5KS5mb3JFYWNoKGZ1bmN0aW9uIChrZXkpIHsKICAgICAgdmFyIG5vZGUgPSBub2RlRm9yKGtleSk7CiAgICAgIGlmICghbm9kZSB8fCBpblJlYnVpbHQobm9kZSkpIHJldHVybjsKICAgICAgdmFyIGNoID0gY2hhbmdlRm9yKG5vZGUsIGtleSwga2V5KTsKICAgICAgaWYgKGNoKSBjaGFuZ2VzLnB1c2goY2gpOwogICAgfSk7CiAgICB2YXIgZ3JvdXBzID0gcmVidWlsdC5tYXAoZnVuY3Rpb24gKGcpIHsKICAgICAgcmV0dXJuIHsKICAgICAgICBncm91cDogZy5pZCwKICAgICAgICBpdGVtczogZy5jdXJyZW50Lm1hcChmdW5jdGlvbiAoaXRlbSkgewogICAgICAgICAgdmFyIGVudHJ5ID0geyBzcmM6IGl0ZW1JbmZvLmdldChpdGVtKS5zcmMsIGNoYW5nZXM6IFtdIH07CiAgICAgICAgICBlZGl0YWJsZXNJbihpdGVtKS5mb3JFYWNoKGZ1bmN0aW9uIChlbCkgewogICAgICAgICAgICB2YXIga2V5ID0gZWwuZ2V0QXR0cmlidXRlKCdkYXRhLXBsdW1hLWtleScpOwogICAgICAgICAgICB2YXIgb3JpZyA9IG9yaWdPZihrZXkpOwogICAgICAgICAgICB2YXIgY2hhbmdlZCA9IGtleSA9PT0gb3JpZyA/ICEhc3RhdGUuZGlydHlba2V5XSA6ICFzYW1lU25hcChzbmFwc2hvdChlbCksIHByaXN0aW5lRm9yKGtleSkpOwogICAgICAgICAgICBpZiAoIWNoYW5nZWQpIHJldHVybjsKICAgICAgICAgICAgdmFyIGNoID0gY2hhbmdlRm9yKGVsLCBvcmlnLCBrZXkpOwogICAgICAgICAgICBpZiAoY2gpIGVudHJ5LmNoYW5nZXMucHVzaChjaCk7CiAgICAgICAgICB9KTsKICAgICAgICAgIHJldHVybiBlbnRyeTsKICAgICAgICB9KQogICAgICB9OwogICAgfSk7CiAgICByZXR1cm4geyBjaGFuZ2VzOiBjaGFuZ2VzLCBncm91cHM6IGdyb3VwcyB9OwogIH0KCiAgZnVuY3Rpb24gc2F2ZSgpIHsKICAgIGlmIChzdGF0ZS5zYXZpbmcgfHwgIXN0YXRlLmRvYykgcmV0dXJuOwogICAgaWYgKHN0YXRlLmVkaXRpbmcpIHN0b3BFZGl0KCk7CiAgICB2YXIgcGF5bG9hZCA9IGNvbGxlY3RQYXlsb2FkKCk7CiAgICB2YXIgbWV0YSA9IG1ldGFDaGFuZ2VzKCk7CiAgICBpZiAoIXBheWxvYWQuY2hhbmdlcy5sZW5ndGggJiYgIXBheWxvYWQuZ3JvdXBzLmxlbmd0aCAmJiAhbWV0YSkgeyB0b2FzdCh0KCdzYXZlLm5vdGhpbmcnKSk7IHJldHVybjsgfQogICAgc3RhdGUuc2F2aW5nID0gdHJ1ZTsKICAgIHVwZGF0ZVN0YXR1cygpOwogICAgdmFyIGJvZHkgPSB7IHBhZ2U6IHN0YXRlLnBhZ2UsIGhhc2g6IHN0YXRlLmhhc2gsIGNoYW5nZXM6IHBheWxvYWQuY2hhbmdlcywgZ3JvdXBzOiBwYXlsb2FkLmdyb3VwcyB9OwogICAgaWYgKG1ldGEpIGJvZHkubWV0YSA9IG1ldGE7CiAgICByZXF1ZXN0KCdzYXZlJywgeyBqc29uOiBib2R5IH0pLnRoZW4oZnVuY3Rpb24gKHJlcykgewogICAgICBzdGF0ZS5oYXNoID0gcmVzLmhhc2g7CiAgICAgIHZhciBvdGhlcnMgPSAocmVzLnNoYXJlZFBhZ2VzIHx8IFtdKS5sZW5ndGg7CiAgICAgIHZhciBva1RleHQgPSBvdGhlcnMgPyB0KCdzYXZlLnNoYXJlZCcsIHsgbjogb3RoZXJzICsgMSB9KSA6IHQoJ3NhdmUub2snKTsKICAgICAgaWYgKG1ldGEgJiYgc3RhdGUucGFnZU1ldGEpIHsKICAgICAgICBzdGF0ZS5wYWdlTWV0YS5vcmlnID0gT2JqZWN0LmFzc2lnbih7fSwgc3RhdGUucGFnZU1ldGEuY3VyLCB7IGltYWdlOiBhYnNvbHV0ZUltYWdlKHN0YXRlLnBhZ2VNZXRhLmN1ci5pbWFnZSkgfSk7CiAgICAgICAgc3RhdGUucGFnZU1ldGEuY3VyID0gT2JqZWN0LmFzc2lnbih7fSwgc3RhdGUucGFnZU1ldGEub3JpZyk7CiAgICAgIH0KICAgICAgaWYgKHBheWxvYWQuZ3JvdXBzLmxlbmd0aCkgewogICAgICAgIC8vIGJsb2NrcyB3ZXJlIGFkZGVkLCBtb3ZlZCBvciByZW1vdmVkOiByZWxvYWQgc28gZXZlcnkgZWxlbWVudCBnZXRzIGl0cyBuZXcgcGxhY2UKICAgICAgICBzdGF0ZS5kaXJ0eSA9IHt9OwogICAgICAgIHN0YXRlLmdyb3VwcyA9IHt9OwogICAgICAgIHN0YXRlLnBhZ2VNZXRhID0gbnVsbDsKICAgICAgICB0b2FzdChva1RleHQsICdvaycpOwogICAgICAgIGxvYWRQYWdlKHN0YXRlLnBhZ2UsIHRydWUpOwogICAgICAgIHJldHVybjsKICAgICAgfQogICAgICBPYmplY3Qua2V5cyhzdGF0ZS5kaXJ0eSkuZm9yRWFjaChmdW5jdGlvbiAoa2V5KSB7CiAgICAgICAgdmFyIG4gPSBub2RlRm9yKGtleSk7CiAgICAgICAgaWYgKG4pIHsKICAgICAgICAgIG4ucmVtb3ZlQXR0cmlidXRlKCdkYXRhLXBsdW1hLWRpcnR5Jyk7CiAgICAgICAgICBzdGF0ZS5wcmlzdGluZVtrZXldID0gc25hcHNob3Qobik7CiAgICAgICAgfQogICAgICB9KTsKICAgICAgc3RhdGUuZGlydHkgPSB7fTsKICAgICAgc3RhdGUubWV0YSA9IHt9OwogICAgICBzdGF0ZS51bmRvID0gW107CiAgICAgIHN0YXRlLnJlZG8gPSBbXTsKICAgICAgdG9hc3Qob2tUZXh0LCAnb2snKTsKICAgIH0pLmNhdGNoKGZ1bmN0aW9uIChlcnIpIHsKICAgICAgaWYgKGVyci5jb2RlID09PSAnY29uZmxpY3QnKSB7IHNob3dDb25mbGljdCgpOyByZXR1cm47IH0KICAgICAgdG9hc3QoZXJyb3JUZXh0KGVyciksICdlcnJvcicpOwogICAgfSkudGhlbihmdW5jdGlvbiAoKSB7CiAgICAgIHN0YXRlLnNhdmluZyA9IGZhbHNlOwogICAgICB1cGRhdGVTdGF0dXMoKTsKICAgIH0pOwogIH0KCiAgZnVuY3Rpb24gZWRpdGVkVGV4dCgpIHsKICAgIHJldHVybiBPYmplY3Qua2V5cyhzdGF0ZS5kaXJ0eSkubWFwKGZ1bmN0aW9uIChrZXkpIHsKICAgICAgdmFyIG4gPSBub2RlRm9yKGtleSk7CiAgICAgIHJldHVybiBuID8gKG4uaW5uZXJUZXh0IHx8IG4udGV4dENvbnRlbnQgfHwgJycpLnRyaW0oKSA6ICcnOwogICAgfSkuZmlsdGVyKEJvb2xlYW4pLmpvaW4oJ1xuXG4nKTsKICB9CgogIGZ1bmN0aW9uIHNob3dDb25mbGljdCgpIHsKICAgIHZhciB0YSA9IGgoJ3RleHRhcmVhJywgeyBjbGFzczogJ3BsLWlucHV0JywgcmVhZG9ubHk6IHRydWUgfSk7CiAgICB0YS52YWx1ZSA9IGVkaXRlZFRleHQoKTsKICAgIG1vZGFsKHsgdGl0bGU6IHQoJ2NvbmZsaWN0LnRpdGxlJyksIGJvZHk6IHQoJ2NvbmZsaWN0LmJvZHknKSwgZXh0cmE6IHRhLCBzdGlja3k6IHRydWUsIGFjdGlvbnM6IFsKICAgICAgeyBsYWJlbDogdCgnY2xvc2UnKSwgdmFsdWU6IGZhbHNlIH0sCiAgICAgIHsgbGFiZWw6IHQoJ2NvbmZsaWN0LnJlbG9hZCcpLCB2YWx1ZTogdHJ1ZSwgcHJpbWFyeTogdHJ1ZSB9CiAgICBdIH0pLnRoZW4oZnVuY3Rpb24gKG9rKSB7IGlmIChvaykgbG9hZFBhZ2Uoc3RhdGUucGFnZSwgdHJ1ZSk7IH0pOwogIH0KCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIHBhZ2VzLCBwcmV2aWV3LCBoaXN0b3J5CiAgZnVuY3Rpb24gc3dpdGNoUGFnZShwYWdlKSB7CiAgICBpZiAocGFnZSA9PT0gc3RhdGUucGFnZSkgcmV0dXJuOwogICAgdmFyIGdvID0gZnVuY3Rpb24gKCkgeyBsb2FkUGFnZShwYWdlKTsgfTsKICAgIGlmIChkaXJ0eUNvdW50KCkgfHwgc3RhdGUuZWRpdGluZykgewogICAgICBjb25maXJtQm94KHQoJ2Jhci51bnNhdmVkJyksIHQoJ2NvbmZpcm0ubGVhdmUnKSwgdCgnY29uZmlybS5kaXNjYXJkJyksIHRydWUpLnRoZW4oZnVuY3Rpb24gKG9rKSB7CiAgICAgICAgaWYgKG9rKSBnbygpOyBlbHNlIHBhZ2VTZWxlY3QudmFsdWUgPSBzdGF0ZS5wYWdlOwogICAgICB9KTsKICAgIH0gZWxzZSB7CiAgICAgIGdvKCk7CiAgICB9CiAgfQoKICBmdW5jdGlvbiB0b2dnbGVQcmV2aWV3KCkgewogICAgc3RvcEVkaXQoKTsKICAgIGNsb3NlRHJhd2VyKCk7CiAgICBzdGF0ZS5wcmV2aWV3ID0gIXN0YXRlLnByZXZpZXc7CiAgICBidG5QcmV2aWV3LnNldEF0dHJpYnV0ZSgnYXJpYS1wcmVzc2VkJywgc3RhdGUucHJldmlldyA/ICd0cnVlJyA6ICdmYWxzZScpOwogICAgaWYgKHN0YXRlLmRvYykgc3RhdGUuZG9jLmRvY3VtZW50RWxlbWVudC5jbGFzc0xpc3QudG9nZ2xlKCdwbHVtYS1wcmV2aWV3Jywgc3RhdGUucHJldmlldyk7CiAgICBoaW50Q2hpcC5jbGFzc0xpc3QucmVtb3ZlKCdpcy1vbicpOwogICAgaGlkZUl0ZW1CYXIodHJ1ZSk7CiAgfQoKICBmdW5jdGlvbiBmb3JtYXREYXRlKHRzKSB7CiAgICB0cnkgewogICAgICByZXR1cm4gbmV3IEludGwuRGF0ZVRpbWVGb3JtYXQodW5kZWZpbmVkLCB7IGRhdGVTdHlsZTogJ21lZGl1bScsIHRpbWVTdHlsZTogJ3Nob3J0JyB9KS5mb3JtYXQobmV3IERhdGUodHMgKiAxMDAwKSk7CiAgICB9IGNhdGNoIChlKSB7IHJldHVybiBuZXcgRGF0ZSh0cyAqIDEwMDApLnRvTG9jYWxlU3RyaW5nKCk7IH0KICB9CgogIGZ1bmN0aW9uIG9wZW5IaXN0b3J5KCkgewogICAgc3RvcEVkaXQoKTsKICAgIHZhciBsaXN0ID0gaCgnZGl2JywgeyBjbGFzczogJ3BsLXZlcnNpb25zJyB9LCBbaCgncCcsIHsgY2xhc3M6ICdwbC1lbXB0eScsIHRleHQ6ICfigKYnIH0pXSk7CiAgICBvcGVuRHJhd2VyKHQoJ2hpc3QudGl0bGUnKSwgW2goJ3AnLCB7IGNsYXNzOiAncGwtbXV0ZWQnLCB0ZXh0OiB0KCdoaXN0LmludHJvJyksIHN0eWxlOiAnbWFyZ2luOjAnIH0pLCBsaXN0XSk7CiAgICByZXF1ZXN0KCdoaXN0b3J5JywgeyBwYXJhbXM6IHsgcDogc3RhdGUucGFnZSB9IH0pLnRoZW4oZnVuY3Rpb24gKHJlcykgewogICAgICBsaXN0LmlubmVySFRNTCA9ICcnOwogICAgICBpZiAoIXJlcy52ZXJzaW9ucy5sZW5ndGgpIHsgbGlzdC5hcHBlbmRDaGlsZChoKCdwJywgeyBjbGFzczogJ3BsLWVtcHR5JywgdGV4dDogdCgnaGlzdC5lbXB0eScpIH0pKTsgcmV0dXJuOyB9CiAgICAgIHJlcy52ZXJzaW9ucy5mb3JFYWNoKGZ1bmN0aW9uICh2KSB7CiAgICAgICAgdmFyIHJlc3RvcmVCdG4gPSBoKCdidXR0b24nLCB7IGNsYXNzOiAncGwtYnRuJywgdHlwZTogJ2J1dHRvbicsIHRleHQ6IHQoJ2hpc3QucmVzdG9yZScpIH0pOwogICAgICAgIHJlc3RvcmVCdG4uYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCBmdW5jdGlvbiAoKSB7CiAgICAgICAgICB2YXIgbXNnID0gdCgnaGlzdC5jb25maXJtJykgKyAoZGlydHlDb3VudCgpID8gJ1xuXG4nICsgdCgnY29uZmlybS5sZWF2ZScpIDogJycpOwogICAgICAgICAgY29uZmlybUJveCh0KCdoaXN0LnJlc3RvcmUnKSwgbXNnLCB0KCdoaXN0LnJlc3RvcmUnKSkudGhlbihmdW5jdGlvbiAob2spIHsKICAgICAgICAgICAgaWYgKCFvaykgcmV0dXJuOwogICAgICAgICAgICByZXF1ZXN0KCdyZXN0b3JlJywgeyBqc29uOiB7IHBhZ2U6IHN0YXRlLnBhZ2UsIHZlcnNpb246IHYuaWQgfSB9KS50aGVuKGZ1bmN0aW9uICgpIHsKICAgICAgICAgICAgICB0b2FzdCh0KCdoaXN0LnJlc3RvcmVkJyksICdvaycpOwogICAgICAgICAgICAgIGxvYWRQYWdlKHN0YXRlLnBhZ2UsIHRydWUpOwogICAgICAgICAgICB9KS5jYXRjaChmdW5jdGlvbiAoZXJyKSB7IHRvYXN0KGVycm9yVGV4dChlcnIpLCAnZXJyb3InKTsgfSk7CiAgICAgICAgICB9KTsKICAgICAgICB9KTsKICAgICAgICBsaXN0LmFwcGVuZENoaWxkKGgoJ2RpdicsIHsgY2xhc3M6ICdwbC12ZXJzaW9uJyB9LCBbCiAgICAgICAgICBoKCdkaXYnLCB7IGNsYXNzOiAncGwtdmVyc2lvbi1kYXRlJyB9LCBbCiAgICAgICAgICAgIGgoJ2InLCB7IHRleHQ6IHYub3JpZ2luYWwgPyB0KCdoaXN0Lm9yaWdpbmFsJykgOiBmb3JtYXREYXRlKHYudGltZSkgfSksCiAgICAgICAgICAgIGgoJ3NwYW4nLCB7IHRleHQ6IHYub3JpZ2luYWwgPyBmb3JtYXREYXRlKHYudGltZSkgOiBNYXRoLm1heCgxLCBNYXRoLnJvdW5kKHYuc2l6ZSAvIDEwMjQpKSArICcgS0InIH0pCiAgICAgICAgICBdKSwKICAgICAgICAgIGgoJ2EnLCB7IGNsYXNzOiAncGwtYnRuIHBsLWJ0bi1naG9zdCcsIGhyZWY6IHVybCgndmVyc2lvbicsIHsgcDogc3RhdGUucGFnZSwgdjogdi5pZCB9KSwgdGFyZ2V0OiAnX2JsYW5rJywgcmVsOiAnbm9vcGVuZXInLCB0ZXh0OiB0KCdoaXN0LnByZXZpZXcnKSB9KSwKICAgICAgICAgIHJlc3RvcmVCdG4KICAgICAgICBdKSk7CiAgICAgIH0pOwogICAgfSkuY2F0Y2goZnVuY3Rpb24gKGVycikgewogICAgICBsaXN0LmlubmVySFRNTCA9ICcnOwogICAgICBsaXN0LmFwcGVuZENoaWxkKGgoJ3AnLCB7IGNsYXNzOiAncGwtZW1wdHknLCB0ZXh0OiBlcnJvclRleHQoZXJyKSB9KSk7CiAgICB9KTsKICB9CgogIGZ1bmN0aW9uIGxvZ291dCgpIHsKICAgIHZhciBnbyA9IGZ1bmN0aW9uICgpIHsKICAgICAgcmVxdWVzdCgnbG9nb3V0JywgeyBqc29uOiB7fSB9KS5jYXRjaChmdW5jdGlvbiAoKSB7fSkudGhlbihmdW5jdGlvbiAoKSB7CiAgICAgICAgc3RhdGUuZGlydHkgPSB7fTsKICAgICAgICBsb2NhdGlvbi5ocmVmID0gU0NSSVBUOwogICAgICB9KTsKICAgIH07CiAgICBpZiAoZGlydHlDb3VudCgpKSB7CiAgICAgIGNvbmZpcm1Cb3godCgnYmFyLmxvZ291dCcpLCB0KCdjb25maXJtLmxlYXZlJyksIHQoJ2Jhci5sb2dvdXQnKSwgdHJ1ZSkudGhlbihmdW5jdGlvbiAob2spIHsgaWYgKG9rKSBnbygpOyB9KTsKICAgIH0gZWxzZSB7CiAgICAgIGdvKCk7CiAgICB9CiAgfQoKICB3aW5kb3cuYWRkRXZlbnRMaXN0ZW5lcignYmVmb3JldW5sb2FkJywgZnVuY3Rpb24gKGUpIHsKICAgIGlmIChkaXJ0eUNvdW50KCkgfHwgKHN0YXRlLmVkaXRpbmcgJiYgIXNhbWVTbmFwKHNuYXBzaG90KHN0YXRlLmVkaXRpbmcubm9kZSksIHN0YXRlLmVkaXRpbmcuYmVmb3JlKSkpIHsKICAgICAgZS5wcmV2ZW50RGVmYXVsdCgpOwogICAgICBlLnJldHVyblZhbHVlID0gJyc7CiAgICAgIHJldHVybiAnJzsKICAgIH0KICB9KTsKICB3aW5kb3cuYWRkRXZlbnRMaXN0ZW5lcigncmVzaXplJywgcG9zaXRpb25Ub29sYmFyKTsKCiAgLy8gLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tIHN0YXJ0CiAgZmlsbFBhZ2VzKCk7CiAgdmFyIGZpcnN0ID0gY2ZnLnBhZ2VzWzBdOwogIHRyeSB7CiAgICB2YXIgbGFzdCA9IHNlc3Npb25TdG9yYWdlLmdldEl0ZW0oJ3BsdW1hLXBhZ2UnKTsKICAgIGlmIChsYXN0ICYmIGNmZy5wYWdlcy5pbmRleE9mKGxhc3QpICE9PSAtMSkgZmlyc3QgPSBsYXN0OwogIH0gY2F0Y2ggKGUpIHsgLyogaWdub3JlICovIH0KICB2YXIgcXAgPSBuZXcgVVJMU2VhcmNoUGFyYW1zKGxvY2F0aW9uLnNlYXJjaCkuZ2V0KCdwJyk7CiAgaWYgKHFwICYmIGNmZy5wYWdlcy5pbmRleE9mKHFwKSAhPT0gLTEpIGZpcnN0ID0gcXA7CiAgaWYgKGZpcnN0KSBsb2FkUGFnZShmaXJzdCk7CiAgdXBkYXRlU3RhdHVzKCk7CgogIGlmICh3aW5kb3cuaW5uZXJXaWR0aCA8IDcwMCkgdG9hc3QodCgnbW9iaWxlLndhcm4nKSwgbnVsbCwgNjAwMCk7CiAgdHJ5IHsKICAgIGlmICghY2ZnLmh0dHBzICYmICFzZXNzaW9uU3RvcmFnZS5nZXRJdGVtKCdwbHVtYS1odHRwLXdhcm5lZCcpKSB7CiAgICAgIHNlc3Npb25TdG9yYWdlLnNldEl0ZW0oJ3BsdW1hLWh0dHAtd2FybmVkJywgJzEnKTsKICAgICAgc2V0VGltZW91dChmdW5jdGlvbiAoKSB7IHRvYXN0KHQoJ3NldHVwLmh0dHAnKSwgJ2Vycm9yJywgOTAwMCk7IH0sIDgwMCk7CiAgICB9CiAgfSBjYXRjaCAoZSkgeyAvKiBpZ25vcmUgKi8gfQp9KSgpOwo=');
            case 'editor.css':
                return base64_decode('LyogUGx1bWFFZGl0b3Ig4oCUIGVkaXRvciBpbnRlcmZhY2UgKi8KOnJvb3QgewogIC0tcGwtcGFwZXI6ICNmYWY4ZjQ7CiAgLS1wbC1zdXJmYWNlOiAjZmZmZmZmOwogIC0tcGwtaW5rOiAjMWMxZDIxOwogIC0tcGwtaW5rLTI6ICM1NTU4NWY7CiAgLS1wbC1pbmstMzogIzhhOGQ5NDsKICAtLXBsLWxpbmU6ICNlNmUyZDk7CiAgLS1wbC1saW5lLTI6ICNkNmQxYzU7CiAgLS1wbC1hY2NlbnQ6ICMyNzUwOGY7CiAgLS1wbC1hY2NlbnQtaW5rOiAjZmZmZmZmOwogIC0tcGwtYWNjZW50LXNvZnQ6ICNlOGVlZjg7CiAgLS1wbC13YXJuOiAjOWE1YjAwOwogIC0tcGwtd2Fybi1zb2Z0OiAjZmZmNGUwOwogIC0tcGwtZGFuZ2VyOiAjYjQyMzE4OwogIC0tcGwtZGFuZ2VyLXNvZnQ6ICNmZGVjZWE7CiAgLS1wbC1vazogIzFmN2E0ZDsKICAtLXBsLXJhZGl1czogMTBweDsKICAtLXBsLXNoYWRvdzogMCAxcHggMnB4IHJnYmEoMjAsIDIyLCAyOCwgLjA2KSwgMCA4cHggMjRweCByZ2JhKDIwLCAyMiwgMjgsIC4xMCk7CiAgLS1wbC1mb250OiB1aS1zYW5zLXNlcmlmLCBzeXN0ZW0tdWksIC1hcHBsZS1zeXN0ZW0sICJTZWdvZSBVSSIsIFJvYm90bywgIkhlbHZldGljYSBOZXVlIiwgQXJpYWwsIHNhbnMtc2VyaWY7CiAgLS1wbC1iYXI6IDU2cHg7Cn0KKiB7IGJveC1zaXppbmc6IGJvcmRlci1ib3g7IH0KaHRtbCwgYm9keSB7IG1hcmdpbjogMDsgaGVpZ2h0OiAxMDAlOyB9CmJvZHkgeyBmb250OiAxNHB4LzEuNDUgdmFyKC0tcGwtZm9udCk7IGNvbG9yOiB2YXIoLS1wbC1pbmspOyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1wYXBlcik7IC13ZWJraXQtZm9udC1zbW9vdGhpbmc6IGFudGlhbGlhc2VkOyB9CmJ1dHRvbiwgaW5wdXQsIHNlbGVjdCwgdGV4dGFyZWEgeyBmb250OiBpbmhlcml0OyBjb2xvcjogaW5oZXJpdDsgfQphIHsgY29sb3I6IHZhcigtLXBsLWFjY2VudCk7IH0KW2hpZGRlbl0geyBkaXNwbGF5OiBub25lICFpbXBvcnRhbnQ7IH0KLnBsLXNyIHsgcG9zaXRpb246IGFic29sdXRlOyB3aWR0aDogMXB4OyBoZWlnaHQ6IDFweDsgb3ZlcmZsb3c6IGhpZGRlbjsgY2xpcDogcmVjdCgwIDAgMCAwKTsgd2hpdGUtc3BhY2U6IG5vd3JhcDsgfQoKLyogLS0tLS0tLS0tLSBidXR0b25zIC0tLS0tLS0tLS0gKi8KLnBsLWJ0biB7CiAgZGlzcGxheTogaW5saW5lLWZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGp1c3RpZnktY29udGVudDogY2VudGVyOyBnYXA6IDZweDsKICBoZWlnaHQ6IDM0cHg7IHBhZGRpbmc6IDAgMTJweDsgYm9yZGVyLXJhZGl1czogOHB4OyBib3JkZXI6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lLTIpOwogIGJhY2tncm91bmQ6IHZhcigtLXBsLXN1cmZhY2UpOyBjdXJzb3I6IHBvaW50ZXI7IHdoaXRlLXNwYWNlOiBub3dyYXA7IGZvbnQtd2VpZ2h0OiA1MDA7CiAgdHJhbnNpdGlvbjogYmFja2dyb3VuZCAuMTJzLCBib3JkZXItY29sb3IgLjEycywgYm94LXNoYWRvdyAuMTJzOwp9Ci5wbC1idG46aG92ZXIgeyBiYWNrZ3JvdW5kOiAjZjNmMWVjOyB9Ci5wbC1idG46Zm9jdXMtdmlzaWJsZSwgLnBsLWljb246Zm9jdXMtdmlzaWJsZSwgLnBsLXNlbGVjdDpmb2N1cy12aXNpYmxlLCAucGwtaW5wdXQ6Zm9jdXMtdmlzaWJsZSB7IG91dGxpbmU6IDJweCBzb2xpZCB2YXIoLS1wbC1hY2NlbnQpOyBvdXRsaW5lLW9mZnNldDogMnB4OyB9Ci5wbC1idG46ZGlzYWJsZWQgeyBvcGFjaXR5OiAuNDU7IGN1cnNvcjogZGVmYXVsdDsgYmFja2dyb3VuZDogdmFyKC0tcGwtc3VyZmFjZSk7IH0KLnBsLWJ0bi1wcmltYXJ5IHsgYmFja2dyb3VuZDogdmFyKC0tcGwtYWNjZW50KTsgYm9yZGVyLWNvbG9yOiB2YXIoLS1wbC1hY2NlbnQpOyBjb2xvcjogdmFyKC0tcGwtYWNjZW50LWluayk7IH0KLnBsLWJ0bi1wcmltYXJ5OmhvdmVyIHsgYmFja2dyb3VuZDogIzFmNDI3ODsgfQoucGwtYnRuLXByaW1hcnk6ZGlzYWJsZWQgeyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1hY2NlbnQpOyB9Ci5wbC1idG4tZGFuZ2VyIHsgY29sb3I6IHZhcigtLXBsLWRhbmdlcik7IH0KLnBsLWJ0bi1naG9zdCB7IGJvcmRlci1jb2xvcjogdHJhbnNwYXJlbnQ7IGJhY2tncm91bmQ6IHRyYW5zcGFyZW50OyB9Ci5wbC1idG4tZ2hvc3Q6aG92ZXIgeyBiYWNrZ3JvdW5kOiByZ2JhKDAsIDAsIDAsIC4wNSk7IH0KLnBsLXdpZGUgeyB3aWR0aDogMTAwJTsgaGVpZ2h0OiA0MnB4OyB9Ci5wbC1pY29uIHsKICB3aWR0aDogMzRweDsgaGVpZ2h0OiAzNHB4OyBkaXNwbGF5OiBpbmxpbmUtZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsganVzdGlmeS1jb250ZW50OiBjZW50ZXI7CiAgYm9yZGVyLXJhZGl1czogOHB4OyBib3JkZXI6IDFweCBzb2xpZCB0cmFuc3BhcmVudDsgYmFja2dyb3VuZDogdHJhbnNwYXJlbnQ7IGN1cnNvcjogcG9pbnRlcjsgY29sb3I6IHZhcigtLXBsLWluay0yKTsKfQoucGwtaWNvbjpob3ZlciB7IGJhY2tncm91bmQ6IHJnYmEoMCwgMCwgMCwgLjA2KTsgY29sb3I6IHZhcigtLXBsLWluayk7IH0KLnBsLWljb246ZGlzYWJsZWQgeyBvcGFjaXR5OiAuMzU7IGN1cnNvcjogZGVmYXVsdDsgYmFja2dyb3VuZDogdHJhbnNwYXJlbnQ7IH0KLnBsLWljb25bYXJpYS1wcmVzc2VkPSJ0cnVlIl0geyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1hY2NlbnQtc29mdCk7IGNvbG9yOiB2YXIoLS1wbC1hY2NlbnQpOyB9Ci5wbC1pY29uIHN2ZywgLnBsLWJ0biBzdmcgeyB3aWR0aDogMThweDsgaGVpZ2h0OiAxOHB4OyBmbGV4OiBub25lOyB9CgovKiAtLS0tLS0tLS0tIGF1dGggcGFnZXMgLS0tLS0tLS0tLSAqLwoucGwtYXV0aCB7IGRpc3BsYXk6IGZsZXg7IGZsZXgtZGlyZWN0aW9uOiBjb2x1bW47IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGp1c3RpZnktY29udGVudDogY2VudGVyOyBtaW4taGVpZ2h0OiAxMDAlOyBwYWRkaW5nOiAzMnB4IDE2cHg7IGJhY2tncm91bmQ6CiAgcmFkaWFsLWdyYWRpZW50KDEyMDBweCA1MDBweCBhdCA1MCUgLTEwJSwgI2VlZjJmOSAwJSwgdHJhbnNwYXJlbnQgNjAlKSwgdmFyKC0tcGwtcGFwZXIpOyB9Ci5wbC1jYXJkIHsgd2lkdGg6IDEwMCU7IG1heC13aWR0aDogNDIwcHg7IGJhY2tncm91bmQ6IHZhcigtLXBsLXN1cmZhY2UpOyBib3JkZXI6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgYm9yZGVyLXJhZGl1czogMTZweDsgcGFkZGluZzogMzJweDsgYm94LXNoYWRvdzogdmFyKC0tcGwtc2hhZG93KTsgfQoucGwtY2FyZCBoMSB7IGZvbnQtc2l6ZTogMjJweDsgbGluZS1oZWlnaHQ6IDEuMjU7IG1hcmdpbjogMjBweCAwIDhweDsgbGV0dGVyLXNwYWNpbmc6IC0uMDFlbTsgfQoucGwtYnJhbmQgeyBkaXNwbGF5OiBmbGV4OyBhbGlnbi1pdGVtczogY2VudGVyOyBnYXA6IDhweDsgZm9udC1zaXplOiAxNnB4OyBsZXR0ZXItc3BhY2luZzogLS4wMWVtOyB9Ci5wbC1icmFuZCBiIHsgZm9udC13ZWlnaHQ6IDYwMDsgY29sb3I6IHZhcigtLXBsLWFjY2VudCk7IH0KLnBsLWxvZ28geyB3aWR0aDogMjhweDsgaGVpZ2h0OiAyOHB4OyBjb2xvcjogdmFyKC0tcGwtYWNjZW50KTsgZGlzcGxheTogaW5saW5lLWZsZXg7IH0KLnBsLWxvZ28gc3ZnIHsgd2lkdGg6IDEwMCU7IGhlaWdodDogMTAwJTsgfQoucGwtbXV0ZWQgeyBjb2xvcjogdmFyKC0tcGwtaW5rLTIpOyBtYXJnaW46IDAgMCAxNnB4OyB9Ci5wbC1mb3JtIHsgZGlzcGxheTogZ3JpZDsgZ2FwOiAxNHB4OyBtYXJnaW4tdG9wOiAxNnB4OyB9Ci5wbC1mb3JtIGxhYmVsIHsgZGlzcGxheTogZ3JpZDsgZ2FwOiA2cHg7IGZvbnQtd2VpZ2h0OiA1MDA7IH0KLnBsLWZvcm0gaW5wdXRbdHlwZT1wYXNzd29yZF0sIC5wbC1pbnB1dCB7CiAgaGVpZ2h0OiA0MnB4OyBwYWRkaW5nOiAwIDEycHg7IGJvcmRlcjogMXB4IHNvbGlkIHZhcigtLXBsLWxpbmUtMik7IGJvcmRlci1yYWRpdXM6IDhweDsgYmFja2dyb3VuZDogI2ZmZjsgd2lkdGg6IDEwMCU7Cn0KLnBsLWZvcm0gaW5wdXQ6Zm9jdXMsIC5wbC1pbnB1dDpmb2N1cyB7IG91dGxpbmU6IDJweCBzb2xpZCB2YXIoLS1wbC1hY2NlbnQpOyBvdXRsaW5lLW9mZnNldDogMDsgYm9yZGVyLWNvbG9yOiB2YXIoLS1wbC1hY2NlbnQpOyB9Ci5wbC1jaGVjayB7IGRpc3BsYXk6IGZsZXggIWltcG9ydGFudDsgZ3JpZC10ZW1wbGF0ZS1jb2x1bW5zOiBub25lOyBhbGlnbi1pdGVtczogY2VudGVyOyBnYXA6IDhweCAhaW1wb3J0YW50OyBmb250LXdlaWdodDogNDAwICFpbXBvcnRhbnQ7IH0KLnBsLWhpbnQgeyBjb2xvcjogdmFyKC0tcGwtaW5rLTMpOyBmb250LXNpemU6IDEzcHg7IG1hcmdpbjogLTZweCAwIDJweDsgfQoucGwtZXJyb3IgeyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1kYW5nZXItc29mdCk7IGNvbG9yOiB2YXIoLS1wbC1kYW5nZXIpOyBwYWRkaW5nOiAxMHB4IDEycHg7IGJvcmRlci1yYWRpdXM6IDhweDsgbWFyZ2luOiAxMnB4IDAgMDsgfQoucGwtd2FybiB7IGJhY2tncm91bmQ6IHZhcigtLXBsLXdhcm4tc29mdCk7IGNvbG9yOiB2YXIoLS1wbC13YXJuKTsgcGFkZGluZzogMTBweCAxMnB4OyBib3JkZXItcmFkaXVzOiA4cHg7IG1hcmdpbjogMTJweCAwIDA7IGZvbnQtc2l6ZTogMTNweDsgfQoucGwtZGV0YWlscyB7IG1hcmdpbi10b3A6IDE4cHg7IGNvbG9yOiB2YXIoLS1wbC1pbmstMik7IGZvbnQtc2l6ZTogMTNweDsgfQoucGwtZGV0YWlscyBzdW1tYXJ5IHsgY3Vyc29yOiBwb2ludGVyOyB9Ci5wbC1wcm9ibGVtcyB7IHBhZGRpbmctbGVmdDogMThweDsgZGlzcGxheTogZ3JpZDsgZ2FwOiA4cHg7IH0KLnBsLWZvb3QgeyBjb2xvcjogdmFyKC0tcGwtaW5rLTMpOyBmb250LXNpemU6IDEycHg7IG1hcmdpbi10b3A6IDE4cHg7IH0KLnBsLWZvb3QgYSB7IGNvbG9yOiBpbmhlcml0OyB9CgovKiAtLS0tLS0tLS0tIGFwcCBsYXlvdXQgLS0tLS0tLS0tLSAqLwoucGwtYXBwIHsgb3ZlcmZsb3c6IGhpZGRlbjsgfQoucGwtYmFyIHsKICBwb3NpdGlvbjogZml4ZWQ7IGluc2V0OiAwIDAgYXV0byAwOyBoZWlnaHQ6IHZhcigtLXBsLWJhcik7IHotaW5kZXg6IDIwOwogIGRpc3BsYXk6IGZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGdhcDogMTBweDsgcGFkZGluZzogMCAxMnB4OwogIGJhY2tncm91bmQ6IHZhcigtLXBsLXN1cmZhY2UpOyBib3JkZXItYm90dG9tOiAxcHggc29saWQgdmFyKC0tcGwtbGluZSk7Cn0KLnBsLWJhciAucGwtYnJhbmQgeyBmb250LXNpemU6IDE1cHg7IG1hcmdpbi1yaWdodDogNHB4OyB9Ci5wbC1iYXIgLnBsLWxvZ28geyB3aWR0aDogMjRweDsgaGVpZ2h0OiAyNHB4OyB9Ci5wbC1zZXAgeyB3aWR0aDogMXB4OyBoZWlnaHQ6IDI0cHg7IGJhY2tncm91bmQ6IHZhcigtLXBsLWxpbmUpOyBtYXJnaW46IDAgMnB4OyB9Ci5wbC1wYWdlcGljayB7IGRpc3BsYXk6IGZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGdhcDogOHB4OyBtaW4td2lkdGg6IDA7IH0KLnBsLXNlbGVjdCB7CiAgaGVpZ2h0OiAzNHB4OyBtYXgtd2lkdGg6IDI2MHB4OyBtaW4td2lkdGg6IDEyMHB4OyBwYWRkaW5nOiAwIDMwcHggMCAxMHB4OyBib3JkZXItcmFkaXVzOiA4cHg7IGJvcmRlcjogMXB4IHNvbGlkIHZhcigtLXBsLWxpbmUtMik7CiAgYmFja2dyb3VuZDogI2ZmZiB1cmwoImRhdGE6aW1hZ2Uvc3ZnK3htbCwlM0NzdmcgeG1sbnM9J2h0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnJyB3aWR0aD0nMTInIGhlaWdodD0nMTInIHZpZXdCb3g9JzAgMCAyNCAyNCcgZmlsbD0nbm9uZScgc3Ryb2tlPSclMjM1NTU4NWYnIHN0cm9rZS13aWR0aD0nMi41JyBzdHJva2UtbGluZWNhcD0ncm91bmQnIHN0cm9rZS1saW5lam9pbj0ncm91bmQnJTNFJTNDcGF0aCBkPSdtNiA5IDYgNiA2LTYnLyUzRSUzQy9zdmclM0UiKSBuby1yZXBlYXQgcmlnaHQgMTBweCBjZW50ZXI7CiAgLXdlYmtpdC1hcHBlYXJhbmNlOiBub25lOyBhcHBlYXJhbmNlOiBub25lOyBjdXJzb3I6IHBvaW50ZXI7IHRleHQtb3ZlcmZsb3c6IGVsbGlwc2lzOwp9Ci5wbC1tb2RlIHsgZm9udC1zaXplOiAxMnB4OyBjb2xvcjogdmFyKC0tcGwtaW5rLTMpOyBib3JkZXI6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgYm9yZGVyLXJhZGl1czogOTk5cHg7IHBhZGRpbmc6IDJweCA4cHg7IHdoaXRlLXNwYWNlOiBub3dyYXA7IGN1cnNvcjogaGVscDsgfQoucGwtc3RhdHVzIHsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsgZ2FwOiA4cHg7IGNvbG9yOiB2YXIoLS1wbC1pbmstMik7IGZvbnQtc2l6ZTogMTNweDsgd2hpdGUtc3BhY2U6IG5vd3JhcDsgbWFyZ2luLWxlZnQ6IGF1dG87IH0KLnBsLWRvdCB7IHdpZHRoOiA4cHg7IGhlaWdodDogOHB4OyBib3JkZXItcmFkaXVzOiA1MCU7IGJhY2tncm91bmQ6IHZhcigtLXBsLW9rKTsgfQoucGwtc3RhdHVzLmlzLWRpcnR5IC5wbC1kb3QgeyBiYWNrZ3JvdW5kOiAjZTBhMTAwOyB9Ci5wbC1zdGF0dXMuaXMtc2F2aW5nIC5wbC1kb3QgeyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1hY2NlbnQpOyBhbmltYXRpb246IHBsLXB1bHNlIDFzIGluZmluaXRlOyB9CkBrZXlmcmFtZXMgcGwtcHVsc2UgeyA1MCUgeyBvcGFjaXR5OiAuMzsgfSB9Ci5wbC1hY3Rpb25zIHsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsgZ2FwOiA0cHg7IH0KLnBsLXN0YWdlIHsgcG9zaXRpb246IGZpeGVkOyBpbnNldDogdmFyKC0tcGwtYmFyKSAwIDAgMDsgYmFja2dyb3VuZDogI2VjZWJlNzsgfQoucGwtZnJhbWUgeyB3aWR0aDogMTAwJTsgaGVpZ2h0OiAxMDAlOyBib3JkZXI6IDA7IGJhY2tncm91bmQ6ICNmZmY7IGRpc3BsYXk6IGJsb2NrOyB9Ci5wbC1sb2FkaW5nIHsgcG9zaXRpb246IGFic29sdXRlOyBpbnNldDogMDsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsganVzdGlmeS1jb250ZW50OiBjZW50ZXI7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IHBvaW50ZXItZXZlbnRzOiBub25lOyB9CgovKiAtLS0tLS0tLS0tIG1lbnUgLS0tLS0tLS0tLSAqLwoucGwtbWVudXdyYXAgeyBwb3NpdGlvbjogcmVsYXRpdmU7IH0KLnBsLW1lbnUgewogIHBvc2l0aW9uOiBhYnNvbHV0ZTsgcmlnaHQ6IDA7IHRvcDogNDBweDsgbWluLXdpZHRoOiAyMjBweDsgcGFkZGluZzogNnB4OyB6LWluZGV4OiA0MDsKICBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1zdXJmYWNlKTsgYm9yZGVyOiAxcHggc29saWQgdmFyKC0tcGwtbGluZSk7IGJvcmRlci1yYWRpdXM6IHZhcigtLXBsLXJhZGl1cyk7IGJveC1zaGFkb3c6IHZhcigtLXBsLXNoYWRvdyk7Cn0KLnBsLW1lbnUgYSwgLnBsLW1lbnUgYnV0dG9uIHsKICBkaXNwbGF5OiBmbGV4OyB3aWR0aDogMTAwJTsgYWxpZ24taXRlbXM6IGNlbnRlcjsgZ2FwOiAxMHB4OyBwYWRkaW5nOiA4cHggMTBweDsgYm9yZGVyOiAwOyBiYWNrZ3JvdW5kOiBub25lOwogIGJvcmRlci1yYWRpdXM6IDZweDsgY29sb3I6IHZhcigtLXBsLWluayk7IHRleHQtZGVjb3JhdGlvbjogbm9uZTsgY3Vyc29yOiBwb2ludGVyOyB0ZXh0LWFsaWduOiBsZWZ0Owp9Ci5wbC1tZW51IGE6aG92ZXIsIC5wbC1tZW51IGJ1dHRvbjpob3ZlciB7IGJhY2tncm91bmQ6ICNmM2YxZWM7IH0KLnBsLW1lbnUgaHIgeyBib3JkZXI6IDA7IGJvcmRlci10b3A6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgbWFyZ2luOiA2cHggMDsgfQoucGwtbWVudSAucGwtbWVudS1tZXRhIHsgY29sb3I6IHZhcigtLXBsLWluay0zKTsgZm9udC1zaXplOiAxMnB4OyBwYWRkaW5nOiA2cHggMTBweCAycHg7IH0KCi8qIC0tLS0tLS0tLS0gaG92ZXIgaGludCAtLS0tLS0tLS0tICovCi5wbC1oaW50LWNoaXAgewogIHBvc2l0aW9uOiBmaXhlZDsgbGVmdDogMTJweDsgYm90dG9tOiAxMnB4OyB6LWluZGV4OiAxNTsgcG9pbnRlci1ldmVudHM6IG5vbmU7CiAgYmFja2dyb3VuZDogcmdiYSgyOCwgMjksIDMzLCAuODgpOyBjb2xvcjogI2ZmZjsgcGFkZGluZzogNnB4IDEwcHg7IGJvcmRlci1yYWRpdXM6IDk5OXB4OyBmb250LXNpemU6IDEycHg7CiAgb3BhY2l0eTogMDsgdHJhbnNmb3JtOiB0cmFuc2xhdGVZKDRweCk7IHRyYW5zaXRpb246IG9wYWNpdHkgLjEycywgdHJhbnNmb3JtIC4xMnM7Cn0KLnBsLWhpbnQtY2hpcC5pcy1vbiB7IG9wYWNpdHk6IDE7IHRyYW5zZm9ybTogbm9uZTsgfQoKLyogLS0tLS0tLS0tLSBmbG9hdGluZyB0ZXh0IHRvb2xiYXIgLS0tLS0tLS0tLSAqLwoucGwtdG9vbGJhciB7CiAgcG9zaXRpb246IGZpeGVkOyB6LWluZGV4OiAzMDsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsgZ2FwOiAycHg7IHBhZGRpbmc6IDRweDsKICBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1pbmspOyBib3JkZXItcmFkaXVzOiAxMHB4OyBib3gtc2hhZG93OiB2YXIoLS1wbC1zaGFkb3cpOwp9Ci5wbC10b29sYmFyIC5wbC1pY29uIHsgY29sb3I6ICNlN2U4ZWE7IHdpZHRoOiAzMnB4OyBoZWlnaHQ6IDMycHg7IH0KLnBsLXRvb2xiYXIgLnBsLWljb246aG92ZXIgeyBiYWNrZ3JvdW5kOiByZ2JhKDI1NSwgMjU1LCAyNTUsIC4xMik7IGNvbG9yOiAjZmZmOyB9Ci5wbC10b29sYmFyIC5wbC1pY29uW2FyaWEtcHJlc3NlZD0idHJ1ZSJdIHsgYmFja2dyb3VuZDogcmdiYSgyNTUsIDI1NSwgMjU1LCAuMik7IGNvbG9yOiAjZmZmOyB9Ci5wbC10b29sYmFyIC5wbC1kb25lIHsgaGVpZ2h0OiAzMnB4OyBwYWRkaW5nOiAwIDEwcHg7IGJvcmRlcjogMDsgYm9yZGVyLXJhZGl1czogN3B4OyBiYWNrZ3JvdW5kOiAjZmZmOyBjb2xvcjogdmFyKC0tcGwtaW5rKTsgZm9udC13ZWlnaHQ6IDYwMDsgY3Vyc29yOiBwb2ludGVyOyBtYXJnaW4tbGVmdDogNHB4OyB9Ci5wbC10b29sYmFyIC5wbC10c2VwIHsgd2lkdGg6IDFweDsgaGVpZ2h0OiAyMHB4OyBiYWNrZ3JvdW5kOiByZ2JhKDI1NSwgMjU1LCAyNTUsIC4yKTsgbWFyZ2luOiAwIDRweDsgfQoKLyogLS0tLS0tLS0tLSBmb2NhbCBwb2ludCAtLS0tLS0tLS0tICovCi5wbC1mb2N1cy13cmFwIHsgcG9zaXRpb246IHJlbGF0aXZlOyBkaXNwbGF5OiBpbmxpbmUtYmxvY2s7IG1heC13aWR0aDogMTAwJTsgbWF4LWhlaWdodDogMTAwJTsgY3Vyc29yOiBjcm9zc2hhaXI7IGxpbmUtaGVpZ2h0OiAwOyB9Ci5wbC1mb2N1cy13cmFwIGltZyB7IG1heC1oZWlnaHQ6IDIxNnB4OyB9Ci5wbC1mb2N1cy1kb3QgewogIHBvc2l0aW9uOiBhYnNvbHV0ZTsgd2lkdGg6IDIycHg7IGhlaWdodDogMjJweDsgbWFyZ2luOiAtMTFweCAwIDAgLTExcHg7IGJvcmRlci1yYWRpdXM6IDUwJTsgcG9pbnRlci1ldmVudHM6IG5vbmU7CiAgYm9yZGVyOiAycHggc29saWQgI2ZmZjsgYm94LXNoYWRvdzogMCAwIDAgMnB4IHZhcigtLXBsLWFjY2VudCksIDAgMnB4IDhweCByZ2JhKDAsIDAsIDAsIC4zNSk7IGJhY2tncm91bmQ6IHJnYmEoMzksIDgwLCAxNDMsIC4yNSk7Cn0KLnBsLWNyb3BzIHsgZGlzcGxheTogZmxleDsgZ2FwOiAxMHB4OyBhbGlnbi1pdGVtczogZmxleC1lbmQ7IG1hcmdpbi10b3A6IDRweDsgfQoucGwtY3JvcHMgZmlndXJlIHsgbWFyZ2luOiAwOyBkaXNwbGF5OiBncmlkOyBnYXA6IDRweDsgfQoucGwtY3JvcHMgZmlnY2FwdGlvbiB7IGZvbnQtc2l6ZTogMTFweDsgY29sb3I6IHZhcigtLXBsLWluay0zKTsgfQoucGwtY3JvcCB7IGRpc3BsYXk6IGJsb2NrOyBiYWNrZ3JvdW5kOiAjZjBlZWU5IGNlbnRlciAvIGNvdmVyIG5vLXJlcGVhdDsgYm9yZGVyLXJhZGl1czogNnB4OyBib3JkZXI6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgfQoucGwtY3JvcC13aWRlIHsgd2lkdGg6IDE3MnB4OyBoZWlnaHQ6IDcycHg7IH0KLnBsLWNyb3AtdGFsbCB7IHdpZHRoOiA1OHB4OyBoZWlnaHQ6IDcycHg7IH0KCi8qIC0tLS0tLS0tLS0gdmlkZW8gLS0tLS0tLS0tLSAqLwoucGwtdmlkZW8tdGh1bWIgeyBwb3NpdGlvbjogcmVsYXRpdmU7IGFzcGVjdC1yYXRpbzogMTYgLyA5OyBib3JkZXItcmFkaXVzOiAxMHB4OyBvdmVyZmxvdzogaGlkZGVuOyBiYWNrZ3JvdW5kOiAjMWMxZDIxOyBjb2xvcjogI2NmZDJkODsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsganVzdGlmeS1jb250ZW50OiBjZW50ZXI7IGZvbnQtc2l6ZTogMTNweDsgfQoucGwtdmlkZW8tdGh1bWIgaW1nIHsgd2lkdGg6IDEwMCU7IGhlaWdodDogMTAwJTsgb2JqZWN0LWZpdDogY292ZXI7IH0KLnBsLXZpZGVvLXBsYXkgeyBwb3NpdGlvbjogYWJzb2x1dGU7IHdpZHRoOiA1MnB4OyBoZWlnaHQ6IDUycHg7IGJvcmRlci1yYWRpdXM6IDUwJTsgYmFja2dyb3VuZDogcmdiYSgwLCAwLCAwLCAuNik7IGNvbG9yOiAjZmZmOyBkaXNwbGF5OiBmbGV4OyBhbGlnbi1pdGVtczogY2VudGVyOyBqdXN0aWZ5LWNvbnRlbnQ6IGNlbnRlcjsgfQoucGwtdmlkZW8tcGxheSBzdmcgeyB3aWR0aDogMjJweDsgaGVpZ2h0OiAyMnB4OyBmaWxsOiBjdXJyZW50Q29sb3I7IHN0cm9rZTogbm9uZTsgbWFyZ2luLWxlZnQ6IDNweDsgfQoKLyogLS0tLS0tLS0tLSBwYWdlIHNldHRpbmdzIC0tLS0tLS0tLS0gKi8KLnBsLWNvdW50IHsganVzdGlmeS1zZWxmOiBlbmQ7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IGZvbnQtc2l6ZTogMTJweDsgfQoucGwtY291bnQuaXMtbG9uZyB7IGNvbG9yOiB2YXIoLS1wbC13YXJuKTsgfQoucGwtc2hhcmUtY2FyZCB7IGJvcmRlcjogMXB4IHNvbGlkIHZhcigtLXBsLWxpbmUtMik7IGJvcmRlci1yYWRpdXM6IDEycHg7IG92ZXJmbG93OiBoaWRkZW47IGJhY2tncm91bmQ6ICNmZmY7IH0KLnBsLXNoYXJlLWltZyB7IGFzcGVjdC1yYXRpbzogMTIwMCAvIDYzMDsgYmFja2dyb3VuZDogI2VjZWFlNCBjZW50ZXIgLyBjb3ZlciBuby1yZXBlYXQ7IGRpc3BsYXk6IGZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGp1c3RpZnktY29udGVudDogY2VudGVyOyBjb2xvcjogdmFyKC0tcGwtaW5rLTMpOyBmb250LXNpemU6IDEzcHg7IH0KLnBsLXNoYXJlLXRleHQgeyBkaXNwbGF5OiBncmlkOyBnYXA6IDJweDsgcGFkZGluZzogMTBweCAxMnB4IDEycHg7IH0KLnBsLXNoYXJlLXRleHQgc21hbGwgeyBjb2xvcjogdmFyKC0tcGwtaW5rLTMpOyBmb250LXNpemU6IDEycHg7IH0KLnBsLXNoYXJlLXRleHQgYiB7IGZvbnQtd2VpZ2h0OiA2MDA7IGxpbmUtaGVpZ2h0OiAxLjM7IH0KLnBsLXNoYXJlLXRleHQgc3BhbiB7IGNvbG9yOiB2YXIoLS1wbC1pbmstMik7IGZvbnQtc2l6ZTogMTNweDsgbGluZS1oZWlnaHQ6IDEuMzU7IGRpc3BsYXk6IC13ZWJraXQtYm94OyAtd2Via2l0LWxpbmUtY2xhbXA6IDI7IC13ZWJraXQtYm94LW9yaWVudDogdmVydGljYWw7IG92ZXJmbG93OiBoaWRkZW47IH0KLnBsLW1vZGFsIC5wbC1maWVsZCB7IG1hcmdpbi10b3A6IDJweDsgfQoKLyogLS0tLS0tLS0tLSBibG9jayBiYXIgKGR1cGxpY2F0ZSwgbW92ZSwgZGVsZXRlKSAtLS0tLS0tLS0tICovCi5wbC1pdGVtYmFyIHsKICBwb3NpdGlvbjogZml4ZWQ7IHotaW5kZXg6IDI4OyBkaXNwbGF5OiBmbGV4OyBhbGlnbi1pdGVtczogY2VudGVyOyBnYXA6IDFweDsgcGFkZGluZzogM3B4OwogIGJhY2tncm91bmQ6IHZhcigtLXBsLXN1cmZhY2UpOyBib3JkZXI6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lLTIpOyBib3JkZXItcmFkaXVzOiAxMHB4OyBib3gtc2hhZG93OiB2YXIoLS1wbC1zaGFkb3cpOwp9Ci5wbC1pdGVtYmFyIC5wbC1pY29uIHsgd2lkdGg6IDMwcHg7IGhlaWdodDogMzBweDsgfQoucGwtaXRlbWJhciAucGwtaWNvbiBzdmcgeyB3aWR0aDogMTZweDsgaGVpZ2h0OiAxNnB4OyB9Ci5wbC1pdGVtYmFyLWxhYmVsIHsgZm9udC1zaXplOiAxMXB4OyBmb250LXdlaWdodDogNjAwOyBsZXR0ZXItc3BhY2luZzogLjA0ZW07IHRleHQtdHJhbnNmb3JtOiB1cHBlcmNhc2U7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IHBhZGRpbmc6IDAgNnB4IDAgOHB4OyB9Ci5wbC1pY29uLWRhbmdlcjpob3ZlciB7IGNvbG9yOiB2YXIoLS1wbC1kYW5nZXIpOyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1kYW5nZXItc29mdCk7IH0KCi8qIC0tLS0tLS0tLS0gZHJhd2VyIC0tLS0tLS0tLS0gKi8KLnBsLWRyYXdlciB7CiAgcG9zaXRpb246IGZpeGVkOyB0b3A6IGNhbGModmFyKC0tcGwtYmFyKSArIDhweCk7IHJpZ2h0OiA4cHg7IGJvdHRvbTogOHB4OyB3aWR0aDogMzgwcHg7IG1heC13aWR0aDogY2FsYygxMDB2dyAtIDE2cHgpOyB6LWluZGV4OiAyNTsKICBkaXNwbGF5OiBmbGV4OyBmbGV4LWRpcmVjdGlvbjogY29sdW1uOyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1zdXJmYWNlKTsgYm9yZGVyOiAxcHggc29saWQgdmFyKC0tcGwtbGluZSk7CiAgYm9yZGVyLXJhZGl1czogMTRweDsgYm94LXNoYWRvdzogdmFyKC0tcGwtc2hhZG93KTsgb3ZlcmZsb3c6IGhpZGRlbjsKICBhbmltYXRpb246IHBsLWluIC4xNnMgZWFzZS1vdXQ7Cn0KQGtleWZyYW1lcyBwbC1pbiB7IGZyb20geyBvcGFjaXR5OiAwOyB0cmFuc2Zvcm06IHRyYW5zbGF0ZVgoMTJweCk7IH0gfQoucGwtZHJhd2VyLWhlYWQgeyBkaXNwbGF5OiBmbGV4OyBhbGlnbi1pdGVtczogY2VudGVyOyBqdXN0aWZ5LWNvbnRlbnQ6IHNwYWNlLWJldHdlZW47IHBhZGRpbmc6IDEycHggMTJweCAxMnB4IDE4cHg7IGJvcmRlci1ib3R0b206IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgfQoucGwtZHJhd2VyLWhlYWQgaDIgeyBmb250LXNpemU6IDE1cHg7IG1hcmdpbjogMDsgfQoucGwtZHJhd2VyLWJvZHkgeyBwYWRkaW5nOiAxNnB4IDE4cHg7IG92ZXJmbG93OiBhdXRvOyBmbGV4OiAxOyBkaXNwbGF5OiBncmlkOyBnYXA6IDE2cHg7IGFsaWduLWNvbnRlbnQ6IHN0YXJ0OyB9Ci5wbC1kcmF3ZXItZm9vdCB7IGRpc3BsYXk6IGZsZXg7IGdhcDogOHB4OyBqdXN0aWZ5LWNvbnRlbnQ6IGZsZXgtZW5kOyBwYWRkaW5nOiAxMnB4IDE4cHg7IGJvcmRlci10b3A6IDFweCBzb2xpZCB2YXIoLS1wbC1saW5lKTsgYmFja2dyb3VuZDogI2ZjZmJmOTsgfQoucGwtZmllbGQgeyBkaXNwbGF5OiBncmlkOyBnYXA6IDZweDsgfQoucGwtZmllbGQgPiBzcGFuIHsgZm9udC13ZWlnaHQ6IDYwMDsgZm9udC1zaXplOiAxM3B4OyB9Ci5wbC1maWVsZCBzbWFsbCB7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IGZvbnQtc2l6ZTogMTJweDsgfQoucGwtaW5wdXQgeyBoZWlnaHQ6IDM4cHg7IH0KdGV4dGFyZWEucGwtaW5wdXQgeyBoZWlnaHQ6IGF1dG87IG1pbi1oZWlnaHQ6IDcwcHg7IHBhZGRpbmc6IDhweCAxMnB4OyByZXNpemU6IHZlcnRpY2FsOyB9Ci5wbC1yb3cgeyBkaXNwbGF5OiBmbGV4OyBhbGlnbi1pdGVtczogY2VudGVyOyBnYXA6IDhweDsgfQoucGwtbm90ZSB7IGZvbnQtc2l6ZTogMTJweDsgY29sb3I6IHZhcigtLXBsLXdhcm4pOyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC13YXJuLXNvZnQpOyBib3JkZXItcmFkaXVzOiA4cHg7IHBhZGRpbmc6IDhweCAxMHB4OyB9CgoucGwtY3VycmVudCB7IGJvcmRlci1yYWRpdXM6IDEwcHg7IG92ZXJmbG93OiBoaWRkZW47IGJhY2tncm91bmQ6IHJlcGVhdGluZy1jb25pYy1ncmFkaWVudCgjZjBlZWU5IDAgMjUlLCAjZmZmIDAgNTAlKSAwIDAgLyAxNnB4IDE2cHg7IGJvcmRlcjogMXB4IHNvbGlkIHZhcigtLXBsLWxpbmUpOyBhc3BlY3QtcmF0aW86IDE2IC8gMTA7IGRpc3BsYXk6IGZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGp1c3RpZnktY29udGVudDogY2VudGVyOyB9Ci5wbC1jdXJyZW50IGltZyB7IG1heC13aWR0aDogMTAwJTsgbWF4LWhlaWdodDogMTAwJTsgb2JqZWN0LWZpdDogY29udGFpbjsgZGlzcGxheTogYmxvY2s7IH0KLnBsLWRyb3AgewogIGJvcmRlcjogMS41cHggZGFzaGVkIHZhcigtLXBsLWxpbmUtMik7IGJvcmRlci1yYWRpdXM6IDEwcHg7IHBhZGRpbmc6IDE4cHg7IHRleHQtYWxpZ246IGNlbnRlcjsgY29sb3I6IHZhcigtLXBsLWluay0yKTsKICBkaXNwbGF5OiBncmlkOyBnYXA6IDhweDsganVzdGlmeS1pdGVtczogY2VudGVyOyB0cmFuc2l0aW9uOiBiYWNrZ3JvdW5kIC4xMnMsIGJvcmRlci1jb2xvciAuMTJzOwp9Ci5wbC1kcm9wLmlzLW92ZXIgeyBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1hY2NlbnQtc29mdCk7IGJvcmRlci1jb2xvcjogdmFyKC0tcGwtYWNjZW50KTsgfQoucGwtZHJvcCBzbWFsbCB7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IH0KLnBsLXByb2dyZXNzIHsgaGVpZ2h0OiA0cHg7IGJvcmRlci1yYWRpdXM6IDRweDsgYmFja2dyb3VuZDogdmFyKC0tcGwtbGluZSk7IG92ZXJmbG93OiBoaWRkZW47IHdpZHRoOiAxMDAlOyB9Ci5wbC1wcm9ncmVzcyBpIHsgZGlzcGxheTogYmxvY2s7IGhlaWdodDogMTAwJTsgd2lkdGg6IDQwJTsgYmFja2dyb3VuZDogdmFyKC0tcGwtYWNjZW50KTsgYW5pbWF0aW9uOiBwbC1zbGlkZSAxcyBlYXNlLWluLW91dCBpbmZpbml0ZTsgfQpAa2V5ZnJhbWVzIHBsLXNsaWRlIHsgZnJvbSB7IHRyYW5zZm9ybTogdHJhbnNsYXRlWCgtMTAwJSk7IH0gdG8geyB0cmFuc2Zvcm06IHRyYW5zbGF0ZVgoMjUwJSk7IH0gfQoucGwtZ3JpZCB7IGRpc3BsYXk6IGdyaWQ7IGdyaWQtdGVtcGxhdGUtY29sdW1uczogcmVwZWF0KDMsIDFmcik7IGdhcDogOHB4OyB9Ci5wbC10aHVtYiB7CiAgYXNwZWN0LXJhdGlvOiAxOyBib3JkZXItcmFkaXVzOiA4cHg7IG92ZXJmbG93OiBoaWRkZW47IGJvcmRlcjogMnB4IHNvbGlkIHRyYW5zcGFyZW50OyBwYWRkaW5nOiAwOyBjdXJzb3I6IHBvaW50ZXI7CiAgYmFja2dyb3VuZDogI2YwZWVlOTsKfQoucGwtdGh1bWIgaW1nIHsgd2lkdGg6IDEwMCU7IGhlaWdodDogMTAwJTsgb2JqZWN0LWZpdDogY292ZXI7IGRpc3BsYXk6IGJsb2NrOyB9Ci5wbC10aHVtYjpob3ZlciB7IGJvcmRlci1jb2xvcjogdmFyKC0tcGwtbGluZS0yKTsgfQoucGwtdGh1bWJbYXJpYS1wcmVzc2VkPSJ0cnVlIl0geyBib3JkZXItY29sb3I6IHZhcigtLXBsLWFjY2VudCk7IGJveC1zaGFkb3c6IDAgMCAwIDJweCB2YXIoLS1wbC1hY2NlbnQtc29mdCk7IH0KLnBsLWVtcHR5IHsgY29sb3I6IHZhcigtLXBsLWluay0zKTsgZm9udC1zaXplOiAxM3B4OyB9CgoucGwtdmVyc2lvbnMgeyBkaXNwbGF5OiBncmlkOyBnYXA6IDJweDsgfQoucGwtdmVyc2lvbiB7IGRpc3BsYXk6IGZsZXg7IGFsaWduLWl0ZW1zOiBjZW50ZXI7IGdhcDogOHB4OyBwYWRkaW5nOiAxMHB4IDhweDsgYm9yZGVyLXJhZGl1czogOHB4OyB9Ci5wbC12ZXJzaW9uOmhvdmVyIHsgYmFja2dyb3VuZDogI2Y2ZjRmMDsgfQoucGwtdmVyc2lvbi1kYXRlIHsgZmxleDogMTsgbWluLXdpZHRoOiAwOyB9Ci5wbC12ZXJzaW9uLWRhdGUgYiB7IGRpc3BsYXk6IGJsb2NrOyBmb250LXdlaWdodDogNTAwOyB9Ci5wbC12ZXJzaW9uLWRhdGUgc3BhbiB7IGNvbG9yOiB2YXIoLS1wbC1pbmstMyk7IGZvbnQtc2l6ZTogMTJweDsgfQoucGwtdmVyc2lvbiAucGwtYnRuIHsgaGVpZ2h0OiAzMHB4OyBwYWRkaW5nOiAwIDEwcHg7IGZvbnQtc2l6ZTogMTNweDsgfQoKLyogLS0tLS0tLS0tLSBtb2RhbCAtLS0tLS0tLS0tICovCi5wbC1vdmVybGF5IHsgcG9zaXRpb246IGZpeGVkOyBpbnNldDogMDsgei1pbmRleDogNTA7IGJhY2tncm91bmQ6IHJnYmEoMjAsIDIyLCAyOCwgLjM1KTsgZGlzcGxheTogZmxleDsgYWxpZ24taXRlbXM6IGNlbnRlcjsganVzdGlmeS1jb250ZW50OiBjZW50ZXI7IHBhZGRpbmc6IDE2cHg7IH0KLnBsLW1vZGFsIHsgd2lkdGg6IDEwMCU7IG1heC13aWR0aDogNDgwcHg7IGJhY2tncm91bmQ6IHZhcigtLXBsLXN1cmZhY2UpOyBib3JkZXItcmFkaXVzOiAxNHB4OyBib3gtc2hhZG93OiB2YXIoLS1wbC1zaGFkb3cpOyBwYWRkaW5nOiAyMnB4OyBkaXNwbGF5OiBncmlkOyBnYXA6IDE0cHg7IH0KLnBsLW1vZGFsIGgyIHsgbWFyZ2luOiAwOyBmb250LXNpemU6IDE3cHg7IH0KLnBsLW1vZGFsIHAgeyBtYXJnaW46IDA7IGNvbG9yOiB2YXIoLS1wbC1pbmstMik7IH0KLnBsLW1vZGFsIHRleHRhcmVhIHsgbWluLWhlaWdodDogMTQwcHg7IH0KLnBsLW1vZGFsLWFjdGlvbnMgeyBkaXNwbGF5OiBmbGV4OyBnYXA6IDhweDsganVzdGlmeS1jb250ZW50OiBmbGV4LWVuZDsgfQoKLyogLS0tLS0tLS0tLSB0b2FzdHMgLS0tLS0tLS0tLSAqLwoucGwtdG9hc3RzIHsgcG9zaXRpb246IGZpeGVkOyBsZWZ0OiA1MCU7IGJvdHRvbTogMThweDsgdHJhbnNmb3JtOiB0cmFuc2xhdGVYKC01MCUpOyB6LWluZGV4OiA2MDsgZGlzcGxheTogZ3JpZDsgZ2FwOiA4cHg7IGp1c3RpZnktaXRlbXM6IGNlbnRlcjsgcG9pbnRlci1ldmVudHM6IG5vbmU7IH0KLnBsLXRvYXN0IHsKICBiYWNrZ3JvdW5kOiB2YXIoLS1wbC1pbmspOyBjb2xvcjogI2ZmZjsgcGFkZGluZzogMTBweCAxNHB4OyBib3JkZXItcmFkaXVzOiAxMHB4OyBib3gtc2hhZG93OiB2YXIoLS1wbC1zaGFkb3cpOwogIG1heC13aWR0aDogbWluKDU2MHB4LCBjYWxjKDEwMHZ3IC0gMzJweCkpOyBhbmltYXRpb246IHBsLXVwIC4xOHMgZWFzZS1vdXQ7IHBvaW50ZXItZXZlbnRzOiBhdXRvOwp9Ci5wbC10b2FzdC5pcy1lcnJvciB7IGJhY2tncm91bmQ6IHZhcigtLXBsLWRhbmdlcik7IH0KLnBsLXRvYXN0LmlzLW9rIHsgYmFja2dyb3VuZDogIzFkM2IyYzsgfQpAa2V5ZnJhbWVzIHBsLXVwIHsgZnJvbSB7IG9wYWNpdHk6IDA7IHRyYW5zZm9ybTogdHJhbnNsYXRlWSg4cHgpOyB9IH0KCi8qIC0tLS0tLS0tLS0gc21hbGwgc2NyZWVucyAtLS0tLS0tLS0tICovCkBtZWRpYSAobWF4LXdpZHRoOiA5MDBweCkgewogIC5wbC1tb2RlLCAucGwtc3RhdHVzLXRleHQsIC5wbC1iYXIgLnBsLWJyYW5kIHNwYW46bGFzdC1jaGlsZCB7IGRpc3BsYXk6IG5vbmU7IH0KICAucGwtYnRuLWxhYmVsIHsgZGlzcGxheTogbm9uZTsgfQp9CkBtZWRpYSAobWF4LXdpZHRoOiA1NjBweCkgewogIC5wbC1iYXIgeyBnYXA6IDRweDsgcGFkZGluZzogMCA2cHg7IH0KICAucGwtc2VsZWN0IHsgbWF4LXdpZHRoOiAxMzBweDsgbWluLXdpZHRoOiAwOyB9CiAgLnBsLXNlcCB7IGRpc3BsYXk6IG5vbmU7IH0KICAucGwtZHJhd2VyIHsgdG9wOiBhdXRvOyBoZWlnaHQ6IDc4dmg7IGxlZnQ6IDhweDsgcmlnaHQ6IDhweDsgd2lkdGg6IGF1dG87IH0KfQo=');
            case 'icon.svg':
                return base64_decode('PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiIgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIj48cGF0aCBmaWxsPSJjdXJyZW50Q29sb3IiIGQ9Ik0yNy42IDMuMmMtOC45IDEuMS0xNS41IDYuMy0xOC42IDE0LjItLjcgMS44LTEuMiAzLjctMS41IDUuNkw0LjYgMjcuNmExIDEgMCAwIDAgMS43IDFsMi43LTMuOWMxLjktLjMgMy44LS45IDUuNi0xLjcgMy42LTEuNiA2LjUtNC4xIDguNi03LjNsLTQuNi42IDUuNi0zLjRjMS44LTMuMyAyLjktNyAzLjQtMTAuOWEuOC44IDAgMCAwLTEuMS0uOHpNMTAuMiAyMS45Yy42LTIuNCAxLjYtNC42IDMtNi42IDIuNi0zLjcgNi4yLTYuNiAxMC40LTguMy0zLjggMi43LTcgNi4yLTkuMyAxMC4zLTEuMSAxLjktMi4xIDMuNC00LjEgNC42eiIvPjwvc3ZnPgo=');
            case 'en.json':
                return base64_decode('ewogICJhcHAubmFtZSI6ICJQbHVtYUVkaXRvciIsCiAgImFwcC50YWdsaW5lIjogIkVkaXQgeW91ciBzdGF0aWMgc2l0ZSBpbiB0aGUgYnJvd3Nlci4iLAogICJzZXR1cC50aXRsZSI6ICJTZXQgdXAgUGx1bWFFZGl0b3IiLAogICJzZXR1cC5pbnRybyI6ICJDaG9vc2UgdGhlIHBhc3N3b3JkIHlvdSB3aWxsIHVzZSB0byBlZGl0IHRoaXMgc2l0ZS4gV2hvZXZlciBvcGVucyB0aGlzIHBhZ2UgZmlyc3Qgc2V0cyBpdCwgc28gZG8gaXQgbm93LiIsCiAgInNldHVwLnBhc3N3b3JkIjogIlBhc3N3b3JkIiwKICAic2V0dXAuY29uZmlybSI6ICJSZXBlYXQgcGFzc3dvcmQiLAogICJzZXR1cC5oaW50IjogIkF0IGxlYXN0IDEwIGNoYXJhY3RlcnMuIEEgc2hvcnQgc2VudGVuY2Ugd29ya3Mgd2VsbC4iLAogICJzZXR1cC5zdWJtaXQiOiAiQ3JlYXRlIHBhc3N3b3JkIGFuZCBzdGFydCBlZGl0aW5nIiwKICAic2V0dXAuaHR0cCI6ICJUaGlzIHNpdGUgaXMgbm90IHVzaW5nIEhUVFBTLiBZb3VyIHBhc3N3b3JkIHdpbGwgdHJhdmVsIHVuZW5jcnlwdGVkLiBBc2sgeW91ciBob3N0IHRvIGVuYWJsZSBIVFRQUyAoaXQgaXMgdXN1YWxseSBmcmVlKS4iLAogICJlbnYudGl0bGUiOiAiUGx1bWFFZGl0b3IgY2FuJ3QgcnVuIGhlcmUgeWV0IiwKICAiZW52LmludHJvIjogIkZpeCB0aGVzZSBpdGVtcyBvbiB5b3VyIGhvc3RpbmcsIHRoZW4gcmVsb2FkIHRoaXMgcGFnZS4iLAogICJlbnYucGhwIjogIlBIUCA4LjEgb3IgbmV3ZXIgaXMgcmVxdWlyZWQgKHRoaXMgc2VydmVyIGhhcyB7dn0pLiIsCiAgImVudi5nZCI6ICJUaGUgUEhQIFwiZ2RcIiBleHRlbnNpb24gaXMgcmVxdWlyZWQgdG8gcHJvY2VzcyBpbWFnZXMuIiwKICAiZW52Lm1ic3RyaW5nIjogIlRoZSBQSFAgXCJtYnN0cmluZ1wiIGV4dGVuc2lvbiBpcyByZXF1aXJlZC4iLAogICJlbnYud3JpdGFibGUiOiAiUEhQIGNhbid0IHdyaXRlIHRvIHRoZSBzaXRlIGZvbGRlci4gUGx1bWFFZGl0b3IgbmVlZHMgdG8gY3JlYXRlIHRoZSBmb2xkZXJzIC5wbHVtYSBhbmQgcGx1bWEtbWVkaWEgdGhlcmUuIiwKICAiZW52LnBhZ2VzIjogIk5vIC5odG1sIHBhZ2VzIHdlcmUgZm91bmQgbmV4dCB0byB0aGlzIGZpbGUuIFB1dCBwbHVtYS5waHAgaW4gdGhlIHNhbWUgZm9sZGVyIGFzIHlvdXIgaW5kZXguaHRtbC4iLAogICJlbnYucmVhZG9ubHkiOiAie259IHBhZ2UocykgYXJlIHJlYWQtb25seSBmb3IgUEhQIGFuZCBjYW4ndCBiZSBzYXZlZDoge2xpc3R9IiwKICAibG9naW4udGl0bGUiOiAiU2lnbiBpbiIsCiAgImxvZ2luLnBhc3N3b3JkIjogIlBhc3N3b3JkIiwKICAibG9naW4ucmVtZW1iZXIiOiAiS2VlcCBtZSBzaWduZWQgaW4gZm9yIDMwIGRheXMiLAogICJsb2dpbi5zdWJtaXQiOiAiU2lnbiBpbiIsCiAgImxvZ2luLmZvcmdvdCI6ICJGb3Jnb3QgdGhlIHBhc3N3b3JkPyIsCiAgImxvZ2luLnJlc2V0IjogIkRlbGV0ZSAucGx1bWEvY29uZmlnLnBocCB3aXRoIHlvdXIgaG9zdGluZyBmaWxlIG1hbmFnZXIgb3IgRlRQLCB0aGVuIG9wZW4gdGhpcyBwYWdlIGFnYWluIHRvIHNldCBhIG5ldyBvbmUuIFlvdXIgaGlzdG9yeSBhbmQgaW1hZ2VzIGFyZSBrZXB0LiIsCiAgImJhci5wYWdlIjogIlBhZ2UiLAogICJiYXIuc2F2ZWQiOiAiQWxsIGNoYW5nZXMgc2F2ZWQiLAogICJiYXIudW5zYXZlZCI6ICJVbnNhdmVkIGNoYW5nZXMiLAogICJiYXIuc2F2aW5nIjogIlNhdmluZ+KApiIsCiAgImJhci5zYXZlIjogIlNhdmUiLAogICJiYXIudW5kbyI6ICJVbmRvIiwKICAiYmFyLnJlZG8iOiAiUmVkbyIsCiAgImJhci5wcmV2aWV3IjogIlByZXZpZXciLAogICJiYXIuZWRpdCI6ICJFZGl0IiwKICAiYmFyLmhpc3RvcnkiOiAiSGlzdG9yeSIsCiAgImJhci5tZW51IjogIk1lbnUiLAogICJiYXIudmlldyI6ICJWaWV3IGxpdmUgcGFnZSIsCiAgImJhci5zdXBwb3J0IjogIlN1cHBvcnQgUGx1bWFFZGl0b3IiLAogICJiYXIuaGVscCI6ICJIZWxwIiwKICAiYmFyLmxvZ291dCI6ICJTaWduIG91dCIsCiAgImJhci5tb2RlLmF1dG8iOiAiQXV0b21hdGljIG1vZGU6IGhlYWRpbmdzLCBwYXJhZ3JhcGhzLCBsaW5rcyBhbmQgaW1hZ2VzIGFyZSBlZGl0YWJsZS4iLAogICJiYXIubW9kZS5tYXJrZWQiOiAiTWFya2VkIG1vZGU6IG9ubHkgZWxlbWVudHMgcHJlcGFyZWQgZm9yIGVkaXRpbmcgYXJlIGVkaXRhYmxlLiIsCiAgImhpbnQudGV4dCI6ICJUZXh0IOKAlCBjbGljayB0byBlZGl0IiwKICAiaGludC5yaWNoIjogIlRleHQg4oCUIGNsaWNrIHRvIGVkaXQiLAogICJoaW50LmxpbmsiOiAiTGluayDigJQgY2xpY2sgdG8gZWRpdCIsCiAgImhpbnQuaW1hZ2UiOiAiSW1hZ2Ug4oCUIGNsaWNrIHRvIHJlcGxhY2UiLAogICJoaW50LmJnIjogIkJhY2tncm91bmQgaW1hZ2Ug4oCUIGNsaWNrIHRvIHJlcGxhY2UiLAogICJoaW50LnN0YXJ0IjogIkNsaWNrIGFueSBoaWdobGlnaHRlZCB0ZXh0IG9yIGltYWdlIHRvIGVkaXQgaXQuIiwKICAiaGludC5ub25lIjogIk5vdGhpbmcgb24gdGhpcyBwYWdlIGNhbiBiZSBlZGl0ZWQgeWV0LiBNYXJrIGVsZW1lbnRzIHdpdGggZGF0YS1wbHVtYSwgb3IgY2hlY2sgdGhhdCB0aGUgcGFnZSBoYXMgaGVhZGluZ3MgYW5kIHBhcmFncmFwaHMuIiwKICAiaXRlbS5ibG9jayI6ICJCbG9jayIsCiAgIml0ZW0uZHVwbGljYXRlIjogIkR1cGxpY2F0ZSIsCiAgIml0ZW0udXAiOiAiTW92ZSB1cCIsCiAgIml0ZW0uZG93biI6ICJNb3ZlIGRvd24iLAogICJpdGVtLmxlZnQiOiAiTW92ZSBsZWZ0IiwKICAiaXRlbS5yaWdodCI6ICJNb3ZlIHJpZ2h0IiwKICAiaXRlbS5kZWxldGUiOiAiRGVsZXRlIiwKICAiaXRlbS5sYXN0IjogIlRoaXMgaXMgdGhlIG9ubHkgb25lIGxlZnQsIHNvIGl0IGNhbid0IGJlIGRlbGV0ZWQuIiwKICAiaXRlbS5kZWxldGVkIjogIkJsb2NrIGRlbGV0ZWQuIFByZXNzIEN0cmwrWiB0byB1bmRvLiIsCiAgInRiLmJvbGQiOiAiQm9sZCIsCiAgInRiLml0YWxpYyI6ICJJdGFsaWMiLAogICJ0Yi5saW5rIjogIkxpbmsiLAogICJ0Yi5kb25lIjogIkRvbmUiLAogICJsaW5rLnRpdGxlIjogIkVkaXQgbGluayIsCiAgImxpbmsudGV4dCI6ICJUZXh0IiwKICAibGluay51cmwiOiAiQWRkcmVzcyIsCiAgImxpbmsudXJsLmhpbnQiOiAiQSBwYWdlIG9mIHRoaXMgc2l0ZSwgYSB3ZWIgYWRkcmVzcywgYW4gZW1haWwgb3IgYSBwaG9uZSBudW1iZXIiLAogICJsaW5rLnBhZ2UiOiAiUGFnZXMgb24gdGhpcyBzaXRlIiwKICAibGluay5uZXd0YWIiOiAiT3BlbiBpbiBhIG5ldyB0YWIiLAogICJsaW5rLmFwcGx5IjogIkFwcGx5IiwKICAibGluay5yZW1vdmUiOiAiUmVtb3ZlIGxpbmsiLAogICJsaW5rLmNhbmNlbCI6ICJDYW5jZWwiLAogICJsaW5rLndhcm4iOiAiVGhpcyBhZGRyZXNzIGxvb2tzIHVudXN1YWwuIENoZWNrIGl0IGJlZm9yZSBzYXZpbmcuIiwKICAibGluay5zZWxlY3QiOiAiU2VsZWN0IHNvbWUgdGV4dCBmaXJzdCwgdGhlbiBhZGQgdGhlIGxpbmsuIiwKICAiaW1nLnRpdGxlIjogIkltYWdlIiwKICAiaW1nLmJnLnRpdGxlIjogIkJhY2tncm91bmQgaW1hZ2UiLAogICJpbWcudXBsb2FkIjogIlVwbG9hZCBhIG5ldyBpbWFnZSIsCiAgImltZy5kcm9wIjogIm9yIGRyb3AgYSBmaWxlIGhlcmUiLAogICJpbWcubGlicmFyeSI6ICJZb3VyIGltYWdlcyIsCiAgImltZy5saWJyYXJ5LmVtcHR5IjogIkltYWdlcyB5b3UgdXBsb2FkIGFwcGVhciBoZXJlLiIsCiAgImltZy5hbHQiOiAiRGVzY3JpcHRpb24gKGFsdCB0ZXh0KSIsCiAgImltZy5hbHQuaGludCI6ICJEZXNjcmliZSB0aGUgaW1hZ2UgZm9yIHBlb3BsZSB3aG8gY2FuJ3Qgc2VlIGl0IGFuZCBmb3Igc2VhcmNoIGVuZ2luZXMuIiwKICAiaW1nLmFwcGx5IjogIkFwcGx5IiwKICAiaW1nLmNhbmNlbCI6ICJDYW5jZWwiLAogICJpbWcudXBsb2FkaW5nIjogIk9wdGltaXNpbmcgaW1hZ2XigKYiLAogICJpbWcuY3VycmVudCI6ICJDdXJyZW50IGltYWdlIiwKICAiaGlzdC50aXRsZSI6ICJIaXN0b3J5IiwKICAiaGlzdC5pbnRybyI6ICJBIGNvcHkgb2YgdGhpcyBwYWdlIGlzIGtlcHQgYmVmb3JlIGV2ZXJ5IHNhdmUuIiwKICAiaGlzdC5vcmlnaW5hbCI6ICJPcmlnaW5hbCAoYmVmb3JlIFBsdW1hRWRpdG9yKSIsCiAgImhpc3QucHJldmlldyI6ICJQcmV2aWV3IiwKICAiaGlzdC5yZXN0b3JlIjogIlJlc3RvcmUiLAogICJoaXN0LmVtcHR5IjogIk5vIHNhdmVkIHZlcnNpb25zIHlldC4gVGhleSBhcHBlYXIgYWZ0ZXIgdGhlIGZpcnN0IHNhdmUuIiwKICAiaGlzdC5jb25maXJtIjogIlJlc3RvcmUgdGhpcyB2ZXJzaW9uPyBUaGUgY3VycmVudCBwYWdlIHdpbGwgYmUga2VwdCBpbiB0aGUgaGlzdG9yeSwgc28geW91IGNhbiB1bmRvIHRoaXMuIiwKICAiaGlzdC5yZXN0b3JlZCI6ICJWZXJzaW9uIHJlc3RvcmVkIiwKICAic2F2ZS5vayI6ICJTYXZlZCIsCiAgInNhdmUubm90aGluZyI6ICJOb3RoaW5nIHRvIHNhdmUiLAogICJjb25maXJtLmxlYXZlIjogIllvdSBoYXZlIHVuc2F2ZWQgY2hhbmdlcy4gTGVhdmUgd2l0aG91dCBzYXZpbmc/IiwKICAiY29uZmlybS5kaXNjYXJkIjogIkRpc2NhcmQgY2hhbmdlcyIsCiAgImNvbmZsaWN0LnRpdGxlIjogIlRoaXMgcGFnZSBjaGFuZ2VkIG9uIHRoZSBzZXJ2ZXIiLAogICJjb25mbGljdC5ib2R5IjogIlNvbWVvbmUgKG9yIGFub3RoZXIgdGFiKSBjaGFuZ2VkIHRoaXMgcGFnZSBhZnRlciB5b3Ugb3BlbmVkIGl0LiBUbyBhdm9pZCBvdmVyd3JpdGluZyB0aGVpciB3b3JrLCBub3RoaW5nIHdhcyBzYXZlZC4gQ29weSB5b3VyIHRleHQgYmVsb3cgaWYgeW91IG5lZWQgaXQsIHRoZW4gcmVsb2FkLiIsCiAgImNvbmZsaWN0LnJlbG9hZCI6ICJSZWxvYWQgcGFnZSIsCiAgIm1vYmlsZS53YXJuIjogIlBsdW1hRWRpdG9yIHdvcmtzIGJlc3Qgb24gYSB0YWJsZXQgb3IgY29tcHV0ZXIuIFlvdSBjYW4gc3RpbGwgZWRpdCBoZXJlLCB3aXRoIGEgc21hbGxlciBzY3JlZW4uIiwKICAiY2xvc2UiOiAiQ2xvc2UiLAogICJlcnIuZ2VuZXJpYyI6ICJTb21ldGhpbmcgd2VudCB3cm9uZy4gUGxlYXNlIHRyeSBhZ2Fpbi4iLAogICJlcnIubmV0d29yayI6ICJDYW4ndCByZWFjaCB0aGUgc2VydmVyLiBDaGVjayB5b3VyIGNvbm5lY3Rpb24gYW5kIHRyeSBhZ2Fpbi4iLAogICJlcnIuc2Vzc2lvbiI6ICJZb3VyIHNlc3Npb24gZXhwaXJlZC4gU2lnbiBpbiBhZ2FpbiAoeW91ciB1bnNhdmVkIGNoYW5nZXMgd2lsbCBiZSBsb3N0IGlmIHlvdSBsZWF2ZSB0aGlzIHBhZ2UpLiIsCiAgImVyci5wYXNzd29yZC10b28tc2hvcnQiOiAiVGhlIHBhc3N3b3JkIG11c3QgaGF2ZSBhdCBsZWFzdCAxMCBjaGFyYWN0ZXJzLiIsCiAgImVyci5wYXNzd29yZC1taXNtYXRjaCI6ICJUaGUgdHdvIHBhc3N3b3JkcyBkb24ndCBtYXRjaC4iLAogICJlcnIuYWxyZWFkeS1pbnN0YWxsZWQiOiAiUGx1bWFFZGl0b3Igd2FzIGFscmVhZHkgc2V0IHVwLiBTaWduIGluIGluc3RlYWQuIiwKICAiZXJyLndyb25nLXBhc3N3b3JkIjogIldyb25nIHBhc3N3b3JkLiIsCiAgImVyci50b28tbWFueS1hdHRlbXB0cyI6ICJUb28gbWFueSBhdHRlbXB0cy4gV2FpdCAxNSBtaW51dGVzIGFuZCB0cnkgYWdhaW4uIiwKICAiZXJyLmJhZC1vcmlnaW4iOiAiUmVxdWVzdCByZWZ1c2VkIGZvciBzZWN1cml0eSByZWFzb25zLiBSZWxvYWQgdGhlIHBhZ2UgYW5kIHRyeSBhZ2Fpbi4iLAogICJlcnIuYmFkLXRva2VuIjogIllvdXIgc2Vzc2lvbiBleHBpcmVkLiBSZWxvYWQgdGhlIHBhZ2UuIiwKICAiZXJyLnBhZ2Utbm90LWZvdW5kIjogIlBhZ2Ugbm90IGZvdW5kLiIsCiAgImVyci5lbGVtZW50LW5vdC1mb3VuZCI6ICJUaGUgcGFnZSBjaGFuZ2VkIGFuZCBhbiBlZGl0ZWQgZWxlbWVudCBjYW4ndCBiZSBmb3VuZC4gUmVsb2FkIHRoZSBwYWdlLiIsCiAgImVyci5iYWQtY2hhbmdlIjogIkludmFsaWQgY2hhbmdlLiIsCiAgImVyci5iYWQtbGluayI6ICJUaGF0IGxpbmsgYWRkcmVzcyBpc24ndCBhbGxvd2VkLiIsCiAgImVyci5iYWQtaW1hZ2UiOiAiVGhhdCBpbWFnZSBhZGRyZXNzIGlzbid0IGFsbG93ZWQuIiwKICAiZXJyLmNvbmZsaWN0IjogIlRoZSBwYWdlIGNoYW5nZWQgb24gdGhlIHNlcnZlci4iLAogICJlcnIudmVyaWZ5LWZhaWxlZCI6ICJUaGUgY2hhbmdlIGNvdWxkIG5vdCBiZSBhcHBsaWVkIHNhZmVseSwgc28gbm90aGluZyB3YXMgc2F2ZWQuIiwKICAiZXJyLndyaXRlLWZhaWxlZCI6ICJUaGUgZmlsZSBjb3VsZCBub3QgYmUgd3JpdHRlbi4gQ2hlY2sgdGhlIGZvbGRlciBwZXJtaXNzaW9ucyBvbiB5b3VyIGhvc3RpbmcuIiwKICAiZXJyLmhpc3RvcnktZmFpbGVkIjogIlRoZSBiYWNrdXAgY29weSBjb3VsZCBub3QgYmUgd3JpdHRlbiwgc28gbm90aGluZyB3YXMgc2F2ZWQuIiwKICAiZXJyLnVwbG9hZC1mYWlsZWQiOiAiVGhlIHVwbG9hZCBmYWlsZWQuIiwKICAiZXJyLmltYWdlLXRvby1sYXJnZSI6ICJUaGUgaW1hZ2UgaXMgdG9vIGxhcmdlLiIsCiAgImVyci5ub3QtYW4taW1hZ2UiOiAiVGhhdCBmaWxlIGlzIG5vdCBhbiBpbWFnZS4iLAogICJlcnIuaW1hZ2UtdHlwZS1ub3QtYWxsb3dlZCI6ICJVc2UgYSBKUEVHLCBQTkcsIFdlYlAgb3IgR0lGIGltYWdlLiIsCiAgImVyci5nZC1taXNzaW5nIjogIkltYWdlIHByb2Nlc3NpbmcgaXNuJ3QgYXZhaWxhYmxlIG9uIHRoaXMgc2VydmVyLiIsCiAgImVyci5jYW5ub3QtY3JlYXRlLWRhdGEiOiAiUGx1bWFFZGl0b3IgY2FuJ3QgY3JlYXRlIHRoZSAucGx1bWEgZm9sZGVyLiBDaGVjayB0aGUgZm9sZGVyIHBlcm1pc3Npb25zLiIsCiAgImVyci5jYW5ub3QtY3JlYXRlLW1lZGlhIjogIlBsdW1hRWRpdG9yIGNhbid0IGNyZWF0ZSB0aGUgcGx1bWEtbWVkaWEgZm9sZGVyLiBDaGVjayB0aGUgZm9sZGVyIHBlcm1pc3Npb25zLiIsCiAgImVyci52ZXJzaW9uLW5vdC1mb3VuZCI6ICJUaGF0IHZlcnNpb24gbm8gbG9uZ2VyIGV4aXN0cy4iLAogICJlcnIubm90LWZvdW5kIjogIk5vdCBmb3VuZC4iLAogICJoaW50LnZpZGVvIjogIlZpZGVvIOKAlCBjbGljayB0byByZXBsYWNlIiwKICAiaGludC5zaGFyZWQiOiAic2hhcmVkIG9uIHtufSBwYWdlcyIsCiAgInNoYXJlZC5lZGl0aW5nIjogIlRoaXMgcGFydCBpcyBzaGFyZWQ6IHNhdmluZyB1cGRhdGVzIGl0IG9uIHtufSBwYWdlcy4iLAogICJpdGVtLnNoYXJlZCI6ICJTaGFyZWQgYmxvY2siLAogICJpbWcuZm9jdXMuaGludCI6ICJDbGljayB0aGUgaW1hZ2UgdG8gY2hvb3NlIHdoYXQgbXVzdCBzdGF5IHZpc2libGUgd2hlbiBpdCBpcyBjcm9wcGVkLiIsCiAgImltZy5jcm9wLndpZGUiOiAiV2lkZSIsCiAgImltZy5jcm9wLnRhbGwiOiAiVGFsbCIsCiAgInZpZGVvLnRpdGxlIjogIlZpZGVvIiwKICAidmlkZW8udXJsIjogIlZpZGVvIGxpbmsiLAogICJ2aWRlby51cmwuaGludCI6ICJQYXN0ZSBhIFlvdVR1YmUgb3IgVmltZW8gbGluay4gWW91VHViZSB2aWRlb3MgcGxheSBpbiBwcml2YWN5IG1vZGUsIHdpdGhvdXQgdHJhY2tpbmcgY29va2llcy4iLAogICJ2aWRlby5sYWJlbCI6ICJEZXNjcmlwdGlvbiIsCiAgInZpZGVvLmxhYmVsLmhpbnQiOiAiUmVhZCBieSBzY3JlZW4gcmVhZGVycywgZm9yIGV4YW1wbGUgXCJUcmFpbGVyXCIuIiwKICAibWV0YS50aXRsZSI6ICJQYWdlIHNldHRpbmdzIiwKICAibWV0YS5pbnRybyI6ICJIb3cgdGhpcyBwYWdlIGFwcGVhcnMgaW4gc2VhcmNoIHJlc3VsdHMgYW5kIHdoZW4gaXRzIGxpbmsgaXMgc2hhcmVkLiIsCiAgIm1ldGEucGFnZS50aXRsZSI6ICJQYWdlIHRpdGxlIiwKICAibWV0YS5wYWdlLnRpdGxlLmhpbnQiOiAiU2hvd24gaW4gdGhlIGJyb3dzZXIgdGFiIGFuZCBhcyB0aGUgaGVhZGxpbmUgaW4gc2VhcmNoIHJlc3VsdHMuIiwKICAibWV0YS5kZXNjcmlwdGlvbiI6ICJEZXNjcmlwdGlvbiIsCiAgIm1ldGEuZGVzY3JpcHRpb24uaGludCI6ICJUaGUgc2hvcnQgdGV4dCB1bmRlciB0aGUgdGl0bGUgaW4gc2VhcmNoIHJlc3VsdHMgYW5kIGxpbmsgcHJldmlld3MuIiwKICAibWV0YS5jb3VudCI6ICJ7bn0gY2hhcmFjdGVycyAoYWJvdXQge2lkZWFsfSBmaXQpIiwKICAibWV0YS5wcmV2aWV3IjogIkxpbmsgcHJldmlldyIsCiAgIm1ldGEuc2hhcmUudGl0bGUiOiAiVGl0bGUgd2hlbiBzaGFyZWQgKG9wdGlvbmFsKSIsCiAgIm1ldGEuc2hhcmUudGl0bGUuaGludCI6ICJMZWF2ZSBlbXB0eSB0byB1c2UgdGhlIHBhZ2UgdGl0bGUuIiwKICAibWV0YS5pbWFnZSI6ICJJbWFnZSB3aGVuIHNoYXJlZCIsCiAgIm1ldGEuaW1hZ2UuaGludCI6ICJTaG93biB3aGVuIHRoZSBsaW5rIGlzIHNoYXJlZCBvbiBXaGF0c0FwcCwgTGlua2VkSW4gb3IgRmFjZWJvb2suIEJlc3QgYXQgMTIwMCDDlyA2MzAgcGl4ZWxzOyBpdCBpcyBzYXZlZCBhcyBKUEVHLCB3aGljaCBldmVyeSBuZXR3b3JrIHJlYWRzLiIsCiAgIm1ldGEuaW1hZ2UucmVtb3ZlIjogIlJlbW92ZSBpbWFnZSIsCiAgIm1ldGEubm9pbWFnZSI6ICJObyBpbWFnZSIsCiAgIm1ldGEudW50aXRsZWQiOiAiVW50aXRsZWQgcGFnZSIsCiAgIm5ld3BhZ2UudGl0bGUiOiAiTmV3IHBhZ2UiLAogICJuZXdwYWdlLmludHJvIjogIkNyZWF0ZXMgYSBjb3B5IG9mIHtwYWdlfSBpbiB0aGUgc2FtZSBmb2xkZXIuIENoYW5nZSBpdHMgdGV4dHMgYW5kIGltYWdlcywgdGhlbiBzYXZlLiIsCiAgIm5ld3BhZ2UubmFtZSI6ICJOYW1lIG9mIHRoZSBuZXcgcGFnZSIsCiAgIm5ld3BhZ2UucGxhY2Vob2xkZXIiOiAiZm9yIGV4YW1wbGU6IG5ldy1wcm9qZWN0IiwKICAibmV3cGFnZS5maWxlIjogIkZpbGU6IHtmaWxlfSIsCiAgIm5ld3BhZ2UuY3JlYXRlIjogIkNyZWF0ZSBwYWdlIiwKICAibmV3cGFnZS5jcmVhdGVkIjogIkNyZWF0ZWQge2ZpbGV9IiwKICAic2F2ZS5zaGFyZWQiOiAiU2F2ZWQgb24ge259IHBhZ2VzIiwKICAiZXJyLmJhZC12aWRlbyI6ICJVc2UgYSBZb3VUdWJlIG9yIFZpbWVvIGxpbmsuIiwKICAiZXJyLmJhZC1wYWdlLW5hbWUiOiAiVXNlIGxldHRlcnMsIG51bWJlcnMsIC0gYW5kIF8gZm9yIHRoZSBwYWdlIG5hbWUuIiwKICAiZXJyLnBhZ2UtZXhpc3RzIjogIkEgcGFnZSB3aXRoIHRoYXQgbmFtZSBhbHJlYWR5IGV4aXN0cy4iLAogICJlcnIuc2hhcmVkLXJlYWRvbmx5IjogIkEgcGFnZSB3aXRoIHRoaXMgc2hhcmVkIHBhcnQgaXMgcmVhZC1vbmx5IGZvciBQSFAsIHNvIG5vdGhpbmcgd2FzIHNhdmVkLiBDaGVjayB0aGUgZmlsZSBwZXJtaXNzaW9ucy4iCn0K');
        }
        return null;
    }
}

// ---- run --------------------------------------------------------

if (!defined('PLUMA_NO_RUN')) {
    PlumaApp::run(__DIR__);
}
