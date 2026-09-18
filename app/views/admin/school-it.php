<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
	$baseUrl = defined('BASE_URL') ? BASE_URL : '';
	header('Location: ' . rtrim($baseUrl, '/') . '/auth/admin_login');
	exit;
}

$db = Database::getConnection();
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$pageUrl = $baseUrl . '/admin/school-it';

if (empty($_SESSION['admin_school_it_csrf'])) {
	$_SESSION['admin_school_it_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_school_it_csrf'];
$message = '';
$messageType = '';
$editingUser = null;
$formValues = [
	'full_name' => '', 'email' => '', 'phone_number' => '', 'gender' => '',
	'school_id' => '', 'force_password_change' => 0,
	'requires_reset' => 0,
];

function schoolItEscape(mixed $value): string
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function schoolItDate(mixed $value): string
{
	if (!$value) {
		return '-';
	}

	$timestamp = strtotime((string)$value);
	return $timestamp ? date('d M Y, H:i', $timestamp) : '-';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedToken = (string)($_POST['csrf_token'] ?? '');
	$action = (string)($_POST['action'] ?? '');

	if (!hash_equals($csrfToken, $submittedToken)) {
		$message = 'The security token expired. Refresh the page and try again.';
		$messageType = 'error';
	} else {
		try {
			if ($action === 'delete') {
				$userId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
				if (!$userId || $userId < 1) {
					throw new InvalidArgumentException('Select a valid School IT account.');
				}
				$delete = $db->prepare('DELETE FROM school_it WHERE id = ?');
				$delete->execute([$userId]);
				$message = $delete->rowCount() ? 'School IT account deleted.' : 'Account was not found.';
				$messageType = $delete->rowCount() ? 'success' : 'error';
			} elseif ($action === 'add' || $action === 'edit') {
				$userId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
				$fullName = trim((string)($_POST['full_name'] ?? ''));
				$email = trim((string)($_POST['email'] ?? ''));
				$phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
				$gender = trim((string)($_POST['gender'] ?? ''));
				$password = (string)($_POST['password'] ?? '');
				$schoolId = filter_var($_POST['school_id'] ?? '', FILTER_VALIDATE_INT);
				$forcePasswordChange = isset($_POST['force_password_change']) ? 1 : 0;
				$requiresReset = isset($_POST['requires_reset']) ? 1 : 0;

				$formValues = [
					'full_name' => $fullName, 'email' => $email, 'phone_number' => $phoneNumber,
					'gender' => $gender, 'school_id' => $schoolId ?: '',
					'force_password_change' => $forcePasswordChange, 'requires_reset' => $requiresReset,
				];

				if ($action === 'edit' && (!$userId || $userId < 1)) {
					throw new InvalidArgumentException('Select a valid School IT account to edit.');
				}
				if ($fullName === '' || mb_strlen($fullName) > 255) {
					throw new InvalidArgumentException('Enter a valid full name.');
				}
				if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					throw new InvalidArgumentException('Enter a valid email address.');
				}
				if ($phoneNumber === '' || mb_strlen($phoneNumber) > 50) {
					throw new InvalidArgumentException('Enter a valid phone number.');
				}
				if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
					throw new InvalidArgumentException('Select a valid gender.');
				}
				if (!$schoolId || $schoolId < 1) {
					throw new InvalidArgumentException('Select a school.');
				}
				if (($action === 'add' && strlen($password) < 8) || ($action === 'edit' && $password !== '' && strlen($password) < 8)) {
					throw new InvalidArgumentException('The password must contain at least 8 characters.');
				}

				$schoolCheck = $db->prepare('SELECT id FROM schools WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
				$schoolCheck->execute([$schoolId]);
				if (!$schoolCheck->fetchColumn()) {
					throw new InvalidArgumentException('The selected school was not found or is archived.');
				}

				$duplicate = $db->prepare('SELECT id FROM school_it WHERE (phone_number = ? OR (email = ? AND email IS NOT NULL AND email <> \'\')) AND id <> ? LIMIT 1');
				$duplicate->execute([$phoneNumber, $email, $action === 'edit' ? $userId : 0]);
				if ($duplicate->fetchColumn()) {
					throw new InvalidArgumentException('That email or phone number is already assigned to another account.');
				}

				if ($action === 'add') {
					$insert = $db->prepare("INSERT INTO school_it (full_name, email, phone_number, gender, password_hash, school_id, created_at, browser_session, profile_image, force_password_change, requires_reset) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
					$insert->execute([$fullName, $email, $phoneNumber, $gender, password_hash($password, PASSWORD_DEFAULT), $schoolId, '', '', $forcePasswordChange, $requiresReset]);
					$message = 'School IT account added successfully.';
				} else {
					$params = [$fullName, $email, $phoneNumber, $gender, $schoolId, $forcePasswordChange, $requiresReset];
					$passwordSql = '';
					if ($password !== '') {
						$passwordSql = ', password_hash = ?';
						$params[] = password_hash($password, PASSWORD_DEFAULT);
					}
					$params[] = $userId;
					$update = $db->prepare("UPDATE school_it SET full_name = ?, email = ?, phone_number = ?, gender = ?, school_id = ?, force_password_change = ?, requires_reset = ?{$passwordSql} WHERE id = ?");
					$update->execute($params);
					$message = 'School IT account updated successfully.';
				}
				$messageType = 'success';
				$formValues = ['full_name' => '', 'email' => '', 'phone_number' => '', 'gender' => '', 'school_id' => '', 'force_password_change' => 0, 'requires_reset' => 0];
			} else {
				throw new InvalidArgumentException('Unsupported action.');
			}
		} catch (Throwable $exception) {
			$message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The account could not be saved. Check the database structure and try again.';
			$messageType = 'error';
		}
	}
}

try {
	$schoolsStmt = $db->query('SELECT id, school_name FROM schools WHERE COALESCE(is_deleted, 0) = 0 ORDER BY school_name ASC');
	$schools = $schoolsStmt->fetchAll(PDO::FETCH_ASSOC);
	$usersStmt = $db->query('SELECT si.id, si.full_name, si.email, si.phone_number, si.gender, si.school_id, si.created_at, si.force_password_change, si.requires_reset, s.school_name FROM school_it si LEFT JOIN schools s ON s.id = si.school_id ORDER BY si.id DESC');
	$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
	$schools = [];
	$users = [];
	$message = $message ?: 'Unable to load School IT accounts.';
	$messageType = 'error';
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($editId && !$message) {
	$editStmt = $db->prepare('SELECT id, full_name, email, phone_number, gender, school_id, force_password_change, requires_reset FROM school_it WHERE id = ? LIMIT 1');
	$editStmt->execute([$editId]);
	$editingUser = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
	if ($editingUser) {
		$formValues = array_merge($formValues, $editingUser);
	}
}

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Administrator')) ?: 'System Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>School IT Management | EduLinCore</title>
	<style>
		:root { --blue:#2563eb; --ink:#172033; --muted:#64748b; --line:#e2e8f0; --bg:#f8fafc; --danger:#dc2626; --success:#15803d; }
		* { box-sizing:border-box; } body { margin:0; background:var(--bg); color:var(--ink); font-family:Arial,sans-serif; } a { color:inherit; }
		.shell { min-height:100vh; } .header { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1rem 5vw; background:var(--ink); color:#fff; } .brand { font-weight:800; text-decoration:none; } .header nav { display:flex; gap:1rem; font-size:.9rem; } .header nav a { color:#dbeafe; text-decoration:none; }
		.main { width:min(1380px,90vw); margin:2rem auto 4rem; } .heading { display:flex; justify-content:space-between; align-items:end; gap:1rem; margin-bottom:1.5rem; } h1 { margin:0 0 .35rem; font-size:clamp(1.65rem,3vw,2.35rem); } .heading p { margin:0; color:var(--muted); }
		.button { display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:.4rem; padding:.7rem 1rem; background:var(--blue); color:#fff; font-weight:700; cursor:pointer; text-decoration:none; } .secondary { background:#e2e8f0; color:var(--ink); } .danger { background:#fee2e2; color:var(--danger); }
		.layout { display:grid; grid-template-columns:minmax(280px,360px) 1fr; gap:1.25rem; align-items:start; } .panel { background:#fff; border:1px solid var(--line); border-radius:.55rem; box-shadow:0 8px 25px rgba(15,23,42,.05); } .panel-header { padding:1.1rem 1.2rem; border-bottom:1px solid var(--line); } .panel-header h2 { margin:0; font-size:1.05rem; } .form { padding:1.2rem; } .field { margin-bottom:.9rem; } .field label { display:block; margin-bottom:.35rem; font-size:.82rem; font-weight:700; color:#334155; } .field input,.field select { width:100%; border:1px solid #cbd5e1; border-radius:.35rem; padding:.65rem .7rem; font:inherit; background:#fff; } .field small { display:block; color:var(--muted); margin-top:.3rem; font-size:.75rem; }
		.checks { display:grid; gap:.55rem; margin:.8rem 0 1rem; } .checks label { display:flex; align-items:center; gap:.5rem; font-size:.85rem; color:#475569; } .actions,.row-actions { display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; } .alert { margin-bottom:1rem; padding:.8rem 1rem; border-radius:.4rem; font-weight:600; } .success { background:#dcfce7; color:var(--success); } .error { background:#fee2e2; color:var(--danger); }
		.table-wrap { overflow-x:auto; } table { width:100%; border-collapse:collapse; min-width:760px; } th,td { padding:.85rem 1rem; text-align:left; border-bottom:1px solid var(--line); vertical-align:middle; } th { color:var(--muted); font-size:.75rem; text-transform:uppercase; background:var(--bg); } td { font-size:.88rem; } .name { font-weight:700; } .muted { color:var(--muted); font-size:.78rem; margin-top:.2rem; } .badge { display:inline-block; padding:.25rem .45rem; border-radius:999px; font-size:.72rem; font-weight:700; background:#f1f5f9; color:#475569; } .warning { background:#fef3c7; color:#92400e; } .empty { padding:2rem 1rem; text-align:center; color:var(--muted); }
		@media(max-width:850px) { .layout { grid-template-columns:1fr; } .heading,.header { align-items:start; flex-direction:column; } }
	</style>
</head>
<body><div class="shell">
	<header class="header"><a class="brand" href="<?= schoolItEscape($baseUrl) ?>/admin/dashboard">EduLinCore Administration</a><nav><span><?= schoolItEscape($adminName) ?></span><a href="<?= schoolItEscape($baseUrl) ?>/admin/dashboard">Dashboard</a><a href="<?= schoolItEscape($baseUrl) ?>/auth/logout">Logout</a></nav></header>
	<main class="main">
		<div class="heading"><div><h1>School IT accounts</h1><p>Add, edit, reset, and remove technical support users across your schools.</p></div><?php if ($editingUser): ?><a class="button secondary" href="<?= schoolItEscape($pageUrl) ?>">Add new account</a><?php endif; ?></div>
		<?php if ($message !== ''): ?><div class="alert <?= schoolItEscape($messageType) ?>"><?= schoolItEscape($message) ?></div><?php endif; ?>
		<div class="layout">
			<section class="panel"><div class="panel-header"><h2><?= $editingUser ? 'Edit account' : 'Add account' ?></h2></div><form class="form" method="post" action="<?= schoolItEscape($pageUrl) ?><?= $editingUser ? '?edit=' . (int)$editingUser['id'] : '' ?>">
				<input type="hidden" name="csrf_token" value="<?= schoolItEscape($csrfToken) ?>"><input type="hidden" name="action" value="<?= $editingUser ? 'edit' : 'add' ?>"><?php if ($editingUser): ?><input type="hidden" name="id" value="<?= (int)$editingUser['id'] ?>"><?php endif; ?>
				<div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= schoolItEscape($formValues['full_name']) ?>" maxlength="255" required></div>
				<div class="field"><label for="email">Email <span class="muted">(optional)</span></label><input id="email" name="email" type="email" value="<?= schoolItEscape($formValues['email']) ?>"></div>
				<div class="field"><label for="phone_number">Phone number</label><input id="phone_number" name="phone_number" value="<?= schoolItEscape($formValues['phone_number']) ?>" maxlength="50" required></div>
				<div class="field"><label for="gender">Gender</label><select id="gender" name="gender" required><option value="">Select gender</option><?php foreach (['Male','Female','Other'] as $gender): ?><option value="<?= $gender ?>" <?= $formValues['gender'] === $gender ? 'selected' : '' ?>><?= $gender ?></option><?php endforeach; ?></select></div>
				<div class="field"><label for="school_id">School</label><select id="school_id" name="school_id" required><option value="">Select school</option><?php foreach ($schools as $school): ?><option value="<?= (int)$school['id'] ?>" <?= (string)$formValues['school_id'] === (string)$school['id'] ? 'selected' : '' ?>><?= schoolItEscape($school['school_name']) ?></option><?php endforeach; ?></select></div>
				<div class="field"><label for="password">Password <?= $editingUser ? '(leave blank to keep current)' : '' ?></label><input id="password" name="password" type="password" minlength="8" <?= $editingUser ? '' : 'required' ?> autocomplete="new-password"><small>At least 8 characters. Passwords are stored securely as hashes.</small></div>
				<div class="checks"><label><input type="checkbox" name="force_password_change" value="1" <?= (int)$formValues['force_password_change'] === 1 ? 'checked' : '' ?>> Force password change at next login</label><label><input type="checkbox" name="requires_reset" value="1" <?= (int)$formValues['requires_reset'] === 1 ? 'checked' : '' ?>> Mark account as requiring reset</label></div>
				<div class="actions"><button class="button" type="submit"><?= $editingUser ? 'Save changes' : 'Add account' ?></button><?php if ($editingUser): ?><a class="button secondary" href="<?= schoolItEscape($pageUrl) ?>">Cancel</a><?php endif; ?></div>
			</form></section>
			<section class="panel"><div class="panel-header"><h2>All School IT accounts (<?= number_format(count($users)) ?>)</h2></div><?php if (!$users): ?><div class="empty">No School IT accounts have been added yet.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Account</th><th>School</th><th>Contact</th><th>Security</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><div class="name"><?= schoolItEscape($user['full_name']) ?></div><div class="muted"><?= schoolItEscape($user['gender']) ?></div></td><td><?= schoolItEscape($user['school_name'] ?: 'Unassigned') ?></td><td><div><?= schoolItEscape($user['email']) ?></div><div class="muted"><?= schoolItEscape($user['phone_number']) ?></div></td><td><?php if ((int)$user['force_password_change'] === 1 || (int)$user['requires_reset'] === 1): ?><span class="badge warning">Reset required</span><?php else: ?><span class="badge">Normal</span><?php endif; ?></td><td><?= schoolItEscape(schoolItDate($user['created_at'])) ?></td><td><div class="row-actions"><a class="button secondary" href="<?= schoolItEscape($pageUrl) ?>?edit=<?= (int)$user['id'] ?>">Edit</a><form method="post" action="<?= schoolItEscape($pageUrl) ?>" onsubmit="return confirm('Delete this School IT account permanently?');"><input type="hidden" name="csrf_token" value="<?= schoolItEscape($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><button class="button danger" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
		</div>
	</main>
</div></body></html>
