<?php
declare(strict_types=1);

final class WaitlistError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

const WAITLIST_CAPACITY = 10000;
const WAITLIST_TITLES = [
    'Virtual Assistant', 'Executive Assistant', 'Project Manager', 'Data Entry Specialist',
    'Customer Support Specialist', 'Technical Support Specialist', 'Community Manager',
    'Sales & Lead Generation Specialist', 'Appointment Setter', 'Digital Marketer', 'Social Media Manager',
    'SEO Specialist', 'Graphic Designer', 'Video Editor', 'Motion Designer', 'Illustrator', 'Photographer',
    'Voice Over Artist', 'Content Writer', 'Copywriter', 'Editor & Proofreader', 'Translator',
    'Web Developer', 'Software Developer', 'Mobile App Developer', 'UI/UX Designer', 'QA Tester',
    'IT Support Specialist', 'System Administrator', 'DevOps Engineer', 'Data Analyst',
    'AI & Data Specialist', 'Automation Specialist', 'Bookkeeper', 'Accountant', 'Consultant', 'Recruiter',
    'HR Specialist', 'Engineer', 'Architect', 'CAD Drafter', 'Paralegal', 'Legal Assistant',
    'E-commerce Specialist', 'Medical Virtual Assistant', 'Healthcare Professional', 'Online Teacher',
    'Tutor',
];

function waitlist_text(mixed $value, int $maxLength, string $label): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new WaitlistError(422, 'invalid_input', "Please enter a valid $label.");
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new WaitlistError(422, 'invalid_input', "Please enter a $label of 1–$maxLength characters.");
    }
    return $value;
}

function waitlist_email(mixed $value): string
{
    if (!is_string($value)) {
        throw new WaitlistError(422, 'invalid_email', 'Please enter a valid email address.');
    }
    $email = strtolower(trim($value));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new WaitlistError(422, 'invalid_email', 'Please enter a valid email address.');
    }
    return $email;
}

function waitlist_token_hash(string $token): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new WaitlistError(401, 'invalid_session', 'Please re-enter your email to continue.');
    }
    return hash('sha256', $token);
}

function waitlist_entry(PDO $pdo, int $number): array
{
    $query = $pdo->prepare('SELECT e.*, r.referral_code AS referrer_code FROM waitlist_entries e
        LEFT JOIN waitlist_entries r ON r.member_number = e.referred_by WHERE e.member_number = ?');
    $query->execute([$number]);
    $row = $query->fetch();
    $skills = $pdo->prepare('SELECT skill FROM waitlist_skills WHERE member_number = ? ORDER BY position');
    $skills->execute([$number]);
    return [
        'email' => $row['email'], 'name' => $row['first_name'] ?? '',
        'title' => $row['professional_title'] ?? '', 'picks' => $skills->fetchAll(PDO::FETCH_COLUMN),
        'no' => (int) $row['member_number'], 'ref' => $row['referral_code'],
        'referredBy' => $row['referrer_code'] ?? '',
        'complete' => $row['profile_completed_at'] !== null,
        'verified' => $row['email_verified_at'] !== null,
    ];
}

function waitlist_join(PDO $pdo, array $input, string $token): array
{
    $email = waitlist_email($input['email'] ?? null);
    $tokenHash = waitlist_token_hash($token);
    $referral = $input['referredBy'] ?? '';
    if (!is_string($referral) || strlen($referral) > 64) {
        throw new WaitlistError(422, 'invalid_referral', 'This referral link is invalid. Please open the signup page again.');
    }
    $pdo->beginTransaction();
    try {
        // A single transactional lock serializes number allocation, including retries.
        // Failed/duplicate signups never advance this counter; AUTO_INCREMENT is not used.
        $next = $pdo->query('SELECT next_number FROM waitlist_counter WHERE id = 1 FOR UPDATE')->fetchColumn();
        if ($next === false) {
            throw new RuntimeException('The waitlist counter has not been installed.');
        }
        $existing = $pdo->prepare('SELECT member_number, edit_token_hash FROM waitlist_entries WHERE email = ?');
        $existing->execute([$email]);
        if ($row = $existing->fetch()) {
            if (!hash_equals($row['edit_token_hash'], $tokenHash)) {
                throw new WaitlistError(409, 'email_exists',
                    'This email is already on the waitlist. We can email you a code to continue here.');
            }
            $entry = waitlist_entry($pdo, (int) $row['member_number']);
            $pdo->commit();
            return ['created' => false, 'entry' => $entry];
        }
        if ((int) $next > WAITLIST_CAPACITY) {
            throw new WaitlistError(409, 'waitlist_full', 'The 10,000 waitlist spots are filled. New signups are closed.');
        }
        $referrer = $pdo->prepare('SELECT member_number FROM waitlist_entries WHERE referral_code = ?');
        $referrer->execute([$referral]);
        $referrerNumber = $referrer->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO waitlist_entries
            (member_number, email, referral_code, referred_by, edit_token_hash) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([(int) $next, $email, 'mg' . bin2hex(random_bytes(8)),
            $referrerNumber === false ? null : (int) $referrerNumber, $tokenHash]);
        $pdo->exec('UPDATE waitlist_counter SET next_number = next_number + 1 WHERE id = 1');
        $entry = waitlist_entry($pdo, (int) $next);
        $pdo->commit();
        return ['created' => true, 'entry' => $entry];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function waitlist_update(PDO $pdo, array $input, string $token, bool $complete): array
{
    $email = waitlist_email($input['email'] ?? null);
    $tokenHash = waitlist_token_hash($token);
    $name = waitlist_text($input['name'] ?? null, 100, 'first name');
    $title = '';
    $skills = [];
    if ($complete) {
        $title = waitlist_text($input['title'] ?? null, 100, 'professional title');
        if (!in_array($title, WAITLIST_TITLES, true)) {
            throw new WaitlistError(422, 'invalid_title', 'Please choose a title from the list.');
        }
        if (!isset($input['picks']) || !is_array($input['picks']) || !array_is_list($input['picks'])
            || count($input['picks']) < 1 || count($input['picks']) > 3) {
            throw new WaitlistError(422, 'invalid_skills', 'Please select 1–3 skills or niches.');
        }
        foreach ($input['picks'] as $skill) { $skills[] = waitlist_text($skill, 80, 'skill'); }
        if (count(array_unique(array_map(fn ($s) => mb_strtolower($s, 'UTF-8'), $skills))) !== count($skills)) {
            throw new WaitlistError(422, 'duplicate_skills', 'Please choose different skills.');
        }
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT member_number, edit_token_hash FROM waitlist_entries WHERE email = ? FOR UPDATE');
        $query->execute([$email]);
        $row = $query->fetch();
        if (!$row || !hash_equals($row['edit_token_hash'], $tokenHash)) {
            throw new WaitlistError(401, 'invalid_session', 'This signup session is unavailable. Re-enter your email to continue.');
        }
        $number = (int) $row['member_number'];
        if ($complete) {
            $update = $pdo->prepare('UPDATE waitlist_entries SET first_name = ?, professional_title = ?,
                profile_completed_at = COALESCE(profile_completed_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
                WHERE member_number = ?');
            $update->execute([$name, $title, $number]);
            $pdo->prepare('DELETE FROM waitlist_skills WHERE member_number = ?')->execute([$number]);
            $insert = $pdo->prepare('INSERT INTO waitlist_skills (member_number, position, skill) VALUES (?, ?, ?)');
            foreach ($skills as $i => $skill) { $insert->execute([$number, $i + 1, $skill]); }
        } else {
            $pdo->prepare('UPDATE waitlist_entries SET first_name = ?, updated_at = UTC_TIMESTAMP() WHERE member_number = ?')
                ->execute([$name, $number]);
        }
        $entry = waitlist_entry($pdo, $number);
        $pdo->commit();
        return ['created' => false, 'entry' => $entry];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function waitlist_rate_limit(PDO $pdo, string $address, string $key, string $action): void
{
    $window = intdiv(time(), 60);
    $scope = match ($action) {
        'join' => 'join', 'send_code', 'recover_send' => 'verification_send', 'verify', 'recover' => 'verification_check',
        default => 'update',
    };
    $limit = match ($scope) { 'join' => 20, 'verification_send' => 10, 'verification_check' => 30, default => 120 };
    $hash = hash_hmac('sha256', "$address|$scope|$window", $key);
    $query = $pdo->prepare('INSERT INTO waitlist_rate_limits (bucket_hash, hits, expires_at)
        VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)) ON DUPLICATE KEY UPDATE hits = hits + 1');
    $query->execute([$hash]);
    $query = $pdo->prepare('SELECT hits FROM waitlist_rate_limits WHERE bucket_hash = ?');
    $query->execute([$hash]);
    $hits = (int) $query->fetchColumn();
    $pdo->exec('DELETE FROM waitlist_rate_limits WHERE expires_at < UTC_TIMESTAMP() LIMIT 100');
    if ($hits > $limit) {
        throw new WaitlistError(429, 'rate_limited', 'Too many attempts. Please wait one minute and try again.');
    }
}
