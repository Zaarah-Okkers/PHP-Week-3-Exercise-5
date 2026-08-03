<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
<?php
/**
 * Deletes a record by ID, triggered by the Delete button on index.php.
 */
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        header("Location: index.php?status=" . urlencode("Record deleted."));
    } else {
        header("Location: index.php?status=" . urlencode("Invalid record ID."));
    }
} else {
    header("Location: index.php");
}

$conn->close();
exit;
?>
</body>
</html>