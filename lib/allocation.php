<?php
declare(strict_types=1);

/**
 * Allocation of delegates to committees and countries.
 *
 * - Every committee has a list of countries (admin → Gremien). One country in
 *   one committee = one seat.
 * - Delegates name two committees when registering and two countries for each.
 * - alloc_compute() finds the assignment with the lowest total "cost"
 *   (Hungarian algorithm), see ALLOC_COSTS.
 * - Delegates see their allocation only after it has been released
 *   (admin → Zuteilung → "Einteilung herausgeben"), which also e-mails it.
 */

/** Cost of a seat for a delegate, by how well it matches the wishes. */
const ALLOC_COSTS = [
    'c1a' => [0, '1. Gremium · 1. Land'],
    'c1b' => [1, '1. Gremium · 2. Land'],
    'c1' => [3, '1. Gremium · anderes Land'],
    'c2a' => [4, '2. Gremium · 1. Land'],
    'c2b' => [5, '2. Gremium · 2. Land'],
    'c2' => [7, '2. Gremium · anderes Land'],
    'other' => [12, 'kein Wunsch erfüllt'],
];
const ALLOC_COST_NONE = 40; // no seat at all

/** The countries delegates can represent in a committee. */
function committee_countries(array $cm): array
{
    $list = [];
    foreach ((array) ($cm['countries'] ?? []) as $c) {
        $c = trim((string) $c);
        if ($c !== '' && !in_array($c, $list, true)) {
            $list[] = $c;
        }
    }
    return $list;
}

function is_delegate_reg(array $r): bool
{
    return ($r['kind'] ?? 'delegate') === 'delegate' && empty($r['is_chair']) && empty($r['is_admin'])
        && empty($r['is_manager']) && empty($r['is_coordinator']);
}

/**
 * May the allocation (committee, country) of this registration be shown and
 * used? Delegates: only once it has been released. Everyone else: always.
 */
function alloc_visible(array $r): bool
{
    return !is_delegate_reg($r) || !empty($r['released']);
}

/** Committee slug of a registration's allocation, '' if none. */
function reg_committee_slug(array $r): string
{
    $cm = committee_by_label((string) ($r['assigned_committee'] ?? ''));
    return $cm['slug'] ?? '';
}

function alloc_complete(array $r): bool
{
    return reg_committee_slug($r) !== '' && trim((string) ($r['assigned_country'] ?? '')) !== '';
}

/** "slug|country" – what a delegate was told when the allocation was released. */
function alloc_key(array $r): string
{
    return reg_committee_slug($r) . '|' . mb_strtolower(trim((string) ($r['assigned_country'] ?? '')));
}

/** Released, and unchanged since then. */
function alloc_is_sent(array $r): bool
{
    return !empty($r['released']) && ($r['released']['key'] ?? '') === alloc_key($r);
}

/** Wishes of a delegate: [[committee slug, [country, country]], …] (first choice first). */
function alloc_wishes(array $r): array
{
    $out = [];
    foreach ([['committee_1', 'country_1a', 'country_1b'], ['committee_2', 'country_2a', 'country_2b']] as [$ck, $a, $b]) {
        $cm = committee_by_label((string) ($r[$ck] ?? ''));
        $out[] = [$cm['slug'] ?? '', array_values(array_filter([trim((string) ($r[$a] ?? '')), trim((string) ($r[$b] ?? ''))], 'strlen'))];
    }
    return $out;
}

/** How well a seat matches the wishes: key of ALLOC_COSTS. */
function alloc_match(array $r, string $slug, string $country): string
{
    $best = 'other';
    foreach (alloc_wishes($r) as $i => [$wSlug, $countries]) {
        if ($wSlug === '' || $wSlug !== $slug) {
            continue;
        }
        $n = $i + 1;
        $pos = array_search(mb_strtolower($country), array_map('mb_strtolower', $countries), true);
        $key = $pos === 0 ? "c{$n}a" : ($pos === 1 ? "c{$n}b" : "c{$n}");
        if (ALLOC_COSTS[$key][0] < ALLOC_COSTS[$best][0]) {
            $best = $key;
        }
    }
    return $best;
}

/** Delegates that take part in the allocation (not cancelled), oldest registration first. */
function alloc_delegates(?array $regs = null): array
{
    $regs ??= read_json(REGISTRATIONS_FILE);
    $list = array_values(array_filter($regs, fn ($r) => is_delegate_reg($r) && ($r['status'] ?? '') !== 'cancelled'));
    usort($list, fn ($a, $b) => strcmp((string) ($a['created'] ?? ''), (string) ($b['created'] ?? '')));
    return $list;
}

/**
 * For every delegate: how contested is their first wish (1st committee + 1st country)?
 * id => ['state' => clear|contested|none, 'rivals' => n]
 */
function alloc_analysis(array $delegates): array
{
    $first = [];
    foreach ($delegates as $r) {
        [$slug, $countries] = alloc_wishes($r)[0];
        if ($slug !== '' && $countries) {
            $first[$slug . '|' . mb_strtolower($countries[0])][] = $r['id'];
        }
    }
    $out = [];
    foreach ($delegates as $r) {
        [$slug, $countries] = alloc_wishes($r)[0];
        if ($slug === '' || !$countries) {
            $out[$r['id']] = ['state' => 'none', 'rivals' => 0];
            continue;
        }
        $n = count($first[$slug . '|' . mb_strtolower($countries[0])]);
        $out[$r['id']] = ['state' => $n === 1 ? 'clear' : 'contested', 'rivals' => $n - 1];
    }
    return $out;
}

/**
 * Minimum-cost assignment (Hungarian algorithm, O(n²·m)).
 * $cost[i][j] for n rows and m ≥ n columns; returns the column of every row.
 */
function hungarian(array $cost): array
{
    $n = count($cost);
    if ($n === 0) {
        return [];
    }
    $m = count($cost[0]);
    $u = array_fill(0, $n + 1, 0);
    $v = array_fill(0, $m + 1, 0);
    $p = array_fill(0, $m + 1, 0);
    $way = array_fill(0, $m + 1, 0);
    for ($i = 1; $i <= $n; $i++) {
        $p[0] = $i;
        $j0 = 0;
        $minv = array_fill(0, $m + 1, PHP_INT_MAX);
        $used = array_fill(0, $m + 1, false);
        do {
            $used[$j0] = true;
            $i0 = $p[$j0];
            $delta = PHP_INT_MAX;
            $j1 = 0;
            for ($j = 1; $j <= $m; $j++) {
                if ($used[$j]) {
                    continue;
                }
                $cur = $cost[$i0 - 1][$j - 1] - $u[$i0] - $v[$j];
                if ($cur < $minv[$j]) {
                    $minv[$j] = $cur;
                    $way[$j] = $j0;
                }
                if ($minv[$j] < $delta) {
                    $delta = $minv[$j];
                    $j1 = $j;
                }
            }
            for ($j = 0; $j <= $m; $j++) {
                if ($used[$j]) {
                    $u[$p[$j]] += $delta;
                    $v[$j] -= $delta;
                } else {
                    $minv[$j] -= $delta;
                }
            }
            $j0 = $j1;
        } while ($p[$j0] !== 0);
        do {
            $j1 = $way[$j0];
            $p[$j0] = $p[$j1];
            $j0 = $j1;
        } while ($j0 !== 0);
    }
    $rowToCol = array_fill(0, $n, -1);
    for ($j = 1; $j <= $m; $j++) {
        if ($p[$j] !== 0) {
            $rowToCol[$p[$j] - 1] = $j - 1;
        }
    }
    return $rowToCol;
}

/**
 * Best possible allocation for all delegates that are not fixed (released
 * allocations and rows ticked "fest" keep their seat).
 * Returns id => ['committee' => slug, 'country' => name] ('' / '' = no seat left).
 * Ties go to the earlier registration.
 */
function alloc_compute(array $delegates): array
{
    $taken = [];
    $open = [];
    foreach ($delegates as $r) {
        if ((!empty($r['released']) || !empty($r['alloc_fixed'])) && alloc_complete($r)) {
            $taken[alloc_key($r)] = true;
        } else {
            $open[] = $r;
        }
    }
    $seats = [];
    foreach (c('committees', []) as $cm) {
        foreach (committee_countries($cm) as $country) {
            if (!isset($taken[$cm['slug'] . '|' . mb_strtolower($country)])) {
                $seats[] = [$cm['slug'], $country];
            }
        }
    }
    $n = count($open);
    $cost = [];
    foreach ($open as $i => $r) {
        // Earlier registrations weigh a little more, so they win ties.
        $weight = 10000 + ($n - $i);
        $row = [];
        foreach ($seats as [$slug, $country]) {
            $row[] = ALLOC_COSTS[alloc_match($r, $slug, $country)][0] * $weight;
        }
        for ($k = 0; $k < $n; $k++) {
            $row[] = ALLOC_COST_NONE * $weight; // "no seat" columns, so a solution always exists
        }
        $cost[] = $row;
    }
    $result = [];
    foreach (hungarian($cost) as $i => $col) {
        $seat = $seats[$col] ?? ['', ''];
        $result[$open[$i]['id']] = ['committee' => $seat[0], 'country' => $seat[1]];
    }
    return $result;
}

/** Text for one delegate: placeholders in the allocation e-mail. */
function alloc_mail_vars(array $r): array
{
    $cm = committee_by_label((string) ($r['assigned_committee'] ?? '')) ?? [];
    return [
        'code' => format_code((string) ($r['code'] ?? '')),
        'country' => trim((string) ($r['assigned_country'] ?? '')),
        'committee' => (string) ($cm['name'] ?? ''),
        'topics' => implode("\n", array_map(fn ($t) => '- ' . $t, (array) ($cm['topics'] ?? []))),
        'conference' => (string) c('conference.edition'),
        'portal' => login_url(),
        'email' => (string) c('site.email'),
    ];
}

/**
 * Releases the allocation: every delegate with a complete allocation that has
 * not been told yet (or whose allocation changed since) gets an e-mail and
 * sees it in the delegate area from now on. Returns [sent, failed].
 */
function alloc_release(string $subject, string $text): array
{
    $sent = 0;
    $failed = 0;
    foreach (alloc_delegates() as $r) {
        if (!alloc_complete($r) || alloc_is_sent($r)) {
            continue;
        }
        $vars = alloc_mail_vars($r);
        // No address (test delegates): released without an e-mail.
        $ok = ($r['email'] ?? '') === '' || send_mail($r['email'], fill_placeholders($subject, $vars), fill_placeholders($text, $vars), c('site.email') ?: null);
        // Visible in the delegate area even if the mail could not be sent.
        update_json(REGISTRATIONS_FILE, function (array $regs) use ($r, $ok) {
            foreach ($regs as &$x) {
                if ($x['id'] === $r['id']) {
                    $x['released'] = ['at' => date('c'), 'key' => $ok ? alloc_key($x) : ''];
                    if (($x['status'] ?? 'received') === 'received') {
                        $x['status'] = 'confirmed'; // releasing the allocation confirms the delegate
                    }
                }
            }
            return $regs;
        });
        $ok ? $sent++ : $failed++;
    }
    return [$sent, $failed];
}

/**
 * What the logged-in area is called: "delegate area" for delegates,
 * "OMUN portal" for everyone else (chairs, team, coordinators, laptops).
 */
function portal_label(?string $account, bool $capital = false): string
{
    $delegate = true;
    if ($account && is_staff_account($account)) {
        $delegate = false;
    }
    foreach ($account ? registrations_for($account) : [] as $r) {
        if (!is_delegate_reg($r)) {
            $delegate = false;
        }
    }
    $name = $account && !$delegate ? c('site.name', 'OMUN') . ' portal' : 'delegate area';
    return $capital ? ucfirst($name) : $name;
}

/* ---------- Who may see what in the delegate area ---------- */

/** Registrations of an account that count (not cancelled, role confirmed). */
function active_regs(?string $account): array
{
    return array_values(array_filter($account ? registrations_for($account) : [],
        fn ($r) => ($r['status'] ?? '') !== 'cancelled' && empty($r['role_pending'])));
}

/** Member of the committee (delegate with a released allocation, or chair), conference admin or MUN Coordinator. */
function committee_access(?string $account, string $slug): bool
{
    foreach (active_regs($account) as $r) {
        if (!empty($r['is_admin']) || !empty($r['is_coordinator']) || (alloc_visible($r) && reg_committee_slug($r) === $slug)) {
            return true;
        }
    }
    return false;
}

/** Chair of the committee (or conference admin). */
function committee_chair_access(?string $account, string $slug): bool
{
    foreach (active_regs($account) as $r) {
        if (!empty($r['is_admin']) || (!empty($r['is_chair']) && reg_committee_slug($r) === $slug)) {
            return true;
        }
    }
    return false;
}

/** Position papers handed in by the delegates of a committee. */
function committee_papers(string $slug): array
{
    $list = array_values(array_filter(read_json(REGISTRATIONS_FILE), fn ($r) => !empty($r['paper']) && is_delegate_reg($r)
        && alloc_visible($r) && reg_committee_slug($r) === $slug && ($r['status'] ?? '') !== 'cancelled'));
    usort($list, fn ($a, $b) => strcasecmp((string) ($a['assigned_country'] ?? ''), (string) ($b['assigned_country'] ?? '')));
    return $list;
}

/** File name of a paper as others see it: by country, never by personal code. */
function paper_public_name(array $reg): string
{
    $cm = committee_by_label((string) ($reg['assigned_committee'] ?? ''));
    $ext = pathinfo((string) ($reg['paper']['file'] ?? ''), PATHINFO_EXTENSION);
    $label = trim((string) ($reg['assigned_country'] ?? '')) ?: 'Delegation';
    return 'Position paper - ' . preg_replace('/[^\w .()-]/u', '_', $label) . (($cm['abbr'] ?? '') !== '' ? ' (' . $cm['abbr'] . ')' : '') . '.' . $ext;
}

/** E-mail to the chairs of the committee when a delegate hands in a position paper. */
function notify_paper(array $reg): void
{
    $slug = reg_committee_slug($reg);
    if ($slug === '' || !alloc_visible($reg)) {
        return;
    }
    $cm = committee_by_slug($slug);
    $country = trim((string) ($reg['assigned_country'] ?? '')) ?: 'A delegation';
    $body = "Hello,\n\n{$country} has handed in a position paper for {$cm['name']}.\n\nYou can read all position papers of your committee in the OMUN portal:\n" . login_url() . "\n";
    foreach (read_json(REGISTRATIONS_FILE) as $r) {
        if (!empty($r['is_chair']) && reg_committee_slug($r) === $slug && ($r['status'] ?? '') !== 'cancelled' && empty($r['role_pending']) && ($r['email'] ?? '') !== '') {
            send_mail($r['email'], c('site.name') . ': position paper from ' . $country . ' (' . (($cm['abbr'] ?? '') ?: $cm['name']) . ')', $body, c('site.email') ?: null);
        }
    }
}

/* ---------- Team members edit their own profile on the team page ---------- */

/** The team page entry linked to a registration (admin → Zuteilung), or null. */
function team_profile(array $reg): ?array
{
    foreach (c('team', []) as $m) {
        if (($reg['team_profile'] ?? '') !== '' && ($m['id'] ?? '') === $reg['team_profile']) {
            return $m;
        }
    }
    return null;
}

/** Turns an uploaded photo into black and white (the team page shows b/w portraits only). */
function image_to_grayscale(string $path): void
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $load = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$ext] ?? null;
    if (!$load || !function_exists($load) || !function_exists('imagefilter')) {
        return;
    }
    $im = @$load($path);
    if (!$im) {
        return;
    }
    imagefilter($im, IMG_FILTER_GRAYSCALE);
    match ($ext) {
        'jpg' => imagejpeg($im, $path, 86),
        'png' => (imagesavealpha($im, true) && imagepng($im, $path)),
        'webp' => imagewebp($im, $path, 86),
    };
}

/** Saves bio and (optionally) a new photo of the own team profile. Returns an error message or null. */
function save_team_profile(array $reg, string $bio, ?array $photo): ?string
{
    $profile = team_profile($reg);
    if (!$profile) {
        return 'No team profile is linked to your registration.';
    }
    $path = null;
    if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($photo['error'] !== UPLOAD_ERR_OK || $photo['size'] > 10 * 1024 * 1024) {
            return 'The photo could not be uploaded. Photos can be up to 10 MB.';
        }
        $ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return 'Please upload the photo as JPG, PNG or WebP.';
        }
        $photo['name'] = 'team-' . $profile['id'] . '.' . $ext; // no personal file names on the server
        try {
            $path = store_upload($photo, 'image');
        } catch (RuntimeException $ex) {
            return 'The photo could not be saved. Please try another file.';
        }
        image_to_grayscale(ROOT . '/' . $path);
    }
    $content = read_json(CONTENT_FILE);
    foreach ($content['team'] ?? [] as $i => $m) {
        if (($m['id'] ?? '') === $profile['id']) {
            $content['team'][$i]['bio'] = trim(mb_substr($bio, 0, 3000));
            if ($path) {
                $content['team'][$i]['photo'] = $path;
            }
        }
    }
    save_content($content, 'Team-Profil (Teilnehmer-Bereich): ' . trim(str_replace('*', '', (string) $profile['name'])));
    return null;
}

/** May this account switch from the delegate area into the website admin? */
function is_site_admin_account(?string $account): bool
{
    foreach (active_regs($account) as $r) {
        if (!empty($r['is_site_admin'])) {
            return true;
        }
    }
    return false;
}
