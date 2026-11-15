<?php
declare(strict_types=1);

/**
 * Handing in and releasing resolutions.
 *
 * 1. The chairs hand in the resolution ("Submit resolution"): it is saved as a
 *    finished resolution with review = 'pending', the committee gets a fresh,
 *    empty one, and the MUN Coordinators (plus portal.resolution_notify_email)
 *    get an e-mail.
 * 2. A MUN Coordinator (or admin) revises it under /portal/resolutions?…&view=review.
 * 3. "Finish and release" sets review = 'released': from then on everyone who is
 *    logged in to the delegate area can read it (all committees), print it as
 *    PDF and download it as a Word file.
 *
 * Resolutions finished before this existed have no 'review' key and stay
 * visible to their own committee only.
 */

/** MUN Coordinators, conference admins and the website admin revise and release resolutions. */
function res_can_review(?string $account): bool
{
    if (!$account) {
        return is_logged_in();
    }
    foreach (active_regs($account) as $r) {
        if (!empty($r['is_coordinator']) || !empty($r['is_admin'])) {
            return true;
        }
    }
    return false;
}

/** All handed-in resolutions of all committees: ['committee' => …, 'res' => …], newest first. */
function res_reviewed_all(): array
{
    $list = [];
    foreach (c('committees', []) as $cm) {
        foreach (res_archive_list($cm['slug']) as $r) {
            if (isset($r['review'])) {
                $list[] = ['committee' => $cm, 'res' => $r];
            }
        }
    }
    usort($list, fn ($a, $b) => strcmp((string) ($b['res']['archived'] ?? ''), (string) ($a['res']['archived'] ?? '')));
    return $list;
}

function res_public_link(array $cm, array $res, array $extra = []): string
{
    return portal_link('resolutions') . '?' . http_build_query(['c' => $cm['slug'], 'res' => $res['id']] + $extra);
}

/** May this visitor read a finished resolution? */
function res_archive_visible(array $cm, array $res, ?string $account): bool
{
    if (res_can_review($account)) {
        return true;
    }
    return match ($res['review'] ?? null) {
        'released' => $account !== null,
        'pending' => committee_chair_access($account, $cm['slug']),
        default => committee_access($account, $cm['slug']) || ($account !== null && is_staff_account($account)),
    };
}

/** E-mail to the MUN Coordinators and the organiser when chairs hand in a resolution. */
function res_notify_submitted(array $cm, array $res): void
{
    $to = [];
    foreach (read_json(REGISTRATIONS_FILE) as $r) {
        if (!empty($r['is_coordinator']) && ($r['status'] ?? '') !== 'cancelled' && ($r['email'] ?? '') !== '') {
            $to[] = normalize_email($r['email']);
        }
    }
    $to[] = normalize_email((string) (c('portal.resolution_notify_email') ?: c('site.email')));
    $link = main_origin() . res_public_link($cm, $res, ['view' => 'review']);
    $body = "Hello,\n\nthe chairs of {$cm['name']} have handed in a resolution:\n\n"
        . 'Resolution ' . (int) ($res['number'] ?? 1) . ': ' . ($res['topic'] ?: 'no topic') . "\n"
        . 'Result: ' . ($res['outcome'] ?? '') . "\n\n"
        . "It is now waiting for the MUN Coordinator: revise it where needed and release it here (log in to the delegate area first):\n{$link}\n\n"
        . "Until it is released, only the chairs and the MUN Coordinator can see it.\n";
    foreach (array_unique(array_filter($to)) as $address) {
        send_mail($address, c('site.name') . ': resolution handed in – ' . (($cm['abbr'] ?? '') ?: $cm['name']), $body, c('site.email') ?: null);
    }
}

/** Edits to a handed-in resolution (same clause logic as in the live editor). */
function res_review_action(array $res, string $a, callable $p, string $by): array
{
    switch ($a) {
        case 'clause_add':
            $res['clauses'] = clause_insert($res['clauses'], new_clause($p('ctype'), (int) $p('level'), $p('text')), $p('after'));
            break;
        case 'clause_edit':
        case 'clause_delete':
        case 'clause_move':
            $res['clauses'] = clauses_sorted($res['clauses']);
            $i = clause_index($res['clauses'], $p('id'));
            if ($i === null) {
                throw new RuntimeException('This clause no longer exists.');
            }
            if ($a === 'clause_delete') {
                array_splice($res['clauses'], $i, 1);
            } elseif ($a === 'clause_move') {
                $j = $i + ($p('dir') === 'up' ? -1 : 1);
                if (isset($res['clauses'][$j]) && $res['clauses'][$j]['type'] === $res['clauses'][$i]['type']) {
                    [$res['clauses'][$i], $res['clauses'][$j]] = [$res['clauses'][$j], $res['clauses'][$i]];
                }
            } else {
                $new = new_clause($p('ctype') ?: $res['clauses'][$i]['type'], (int) $p('level'), $p('text'));
                $new['id'] = $res['clauses'][$i]['id'];
                $res['clauses'][$i] = $new;
                $res['clauses'] = clauses_sorted($res['clauses']);
            }
            break;
        case 'meta':
            $res['topic'] = trim(mb_substr($p('topic'), 0, 300));
            $res['co_submitters'] = trim(mb_substr($p('co_submitters'), 0, 1000));
            $res['signatories'] = trim(mb_substr($p('signatories'), 0, 2000));
            break;
        case 'release':
            $res['review'] = 'released';
            $res['released'] = date('c');
            break;
        case 'unrelease':
            $res['review'] = 'pending';
            unset($res['released']);
            break;
        default:
            throw new RuntimeException('Unknown action.');
    }
    $res['revised'] = date('c');
    $res['revised_by'] = $by;
    return $res;
}

/* ---------- Word file ---------- */

/** One paragraph of the Word document; $runs = [[text, 'b'|'i'|'u'|''], …]. */
function docx_p(array $runs, int $indent = 0, int $hanging = 0, int $after = 120, string $align = ''): string
{
    $x = '<w:p><w:pPr>' . ($align ? '<w:jc w:val="' . $align . '"/>' : '') . '<w:spacing w:after="' . $after . '"/>'
        . ($indent || $hanging ? '<w:ind w:left="' . $indent . '" w:hanging="' . $hanging . '"/>' : '') . '</w:pPr>';
    foreach ($runs as [$text, $style]) {
        foreach (explode("\n", (string) $text) as $n => $line) {
            $x .= '<w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
                . (str_contains($style, 'b') ? '<w:b/>' : '') . (str_contains($style, 'i') ? '<w:i/>' : '') . (str_contains($style, 'u') ? '<w:u w:val="single"/>' : '')
                . '<w:sz w:val="24"/></w:rPr>' . ($n ? '<w:br/>' : '')
                . '<w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
        }
    }
    return $x . '</w:p>';
}

/** Builds the resolution as a .docx file and returns the path of the temporary file. */
function res_docx(array $res, array $cm): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Word files cannot be created on this server (PHP extension "zip" is missing).');
    }
    $clauses = clauses_sorted($res['clauses']);
    $nums = clause_numbers($clauses);
    $members = [];
    foreach (committee_members($cm) as $m) {
        $members[$m['id']] = reg_label($m);
    }
    $forum = trim($cm['name'] . (($cm['abbr'] ?? '') !== '' ? ' (' . $cm['abbr'] . ')' : ''));
    $body = docx_p([['FORUM: ', 'b'], [$forum, '']], 0, 0, 0) . docx_p([['TOPIC: ', 'b'], [$res['topic'], '']], 0, 0, 0);
    if (res_has_submitters($cm)) {
        $body .= docx_p([['MAIN SUBMITTER: ', 'b'], [$members[$res['main_submitter']] ?? '', '']], 0, 0, 0)
            . docx_p([['CO-SUBMITTER: ', 'b'], [$res['co_submitters'], '']], 0, 0, 0)
            . docx_p([['SIGNATORIES: ', 'b'], [$res['signatories'] ?? '', '']], 0, 0, 0);
    }
    $body .= docx_p([['', '']]) . docx_p([[mb_strtoupper($cm['name']) . ',', '']], 0, 0, 240);
    foreach ($clauses as $cl) {
        [$open, $rest] = clause_opening($cl['text']);
        $lvl = (int) $cl['level'];
        if ($cl['type'] === 'pre') {
            $body .= docx_p([[$open, 'i'], [$rest, '']]);
        } else {
            $left = 567 * ($lvl + 1);
            $body .= docx_p([[($nums[$cl['id']]['num'] ?? '') . "\t", ''], [$open, $lvl === 0 ? 'u' : ''], [$rest, '']], $left, 567);
        }
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
    $tmp = tempnam(sys_get_temp_dir(), 'res');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    return $tmp;
}

function res_file_title(array $cm, array $res): string
{
    return preg_replace('/[^\w .()-]/u', '_', c('site.name') . ' ' . (($cm['abbr'] ?? '') ?: $cm['name']) . ' Resolution ' . (int) ($res['number'] ?? 1));
}

/* ---------- Route: /portal/resolutions ---------- */

function resolutions_route(): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $account = portal_account();
    if (!$account && !is_logged_in()) {
        redirect_to(login_url());
    }
    $cm = committee_by_slug((string) ($_GET['c'] ?? ''));
    $res = $cm ? res_archive_load($cm['slug'], (string) ($_GET['res'] ?? '')) : null;
    if (!$res || !res_archive_visible($cm, $res, $account)) {
        not_found();
    }
    $view = (string) ($_GET['view'] ?? '');
    $self = res_public_link($cm, $res, ['view' => 'review']);

    if ($view === 'review') {
        if (!res_can_review($account)) {
            not_found();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                if (!csrf_check()) {
                    throw new RuntimeException('Your session expired. Please try again.');
                }
                $a = (string) ($_POST['a'] ?? '');
                $by = $account ? format_code($account) : 'admin';
                update_json(res_archive_file($cm['slug'], $res['id']), fn (array $r) => res_review_action($r, $a, fn (string $k) => (string) ($_POST[$k] ?? ''), $by));
                res_flash(['release' => 'Released. Everyone in the delegate area can now read and download this resolution.',
                    'unrelease' => 'Taken back. Only the chairs and you can see this resolution again.'][$a] ?? 'Saved.');
            } catch (RuntimeException $ex) {
                res_flash($ex->getMessage(), 'error');
            }
            redirect_to($self . (($_POST['anchor'] ?? '') ? '#' . preg_replace('/[^\w-]/', '', (string) $_POST['anchor']) : ''));
        }
        render('resolution-review', ['title' => 'Review · ' . $cm['name'], 'cm' => $cm, 'res' => $res, 'self' => $self, 'flash' => res_take_flash()]);
        return;
    }
    if (($_GET['format'] ?? '') === 'docx') {
        try {
            $tmp = res_docx($res, $cm);
        } catch (RuntimeException $ex) {
            http_response_code(500);
            exit(e($ex->getMessage()));
        }
        $name = res_file_title($cm, $res) . '.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
    $ctx = ['committee' => $cm];
    $docx = res_public_link($cm, $res, ['format' => 'docx']);
    require __DIR__ . '/../templates/resolution-print.php';
    exit;
}

/** "Previous resolutions" of a committee as seen by this visitor (list items). */
function res_archive_items_html(array $cm, array $archive, ?string $account): string
{
    $h = '';
    foreach (array_reverse($archive) as $old) {
        if (!res_archive_visible($cm, $old, $account)) {
            continue;
        }
        $review = $old['review'] ?? null;
        $link = res_public_link($cm, $old);
        $h .= '<li><span class="tag">' . e($old['outcome'] ?? 'Closed') . '</span> '
            . '<strong>Resolution ' . (int) ($old['number'] ?? 1) . '</strong> · ' . e($old['topic'] ?: 'No topic')
            . ' <span class="muted">' . e(date('j M Y, H:i', strtotime($old['archived']))) . '</span> '
            . ($review === 'pending' ? '<span class="tag status-waitlist">With the MUN Coordinator</span> ' : ($review === 'released' ? '<span class="tag status-confirmed">Released</span> ' : ''))
            . '<a href="' . e($link) . '" target="_blank">View / PDF</a> · <a href="' . e($link . '&format=docx') . '">Word</a>'
            . (isset($review) && res_can_review($account) ? ' · <a href="' . e($link . '&view=review') . '">Review</a>' : '') . '</li>';
    }
    return $h;
}
