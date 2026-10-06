<?php
declare(strict_types=1);

/**
 * Participant portal: login with the personal code ("Kennung") + the password
 * chosen when registering, "forgot password" via one-time link to the e-mail
 * address of the registration, overview of the own registration and upload of
 * position papers. Names are not stored (see lib/codes.php).
 */

require_once __DIR__ . '/codes.php';

const LOGIN_TOKEN_TTL = 1800;       // link valid for 30 minutes
const PORTAL_IDLE = 2 * 3600;       // logged out after 2 h without activity
const PAPER_EXT = ['pdf', 'doc', 'docx', 'odt'];
const PAPER_MAX_BYTES = 10 * 1024 * 1024;
const ACCOUNTS_FILE = DATA_DIR . '/accounts.json';
const STAFF_FILE = DATA_DIR . '/staff-accounts.json';
/** Built-in view-only account for the beamer laptops (password changeable in the admin). */
const STAFF_DEFAULTS = ['laptops' => ['hash' => '$2y$12$A4G0AZf0Ht1rdvB.W5Vty.N/.uWCWVCwbxqI8SsSD7IssIWJ3nY1m', 'label' => 'Laptop (beamer)']];

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
 * Creates a one-time login token for an account (personal code) and returns it,
 * or null if a link was requested for this account less than a minute ago.
 */
function create_login_token(string $account): ?string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $created = true;
    update_json(TOKENS_FILE, function (array $tokens) use ($account, $token, &$created) {
        $now = time();
        $tokens = array_filter($tokens, fn ($t) => $t['exp'] > $now);
        foreach ($tokens as $t) {
            if (($t['account'] ?? null) === $account && $t['created'] > $now - 60) {
                $created = false;
                return $tokens;
            }
        }
        $tokens[hash('sha256', $token)] = ['account' => $account, 'created' => $now, 'exp' => $now + LOGIN_TOKEN_TTL];
        return $tokens;
    });
    return $created ? $token : null;
}

/** Returns the account for a valid token and invalidates the token. */
function consume_login_token(string $token): ?string
{
    $account = null;
    $hash = hash('sha256', $token);
    update_json(TOKENS_FILE, function (array $tokens) use ($hash, &$account) {
        if (isset($tokens[$hash]) && $tokens[$hash]['exp'] > time()) {
            $account = $tokens[$hash]['account'] ?? null;
        }
        unset($tokens[$hash]);
        return array_filter($tokens, fn ($t) => $t['exp'] > time());
    });
    return $account;
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

/* ---------- Accounts: username = personal code ---------- */

/**
 * What someone types as username → account key: the staff account name
 * ("laptops"), otherwise the normalized personal code.
 */
function account_key(string $login): string
{
    $name = strtolower(trim($login));
    return isset(staff_accounts()[$name]) ? $name : normalize_code($login);
}

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

function set_account_password(string $account, string $password): void
{
    update_json(ACCOUNTS_FILE, function (array $accounts) use ($account, $password) {
        $accounts[$account] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'changed' => date('c')];
        return $accounts;
    });
}

/* ---------- Staff accounts (fixed usernames, not tied to a registration) ---------- */

function staff_accounts(): array
{
    return read_json(STAFF_FILE) + STAFF_DEFAULTS;
}

function is_staff_account(?string $name): bool
{
    return $name !== null && isset(staff_accounts()[normalize_email($name)]);
}

function set_staff_password(string $name, string $password): void
{
    $name = normalize_email($name);
    update_json(STAFF_FILE, function (array $accounts) use ($name, $password) {
        $accounts[$name] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'label' => (staff_accounts()[$name]['label'] ?? $name), 'changed' => date('c')];
        return $accounts;
    });
}

function verify_account(string $account, string $password): bool
{
    $hash = $account === '' ? null : ((staff_accounts()[$account] ?? read_json(ACCOUNTS_FILE)[$account] ?? [])['hash'] ?? null);
    if (!$hash) {
        // Same amount of work as a real check, so the response time does not
        // reveal whether a code is registered.
        password_verify($password, '$2y$12$oijHYh35lxqfWJ94XfYnReqTZc.sNxlC6Db3vCxP3rqjB6U9J7gfW');
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * Removes accounts whose registration has been deleted (also old accounts from
 * before the switch to personal codes) and frees their codes.
 */
function delete_orphan_accounts(): void
{
    release_unused_codes();
    if (!is_file(ACCOUNTS_FILE)) {
        return;
    }
    $codes = array_map(fn ($r) => normalize_code((string) ($r['code'] ?? '')), read_json(REGISTRATIONS_FILE));
    update_json(ACCOUNTS_FILE, fn (array $accounts) => array_intersect_key($accounts, array_flip(array_filter($codes))));
}

function credentials_block(array $vars): string
{
    return "\n\nYour login for the delegate area:\n"
        . "Website:       {$vars['portal']}\n"
        . "Personal code: {$vars['username']}\n"
        . "Password:      {$vars['password']}\n";
}

/** The e-mail address of an account (from its registration), or null. */
function account_email(string $account): ?string
{
    foreach (registrations_for($account) as $r) {
        if (($r['email'] ?? '') !== '') {
            return $r['email'];
        }
    }
    return null;
}

/**
 * Confirmation mail right after registering. The participant chose the
 * password in the form, so it is not repeated here.
 */
function send_confirmation(array $entry): bool
{
    if (!c('portal.confirm_email')) {
        return false;
    }
    $portal = login_url();
    $code = format_code($entry['code']);
    $vars = [
        'code' => $code,
        // Older mail texts still greet with {first_name}; names are no longer stored.
        'first_name' => $code,
        'last_name' => '',
        'conference' => c('conference.edition'),
        'username' => $code,
        'password' => '(the password you chose when registering)',
        'portal' => $portal,
        'link' => $portal,
        'email' => c('site.email'),
    ];
    $template = (string) c('portal.confirm_text');
    $body = fill_placeholders($template, $vars);
    if (!str_contains($template, '{code}') && !str_contains($template, '{username}')) {
        $body .= credentials_block($vars);
    }
    $summary = [];
    foreach (registration_fields() as $key => [$label]) {
        if (($entry[$key] ?? '') !== '') {
            $summary[] = $label . ': ' . ($key === 'code' ? format_code($entry[$key]) : $entry[$key]);
        }
    }
    $body .= "\n\n---\nYour details:\n" . implode("\n", $summary) . "\n";
    return send_mail($entry['email'], fill_placeholders((string) c('portal.confirm_subject'), $vars), $body, c('site.email') ?: null);
}

/** New password + e-mail with the login details to the registration's address (button in the admin). */
function send_new_credentials(string $account): bool
{
    $email = account_email($account);
    if (!$email) {
        return false;
    }
    $password = generate_password();
    set_account_password($account, $password);
    $vars = ['portal' => login_url(), 'username' => format_code($account), 'password' => $password];
    $body = "Hello,\n\nhere are your (new) login details for the " . c('site.name') . " delegate area."
        . credentials_block($vars)
        . "\nAny previous password no longer works. You can change the password after logging in.\n";
    return send_mail($email, c('site.name') . ' – your login details', $body, c('site.email') ?: null);
}

/** "Forgot password": one-time login link to the registration's address, then set a new password in the portal. */
function send_login_link(string $account): bool
{
    $email = account_email($account);
    if (!$email) {
        return false;
    }
    $token = create_login_token($account);
    if (!$token) {
        return true; // one was just sent, don't flood the inbox
    }
    $body = "Hello,\n\nuse this link to log in to your " . c('site.name') . " delegate area:\n\n"
        . login_link($token) . "\n\nThe link can be used once and is valid for 30 minutes. "
        . "After logging in you can set a new password.\n"
        . "If you did not request it, you can ignore this e-mail – your password stays the same.\n";
    return send_mail($email, c('site.name') . ' login link', $body, c('site.email') ?: null);
}

/* ---------- Login on/off per group ---------- */

/** Groups an account belongs to: delegate, chair, manager, admin, staff, laptop. */
function login_groups(string $account): array
{
    if (is_staff_account($account)) {
        return ['laptop'];
    }
    $groups = [];
    foreach (registrations_for($account) as $r) {
        $kind = $r['kind'] ?? registration_kind((string) ($r['role'] ?? ''));
        if (!empty($r['is_admin'])) {
            $groups[] = 'admin';
        }
        if (!empty($r['is_chair']) || $kind === 'chair') {
            $groups[] = 'chair';
        } elseif (!empty($r['is_manager']) || $kind === 'manager') {
            $groups[] = 'manager';
        } elseif ($kind === 'staff') {
            $groups[] = 'staff';
        } elseif (empty($r['is_admin'])) {
            $groups[] = 'delegate';
        }
    }
    return array_values(array_unique($groups)) ?: ['delegate'];
}

/** True if at least one of the account's groups may currently log in (Admin → Teilnehmer-Bereich). */
function login_allowed(string $account): bool
{
    static $cache = [];
    if (!isset($cache[$account])) {
        $keys = ['delegate' => 'login_delegates', 'chair' => 'login_chairs', 'manager' => 'login_managers',
            'admin' => 'login_admins', 'staff' => 'login_staff', 'laptop' => 'login_laptop'];
        $cache[$account] = false;
        foreach (login_groups($account) as $g) {
            if (c('portal.' . $keys[$g], true)) {
                $cache[$account] = true;
            }
        }
    }
    return $cache[$account];
}

function login_closed_message(): string
{
    return (string) (c('portal.login_closed_message') ?: 'The login is currently closed.');
}

/* ---------- Session ---------- */

/** The logged-in account (personal code or staff account name), or null. */
function portal_account(): ?string
{
    start_session();
    $p = $_SESSION['portal'] ?? null;
    // Sessions from before the switch to personal codes have no 'account'.
    if (!$p || !isset($p['account']) || time() - $p['last'] > PORTAL_IDLE) {
        unset($_SESSION['portal']);
        return null;
    }
    if (!login_allowed($p['account'])) {
        unset($_SESSION['portal']);
        $_SESSION['login_closed'] = true;
        return null;
    }
    $_SESSION['portal']['last'] = time();
    return $p['account'];
}

function portal_login(string $account): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['portal'] = ['account' => $account, 'last' => time()];
}

/* ---------- Registrations & papers ---------- */

/** The registration(s) of an account (one per personal code). */
function registrations_for(string $account): array
{
    $code = normalize_code($account);
    if ($code === '' || is_staff_account($account)) {
        return [];
    }
    return array_values(array_filter(read_json(REGISTRATIONS_FILE), fn ($r) => normalize_code((string) ($r['code'] ?? '')) === $code));
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
                    // Not the original file name – it often contains the participant's name.
                    'name' => 'Position paper ' . (format_code((string) ($r['code'] ?? '')) ?: $regId) . '.' . $ext,
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
    $label = trim($label);
    foreach (c('committees', []) as $cm) {
        // Assignments are stored by slug (stays the same when a committee is renamed);
        // older ones by name or "ABBR – Name".
        if ($label !== '' && ($label === ($cm['slug'] ?? null) || strcasecmp($label, $cm['abbr'] ?? '') === 0 || strcasecmp($label, $cm['name']) === 0
            || strcasecmp($label, trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –')) === 0)) {
            return $cm;
        }
    }
    return null;
}

/** Display name of an assigned committee ("SC – Security Council"), or the stored text if it no longer exists. */
function committee_display(string $value): string
{
    $cm = committee_by_label($value);
    return $cm ? trim(($cm['abbr'] ?? '') . ' – ' . $cm['name'], ' –') : $value;
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
    // The portal always runs under /portal on the main domain (Strato does not
    // serve SSL for the conference subdomain). An old "portal_url" is ignored.
    return null;
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
    // Same cookie domain as when the portal still ran on conference.omun.eu,
    // so existing login cookies are replaced instead of duplicated.
    $host = preg_replace('/^www\./', '', strip_port(request_host()));
    return str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP) ? $host : '';
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
            } elseif (($account = consume_login_token((string) ($_POST['token'] ?? ''))) && !login_allowed($account)) {
                $error = login_closed_message();
            } elseif ($account) {
                portal_login($account);
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
            $code = normalize_code((string) ($_POST['code'] ?? ''));
            if (!csrf_check()) {
                $error = 'Your session expired. Please try again.';
            } elseif (!code_format_ok($code)) {
                $error = 'Please enter your personal code (8 characters, e.g. K7QF-M3XP).';
            } elseif (rate_limited('portal-link', 6, 3600)) {
                $error = 'Too many requests. Please try again later.';
            } else {
                send_login_link($code);
                $sent = true; // same answer whether the code is registered or not
            }
        }
        render('portal-forgot', ['title' => 'Forgot password', 'sent' => $sent, 'error' => $error]);
        return;
    }

    if ($sub !== null) {
        not_found();
    }
    if (portal_account()) {
        redirect_to(portal_home());
    }
    $error = '';
    if (!empty($_SESSION['login_closed'])) {
        unset($_SESSION['login_closed']);
        $error = login_closed_message();
    }
    $address = '';
    if ($post) {
        $address = trim((string) ($_POST['code'] ?? ''));
        $account = account_key($address);
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif (rate_limited('portal-password', 10, 900)) {
            $error = 'Too many attempts. Please wait 15 minutes and try again.';
        } elseif (verify_account($account, (string) ($_POST['password'] ?? ''))) {
            if (!login_allowed($account)) {
                $error = login_closed_message();
            } else {
                portal_login($account);
                redirect_to(portal_home());
            }
        } else {
            usleep(300000);
            $error = 'Personal code or password is wrong.';
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

    $account = portal_account();

    if ($sub === 'resolution') {
        resolution_route();
        return;
    }
    if ($sub === 'certificate') {
        foreach ($account ? registrations_for($account) : [] as $reg) {
            if ($reg['id'] === ($_GET['id'] ?? '') && certificate_available($reg)) {
                send_certificate($reg);
            }
        }
        if (!$account) {
            redirect_to(login_url());
        }
        not_found();
    }
    if ($sub === 'paper') {
        foreach ($account ? registrations_for($account) : [] as $reg) {
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
    if (!$account) {
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
        $own = array_filter(registrations_for($account), fn ($r) => $r['id'] === $regId);
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
    $staff = is_staff_account($account);
    if ($post && ($_POST['a'] ?? '') === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif ($staff) {
            $error = 'The password of this account can only be changed in the admin area.';
        } elseif (mb_strlen($new) < 10) {
            $error = 'The new password must be at least 10 characters long.';
        } elseif ($new !== ($_POST['new_password2'] ?? '')) {
            $error = 'The two passwords do not match.';
        } else {
            set_account_password($account, $new);
            $message = 'Your password has been changed.';
        }
    }
    render('portal', ['title' => $staff ? 'Conference area' : 'Your registration', 'account' => $account, 'staff' => $staff, 'regs' => $staff ? [] : registrations_for($account), 'message' => $message, 'error' => $error]);
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

/* ---------- Personal schedule ---------- */

/**
 * The conference schedule for one participant: grouped by day, with the room of
 * their committee filled in for committee sessions and the current/next item marked.
 * Days are dated in the order they appear, starting at the conference start date.
 */
function portal_schedule(?array $reg): array
{
    $committee = $reg ? committee_by_label((string) ($reg['assigned_committee'] ?? '')) : null;
    $start = c('conference.date_start') ? strtotime(c('conference.date_start')) : null;
    $days = [];
    foreach (c('conference.schedule', []) as $item) {
        $day = trim((string) ($item['day'] ?? '')) ?: '–';
        if (!isset($days[$day])) {
            $days[$day] = ['name' => $day, 'date' => $start ? date('Y-m-d', strtotime('+' . count($days) . ' days', $start)) : null, 'items' => []];
        }
        $isSession = preg_match('/committee|session/i', ($item['title'] ?? '') . ' ' . ($item['location'] ?? ''));
        $where = (string) ($item['location'] ?? '');
        $mine = false;
        if ($isSession && $committee) {
            $where = trim(($committee['room'] ?? '') !== '' ? $committee['room'] . ' · ' . $committee['name'] : $committee['name']);
            $mine = true;
        }
        $from = $to = null;
        if (preg_match('/(\d{1,2})[:.](\d{2})\s*(?:[–-]\s*(\d{1,2})[:.](\d{2}))?/u', (string) ($item['time'] ?? ''), $m) && $days[$day]['date']) {
            $from = strtotime($days[$day]['date'] . sprintf(' %02d:%02d', $m[1], $m[2]));
            $to = isset($m[3]) && $m[3] !== '' ? strtotime($days[$day]['date'] . sprintf(' %02d:%02d', $m[3], $m[4])) : $from + 3600;
        }
        $days[$day]['items'][] = ['time' => $item['time'] ?? '', 'title' => $item['title'] ?? '', 'where' => $where, 'mine' => $mine, 'from' => $from, 'to' => $to, 'state' => ''];
    }
    // Mark what is happening now, or else the next item.
    $now = time();
    $next = null;
    foreach ($days as $k => $d) {
        foreach ($d['items'] as $i => $it) {
            if ($it['from'] && $it['from'] <= $now && $now < $it['to']) {
                $days[$k]['items'][$i]['state'] = 'now';
                $next = false;
            } elseif ($it['from'] && $it['from'] > $now && $next === null) {
                $next = [$k, $i];
            }
        }
    }
    if ($next) {
        $days[$next[0]]['items'][$next[1]]['state'] = 'next';
    }
    return array_values($days);
}

/* ---------- Certificates ---------- */

function certificate_available(array $reg): bool
{
    return (bool) c('portal.certificates_open') && ($reg['status'] ?? '') === 'confirmed';
}

/** Text pieces for a participant's certificate. */
function certificate_vars(array $reg): array
{
    $cm = committee_by_label((string) ($reg['assigned_committee'] ?? ''));
    $country = trim((string) ($reg['assigned_country'] ?? ''));
    $kind = $reg['kind'] ?? 'delegate';
    $cmName = $cm ? 'the ' . preg_replace('/^the\s+/i', '', $cm['name']) : '';
    if (!empty($reg['is_chair']) && $cm) {
        $part = 'Chair of ' . $cmName;
    } elseif (!empty($reg['is_manager']) || $kind === 'manager') {
        $part = 'Conference Manager';
    } elseif ($kind === 'delegate' && $country !== '') {
        $part = 'Delegate of ' . $country . ($cm ? ' in ' . $cmName : '');
    } else {
        $part = trim((string) ($reg['role'] ?? 'participant')) . ($cm ? ' in ' . $cmName : '');
    }
    $vars = [
        'participation' => $part,
        'conference' => c('conference.edition') ?: c('site.name'),
        'dates' => date_range(c('conference.date_start'), c('conference.date_end')),
        'country' => $country,
        'committee' => $cm['name'] ?? '',
        'role' => (string) ($reg['role'] ?? ''),
    ];
    $signers = [];
    foreach ((array) c('portal.cert_signers', []) as $line) {
        $bits = array_map('trim', explode('|', (string) $line, 2));
        if ($bits[0] !== '') {
            $signers[] = ['name' => $bits[0], 'title' => $bits[1] ?? ''];
        }
    }
    return [
        // Names are not stored: the participant types it on the certificate page (stays in the browser).
        'name' => '',
        'title' => c('portal.cert_title') ?: 'Certificate of Participation',
        'text' => fill_placeholders((string) c('portal.cert_text'), $vars),
        'signers' => $signers,
        'conference' => $vars['conference'],
    ];
}

function send_certificate(array $reg): never
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $cert = certificate_vars($reg);
    require __DIR__ . '/../templates/certificate.php';
    exit;
}
