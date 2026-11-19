<?php
declare(strict_types=1);

/**
 * Research reports: the chairs of a committee upload them in the portal. They
 * are shown to the delegates of that committee in the portal and are public
 * under Downloads and on the committee page (files in uploads/research-reports/).
 *
 * data/research-reports.json: [ { id, committee (slug), title, file, size, uploaded, by (registration id) } ]
 */

const REPORTS_FILE = DATA_DIR . '/research-reports.json';
const REPORTS_DIR = UPLOAD_DIR . '/research-reports';
const REPORT_EXT = ['pdf', 'docx', 'doc', 'odt'];
const REPORT_MAX_BYTES = 20 * 1024 * 1024;

/** All reports (of one committee), in committee order and then by upload time. */
function research_reports(?string $slug = null): array
{
    $order = array_flip(array_column(c('committees', []), 'slug'));
    $list = array_values(array_filter(read_json(REPORTS_FILE),
        fn ($r) => isset($order[$r['committee'] ?? '']) && ($slug === null || $r['committee'] === $slug) && is_file(report_path($r))));
    usort($list, fn ($a, $b) => [$order[$a['committee']], $a['uploaded']] <=> [$order[$b['committee']], $b['uploaded']]);
    return $list;
}

function report_path(array $r): string
{
    return REPORTS_DIR . '/' . basename((string) ($r['file'] ?? ''));
}

/** Public address of the file. */
function report_url(array $r): string
{
    return media('uploads/research-reports/' . basename((string) $r['file']));
}

/** Stores an uploaded research report for a committee. Returns an error message (English) or null. */
function store_report(string $slug, string $title, array $file, string $by): ?string
{
    $cm = committee_by_slug($slug);
    if (!$cm) {
        return 'Committee not found.';
    }
    $title = trim(preg_replace('/\s+/', ' ', $title));
    if ($title === '') {
        return 'Please enter a title (for example the topic of the report).';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Please choose a file.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > REPORT_MAX_BYTES) {
        return 'The upload failed. Files can be up to 20 MB.';
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, REPORT_EXT, true)) {
        return 'Please upload a PDF or Word document (.pdf, .docx, .doc, .odt).';
    }
    if ($ext === 'pdf' && function_exists('finfo_open') && finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) !== 'application/pdf') {
        return 'This file is not a valid PDF.';
    }
    if (!is_dir(REPORTS_DIR)) {
        mkdir(REPORTS_DIR, 0755, true);
    }
    // Readable public file name; the random part keeps addresses from being guessed before the report is listed.
    $base = substr(slugify('research report ' . (($cm['abbr'] ?? '') ?: $cm['name']) . ' ' . $title), 0, 80);
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], REPORTS_DIR . '/' . $name)) {
        return 'The file could not be saved. Please try again later.';
    }
    @chmod(REPORTS_DIR . '/' . $name, 0644);
    append_json(REPORTS_FILE, ['id' => bin2hex(random_bytes(5)), 'committee' => $slug, 'title' => mb_substr($title, 0, 150),
        'file' => $name, 'size' => (int) $file['size'], 'uploaded' => date('c'), 'by' => $by]);
    audit('report.upload', (($cm['abbr'] ?? '') ?: $cm['name']) . ': ' . $title, $by);
    return null;
}

/** Removes a report (and its file). Only reports of the given committee, unless $slug is null (admin). */
function delete_report(string $id, ?string $slug = null, string $actor = 'admin'): bool
{
    $removed = null;
    update_json(REPORTS_FILE, function (array $list) use ($id, $slug, &$removed) {
        foreach ($list as $k => $r) {
            if (($r['id'] ?? '') === $id && ($slug === null || ($r['committee'] ?? '') === $slug)) {
                $removed = $r;
                unset($list[$k]);
            }
        }
        return array_values($list);
    });
    if ($removed) {
        @unlink(report_path($removed));
        audit('report.delete', ($removed['committee'] ?? '') . ': ' . ($removed['title'] ?? ''), $actor);
    }
    return $removed !== null;
}

/** Display title: "Research report – <title>". */
function report_title(array $r): string
{
    return 'Research report: ' . $r['title'];
}
