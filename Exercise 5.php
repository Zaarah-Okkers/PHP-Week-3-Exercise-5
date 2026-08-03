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
 *                 table creation, and CRUD — since the users
 *                 table is created automatically below and every
 *                 create/update/delete goes through prepared
 *                 statements against it)
 *
 * Setup: update the four DB_* constants, create the database
 * itself (e.g. `CREATE DATABASE exercise5_db;`), then run:
 *   php -S localhost:8000
 * and visit http://localhost:8000/exercise5.php
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
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('Database connection failed: ' . $conn->connect_error);
        }
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
// Bootstrap: connect + make sure the table/sample row exist.
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
        body { font-family: Arial, sans-serif; margin: 2rem; max-width: 700px; }
        nav a { margin-right: 1rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1.5rem; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        th { background: #f2f2f2; }
        .feedback { color: #0a6b1c; }
        .actions a { margin-right: 0.75rem; }
        section { margin-top: 2rem; }
    </style>
</head>
<body>
    <nav>
        <a href="?page=home">Home</a>
        <a href="?page=task1">Task 1: $_REQUEST Form</a>
        <a href="?page=task2">Task 2: $_SERVER Details</a>
        <a href="?page=users">Tasks 3-5: Users (DB + CRUD)</a>
    </nav>
    <hr>

    <?php if ($page === 'home'): ?>

        <h1>Exercise 5 &mdash; Super Globals and Integrating PHP with SQL</h1>
        <p>Use the navigation above to try each task. Tasks 3, 4, and 5 are
           combined under "Users" since they all operate on the same
           database table.</p>

    <?php elseif ($page === 'task1'): ?>

        <h1>Task 1: Handling Form Data with $_REQUEST</h1>
        <form action="?page=task1" method="POST">
            <input type="hidden" name="submitted" value="1">
            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>

            <label for="email">Email:</label><br>
            <input type="email" id="email" name="email" required><br><br>

            <label for="message">Message:</label><br>
            <textarea id="message" name="message" rows="3" cols="40" required></textarea><br><br>

            <button type="submit">Submit</button>
        </form>

        <?php if ($task1Output !== ''): ?>
            <p><strong>Result:</strong> <?= $task1Output /* already escaped above */ ?></p>
        <?php endif; ?>

    <?php elseif ($page === 'task2'): ?>

        <h1>Task 2: Server Details with $_SERVER</h1>
        <ul>
            <li><strong>Host Name:</strong> <?= htmlspecialchars($hostName) ?></li>
            <li><strong>PHP Version:</strong> <?= htmlspecialchars($phpVersion) ?></li>
            <li><strong>Request Method:</strong> <?= htmlspecialchars($requestMethod) ?></li>
        </ul>

    <?php elseif ($page === 'users'): ?>

        <h1>Tasks 3-5: Database Connection, CRUD &amp; Final Project</h1>

        <?php if ($feedback): ?>
            <p class="feedback"><?= htmlspecialchars($feedback) ?></p>
        <?php endif; ?>

        <h2>Add a New Record</h2>
        <form action="?page=users" method="POST">
            <input type="hidden" name="action" value="create_user">

            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>

            <label for="email">Email:</label><br>
            <input type="email" id="email" name="email" required><br><br>

            <label for="message">Message:</label><br>
            <textarea id="message" name="message" rows="3" cols="40"></textarea><br><br>

            <button type="submit">Save</button>
        </form>

        <h2>All Records</h2>
        <?php if (empty($users)): ?>
            <p>No records yet.</p>
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
                                <a href="?page=edit&id=<?= (int) $user['id'] ?>">Edit</a>
                                <a href="?page=delete&id=<?= (int) $user['id'] ?>"
                                   onclick="return confirm('Delete this record?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($page === 'edit'): ?>

        <h1>Edit Record</h1>
        <?php if (!$editingUser): ?>
            <p>Record not found. <a href="?page=users">Back to list</a></p>
        <?php else: ?>
            <form action="?page=users" method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>">

                <label for="name">Name:</label><br>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($editingUser['name']) ?>" required><br><br>

                <label for="email">Email:</label><br>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($editingUser['email']) ?>" required><br><br>

                <label for="message">Message:</label><br>
                <textarea id="message" name="message" rows="3" cols="40"><?= htmlspecialchars($editingUser['message'] ?? '') ?></textarea><br><br>

                <button type="submit">Update</button>
                <a href="?page=users">Cancel</a>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</body>
</html>