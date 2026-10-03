<?php
declare(strict_types=1);

/**
 * Participant portal: login with e-mail + generated password (sent after
 * registering), "forgot password" via one-time e-mail link, overview of the
 * own registration(s) and upload of position papers.
 */

const LOGIN_TOKEN_TTL = 1800;       // link valid for 30 minutes
const PORTAL_IDLE = 2 * 3600;       // logged out after 2 h without activity
const PAPER_EXT = ['pdf', 'doc', 'docx', 'odt'];
const PAPER_MAX_BYTES = 10 * 1024 * 1024;
const ACCOUNTS_FILE = DATA_DIR . '/accounts.json';

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
    return login_url() . '/link?token=' . rawurlencode($token);
}

/** Replaces {placeholders} in admin-editable mail texts. */
function fill_placeholders(string $text, array $vars): string
{
    return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
}

/* ---------- Accounts: username = e-mail address ---------- */

/**
 * Strong but easy to type password, e.g. "Kavo-Rimu-Teza-Lopa-47":
 * 8 random syllables + 2 digits ≈ 55 bits of randomness.
 */
function generate_password(): string
{
    $cons = 'bdfghkmnprstvz';
    $vow = 'aeiou';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $w = '';
        for ($i = 0; $i < 2; $i++) {
            $w .= $cons[random_int(0, strlen($cons) - 1)] . $vow[random_int(0, strlen($vow) - 1)];
        }
        $groups[] = ucfirst($w);
    }
    return implode('-', $groups) . '-' . random_int(10, 99);
}

function account_exists(string $email): bool
{
    return isset(read_json(ACCOUNTS_FILE)[normalize_email($email)]);
}

function set_account_password(string $email, string $password): void
{
    $email = normalize_email($email);
    update_json(ACCOUNTS_FILE, function (array $accounts) use ($email, $password) {
        $accounts[$email] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'changed' => date('c')];
        return $accounts;
    });
}

function verify_account(string $email, string $password): bool
{
    $hash = read_json(ACCOUNTS_FILE)[normalize_email($email)]['hash'] ?? null;
    if (!$hash) {
        // Same amount of work as a real check, so the response time does not
        // reveal whether an address is registered.
        password_verify($password, '$2y$12$oijHYh35lxqfWJ94XfYnReqTZc.sNxlC6Db3vCxP3rqjB6U9J7gfW');
        return false;
    }
    return password_verify($password, $hash);
}

/** Removes accounts whose registrations have all been deleted. */
function delete_orphan_accounts(): void
{
    if (!is_file(ACCOUNTS_FILE)) {
        return;
    }
    $emails = array_map(fn ($r) => normalize_email($r['email'] ?? ''), read_json(REGISTRATIONS_FILE));
    update_json(ACCOUNTS_FILE, fn (array $accounts) => array_intersect_key($accounts, array_flip($emails)));
}

function credentials_block(array $vars): string
{
    return "\n\nYour login for the delegate area:\n"
        . "Website:  {$vars['portal']}\n"
        . "Username: {$vars['username']}\n"
        . "Password: {$vars['password']}\n";
}

/**
 * Confirmation mail right after registering. New addresses get an account
 * with a generated password; addresses that already have one keep it.
 */
function send_confirmation(array $entry): bool
{
    if (!c('portal.confirm_email')) {
        return false;
    }
    $password = null;
    if (!account_exists($entry['email'])) {
        $password = generate_password();
        set_account_password($entry['email'], $password);
    }
    $portal = login_url();
    $vars = [
        'first_name' => $entry['first_name'],
        'last_name' => $entry['last_name'],
        'conference' => c('conference.edition'),
        'username' => $entry['email'],
        'password' => $password ?? '(unchanged – use the password you already received)',
        'portal' => $portal,
        'link' => $portal,
        'email' => c('site.email'),
    ];
    $template = (string) c('portal.confirm_text');
    $body = fill_placeholders($template, $vars);
    if (!str_contains($template, '{password}')) {
        $body .= credentials_block($vars);
    }
    $summary = [];
    foreach (registration_fields() as $key => [$label]) {
        if (($entry[$key] ?? '') !== '') {
            $summary[] = $label . ': ' . $entry[$key];
        }
    }
    $body .= "\n\n---\nYour details:\n" . implode("\n", $summary) . "\n";
    return send_mail($entry['email'], fill_placeholders((string) c('portal.confirm_subject'), $vars), $body, c('site.email') ?: null);
}

/** New password + e-mail with the login details (button in the admin). */
function send_new_credentials(string $email): bool
{
    $email = normalize_email($email);
    if (!registrations_for($email)) {
        return false;
    }
    $password = generate_password();
    set_account_password($email, $password);
    $vars = ['portal' => login_url(), 'username' => $email, 'password' => $password];
    $body = "Hello,\n\nhere are your (new) login details for the " . c('site.name') . " delegate area."
        . credentials_block($vars)
        . "\nAny previous password no longer works. You can change the password after logging in.\n";
    return send_mail($email, c('site.name') . ' – your login details', $body, c('site.email') ?: null);
}

/** "Forgot password": one-time login link, then set a new password in the portal. */
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
        . login_link($token) . "\n\nThe link can be used once and is valid for 30 minutes. "
        . "After logging in you can set a new password.\n"
        . "If you did not request it, you can ignore this e-mail – your password stays the same.\n";
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

/* ---------- Where the portal lives ---------- */

/*
 * The delegate area can run on its own subdomain (e.g. conference.omun.eu),
 * set in the admin. The subdomain must point to the same folder as the main
 * site, so both share data/ and the login session (cookie for the whole domain).
 * Login itself happens on the main site at /login. Without a subdomain (or when
 * testing locally) everything runs on the main site under /portal.
 */

function request_host(): string
{
    return strtolower(preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? ''));
}

function strip_port(string $host): string
{
    return preg_replace('/:\d+$/', '', $host);
}

/** "conference.omun.eu" (with ":port" if the setting has one), or null. */
function portal_host_setting(): ?string
{
    $u = parse_url(trim((string) c('portal.portal_url')));
    if (empty($u['host'])) {
        return null;
    }
    return strtolower($u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
}

/** The shared parent domain, e.g. "omun.eu". */
function portal_base_domain(): ?string
{
    $host = portal_host_setting();
    $pos = $host ? strpos(strip_port($host), '.') : false;
    return $pos === false ? null : substr(strip_port($host), $pos + 1);
}

/** True when the portal runs on its own subdomain for the domain of this request. */
function split_portal(): bool
{
    $base = portal_base_domain();
    $current = strip_port(request_host());
    return $base !== null && ($current === $base || str_ends_with($current, '.' . $base));
}

function on_portal_host(): bool
{
    return split_portal() && request_host() === portal_host_setting();
}

/** Origin of the main website, also when called from the portal subdomain. */
function main_origin(): string
{
    if (!on_portal_host()) {
        return site_origin();
    }
    $host = request_host();
    return (str_starts_with(site_origin(), 'https') ? 'https' : 'http') . '://' . substr($host, strpos($host, '.') + 1);
}

/** Absolute address of the delegate area's start page. */
function portal_home(): string
{
    return split_portal() ? rtrim(trim((string) c('portal.portal_url')), '/') . '/' : site_origin() . url('portal');
}

/** Link to a page inside the delegate area ('' = start page, 'paper', 'logout'). */
function portal_link(string $page = ''): string
{
    return on_portal_host() ? url($page) : url(rtrim('portal/' . $page, '/'));
}

function login_url(): string
{
    return main_origin() . url('login');
}

/** Cookie domain so that omun.eu/login and conference.omun.eu share the session. */
function session_cookie_domain(): string
{
    return split_portal() ? (string) portal_base_domain() : '';
}

function redirect_to(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

/* ---------- Routes on the main site: /login, /login/forgot, /login/link ---------- */

function login_route(?string $sub): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($sub === 'link') {
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
                redirect_to(portal_home());
            } else {
                $error = 'This login link is invalid or has expired.';
            }
        }
        render('portal-link', ['title' => 'Log in', 'token' => $token, 'error' => $error]);
        return;
    }

    if ($sub === 'forgot') {
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
        render('portal-forgot', ['title' => 'Forgot password', 'sent' => $sent, 'error' => $error]);
        return;
    }

    if ($sub !== null) {
        not_found();
    }
    if (portal_email()) {
        redirect_to(portal_home());
    }
    $error = '';
    $address = '';
    if ($post) {
        $address = trim((string) ($_POST['email'] ?? ''));
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif (rate_limited('portal-password', 10, 900)) {
            $error = 'Too many attempts. Please wait 15 minutes and try again.';
        } elseif (verify_account($address, (string) ($_POST['password'] ?? ''))) {
            portal_login(normalize_email($address));
            redirect_to(portal_home());
        } else {
            usleep(300000);
            $error = 'E-mail address or password is wrong.';
        }
    }
    render('portal-login', ['title' => 'Delegate login', 'error' => $error, 'address' => $address]);
}

/* ---------- The delegate area: start page, /paper, /logout ---------- */

function portal_route(?string $sub): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($sub === 'logout') {
        if ($post && csrf_check()) {
            start_session();
            unset($_SESSION['portal']);
        }
        redirect_to(login_url());
    }

    $email = portal_email();

    if ($sub === 'resolution') {
        resolution_route();
        return;
    }
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
        redirect_to(login_url());
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
    if ($post && ($_POST['a'] ?? '') === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif (mb_strlen($new) < 10) {
            $error = 'The new password must be at least 10 characters long.';
        } elseif ($new !== ($_POST['new_password2'] ?? '')) {
            $error = 'The two passwords do not match.';
        } else {
            set_account_password($email, $new);
            $message = 'Your password has been changed.';
        }
    }
    render('portal', ['title' => 'Your registration', 'email' => $email, 'regs' => registrations_for($email), 'message' => $message, 'error' => $error]);
}

/** Requests on the portal subdomain: only the delegate area, everything else goes to the main site. */
function portal_host_route(array $parts): void
{
    $first = $parts[0] ?? '';
    if ($first === 'portal') {
        array_shift($parts); // old links like conference.omun.eu/portal/paper
        $first = $parts[0] ?? '';
    }
    if (count($parts) <= 1 && in_array($first, ['', 'paper', 'logout', 'resolution'], true)) {
        portal_route($first === '' ? null : $first);
        return;
    }
    $query = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . main_origin() . url(implode('/', array_map('rawurlencode', $parts))) . $query, true, 301);
    exit;
}
