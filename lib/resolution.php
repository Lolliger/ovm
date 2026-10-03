<?php
declare(strict_types=1);

/**
 * Resolution editor: one resolution per committee.
 *
 * - draft:  the main submitter (set by the chairs) writes the document; chairs can always edit.
 * - debate: only chairs edit the document directly. Delegates submit amendments
 *           (add / modify / strike a clause). An amendment is visible to its author and
 *           the chairs only. Chairs put one amendment "on the floor" (shown on the beamer),
 *           then accept or reject it. While an amendment is on the floor, anyone can submit
 *           an amendment to it (2nd degree = new wording for it). Accepting a 2nd-degree
 *           amendment changes the original amendment and accepts it as well; rejecting it
 *           puts the original amendment back on the floor.
 * - closed: read-only.
 *
 * Chairs = registrations with "is_chair" for that committee; admins act as chairs.
 * Viewers = confirmed conference managers and the laptop account (all committees, read only).
 * Data: data/resolutions/<committee-slug>.json
 */

require_once __DIR__ . '/admin.php';

const RES_DIR = DATA_DIR . '/resolutions';
const RES_MAX_TEXT = 3000;

/* ---------- Storage ---------- */

function res_file(string $slug): string
{
    return RES_DIR . '/' . slugify($slug) . '.json';
}

function res_default(array $committee): array
{
    return [
        'committee' => $committee['slug'],
        'topic' => $committee['topics'][0] ?? '',
        'status' => 'draft',
        'main_submitter' => '',
        'co_submitters' => '',
        'signatories' => '',
        'clauses' => [],
        'amendments' => [],
        'current' => null,
        'speakers' => [],
        'rev' => 0,
        'updated' => date('c'),
    ];
}

function res_load(array $committee): array
{
    $data = read_json(res_file($committee['slug']));
    return $data ? $data + res_default($committee) : res_default($committee);
}

/** Locked read-modify-write; $fn may throw RuntimeException with a message for the user. */
function res_update(array $committee, callable $fn): array
{
    return update_json(res_file($committee['slug']), function (array $data) use ($committee, $fn) {
        $data = ($data ?: res_default($committee)) + res_default($committee);
        $data = $fn($data);
        $data['rev'] = (int) $data['rev'] + 1;
        $data['updated'] = date('c');
        return $data;
    });
}

/* ---------- Finished resolutions (several per committee) ---------- */

const RES_OUTCOMES = ['Adopted', 'Not adopted', 'Withdrawn', 'Closed'];

function res_archive_file(string $slug, string $id): string
{
    return RES_DIR . '/' . slugify($slug) . '--' . preg_replace('/[^a-z0-9]/', '', $id) . '.json';
}

/** Finished resolutions of a committee, oldest first. */
function res_archive_list(string $slug): array
{
    $list = [];
    foreach (glob(RES_DIR . '/' . slugify($slug) . '--*.json') ?: [] as $f) {
        if ($r = read_json($f)) {
            $list[] = $r;
        }
    }
    usort($list, fn ($a, $b) => [$a['number'] ?? 0, $a['archived'] ?? ''] <=> [$b['number'] ?? 0, $b['archived'] ?? '']);
    return $list;
}

function res_archive_load(string $slug, string $id): ?array
{
    return $id !== '' && is_file(res_archive_file($slug, $id)) ? read_json(res_archive_file($slug, $id)) : null;
}

/** Saves the current resolution as finished and returns a fresh one (speakers list is kept). */
function res_archive_and_start_new(array $res, string $outcome): array
{
    $cm = res_committee_of($res);
    $done = $res;
    $done['id'] = res_id();
    $done['number'] = (int) ($res['number'] ?? 1);
    $done['status'] = 'closed';
    $done['current'] = null;
    $done['outcome'] = $outcome;
    $done['archived'] = date('c');
    $done['amendments'] = array_map(fn ($a) => in_array($a['status'], ['pending', 'floor'], true) ? array_merge($a, ['status' => 'obsolete']) : $a, $res['amendments']);
    write_json(res_archive_file($res['committee'], $done['id']), $done);

    $used = array_map(fn ($r) => $r['topic'] ?? '', array_merge(res_archive_list($res['committee']), [$res]));
    $fresh = res_default($cm + ['slug' => $res['committee']]);
    $fresh['topic'] = '';
    foreach ($cm['topics'] ?? [] as $t) {
        if (!in_array($t, $used, true)) {
            $fresh['topic'] = $t;
            break;
        }
    }
    $fresh['number'] = $done['number'] + 1;
    $fresh['speakers'] = $res['speakers'];
    $fresh['rev'] = $res['rev'];
    return $fresh;
}

function res_id(): string
{
    return bin2hex(random_bytes(5));
}

/* ---------- Who is looking? ---------- */

function reg_label(array $r): string
{
    return trim((string) ($r['assigned_country'] ?? '')) ?: trim($r['first_name'] . ' ' . $r['last_name']);
}

/** All non-cancelled registrations allocated to a committee. */
function committee_members(array $committee): array
{
    return array_values(array_filter(read_json(REGISTRATIONS_FILE), function ($r) use ($committee) {
        $cm = committee_by_label((string) ($r['assigned_committee'] ?? ''));
        return $cm && $cm['slug'] === $committee['slug'] && ($r['status'] ?? '') !== 'cancelled' && empty($r['role_pending']);
    }));
}

function committee_by_slug(string $slug): ?array
{
    foreach (c('committees', []) as $cm) {
        if (($cm['slug'] ?? '') === $slug) {
            return $cm;
        }
    }
    return null;
}

/**
 * Returns the viewer's context for a committee, or null if they may not see it:
 * ['committee', 'role' => admin|chair|delegate, 'reg' => registration|null, 'label', 'committees' => [slug => name]]
 */
function res_context(?string $slug): ?array
{
    $email = portal_email();
    // Logged in as a participant (e.g. testing a delegate account) → their own
    // rights only, even if the same browser is also logged into the admin.
    $isAdmin = !$email && is_logged_in();
    $rank = ['viewer' => 0, 'delegate' => 1, 'chair' => 2, 'admin' => 3];
    $options = []; // slug => [committee, role, reg]
    $offer = function (array $cm, string $role, ?array $reg) use (&$options, $rank) {
        $have = $options[$cm['slug']] ?? null;
        if (!$have || $rank[$role] > $rank[$have[1]]) {
            $options[$cm['slug']] = [$cm, $role, $reg];
        }
    };
    foreach (c('committees', []) as $cm) {
        if ($isAdmin) {
            $offer($cm, 'admin', null);
        } elseif (is_staff_account($email)) {
            $offer($cm, 'viewer', null);
        }
    }
    foreach ($email ? registrations_for($email) : [] as $r) {
        if (($r['status'] ?? '') === 'cancelled' || !empty($r['role_pending'])) {
            continue; // chairs / conference managers only once confirmed in the admin
        }
        if (!empty($r['is_manager'])) {
            foreach (c('committees', []) as $cm) {
                $offer($cm, 'viewer', $r);
            }
        }
        if ($cm = committee_by_label((string) ($r['assigned_committee'] ?? ''))) {
            $offer($cm, !empty($r['is_chair']) ? 'chair' : 'delegate', $r);
        }
    }
    if (!$options) {
        return null;
    }
    $pick = $slug !== null && isset($options[$slug]) ? $options[$slug] : reset($options);
    [$cm, $role, $reg] = $pick;
    $label = match ($role) {
        'viewer' => $reg ? 'Conference manager (view only)' : (staff_accounts()[$email]['label'] ?? 'Laptop') . ' (view only)',
        'admin' => 'Chair',
        default => $reg ? reg_label($reg) : 'Chair',
    };
    return [
        'committee' => $cm,
        'role' => $role,
        'reg' => $role === 'viewer' ? null : $reg,
        'label' => $label,
        'committees' => array_map(fn ($o) => $o[0]['name'], $options),
    ];
}

/** Conference managers and the laptop account: see everything, change nothing. */
function res_is_viewer(array $ctx): bool
{
    return $ctx['role'] === 'viewer';
}

function res_is_chair(array $ctx): bool
{
    return in_array($ctx['role'], ['chair', 'admin'], true);
}

function res_can_edit(array $ctx, array $res): bool
{
    if (res_is_chair($ctx)) {
        return true;
    }
    return $res['status'] === 'draft' && $ctx['reg'] && $res['main_submitter'] === $ctx['reg']['id'] && res_has_submitters($ctx['committee']);
}

/* ---------- Clauses ---------- */

/** Common opening phrases, so "Deeply concerned" is formatted as a whole. */
function clause_opening(string $text): array
{
    $multi = 'deeply (?:concerned|convinced|disturbed|regretting|conscious)|fully (?:aware|alarmed|believing)|further (?:\w+)|bearing in mind|keeping in mind|having (?:\w+)(?: (?:also|further))?|taking (?:note|into account|into consideration)|noting (?:with \w+(?: \w+)?|further)|recognizing (?:also|further)|calls upon|strongly (?:\w+)|draws (?:the attention|attention)|expresses (?:its \w+)|takes note|has resolved|solemnly affirms|seriously (?:\w+)|guided by|viewing with \w+|with (?:\w+)|reaffirming (?:also)|emphasizing (?:further)|stressing (?:further)';
    if (preg_match('/^(' . $multi . ')\b(.*)$/isu', $text, $m)) {
        return [$m[1], $m[2]];
    }
    if (preg_match('/^(\S+)(.*)$/su', $text, $m)) {
        return [$m[1], $m[2]];
    }
    return ['', $text];
}

function roman(int $n): string
{
    $map = ['x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1];
    $out = '';
    foreach ($map as $r => $v) {
        while ($n >= $v) {
            $out .= $r;
            $n -= $v;
        }
    }
    return $out;
}

/** id => ['num' => '3 b)', 'label' => 'Operative clause 3 b)'] */
function clause_numbers(array $clauses): array
{
    $out = [];
    $pre = 0;
    $c = [0, 0, 0];
    foreach ($clauses as $cl) {
        if ($cl['type'] === 'pre') {
            $pre++;
            $out[$cl['id']] = ['num' => '', 'label' => 'Preambular clause ' . $pre];
            continue;
        }
        $lvl = max(0, min(2, (int) $cl['level']));
        $c[$lvl]++;
        for ($i = $lvl + 1; $i < 3; $i++) {
            $c[$i] = 0;
        }
        $num = match ($lvl) {
            0 => $c[0] . ')',
            1 => chr(96 + min(26, $c[1])) . ')',
            default => roman($c[2]) . ')',
        };
        $full = $c[0] . ($lvl >= 1 ? ' ' . chr(96 + min(26, $c[1])) . ')' : '') . ($lvl >= 2 ? ' ' . roman($c[2]) . ')' : '');
        $out[$cl['id']] = ['num' => $num, 'label' => 'Operative clause ' . $full];
    }
    return $out;
}

/** Clauses in display order: preambular first, then operative (each in stored order). */
function clauses_sorted(array $clauses): array
{
    return array_merge(
        array_values(array_filter($clauses, fn ($c) => $c['type'] === 'pre')),
        array_values(array_filter($clauses, fn ($c) => $c['type'] !== 'pre'))
    );
}

function clause_index(array $clauses, string $id): ?int
{
    foreach ($clauses as $i => $c) {
        if ($c['id'] === $id) {
            return $i;
        }
    }
    return null;
}

/** Inserts a clause; $after = clause id, or 'pre_start' | 'pre_end' | 'op_start' | 'op_end'. */
function clause_insert(array $clauses, array $clause, string $after): array
{
    $clauses = clauses_sorted($clauses);
    $pres = count(array_filter($clauses, fn ($c) => $c['type'] === 'pre'));
    $pos = match ($after) {
        'pre_start' => 0,
        'pre_end', 'op_start' => $pres,
        'op_end', '' => count($clauses),
        default => ($i = clause_index($clauses, $after)) === null ? null : $i + 1,
    };
    if ($pos === null) {
        throw new RuntimeException('The clause this refers to no longer exists.');
    }
    array_splice($clauses, $pos, 0, [$clause]);
    return clauses_sorted($clauses);
}

function clean_text(string $s): string
{
    return trim(mb_substr(str_replace("\r", '', $s), 0, RES_MAX_TEXT));
}

function new_clause(string $type, int $level, string $text): array
{
    $text = clean_text($text);
    if ($text === '') {
        throw new RuntimeException('The clause text is empty.');
    }
    return ['id' => res_id(), 'type' => $type === 'pre' ? 'pre' : 'op', 'level' => $type === 'pre' ? 0 : max(0, min(2, $level)), 'text' => $text];
}

/* ---------- Amendments ---------- */

function amendment_by_id(array $res, ?string $id): ?array
{
    foreach ($res['amendments'] as $a) {
        if ($a['id'] === $id) {
            return $a;
        }
    }
    return null;
}

function set_amendment(array &$res, string $id, array $changes): void
{
    foreach ($res['amendments'] as &$a) {
        if ($a['id'] === $id) {
            $a = $changes + $a;
        }
    }
}

/** Applies an accepted 1st-degree amendment to the clauses. */
function apply_amendment(array $res, array $a): array
{
    $clauses = $res['clauses'];
    if ($a['kind'] === 'add') {
        $clauses = clause_insert($clauses, new_clause($a['ctype'], (int) $a['level'], $a['text']), $a['after']);
    } else {
        $i = clause_index($clauses, (string) $a['target']);
        if ($i === null) {
            throw new RuntimeException('The clause this amendment refers to no longer exists. Reject the amendment instead.');
        }
        if ($a['kind'] === 'strike') {
            array_splice($clauses, $i, 1);
        } else {
            $clauses[$i]['text'] = $a['text'];
        }
    }
    $res['clauses'] = $clauses;
    return $res;
}

function close_children(array &$res, string $parentId, string $status): void
{
    foreach ($res['amendments'] as &$a) {
        if (($a['parent'] ?? null) === $parentId && in_array($a['status'], ['pending', 'floor'], true)) {
            $a['status'] = $status;
        }
    }
}

/* ---------- Actions (POST) ---------- */

function res_handle_post(array $ctx): void
{
    if (res_is_viewer($ctx)) {
        throw new RuntimeException('This account can only view the resolutions.');
    }
    $a = (string) ($_POST['a'] ?? '');
    $chair = res_is_chair($ctx);
    $cm = $ctx['committee'];
    $me = $ctx['reg'];
    $flash = '';

    res_update($cm, function (array $res) use ($a, $chair, $ctx, $me, &$flash) {
        $editor = res_can_edit($ctx, $res);
        $closed = $res['status'] === 'closed';
        $p = fn (string $k) => (string) ($_POST[$k] ?? '');

        switch ($a) {
            /* --- document (chairs; main submitter while drafting) --- */
            case 'clause_add':
                if (!$editor || ($closed && !$chair)) {
                    throw new RuntimeException('You cannot edit the document.');
                }
                $res['clauses'] = clause_insert($res['clauses'], new_clause($p('ctype'), (int) $p('level'), $p('text')), $p('after'));
                $flash = 'Clause added.';
                break;
            case 'clause_edit':
            case 'clause_delete':
            case 'clause_move':
                if (!$editor || ($closed && !$chair)) {
                    throw new RuntimeException('You cannot edit the document.');
                }
                $res['clauses'] = clauses_sorted($res['clauses']);
                $i = clause_index($res['clauses'], $p('id'));
                if ($i === null) {
                    throw new RuntimeException('This clause no longer exists – someone else changed the document.');
                }
                if ($a === 'clause_delete') {
                    array_splice($res['clauses'], $i, 1);
                    $flash = 'Clause deleted.';
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
                    $flash = 'Clause saved.';
                }
                break;

            /* --- amendments (delegates and chairs) --- */
            case 'amend':
                if ($res['status'] !== 'debate') {
                    throw new RuntimeException('Amendments can only be submitted during the debate.');
                }
                $parent = $p('parent') !== '' ? amendment_by_id($res, $p('parent')) : null;
                $debated = res_debated_amendment($res);
                if ($p('parent') !== '' && (!$parent || !$debated || $debated['id'] !== $parent['id'])) {
                    throw new RuntimeException('You can only amend the amendment that is currently on the floor.');
                }
                $kind = $parent ? 'modify' : (in_array($p('kind'), ['add', 'modify', 'strike'], true) ? $p('kind') : 'modify');
                $text = $kind === 'strike' ? '' : clean_text($p('text'));
                if ($kind !== 'strike' && $text === '') {
                    throw new RuntimeException('Please enter the text of your amendment.');
                }
                if (!$parent && $kind !== 'add' && clause_index($res['clauses'], $p('target')) === null) {
                    throw new RuntimeException('Please choose the clause your amendment refers to.');
                }
                if ($parent && $parent['kind'] === 'strike') {
                    throw new RuntimeException('An amendment to strike a clause cannot be amended – debate and vote on it.');
                }
                $nums = clause_numbers(clauses_sorted($res['clauses']));
                $targetId = $parent ? null : ($kind === 'add' ? null : $p('target'));
                $orig = '';
                foreach ($res['clauses'] as $cl) {
                    if ($cl['id'] === $targetId) {
                        $orig = $cl['text'];
                    }
                }
                $res['amendments'][] = [
                    // Snapshot of what the amendment referred to when it was submitted,
                    // so the history still makes sense after the document changed.
                    'target_label' => $targetId ? ($nums[$targetId]['label'] ?? '') : ($parent['target_label'] ?? ''),
                    'orig' => $orig,
                    'position_label' => $kind === 'add' && !$parent ? add_position_label($res, $p('after') ?: 'op_end') : '',
                    'parent_text' => $parent['text'] ?? '',
                    'id' => res_id(),
                    'parent' => $parent['id'] ?? null,
                    'kind' => $kind,
                    'target' => $parent ? null : ($kind === 'add' ? null : $p('target')),
                    'after' => $kind === 'add' ? ($p('after') ?: 'op_end') : null,
                    'ctype' => $p('ctype') === 'pre' ? 'pre' : 'op',
                    'level' => max(0, min(2, (int) $p('level'))),
                    'text' => $text,
                    'author' => $me['id'] ?? 'chair',
                    'author_label' => $chair ? 'Chair' : $ctx['label'],
                    'status' => 'pending',
                    'created' => date('c'),
                ];
                $flash = $parent ? 'Your second-degree amendment was submitted to the chairs.' : 'Your amendment was submitted to the chairs.';
                break;
            case 'amend_withdraw':
                $am = amendment_by_id($res, $p('id'));
                if (!$am || ($am['author'] !== ($me['id'] ?? '') && !$chair) || $am['status'] !== 'pending') {
                    throw new RuntimeException('This amendment cannot be withdrawn.');
                }
                set_amendment($res, $am['id'], ['status' => 'withdrawn']);
                $flash = 'Amendment withdrawn.';
                break;

            /* --- chairs only --- */
            case 'meta':
            case 'status':
            case 'floor':
            case 'accept':
            case 'reject':
            case 'clear_floor':
            case 'speaker_add':
            case 'speaker_next':
            case 'speaker_remove':
            case 'speaker_clear':
            case 'res_new':
                if (!$chair) {
                    throw new RuntimeException('Only the chairs can do this.');
                }
                $res = res_chair_action($res, $a, $p, $flash);
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        return $res;
    });
    if ($flash) {
        res_flash($flash);
    }
}

function res_chair_action(array $res, string $a, callable $p, string &$flash): array
{
    switch ($a) {
        case 'meta':
            $res['topic'] = trim(mb_substr($p('topic'), 0, 300));
            $res['main_submitter'] = $p('main_submitter');
            $res['co_submitters'] = trim(mb_substr($p('co_submitters'), 0, 1000));
            $res['signatories'] = trim(mb_substr($p('signatories'), 0, 2000));
            $flash = 'Details saved.';
            break;
        case 'status':
            if (in_array($p('status'), ['draft', 'debate', 'closed'], true)) {
                if ($p('status') === 'debate' && $res['status'] !== 'debate' && $res['main_submitter'] !== '' && res_has_submitters(res_committee_of($res))) {
                    // The main submitter presents the draft resolution first.
                    res_speaker_push($res, res_author_label(['author' => $res['main_submitter']]));
                }
                $res['status'] = $p('status');
                if ($res['status'] !== 'debate') {
                    $res['current'] = null;
                }
                $flash = 'Status changed.';
            }
            break;
        case 'floor':
            $am = amendment_by_id($res, $p('id'));
            if (!$am || !in_array($am['status'], ['pending', 'floor'], true)) {
                throw new RuntimeException('This amendment can no longer be debated.');
            }
            // A 2nd-degree amendment goes on the floor on top of its parent.
            if ($cur = amendment_by_id($res, $res['current'])) {
                if ($cur['id'] !== ($am['parent'] ?? null) && $cur['status'] === 'floor') {
                    set_amendment($res, $cur['id'], ['status' => 'pending']);
                }
            }
            if (!empty($am['parent'])) {
                set_amendment($res, $am['parent'], ['status' => 'floor']);
            }
            set_amendment($res, $am['id'], ['status' => 'floor']);
            $res['current'] = $am['id'];
            res_speaker_push($res, res_author_label($am));
            break;
        case 'clear_floor':
            if ($cur = amendment_by_id($res, $res['current'])) {
                set_amendment($res, $cur['id'], ['status' => 'pending']);
                if (!empty($cur['parent'])) {
                    set_amendment($res, $cur['parent'], ['status' => 'pending']);
                }
            }
            $res['current'] = null;
            break;
        case 'accept':
            $am = amendment_by_id($res, $p('id'));
            if (!$am || !in_array($am['status'], ['pending', 'floor'], true)) {
                throw new RuntimeException('This amendment is no longer open.');
            }
            if (!empty($am['parent'])) {
                // 2nd degree: new wording for the original, which is accepted with it.
                $parent = amendment_by_id($res, $am['parent']);
                if (!$parent || !in_array($parent['status'], ['pending', 'floor'], true)) {
                    throw new RuntimeException('The original amendment is no longer open.');
                }
                $parent['text'] = $am['text'];
                $res = apply_amendment($res, $parent);
                set_amendment($res, $am['id'], ['status' => 'accepted', 'decided' => date('c')]);
                set_amendment($res, $parent['id'], ['status' => 'accepted', 'text' => $am['text'], 'text_before' => $parent['text_before'] ?? amendment_by_id($res, $parent['id'])['text'], 'decided' => date('c'), 'amended_by' => $am['id']]);
                close_children($res, $parent['id'], 'obsolete');
                $flash = 'Second-degree amendment accepted – the original amendment was accepted with the new wording.';
            } else {
                $res = apply_amendment($res, $am);
                set_amendment($res, $am['id'], ['status' => 'accepted', 'decided' => date('c')]);
                close_children($res, $am['id'], 'obsolete');
                $flash = 'Amendment accepted and applied to the document.';
            }
            $res['current'] = null;
            break;
        case 'reject':
            $am = amendment_by_id($res, $p('id'));
            if (!$am || !in_array($am['status'], ['pending', 'floor'], true)) {
                throw new RuntimeException('This amendment is no longer open.');
            }
            set_amendment($res, $am['id'], ['status' => 'rejected', 'decided' => date('c')]);
            if (!empty($am['parent'])) {
                // Back to the original amendment.
                if ($res['current'] === $am['id']) {
                    $res['current'] = $am['parent'];
                }
                $flash = 'Second-degree amendment rejected – back to the original amendment.';
            } else {
                close_children($res, $am['id'], 'obsolete');
                if ($res['current'] === $am['id']) {
                    $res['current'] = null;
                }
                $flash = 'Amendment rejected.';
            }
            break;
        case 'speaker_add':
            $label = trim(mb_substr($p('label'), 0, 80));
            if ($label !== '') {
                $res['speakers'][] = ['id' => res_id(), 'label' => $label];
            }
            break;
        case 'speaker_next':
            array_shift($res['speakers']);
            break;
        case 'speaker_remove':
            $res['speakers'] = array_values(array_filter($res['speakers'], fn ($s) => $s['id'] !== $p('id')));
            break;
        case 'speaker_clear':
            $res['speakers'] = [];
            break;
        case 'res_new':
            if (!$res['clauses']) {
                throw new RuntimeException('This resolution is still empty – there is nothing to save yet.');
            }
            $outcome = in_array($p('outcome'), RES_OUTCOMES, true) ? $p('outcome') : 'Closed';
            $res = res_archive_and_start_new($res, $outcome);
            $flash = 'Resolution saved as "' . $outcome . '". A new, empty resolution has been started.';
            break;
    }
    return res_normalize_floor($res);
}

/** Keeps "current" and the "floor" status consistent after any decision. */
function res_normalize_floor(array $res): array
{
    $cur = amendment_by_id($res, $res['current']);
    if ($cur && !in_array($cur['status'], ['pending', 'floor'], true)) {
        $parent = !empty($cur['parent']) ? amendment_by_id($res, $cur['parent']) : null;
        $cur = $parent && in_array($parent['status'], ['pending', 'floor'], true) ? $parent : null;
    }
    $res['current'] = $cur['id'] ?? null;
    $keep = $cur ? array_filter([$cur['id'], $cur['parent'] ?? null]) : [];
    foreach ($res['amendments'] as &$a) {
        if (in_array($a['status'], ['pending', 'floor'], true)) {
            $a['status'] = in_array($a['id'], $keep, true) ? 'floor' : 'pending';
        }
    }
    return $res;
}

function res_flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['res_flash'] = [$type, $msg];
}

function res_take_flash(): ?array
{
    start_session();
    $f = $_SESSION['res_flash'] ?? null;
    unset($_SESSION['res_flash']);
    return $f;
}

/* ---------- Rendering helpers (HTML snippets, also used for live updates) ---------- */

function clause_html(array $cl, array $nums, bool $struck = false): string
{
    [$open, $rest] = clause_opening($cl['text']);
    $cls = 'clause clause-' . $cl['type'] . ' lvl-' . (int) $cl['level'] . ($struck ? ' struck' : '');
    $num = $nums[$cl['id']]['num'] ?? '';
    $tag = $cl['type'] === 'pre' || (int) $cl['level'] === 0 ? 'em' : 'span';
    return '<p class="' . $cls . '" data-clause="' . e($cl['id']) . '">'
        . ($num !== '' ? '<span class="num">' . e($num) . '</span> ' : '')
        . '<' . $tag . '>' . e($open) . '</' . $tag . '>' . nl2br(e($rest), false) . '</p>';
}

/** Describes what an amendment does, with the original wording where useful. */
/**
 * Who an amendment is shown as: always the delegation's country, never the
 * person's name ("Chair" for chairs/admins). Looked up live, so a country
 * allocated later shows up too.
 */
function res_author_label(array $am): string
{
    static $regs = null;
    $regs ??= array_column(read_json(REGISTRATIONS_FILE), null, 'id');
    $r = $regs[$am['author'] ?? ''] ?? null;
    if (!$r) {
        return ($am['author'] ?? '') === 'chair' || ($am['author_label'] ?? '') === 'Chair' ? 'Chair' : 'Delegation';
    }
    if (!empty($r['is_chair'])) {
        return 'Chair';
    }
    return trim((string) ($r['assigned_country'] ?? '')) ?: 'Delegation (no country allocated)';
}

function res_committee_of(array $res): array
{
    foreach (c('committees', []) as $cm) {
        if ($cm['slug'] === ($res['committee'] ?? '')) {
            return $cm;
        }
    }
    return ['name' => '', 'abbr' => ''];
}

/** Committees like the Security Council have no main/co-submitters or signatories. */
function res_has_submitters(array $committee): bool
{
    $f = (string) ($committee['res_format'] ?? '');
    if (str_starts_with($f, 'Nur')) {
        return false;
    }
    if (str_starts_with($f, 'Mit')) {
        return true;
    }
    return !preg_match('/^(UN)?SC$/i', trim((string) ($committee['abbr'] ?? ''))) && stripos((string) $committee['name'], 'security council') === false;
}

/**
 * Fingerprint of everything on the editor page outside the live regions
 * (forms, buttons, settings). When it changes the page reloads itself.
 */
function res_layout_key(array $ctx, array $res): string
{
    $clauses = array_map(fn ($c) => [$c['id'], $c['type'], $c['level'], $c['text']], clauses_sorted($res['clauses']));
    return substr(md5(json_encode([$res['number'] ?? 1, $res['status'], res_can_edit($ctx, $res), $res['current'], $clauses, $res['topic'],
        $res['main_submitter'], $res['co_submitters'], $res['signatories'] ?? ''])), 0, 12);
}

/** Adds a delegation to the end of the speakers list unless it is already on it. */
function res_speaker_push(array &$res, string $label): void
{
    if ($label === '' || $label === 'Chair' || str_starts_with($label, 'Delegation')) {
        return;
    }
    foreach ($res['speakers'] as $sp) {
        if (strcasecmp($sp['label'], $label) === 0) {
            return;
        }
    }
    $res['speakers'][] = ['id' => res_id(), 'label' => $label];
}

/** The 1st-degree amendment being debated (also while a 2nd-degree one on it is on the floor). */
function res_debated_amendment(array $res): ?array
{
    $cur = amendment_by_id($res, $res['current']);
    if (!$cur) {
        return null;
    }
    return !empty($cur['parent']) ? amendment_by_id($res, $cur['parent']) : $cur;
}

/** "On the floor" panel of the editor page incl. the 2nd-degree form for everyone who may amend. */
function res_floor_panel_html(array $ctx, array $res): string
{
    $floor = res_floor_html($res);
    if ($floor === '') {
        return '<p class="muted">No amendment is being debated right now.</p>';
    }
    $base = res_debated_amendment($res);
    if (res_is_viewer($ctx) || $res['status'] !== 'debate' || !$base || $base['kind'] === 'strike') {
        return $floor;
    }
    return $floor . '<details class="amend-2nd"><summary>Amend this amendment (2nd degree)</summary>'
        . '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="a" value="amend"><input type="hidden" name="parent" value="' . e($base['id']) . '">'
        . '<label class="field"><span>Your new wording for this amendment</span><textarea name="text" rows="4" required>' . e($base['text']) . '</textarea></label>'
        . '<button class="btn btn-small">Submit to the chairs</button></form></details>';
}

function amendment_html(array $res, array $am, bool $big = false): string
{
    $nums = clause_numbers(clauses_sorted($res['clauses']));
    $parent = !empty($am['parent']) ? amendment_by_id($res, $am['parent']) : null;
    $base = $parent ?: $am;
    $html = '<div class="amend' . ($big ? ' amend-big' : '') . '">';
    $html .= '<p class="amend-by">' . ($parent ? 'Amendment to the amendment' : 'Amendment') . ' submitted by <strong>' . e(res_author_label($am)) . '</strong>'
        . ($parent ? ' · original by ' . e(res_author_label($parent)) : '') . '</p>';

    $open = in_array($am['status'], ['pending', 'floor'], true);
    $orig = null;
    if ($open) {
        foreach ($res['clauses'] as $cl) {
            if ($cl['id'] === $base['target']) {
                $orig = $cl;
            }
        }
    }
    if ($open && $base['target'] && $orig) {
        $target = $nums[$base['target']]['label'] ?? '';
        $origText = $orig['text'];
    } else {
        // Decided (or the clause is gone): show the state at submission time.
        $target = ($base['target_label'] ?? '') ?: 'a clause that no longer exists';
        $origText = (string) ($base['orig'] ?? '');
    }
    $where = match ($base['kind']) {
        'add' => 'Add a new ' . ($base['ctype'] === 'pre' ? 'preambular' : 'operative') . ' clause '
            . e($open || empty($base['position_label']) ? add_position_label($res, (string) $base['after']) : $base['position_label']),
        'strike' => 'Strike ' . e($target),
        default => 'Change ' . e($target),
    };
    $html .= '<p class="amend-what">' . $where . '</p>';
    if ($parent) {
        $parentText = $open ? $parent['text'] : (($am['parent_text'] ?? '') ?: ($parent['text_before'] ?? $parent['text']));
        $html .= '<div class="amend-cols"><div><p class="amend-h">Original amendment</p>' . amend_text_html($parentText) . '</div>'
            . '<div><p class="amend-h">Proposed new wording</p>' . amend_text_html($am['text']) . '</div></div>';
    } elseif ($base['kind'] === 'modify' && $origText !== '') {
        $html .= '<div class="amend-cols"><div><p class="amend-h">' . ($open ? 'Current text' : 'Text at the time') . '</p>' . amend_text_html($origText) . '</div>'
            . '<div><p class="amend-h">Proposed text</p>' . amend_text_html(($am['text_before'] ?? '') !== '' ? $am['text_before'] : $am['text']) . '</div></div>';
        if (!empty($am['text_before'])) {
            $html .= '<p class="amend-h">Accepted with new wording (2nd degree)</p>' . amend_text_html($am['text']);
        }
    } elseif ($base['kind'] === 'strike' && $origText !== '') {
        $html .= '<p class="amend-text struck-text">' . nl2br(e($origText), false) . '</p>';
    } else {
        $html .= amend_text_html($am['text']);
        if (!empty($am['text_before'])) {
            $html .= '<p class="amend-h">Originally proposed</p>' . amend_text_html($am['text_before']);
        }
    }
    return $html . '</div>';
}

function amend_text_html(string $text): string
{
    return '<p class="amend-text">' . nl2br(e($text), false) . '</p>';
}

function add_position_label(array $res, string $after): string
{
    $nums = clause_numbers(clauses_sorted($res['clauses']));
    return match ($after) {
        'pre_start' => 'at the beginning of the preamble',
        'pre_end' => 'at the end of the preamble',
        'op_start' => 'before the first operative clause',
        'op_end', '' => 'at the end',
        default => isset($nums[$after]) ? 'after ' . $nums[$after]['label'] : 'at a position that no longer exists',
    };
}

function status_label(string $s): string
{
    return ['draft' => 'Draft', 'debate' => 'In debate', 'closed' => 'Closed'][$s] ?? $s;
}

/** Header + clauses. $mine = own pending amendments shown inline (personal notes). */
function res_document_html(array $res, array $committee, array $mine = []): string
{
    $clauses = clauses_sorted($res['clauses']);
    $nums = clause_numbers($clauses);
    $members = [];
    foreach (committee_members($committee) as $m) {
        $members[$m['id']] = reg_label($m);
    }
    $byTarget = [];
    $adds = [];
    foreach ($mine as $am) {
        if ($am['kind'] === 'add') {
            $adds[$am['after'] ?: 'op_end'][] = $am;
        } else {
            $byTarget[$am['target']][] = $am;
        }
    }
    $note = function (array $am): string {
        $what = ['add' => 'Your amendment: add', 'modify' => 'Your amendment: change to', 'strike' => 'Your amendment: strike this clause'][$am['kind']];
        return '<div class="my-amend"><span>' . $what . '</span>' . ($am['kind'] !== 'strike' ? '<p>' . nl2br(e($am['text']), false) . '</p>' : '') . '</div>';
    };

    $forum = trim($committee['name'] . (($committee['abbr'] ?? '') !== '' ? ' (' . $committee['abbr'] . ')' : ''));
    $h = '<div class="res-doc">';
    $h .= '<img class="res-emblem" src="' . e(url('assets/img/res-emblem.jpg')) . '" alt="" width="360" height="306">';
    $h .= '<dl class="res-head">';
    $h .= '<div><dt>FORUM:</dt><dd>' . e($forum) . '</dd></div>';
    $h .= '<div><dt>TOPIC:</dt><dd>' . e($res['topic']) . '</dd></div>';
    if (res_has_submitters($committee)) {
        $h .= '<div><dt>MAIN SUBMITTER:</dt><dd>' . e($members[$res['main_submitter']] ?? '') . '</dd></div>';
        $h .= '<div><dt>CO-SUBMITTER:</dt><dd>' . e($res['co_submitters']) . '</dd></div>';
        $h .= '<div><dt>SIGNATORIES:</dt><dd>' . e($res['signatories'] ?? '') . '</dd></div>';
    }
    $h .= '</dl>';
    $h .= '<p class="res-committee">' . e(mb_strtoupper($committee['name'])) . ',</p>';
    if (!$clauses && !$adds) {
        $h .= '<p class="muted res-empty">No clauses yet.</p>';
    }
    $h .= '<div class="res-body">';
    foreach ($adds['pre_start'] ?? [] as $am) {
        $h .= $note($am);
    }
    $lastPre = null;
    foreach ($clauses as $i => $cl) {
        if ($cl['type'] !== 'pre' && $lastPre !== false) {
            foreach ($adds['pre_end'] ?? [] as $am) {
                $h .= $note($am);
            }
            $h .= '<p class="res-op-heading">Operative Clauses</p>';
            foreach ($adds['op_start'] ?? [] as $am) {
                $h .= $note($am);
            }
            $lastPre = false;
        }
        $struck = false;
        foreach ($byTarget[$cl['id']] ?? [] as $am) {
            $struck = $struck || $am['kind'] === 'strike';
        }
        $h .= clause_html($cl, $nums, $struck);
        foreach ($byTarget[$cl['id']] ?? [] as $am) {
            $h .= $note($am);
        }
        foreach ($adds[$cl['id']] ?? [] as $am) {
            $h .= $note($am);
        }
    }
    if ($lastPre !== false) {
        foreach (array_merge($adds['pre_end'] ?? [], $adds['op_start'] ?? []) as $am) {
            $h .= $note($am);
        }
    }
    foreach ($adds['op_end'] ?? [] as $am) {
        $h .= $note($am);
    }
    return $h . '</div></div>';
}

function res_speakers_html(array $res): string
{
    $h = '<div class="speakers"><p class="speakers-h">Speakers list</p>';
    if (!$res['speakers']) {
        return $h . '<p class="muted">No speakers.</p></div>';
    }
    $h .= '<ol>';
    foreach ($res['speakers'] as $i => $s) {
        $h .= '<li' . ($i === 0 ? ' class="now"' : '') . '>' . e($s['label']) . ($i === 0 ? ' <span>speaking</span>' : '') . '</li>';
    }
    return $h . '</ol></div>';
}

/** What is on the floor right now (public to the committee). */
function res_floor_html(array $res): string
{
    $cur = amendment_by_id($res, $res['current']);
    if (!$cur) {
        return '';
    }
    return amendment_html($res, $cur);
}

/* ---------- Routes ---------- */

function resolution_route(): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    $view = (string) ($_GET['view'] ?? '');
    $ctx = res_context(isset($_GET['c']) ? (string) $_GET['c'] : null);
    if (!$ctx) {
        if ($view === 'state') {
            http_response_code(403);
            exit('{}');
        }
        if (!portal_email() && !is_logged_in()) {
            redirect_to(login_url());
        }
        render('resolution-none', ['title' => 'Resolution']);
        return;
    }
    $cm = $ctx['committee'];
    $self = portal_link('resolution') . '?c=' . rawurlencode($cm['slug']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (!csrf_check()) {
                throw new RuntimeException('Your session expired. Please try again.');
            }
            res_handle_post($ctx);
        } catch (RuntimeException $ex) {
            res_flash($ex->getMessage(), 'error');
        }
        $back = (string) ($_POST['back'] ?? '');
        redirect_to($back === 'screen' ? $self . '&view=screen' : $self . (($_POST['anchor'] ?? '') ? '#' . preg_replace('/[^\w-]/', '', (string) $_POST['anchor']) : ''));
    }

    $res = res_load($cm);
    if ($view === 'state') {
        header('Content-Type: application/json');
        $since = (int) ($_GET['rev'] ?? -1);
        if ($since === (int) $res['rev']) {
            exit(json_encode(['rev' => $res['rev']]));
        }
        exit(json_encode(['rev' => $res['rev'], 'layout' => res_layout_key($ctx, $res), 'regions' => res_regions($ctx, $res, ($_GET['screen'] ?? '') !== '' ? 'screen' : 'page')]));
    }
    if ($view === 'screen') {
        if (!res_is_chair($ctx) && !res_is_viewer($ctx)) {
            redirect_to($self);
        }
        require __DIR__ . '/../templates/resolution-screen.php';
        exit;
    }
    if ($view === 'print') {
        if (isset($_GET['res'])) {
            $res = res_archive_load($cm['slug'], (string) $_GET['res']);
            if (!$res) {
                not_found();
                return;
            }
        }
        require __DIR__ . '/../templates/resolution-print.php';
        exit;
    }
    render('resolution', ['title' => 'Resolution · ' . $cm['name'], 'ctx' => $ctx, 'res' => $res, 'self' => $self, 'flash' => res_take_flash()]);
}

/** The parts of the page that update live (each is replaced as a whole). */
function res_regions(array $ctx, array $res, string $mode): array
{
    $cm = $ctx['committee'];
    $myId = $ctx['reg']['id'] ?? '';
    $mine = array_values(array_filter($res['amendments'], fn ($a) => $a['author'] === $myId && $a['status'] === 'pending' && empty($a['parent'])));
    if ($mode === 'screen') {
        $floor = res_floor_html($res);
        return [
            'screen-main' => $floor !== '' ? $floor : res_document_html($res, $cm),
            'speakers' => res_speakers_html($res),
            'status' => e(status_label($res['status'])),
        ];
    }
    $regions = [
        'doc' => res_document_html($res, $cm, res_is_chair($ctx) ? [] : $mine),
        'floor' => res_floor_panel_html($ctx, $res),
        'speakers' => res_speakers_html($res),
        'status' => e(status_label($res['status'])),
        'mine' => res_my_amendments_html($res, $myId),
    ];
    if (res_is_chair($ctx)) {
        $regions['queue'] = res_queue_html($res);
    }
    return $regions;
}

function amendment_status_label(string $s): string
{
    return ['pending' => 'submitted', 'floor' => 'on the floor', 'accepted' => 'accepted', 'rejected' => 'rejected', 'withdrawn' => 'withdrawn', 'obsolete' => 'closed'][$s] ?? $s;
}

function res_my_amendments_html(array $res, string $myId): string
{
    $mine = array_reverse(array_values(array_filter($res['amendments'], fn ($a) => $a['author'] === $myId)));
    if (!$myId || !$mine) {
        return '<p class="muted">You have not submitted any amendments yet.</p>';
    }
    $h = '<ul class="my-list">';
    foreach ($mine as $a) {
        $h .= '<li class="st-' . e($a['status']) . '"><span class="tag">' . e(amendment_status_label($a['status'])) . '</span>' . amendment_html($res, $a)
            . ($a['status'] === 'pending' ? '<form method="post" class="inline-act">' . csrf_field() . '<input type="hidden" name="a" value="amend_withdraw"><input type="hidden" name="id" value="' . e($a['id']) . '"><button class="btn-link">Withdraw</button></form>' : '')
            . '</li>';
    }
    return $h . '</ul>';
}

/** Chairs: all open amendments, 2nd-degree ones under their original. */
function res_queue_html(array $res): string
{
    $open = fn ($a) => in_array($a['status'], ['pending', 'floor'], true);
    $firsts = array_filter($res['amendments'], fn ($a) => empty($a['parent']) && $open($a));
    if (!$firsts) {
        return '<p class="muted">No open amendments.</p>';
    }
    $btn = fn (string $act, string $id, string $label, string $cls = 'btn-ghost') =>
        '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="' . $act . '"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="anchor" value="queue"><button class="' . $cls . '">' . $label . '</button></form>';
    $item = function (array $a) use ($res, $btn) {
        $onFloor = $res['current'] === $a['id'];
        return '<div class="queue-item' . ($onFloor ? ' on-floor' : '') . '">' . amendment_html($res, $a)
            . '<div class="queue-actions">'
            . ($onFloor ? '<span class="tag">on the floor</span>' : $btn('floor', $a['id'], 'Put on the floor'))
            . $btn('accept', $a['id'], 'Accept', 'btn btn-small')
            . $btn('reject', $a['id'], 'Reject')
            . '</div></div>';
    };
    $h = '';
    foreach ($firsts as $a) {
        $h .= '<div class="queue-group">' . $item($a);
        foreach ($res['amendments'] as $b) {
            if (($b['parent'] ?? null) === $a['id'] && $open($b)) {
                $h .= '<div class="queue-second">' . $item($b) . '</div>';
            }
        }
        $h .= '</div>';
    }
    return $h;
}
