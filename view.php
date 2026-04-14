<?php
// view.php
session_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр анкет — Задание 6</title>
    <link rel="stylesheet" href="style.css">
   
</head>
<body>
    <div class="container">
        <h1>Сохранённые анкеты</h1>

        <?php
        // Дублируем getDB
        function getDB() {
            static $pdo = null;
            if ($pdo === null) {
                $db_host = 'localhost';
                $db_user = 'u82457';
                $db_pass = '7777166';
                $db_name = 'u82457';
                try {
                    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                } catch (PDOException $e) {
                    die("Ошибка подключения к БД: " . $e->getMessage());
                }
            }
            return $pdo;
        }

        $pdo = getDB();
        $stmt = $pdo->query("
            SELECT a.*, GROUP_CONCAT(l.name SEPARATOR ', ') AS languages 
            FROM application a 
            LEFT JOIN application_language al ON a.id = al.application_id 
            LEFT JOIN language l ON al.language_id = l.id 
            GROUP BY a.id 
            ORDER BY a.id DESC
        ");

        echo '<table border="1" cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse;">';
        echo '<tr><th>ID</th><th>ФИО</th><th>Email</th><th>Телефон</th><th>Дата рождения</th><th>Пол</th><th>Языки</th><th>Биография</th></tr>';

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['email']) . '</td>';
            echo '<td>' . htmlspecialchars($row['phone']) . '</td>';
            echo '<td>' . htmlspecialchars($row['birth_date']) . '</td>';
            echo '<td>' . htmlspecialchars($row['gender'] === 'male' ? 'Мужской' : 'Женский') . '</td>';
            echo '<td>' . htmlspecialchars($row['languages'] ?? '—') . '</td>';
            echo '<td style="max-width:300px;">' . nl2br(htmlspecialchars($row['biography'])) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        ?>

        <div class="back-link" style="margin-top:30px;">
            <a href="index.php">← Вернуться к форме</a>
            <?php if (isset($_SESSION['application_id'])): ?>
                <a href="index.php?logout=1">Выйти</a>
            <?php else: ?>
                <a href="login.php">Войти для редактирования</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
