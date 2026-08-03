<?php
/**
 * exercise5.php
 * ---------------------------------------------------------------
 * Super Globals and Integrating PHP with SQL — all five tasks in
 * a single file, navigated via ?page=... links/forms.
 *
 * Pages:
 *   ?page=home   Menu (default)
 *   ?page=task1  $_REQUEST form demo
 *   ?page=task2  $_SERVER details
 *   ?page=users  Final project: create/list/edit/delete users
 *                (this also covers Tasks 3 & 4 — DB connection,
 *                 table creation, and CRUD — since both the
 *                 database and the users table are created
 *                 automatically below, and every create/update/
 *                 delete goes through prepared statements)
 *
 * Setup: update the four DB_* constants below if needed (defaults
 * match a stock XAMPP install), then just open this file in the
 * browser. The database, table, and sample record are created
 * automatically on first load.
 * ---------------------------------------------------------------
 */

// ---------------------------------------------------------------
// Task 3 (part 1): Database connection settings
// ---------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'exercise5_db');

function get_db_connection(): mysqli
{
    static $conn = null;

    if ($conn === null) {
        // Connect WITHOUT selecting a database first, so we can create
        // it if it doesn't exist yet (avoids "Unknown database" errors
        // on a fresh XAMPP/MySQL install).
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($conn->connect_error) {
            die('Database connection failed: ' . $conn->connect_error);
        }

        $conn->query('CREATE DATABASE IF NOT EXISTS ' . DB_NAME . ' CHARACTER SET utf8mb4');
        $conn->select_db(DB_NAME);
        $conn->set_charset('utf8mb4');
    }

    return $conn;
}

// ---------------------------------------------------------------
// Task 3 (part 2): Create the `users` table if it doesn't exist yet,
// and insert one sample record the very first time.
// ---------------------------------------------------------------
function ensure_users_table_exists(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Insert the sample record only if the table is empty.
    $count = $conn->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];
    if ((int) $count === 0) {
        $stmt = $conn->prepare('INSERT INTO users (name, email, message) VALUES (?, ?, ?)');
        $sampleName = 'John Doe';
        $sampleEmail = 'john@example.com';
        $sampleMessage = 'Hello there!';
        $stmt->bind_param('sss', $sampleName, $sampleEmail, $sampleMessage);
        $stmt->execute();
        $stmt->close();
    }
}

// ---------------------------------------------------------------
// Task 4: CRUD functions used by the "users" page below.
// ---------------------------------------------------------------
function create_user(mysqli $conn, string $name, string $email, string $message = ''): int
{
    $stmt = $conn->prepare('INSERT INTO users (name, email, message) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $name, $email, $message);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function get_user(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT id, name, email, message, created_at FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function get_all_users(mysqli $conn): array
{
    $result = $conn->query('SELECT id, name, email, message, created_at FROM users ORDER BY id DESC');
    return $result->fetch_all(MYSQLI_ASSOC);
}

/** Update a user's core fields (used by the edit form; also doubles as the
 *  "update a user's email" example from Task 4 when only email changes). */
function update_user(mysqli $conn, int $id, string $name, string $email, string $message): bool
{
    $stmt = $conn->prepare('UPDATE users SET name = ?, email = ?, message = ? WHERE id = ?');
    $stmt->bind_param('sssi', $name, $email, $message, $id);
    $stmt->execute();
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    return $success;
}

/** Delete a user by id (Task 4 example). */
function delete_user(mysqli $conn, int $id): bool
{
    $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    return $success;
}

// ---------------------------------------------------------------
// Bootstrap: connect + make sure the database/table/sample row exist.
// ---------------------------------------------------------------
$conn = get_db_connection();
ensure_users_table_exists($conn);

// ---------------------------------------------------------------
// Routing: handle POST actions (create/update) and GET actions
// (delete) before rendering any HTML, then redirect back to the
// users page (Post/Redirect/Get pattern avoids duplicate submits).
// ---------------------------------------------------------------
$page = $_GET['page'] ?? 'home';
$feedback = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name !== '' && $email !== '') {
        try {
            create_user($conn, $name, $email, $message);
        } catch (mysqli_sql_exception $e) {
            $feedback = 'Could not save record (maybe duplicate email): ' . $e->getMessage();
        }
    }
    if ($feedback === '') {
        header('Location: ?page=users');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($id > 0 && $name !== '' && $email !== '') {
        update_user($conn, $id, $name, $email, $message);
        header('Location: ?page=users');
        exit;
    }
}

if ($page === 'delete' && isset($_GET['id'])) {
    delete_user($conn, (int) $_GET['id']);
    header('Location: ?page=users');
    exit;
}

// ---------------------------------------------------------------
// Task 1 data (only relevant when $page === 'task1' and the form
// was submitted): read via $_REQUEST as the exercise specifies.
// ---------------------------------------------------------------
$task1Output = '';
if ($page === 'task1' && ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') && isset($_REQUEST['submitted'])) {
    $name = htmlspecialchars(trim($_REQUEST['name'] ?? ''));
    $email = htmlspecialchars(trim($_REQUEST['email'] ?? ''));
    $message = htmlspecialchars(trim($_REQUEST['message'] ?? ''));

    $task1Output = ($name === '' || $email === '' || $message === '')
        ? 'Please fill in all fields (Name, Email, Message).'
        : "Name: {$name}, Email: {$email}, Message: {$message}";
}

// ---------------------------------------------------------------
// Task 2 data
// ---------------------------------------------------------------
$hostName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'Unknown host';
$phpVersion = phpversion();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'Unknown method';

// ---------------------------------------------------------------
// Data needed for the "users" page and edit form
// ---------------------------------------------------------------
$users = ($page === 'users') ? get_all_users($conn) : [];
$editingUser = ($page === 'edit' && isset($_GET['id'])) ? get_user($conn, (int) $_GET['id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exercise 5 - Super Globals and PHP/SQL</title>
    <style>
        :root {
            --bg: #f5f6fa;
            --surface: #ffffff;
            --border: #e2e5eb;
            --text: #1f2430;
            --text-muted: #6b7280;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --success-bg: #ecfdf3;
            --success-text: #15803d;
            --radius: 10px;
            --shadow: 0 1px 3px rgba(16, 24, 40, 0.08), 0 1px 2px rgba(16, 24, 40, 0.04);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 2.5rem 1.5rem;
            line-height: 1.5;
        }

        .container {
            max-width: 760px;
            margin: 0 auto;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        nav a {
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.5rem 0.9rem;
            border-radius: 8px;
            transition: background 0.15s ease, color 0.15s ease;
        }

        nav a:hover {
            background: var(--bg);
            color: var(--text);
        }

        nav a.active {
            background: var(--primary);
            color: #fff;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
        }

        h1 {
            font-size: 1.4rem;
            margin-top: 0;
            margin-bottom: 0.5rem;
        }

        h2 {
            font-size: 1.05rem;
            margin-top: 0;
            margin-bottom: 1rem;
        }

        p.subtitle {
            color: var(--text-muted);
            margin-top: 0;
        }

        .form-group {
            margin-bottom: 1.1rem;
        }

        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
            color: var(--text-muted);
        }

        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            font-size: 0.95rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            background: var(--surface);
        }

        textarea { resize: vertical; }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.1rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            color: #fff;
            background: var(--primary);
            transition: background 0.15s ease;
        }

        .btn:hover { background: var(--primary-hover); }

        .btn-link {
            background: none;
            color: var(--text-muted);
            padding: 0.6rem 0.4rem;
        }

        .btn-link:hover {
            background: none;
            color: var(--text);
            text-decoration: underline;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 0.9rem;
        }

        th, td {
            border-bottom: 1px solid var(--border);
            padding: 0.65rem 0.75rem;
            text-align: left;
        }

        th {
            color: var(--text-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-weight: 600;
        }

        tbody tr:hover { background: var(--bg); }

        .actions a {
            margin-right: 0.75rem;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
        }

        .actions a.edit { color: var(--primary); }
        .actions a.delete { color: var(--danger); }
        .actions a:hover { text-decoration: underline; }

        .feedback {
            background: var(--success-bg);
            color: var(--success-text);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
        }

        ul.server-details {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        ul.server-details li {
            display: flex;
            justify-content: space-between;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }

        ul.server-details li:last-child { border-bottom: none; }
        ul.server-details strong { color: var(--text-muted); font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <nav>
            <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">Home</a>
            <a href="?page=task1" class="<?= $page === 'task1' ? 'active' : '' ?>">Task 1: $_REQUEST Form</a>
            <a href="?page=task2" class="<?= $page === 'task2' ? 'active' : '' ?>">Task 2: $_SERVER Details</a>
            <a href="?page=users" class="<?= in_array($page, ['users', 'edit']) ? 'active' : '' ?>">Tasks 3-5: Users (DB + CRUD)</a>
        </nav>

    <?php if ($page === 'home'): ?>

        <div class="card">
            <h1>Exercise 5 &mdash; Super Globals and Integrating PHP with SQL</h1>
            <p class="subtitle">Use the navigation above to try each task. Tasks 3, 4, and 5 are
               combined under "Users" since they all operate on the same
               database table.</p>
        </div>

    <?php elseif ($page === 'task1'): ?>

        <div class="card">
            <h1>Task 1: Handling Form Data with $_REQUEST</h1>
            <form action="?page=task1" method="POST">
                <input type="hidden" name="submitted" value="1">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn">Submit</button>
            </form>
        </div>

        <?php if ($task1Output !== ''): ?>
            <div class="card">
                <strong>Result:</strong> <?= $task1Output /* already escaped above */ ?>
            </div>
        <?php endif; ?>

    <?php elseif ($page === 'task2'): ?>

        <div class="card">
            <h1>Task 2: Server Details with $_SERVER</h1>
            <ul class="server-details">
                <li><strong>Host Name</strong> <span><?= htmlspecialchars($hostName) ?></span></li>
                <li><strong>PHP Version</strong> <span><?= htmlspecialchars($phpVersion) ?></span></li>
                <li><strong>Request Method</strong> <span><?= htmlspecialchars($requestMethod) ?></span></li>
            </ul>
        </div>

    <?php elseif ($page === 'users'): ?>

        <h1>Tasks 3-5: Database Connection, CRUD &amp; Final Project</h1>

        <?php if ($feedback): ?>
            <p class="feedback"><?= htmlspecialchars($feedback) ?></p>
        <?php endif; ?>

        <div class="card">
            <h2>Add a New Record</h2>
            <form action="?page=users" method="POST">
                <input type="hidden" name="action" value="create_user">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">Save</button>
            </form>
        </div>

        <div class="card">
            <h2>All Records</h2>
            <?php if (empty($users)): ?>
                <p class="subtitle">No records yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th><th>Email</th><th>Message</th><th>Created At</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= (int) $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['name']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['message']) ?></td>
                                <td><?= htmlspecialchars($user['created_at']) ?></td>
                                <td class="actions">
                                    <a class="edit" href="?page=edit&id=<?= (int) $user['id'] ?>">Edit</a>
                                    <a class="delete" href="?page=delete&id=<?= (int) $user['id'] ?>"
                                       onclick="return confirm('Delete this record?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php elseif ($page === 'edit'): ?>

        <div class="card">
            <h1>Edit Record</h1>
            <?php if (!$editingUser): ?>
                <p class="subtitle">Record not found. <a href="?page=users">Back to list</a></p>
            <?php else: ?>
                <form action="?page=users" method="POST">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>">
                    <div class="form-group">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($editingUser['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($editingUser['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" rows="3"><?= htmlspecialchars($editingUser['message'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn">Update</button>
                    <a href="?page=users" class="btn btn-link">Cancel</a>
                </form>
            <?php endif; ?>
        </div>

    <?php endif; ?>
    </div>
</body>
</html>