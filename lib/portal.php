<?php
declare(strict_types=1);

/**
 * Participant portal: passwordless login via e-mail link, overview of the
 * own registration(s) and upload of position papers.
 */

const LOGIN_TOKEN_TTL = 1800;       // link valid for 30 minutes
const PORTAL_IDLE = 2 * 3600;       // logged out after 2 h without activity
const PAPER_EXT = ['pdf', 'doc', 'docx', 'odt'];
const PAPER_MAX_BYTES = 10 * 1024 * 1024;

/** Registration status: key => [English label for the portal, German label for the admin]. */
function registration_statuses(): array
{
    return [
        'received' => ['Received', 'Eingegangen'],
        'confirmed' => ['Confirmed', 'Bestätigt'],
        'waitlist' => ['Waiting list', 'Warteliste'],
        'cancelled' => ['Cancelled', 'Abgesagt'],
    ];
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/* ---------- Login links ---------- */

/**
 * Creates a one-time login token for an e-mail address and returns it,
 * or null if a link was requested for this address less than a minute ago.
 */
function create_login_token(string $email): ?string
{
    $email = normalize_email($email);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $created = true;
    update_json(TOKENS_FILE, function (array $tokens) use ($email, $token, &$created) {
        $now = time();
        $tokens = array_filter($tokens, fn ($t) => $t['exp'] > $now);
        foreach ($tokens as $t) {
            if ($t['email'] === $email && $t['created'] > $now - 60) {
                $created = false;
                return $tokens;
            }
        }
        $tokens[hash('sha256', $token)] = ['email' => $email, 'created' => $now, 'exp' => $now + LOGIN_TOKEN_TTL];
        return $tokens;
    });
    return $created ? $token : null;
}

/** Returns the e-mail address for a valid token and invalidates the token. */
function consume_login_token(string $token): ?string
{
    $email = null;
    $hash = hash('sha256', $token);
    update_json(TOKENS_FILE, function (array $tokens) use ($hash, &$email) {
        if (isset($tokens[$hash]) && $tokens[$hash]['exp'] > time()) {
            $email = $tokens[$hash]['email'];
        }
        unset($tokens[$hash]);
        return array_filter($tokens, fn ($t) => $t['exp'] > time());
    });
    return $email;
}

function login_link(string $token): string
{
    return site_origin() . url('portal/login') . '?token=' . rawurlencode($token);
}

/** Replaces {placeholders} in admin-editable mail texts. */
function fill_placeholders(string $text, array $vars): string
{
    return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
}

/** Confirmation mail right after registering, with a login link. */
function send_confirmation(array $entry): bool
{
    if (!c('portal.confirm_email')) {
        return false;
    }
    $token = create_login_token($entry['email']);
    $vars = [
        'first_name' => $entry['first_name'],
        'last_name' => $entry['last_name'],
        'conference' => c('conference.edition'),
        'link' => $token ? login_link($token) : site_origin() . url('portal'),
        'portal' => site_origin() . url('portal'),
        'email' => c('site.email'),
    ];
    $summary = [];
    foreach (registration_fields() as $key => [$label]) {
        if (($entry[$key] ?? '') !== '') {
            $summary[] = $label . ': ' . $entry[$key];
        }
    }
    $body = fill_placeholders((string) c('portal.confirm_text'), $vars)
        . "\n\n---\nYour details:\n" . implode("\n", $summary) . "\n";
    return send_mail($entry['email'], fill_placeholders((string) c('portal.confirm_subject'), $vars), $body, c('site.email') ?: null);
}

/** Login link on request (portal login form or "resend" in the admin). */
function send_login_link(string $email): bool
{
    $email = normalize_email($email);
    if (!registrations_for($email)) {
        return false;
    }
    $token = create_login_token($email);
    if (!$token) {
        return true; // one was just sent, don't flood the inbox
    }
    $body = "Hello,\n\nuse this link to log in to your " . c('site.name') . " delegate area:\n\n"
        . login_link($token) . "\n\nThe link can be used once and is valid for 30 minutes.\n"
        . "If you did not request it, you can ignore this e-mail.\n";
    return send_mail($email, c('site.name') . ' login link', $body, c('site.email') ?: null);
}

/* ---------- Session ---------- */

function portal_email(): ?string
{
    start_session();
    $p = $_SESSION['portal'] ?? null;
    if (!$p || time() - $p['last'] > PORTAL_IDLE) {
        unset($_SESSION['portal']);
        return null;
    }
    $_SESSION['portal']['last'] = time();
    return $p['email'];
}

function portal_login(string $email): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['portal'] = ['email' => $email, 'last' => time()];
}

/* ---------- Registrations & papers ---------- */

function registrations_for(string $email): array
{
    $email = normalize_email($email);
    return array_values(array_filter(read_json(REGISTRATIONS_FILE), fn ($r) => normalize_email($r['email'] ?? '') === $email));
}

function papers_open(): bool
{
    if (!c('portal.papers_open')) {
        return false;
    }
    $deadline = c('portal.paper_deadline');
    return !$deadline || time() <= strtotime($deadline . ' 23:59:59');
}

function paper_path(array $reg): ?string
{
    $file = basename((string) ($reg['paper']['file'] ?? ''));
    return $file !== '' && is_file(PAPERS_DIR . '/' . $file) ? PAPERS_DIR . '/' . $file : null;
}

function delete_paper_file(array $reg): void
{
    if ($path = paper_path($reg)) {
        @unlink($path);
    }
}

/** Validates and stores an uploaded paper for a registration. Returns an error message or null. */
function store_paper(string $regId, array $file): ?string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Please choose a file.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > PAPER_MAX_BYTES) {
        return 'The upload failed. Files can be up to 10 MB.';
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PAPER_EXT, true)) {
        return 'Please upload a PDF or Word document (.pdf, .docx, .doc, .odt).';
    }
    if ($ext === 'pdf' && function_exists('finfo_open') && finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) !== 'application/pdf') {
        return 'This file is not a valid PDF.';
    }
    if (!is_dir(PAPERS_DIR)) {
        mkdir(PAPERS_DIR, 0755, true);
    }
    $stored = $regId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], PAPERS_DIR . '/' . $stored)) {
        return 'The file could not be saved. Please try again later.';
    }
    $old = null;
    update_json(REGISTRATIONS_FILE, function (array $regs) use ($regId, $stored, $file, &$old) {
        foreach ($regs as &$r) {
            if ($r['id'] === $regId) {
                $old = $r;
                $r['paper'] = [
                    'file' => $stored,
                    'name' => mb_substr(preg_replace('/[^\w .()-]/u', '_', $file['name']), 0, 120),
                    'size' => (int) $file['size'],
                    'uploaded' => date('c'),
                ];
            }
        }
        return $regs;
    });
    if ($old) {
        delete_paper_file($old);
    }
    return null;
}

function send_paper(array $reg): never
{
    $path = paper_path($reg);
    if (!$path) {
        http_response_code(404);
        exit('File not found');
    }
    $name = $reg['paper']['name'] ?: basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/** Committee entry matching an allocation (by abbreviation or name). */
function committee_by_label(string $label): ?array
{
    foreach (c('committees', []) as $cm) {
        if ($label !== '' && (strcasecmp($label, $cm['abbr'] ?? '') === 0 || strcasecmp($label, $cm['name']) === 0
            || strcasecmp($label, trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –')) === 0)) {
            return $cm;
        }
    }
    return null;
}

/* ---------- Routes: /portal, /portal/login, /portal/logout, /portal/paper ---------- */

function portal_route(?string $sub): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($sub === 'login') {
        $token = (string) ($_GET['token'] ?? '');
        $error = '';
        // The link opens a page with a button; only the POST logs in. This way
        // mail scanners that "click" every link cannot use up the token.
        if ($post) {
            if (!csrf_check()) {
                $error = 'Your session expired. Please click the button again.';
            } elseif (rate_limited('portal-login', 30, 3600)) {
                $error = 'Too many attempts. Please try again later.';
            } elseif ($email = consume_login_token((string) ($_POST['token'] ?? ''))) {
                portal_login($email);
                header('Location: ' . url('portal'), true, 303);
                exit;
            } else {
                $error = 'This login link is invalid or has expired. Request a new one below.';
            }
        }
        render('portal-link', ['title' => 'Log in', 'token' => $token, 'error' => $error]);
        return;
    }

    if ($sub === 'logout') {
        if ($post && csrf_check()) {
            start_session();
            unset($_SESSION['portal']);
        }
        header('Location: ' . url('portal'), true, 303);
        exit;
    }

    $email = portal_email();

    if ($sub === 'paper') {
        foreach ($email ? registrations_for($email) : [] as $reg) {
            if ($reg['id'] === ($_GET['id'] ?? '')) {
                send_paper($reg);
            }
        }
        http_response_code(404);
        exit('File not found');
    }
    if ($sub !== null) {
        not_found();
    }

    if (!$email) {
        // Login form: ask for the e-mail address and send a link.
        $sent = false;
        $error = '';
        if ($post) {
            $address = trim((string) ($_POST['email'] ?? ''));
            if (!csrf_check()) {
                $error = 'Your session expired. Please try again.';
            } elseif (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid e-mail address.';
            } elseif (rate_limited('portal-link', 6, 3600)) {
                $error = 'Too many requests. Please try again later.';
            } else {
                send_login_link($address);
                $sent = true; // same answer whether the address is registered or not
            }
        }
        render('portal-login', ['title' => 'Delegate login', 'sent' => $sent, 'error' => $error]);
        return;
    }

    $message = '';
    $error = '';
    if ($post && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole request if it is larger than post_max_size.
        $error = 'The file is too large. Files can be up to 10 MB.';
    }
    if ($post && ($_POST['a'] ?? '') === 'paper') {
        $regId = (string) ($_POST['id'] ?? '');
        $own = array_filter(registrations_for($email), fn ($r) => $r['id'] === $regId);
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif (!$own) {
            $error = 'Registration not found.';
        } elseif (!papers_open()) {
            $error = 'Position papers can no longer be uploaded.';
        } elseif ($err = store_paper($regId, $_FILES['paper'] ?? ['error' => UPLOAD_ERR_NO_FILE])) {
            $error = $err;
        } else {
            $message = 'Thank you! Your position paper has been uploaded.';
        }
    }
    render('portal', ['title' => 'Your registration', 'email' => $email, 'regs' => registrations_for($email), 'message' => $message, 'error' => $error]);
}
