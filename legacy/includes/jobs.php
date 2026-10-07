<?php


defined('ABSPATH') or require_once __DIR__ . '/config.php';



function jobs_get_published(array $opts = []): array
{
    global $conn;

    $type  = isset($opts['type'])  ? $conn->real_escape_string(trim($opts['type']))  : '';
    $dept  = isset($opts['dept'])  ? $conn->real_escape_string(trim($opts['dept']))  : '';
    $limit = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;
    $order = isset($opts['order']) ? $opts['order']
                                   : 'is_featured DESC, is_urgent DESC, created_at DESC';

    $where = "status = 'Published' AND (deadline IS NULL OR deadline >= CURDATE())";
    if ($type) $where .= " AND job_type = '$type'";
    if ($dept) $where .= " AND department = '$dept'";

    $limitSQL = $limit > 0 ? "LIMIT $limit" : '';

    $rows = [];
    $res  = $conn->query("SELECT * FROM jobs WHERE $where ORDER BY $order $limitSQL");
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}



function jobs_get_by_id(int $id): ?array
{
    global $conn;
    $res = $conn->query("SELECT * FROM jobs WHERE job_id = $id LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}



function jobs_get_public(int $id): ?array
{
    global $conn;
    $res = $conn->query("
        SELECT * FROM jobs
        WHERE job_id = $id
          AND status = 'Published'
          AND (deadline IS NULL OR deadline >= CURDATE())
        LIMIT 1
    ");
    return $res ? $res->fetch_assoc() : null;
}



function jobs_get_types(): array
{
    global $conn;
    $types = [];
    $res   = $conn->query("
        SELECT DISTINCT job_type
        FROM jobs
        WHERE status = 'Published'
          AND (deadline IS NULL OR deadline >= CURDATE())
        ORDER BY job_type
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) $types[] = $r['job_type'];
    }
    return $types;
}



function jobs_get_departments(): array
{
    global $conn;
    $depts = [];
    $res   = $conn->query("
        SELECT DISTINCT department
        FROM jobs
        WHERE status = 'Published'
          AND (deadline IS NULL OR deadline >= CURDATE())
          AND department IS NOT NULL AND department != ''
        ORDER BY department
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) $depts[] = $r['department'];
    }
    return $depts;
}


function jobs_get_stats(): array
{
    global $conn;

    $stats = ['total' => 0, 'published' => 0, 'draft' => 0,
              'closed' => 0, 'archived' => 0, 'total_applications' => 0];

    $res = $conn->query("
        SELECT
            COUNT(*)                                       AS total,
            SUM(status = 'Published')                      AS published,
            SUM(status = 'Draft')                          AS draft,
            SUM(status = 'Closed')                         AS closed,
            SUM(status = 'Archived')                       AS archived,
            (SELECT COUNT(*) FROM job_applications)        AS total_applications
        FROM jobs
    ");
    if ($res && $r = $res->fetch_assoc()) {
        $stats = array_map('intval', $r);
    }
    return $stats;
}



function jobs_get_all_admin(): array
{
    global $conn;
    $rows = [];
    $res  = $conn->query("
        SELECT j.*,
            (SELECT COUNT(*) FROM job_applications WHERE job_id = j.job_id) AS app_count
        FROM jobs j
        ORDER BY j.is_featured DESC, j.created_at DESC
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}



function jobs_save(array $d): array
{
    global $conn;

    $esc = fn($v) => $conn->real_escape_string(trim((string)$v));

    $fields = [
        'job_title'        => $esc($d['job_title']        ?? ''),
        'department'       => $esc($d['department']       ?? ''),
        'location'         => $esc($d['location']         ?? ''),
        'job_type'         => $esc($d['job_type']         ?? 'Full-Time'),
        'experience_level' => $esc($d['experience_level'] ?? 'Entry Level'),
        'salary_min'       => is_numeric($d['salary_min'] ?? '') ? (float)$d['salary_min'] : null,
        'salary_max'       => is_numeric($d['salary_max'] ?? '') ? (float)$d['salary_max'] : null,
        'salary_currency'  => $esc($d['salary_currency']  ?? 'UGX'),
        'salary_period'    => $esc($d['salary_period']    ?? 'Month'),
        'show_salary'      => (int)(bool)($d['show_salary'] ?? 1),
        'description'      => $esc($d['description']      ?? ''),
        'responsibilities' => $esc($d['responsibilities'] ?? ''),
        'requirements'     => $esc($d['requirements']     ?? ''),
        'benefits'         => $esc($d['benefits']         ?? ''),
        'apply_url'        => $esc($d['apply_url']        ?? ''),
        'deadline'         => !empty($d['deadline'])       ? $esc($d['deadline']) : null,
        'max_applicants'   => is_numeric($d['max_applicants'] ?? '') ? (int)$d['max_applicants'] : null,
        'status'           => $esc($d['status']            ?? 'Draft'),
        'is_featured'      => (int)(bool)($d['is_featured'] ?? 0),
        'is_urgent'        => (int)(bool)($d['is_urgent']   ?? 0),
    ];

    if (empty($fields['job_title'])) {
        return ['ok' => false, 'msg' => 'Job title is required.'];
    }

    /* -- helper to build SQL value -- */
    $sqlVal = function ($col, $val) {
        $nullable = ['salary_min', 'salary_max', 'deadline', 'max_applicants', 'apply_url',
                     'department', 'location', 'responsibilities', 'requirements', 'benefits'];
        if ($val === null || $val === '') {
            return in_array($col, $nullable) ? 'NULL' : "''";
        }
        if (in_array($col, ['salary_min', 'salary_max'])) return (float)$val;
        if (in_array($col, ['max_applicants', 'show_salary', 'is_featured', 'is_urgent'])) return (int)$val;
        if ($col === 'deadline') return "'" . $val . "'";
        return "'" . $val . "'";
    };

    $jobId = !empty($d['job_id']) ? (int)$d['job_id'] : 0;

    if ($jobId) {
        /* UPDATE */
        $set = [];
        foreach ($fields as $col => $val) {
            $set[] = "`$col` = " . $sqlVal($col, $val);
        }
        $set[] = "`published_at` = IF(`status`='Published' AND `published_at` IS NULL, NOW(), `published_at`)";
        $sql   = "UPDATE jobs SET " . implode(', ', $set) . " WHERE job_id = $jobId";
    } else {
        /* INSERT */
        $cols = array_keys($fields);
        $vals = [];
        foreach ($fields as $col => $val) {
            $vals[] = $sqlVal($col, $val);
        }
        $cols[] = 'published_at';
        $vals[] = ($fields['status'] === 'Published') ? 'NOW()' : 'NULL';
        $sql    = "INSERT INTO jobs (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
    }

    if ($conn->query($sql)) {
        return ['ok' => true, 'job_id' => $jobId ?: $conn->insert_id];
    }
    return ['ok' => false, 'msg' => 'Database error: ' . $conn->error];
}


function jobs_delete(int $id): array
{
    global $conn;
    return $conn->query("DELETE FROM jobs WHERE job_id = $id")
        ? ['ok' => true,  'msg' => 'Job deleted.']
        : ['ok' => false, 'msg' => $conn->error];
}



function jobs_toggle(int $id, string $field): array
{
    global $conn;
    $allowed = ['is_featured', 'is_urgent'];
    if (!in_array($field, $allowed)) {
        return ['ok' => false, 'msg' => 'Invalid field.'];
    }
    return $conn->query("UPDATE jobs SET `$field` = 1 - `$field` WHERE job_id = $id")
        ? ['ok' => true,  'msg' => 'Toggled.']
        : ['ok' => false, 'msg' => $conn->error];
}



function jobs_set_status(int $id, string $status): array
{
    global $conn;
    $allowed = ['Draft', 'Published', 'Closed', 'Archived'];
    if (!in_array($status, $allowed)) {
        return ['ok' => false, 'msg' => 'Invalid status.'];
    }
    $s   = $conn->real_escape_string($status);
    $pub = $status === 'Published'
        ? ", published_at = COALESCE(published_at, NOW())"
        : '';
    return $conn->query("UPDATE jobs SET status='$s'$pub WHERE job_id=$id")
        ? ['ok' => true,  'msg' => 'Status updated.']
        : ['ok' => false, 'msg' => $conn->error];
}


//return array|null  { line: string, per: string } or null if hidden/empty
 
function jobs_format_salary(array $job): ?array
{
    if (empty($job['show_salary'])) return null;
    if (empty($job['salary_min']) && empty($job['salary_max'])) return null;

    $cur = $job['salary_currency'] ?? 'UGX';
    $per = $job['salary_period']   ?? 'Month';
    $fmt = fn($v) => $cur . ' ' . number_format((float)$v, 0);

    $min = !empty($job['salary_min']) ? (float)$job['salary_min'] : null;
    $max = !empty($job['salary_max']) ? (float)$job['salary_max'] : null;

    if ($min && $max) $line = $fmt($min) . ' – ' . $fmt($max);
    elseif ($min)     $line = 'From ' . $fmt($min);
    else              $line = 'Up to ' . $fmt($max);

    return ['line' => $line, 'per' => $per];
}



function jobs_deadline_info(array $job): ?array
{
    if (empty($job['deadline'])) return null;

    $deadline  = new DateTime($job['deadline']);
    $today     = new DateTime();
    $daysLeft  = (int)$today->diff($deadline)->days;
    $isSoon    = $daysLeft <= 7;

    if ($daysLeft === 0)  $label = 'Closes today!';
    elseif ($isSoon)      $label = $daysLeft . 'd left';
    else                  $label = date('d M Y', strtotime($job['deadline']));

    return [
        'days_left' => $daysLeft,
        'label'     => $label,
        'is_soon'   => $isSoon,
        'formatted' => date('d M Y', strtotime($job['deadline'])),
    ];
}



function jobs_parse_bullets(?string $text): array
{
    if (empty($text)) return [];
    $lines = preg_split('/\r?\n/', $text);
    $out   = [];
    foreach ($lines as $line) {
        $line = trim(ltrim(trim($line), '-•·* '));
        if ($line !== '') $out[] = $line;
    }
    return $out;
}



function jobs_apply_link(array $job, string $baseUrl = ''): array
{
    if (!empty($job['apply_url'])) {
        return ['href' => $job['apply_url'], 'target' => '_blank'];
    }
    $base = rtrim($baseUrl, '/');
    return [
        'href'   => $base . '/apply-job?job_id=' . (int)$job['job_id'],
        'target' => '_self',
    ];
}


function jobs_status_class(string $status): string
{
    return match ($status) {
        'Published' => 'pill-pub',
        'Draft'     => 'pill-draft',
        'Closed'    => 'pill-closed',
        default     => 'pill-archived',
    };
}



function jobs_excerpt(string $text, int $max = 200, string $ellipsis = '...'): string
{
    $text = strip_tags($text);
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_strrpos(mb_substr($text, 0, $max), ' ');
    return mb_substr($text, 0, $cut ?: $max) . $ellipsis;
}