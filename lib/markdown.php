<?php
declare(strict_types=1);

/**
 * Small, safe Markdown renderer for the content editor.
 * All input is HTML-escaped first, so editors cannot inject scripts.
 *
 * Supports: # headings, paragraphs, **bold**, *italic*, `code`,
 * [links](https://…), ![images](uploads/…), - / 1. lists, > quotes, --- rules.
 */
function md(?string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
    if ($text === '') {
        return '';
    }
    $lines = explode("\n", $text);
    $html = '';
    $para = [];
    $list = null;   // 'ul' | 'ol'
    $quote = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . implode("<br>\n", array_map('md_inline', $para)) . "</p>\n";
            $para = [];
        }
    };
    $flushList = function () use (&$list, &$html) {
        if ($list) {
            $html .= "</$list>\n";
            $list = null;
        }
    };
    $flushQuote = function () use (&$quote, &$html) {
        if ($quote) {
            $html .= '<blockquote>' . md(implode("\n", $quote)) . "</blockquote>\n";
            $quote = [];
        }
    };

    foreach ($lines as $line) {
        $trim = trim($line);

        if (str_starts_with($trim, '>')) {
            $flushPara();
            $flushList();
            $quote[] = ltrim(substr($trim, 1));
            continue;
        }
        $flushQuote();

        if ($trim === '') {
            $flushPara();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $flushList();
            $lvl = strlen($m[1]) + 1; // "#" becomes <h2>, the page title is <h1>
            $html .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
            $flushPara();
            $flushList();
            $html .= "<hr>\n";
            continue;
        }
        if (preg_match('/^([-*+]|\d+[.)])\s+(.+)$/', $trim, $m)) {
            $flushPara();
            $type = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $type) {
                $flushList();
                $html .= "<$type>\n";
                $list = $type;
            }
            $html .= '<li>' . md_inline($m[2]) . "</li>\n";
            continue;
        }
        $flushList();
        $para[] = $trim;
    }
    $flushQuote();
    $flushPara();
    $flushList();
    return $html;
}

function md_safe_url(string $url): string
{
    $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    if (preg_match('~^(https?:|mailto:|tel:|/|#|uploads/)~i', $url)) {
        if (str_starts_with($url, 'uploads/')) {
            $url = url($url);
        }
        return e($url);
    }
    if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
        return e($url); // relative link
    }
    return '#';
}

function md_inline(string $s): string
{
    $s = e($s);
    $codes = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $s);
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn ($m) =>
        '<img src="' . md_safe_url($m[2]) . '" alt="' . $m[1] . '" loading="lazy">', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $href = md_safe_url($m[2]);
        $ext = preg_match('~^https?://~', html_entity_decode($m[2])) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $href . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $s);
    return preg_replace_callback("/\x00(\d+)\x00/", fn ($m) => $codes[(int) $m[1]], $s);
}
