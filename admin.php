<?php
header('Content-Type: text/html; charset=UTF-8');

// === ПОДКЛЮЧЕНИЕ К БД (DRY — используем ту же функцию) ===
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

// === HTTP-АВТОРИЗАЦИЯ  ===
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="Админ-панель Задание 6"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1 style="text-align:center;color:#e67e22;margin-top:50px;">Доступ запрещён</h1>';
    exit;
}

$auth_login = $_SERVER['PHP_AUTH_USER'];
$auth_pass  = $_SERVER['PHP_AUTH_PW'];

// Проверка логина/хеша из отдельной таблицы admin 
$stmt = $pdo->prepare("SELECT password_hash FROM admin WHERE login = ?");
$stmt->execute([$auth_login]);
$admin_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin_row || !password_verify($auth_pass, $admin_row['password_hash'])) {
    header('WWW-Authenticate: Basic realm="Админ-панель Задание 6"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1 style="text-align:center;color:#e67e22;margin-top:50px;">Неверный логин или пароль</h1>';
    exit;
}

// === ОБРАБОТКА ДЕЙСТВИЙ АДМИНА ===
$messages = [];

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM application_language WHERE application_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM application WHERE id = ?")->execute([$id]);
    $messages[] = '<div class="success-message">Анкета №' . $id . ' успешно удалена</div>';
}

$edit_id = 0;
$edit_values = [];
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM application WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_values = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($edit_values) {
        // Загружаем выбранные языки
        $lang_stmt = $pdo->prepare("
            SELECT l.name 
            FROM application_language al 
            JOIN language l ON al.language_id = l.id 
            WHERE al.application_id = ?
        ");
        $lang_stmt->execute([$edit_id]);
        $edit_values['languages'] = [];
        while ($l = $lang_stmt->fetch(PDO::FETCH_ASSOC)) {
            $edit_values['languages'][] = $l['name'];
        }
    }
}

// Обработка сохранения редактирования
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $id = (int)$_POST['edit_id'];

    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $biography = trim($_POST['biography'] ?? '');
    $contract_accepted = isset($_POST['contract_accepted']) ? 1 : 0;
    $languages = $_POST['languages'] ?? [];

    // Простая валидация (KISS)
    if (empty($full_name) || empty($email) || empty($phone) || empty($birth_date) || empty($gender)) {
        $messages[] = '<div class="error-message">Заполните обязательные поля</div>';
    } else {
        $pdo->beginTransaction();

        // Обновление анкеты
        $stmt = $pdo->prepare("
            UPDATE application 
            SET full_name = ?, phone = ?, email = ?, birth_date = ?, 
                gender = ?, biography = ?, contract_accepted = ?
            WHERE id = ?
        ");
        $stmt->execute([$full_name, $phone, $email, $birth_date, $gender, $biography, $contract_accepted, $id]);

        // Обновление языков
        $pdo->prepare("DELETE FROM application_language WHERE application_id = ?")->execute([$id]);

        $lang_map = [];
        $stmt = $pdo->query("SELECT id, name FROM language");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lang_map[$row['name']] = $row['id'];
        }
        $stmt = $pdo->prepare("INSERT INTO application_language (application_id, language_id) VALUES (?, ?)");
        foreach ($languages as $lang_name) {
            if (isset($lang_map[$lang_name])) {
                $stmt->execute([$id, $lang_map[$lang_name]]);
            }
        }

        $pdo->commit();
        $messages[] = '<div class="success-message">Анкета №' . $id . ' успешно обновлена</div>';
        $edit_id = 0; // выходим из режима редактирования
    }
}

// === ЗАГРУЗКА ДАННЫХ ДЛЯ ТАБЛИЦЫ ===
$applications = [];
$stmt = $pdo->query("
    SELECT a.*, GROUP_CONCAT(l.name SEPARATOR ', ') AS languages_list
    FROM application a
    LEFT JOIN application_language al ON a.id = al.application_id
    LEFT JOIN language l ON al.language_id = l.id
    GROUP BY a.id
    ORDER BY a.id DESC
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $applications[] = $row;
}

// === СТАТИСТИКА  ===
$stats = [];
$stmt = $pdo->query("
    SELECT l.name, COUNT(DISTINCT al.application_id) AS count
    FROM language l
    LEFT JOIN application_language al ON l.id = al.language_id
    GROUP BY l.id, l.name
    ORDER BY count DESC, l.name
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stats[] = $row;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель — Задание 6</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>🔧 Админ-панель</h1>
        <p style="text-align:center;color:#b87333;">Авторизован как <strong><?= htmlspecialchars($auth_login) ?></strong></p>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <?= $msg ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- РЕДАКТИРОВАНИЕ -->
        <?php if ($edit_id > 0 && !empty($edit_values)): ?>
            <h2 style="margin:30px 0 15px;">Редактирование анкеты №<?= $edit_id ?></h2>
            <form method="POST" style="background:#252525;padding:25px;border-radius:15px;">
                <input type="hidden" name="edit_id" value="<?= $edit_id ?>">

                <div class="form-group">
                    <label>ФИО</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($edit_values['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Телефон</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($edit_values['phone']) ?>" required>
                </div>
                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($edit_values['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Дата рождения</label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($edit_values['birth_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Пол</label>
                    <select name="gender" required>
                        <option value="male" <?= $edit_values['gender']==='male'?'selected':'' ?>>Мужской</option>
                        <option value="female" <?= $edit_values['gender']==='female'?'selected':'' ?>>Женский</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Любимые языки</label>
                    <select name="languages[]" multiple size="6" style="width:100%;">
                        <?php
                        $all_langs = $pdo->query("SELECT name FROM language ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($all_langs as $lang): ?>
                            <option value="<?= htmlspecialchars($lang) ?>"
                                <?= in_array($lang, $edit_values['languages'] ?? []) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Биография</label>
                    <textarea name="biography" rows="5"><?= htmlspecialchars($edit_values['biography'] ?? '') ?></textarea>
                </div>
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" name="contract_accepted" value="1" <?= $edit_values['contract_accepted'] ? 'checked' : '' ?>>
                        Я ознакомлен(а) с контрактом
                    </label>
                </div>

                <button type="submit">Сохранить изменения</button>
                <a href="admin.php" style="display:block;text-align:center;margin-top:15px;color:#b87333;">Отмена</a>
            </form>
        <?php endif; ?>

        <!-- ТАБЛИЦА ВСЕХ АНКЕТ-->
        <h2>Все анкеты пользователей</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>ФИО</th>
                <th>Email</th>
                <th>Телефон</th>
                <th>Дата рожд.</th>
                <th>Пол</th>
                <th>Языки</th>
                <th>Действия</th>
            </tr>
            <?php foreach ($applications as $app): ?>
            <tr>
                <td><?= $app['id'] ?></td>
                <td><?= htmlspecialchars($app['full_name']) ?></td>
                <td><?= htmlspecialchars($app['email']) ?></td>
                <td><?= htmlspecialchars($app['phone']) ?></td>
                <td><?= htmlspecialchars($app['birth_date']) ?></td>
                <td><?= $app['gender']==='male'?'М':'Ж' ?></td>
                <td><?= htmlspecialchars($app['languages_list'] ?? '—') ?></td>
                <td>
                    <a href="admin.php?edit=<?= $app['id'] ?>" style="color:#b87333;">✏️ Ред.</a> |
                    <a href="admin.php?delete=<?= $app['id'] ?>" onclick="return confirm('Удалить анкету №<?= $app['id'] ?>?')" style="color:#e67e22;">🗑 Удалить</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($applications)): ?>
            <tr><td colspan="8" style="text-align:center;color:#888;">Пока нет ни одной анкеты</td></tr>
            <?php endif; ?>
        </table>

        <!-- СТАТИСТИКА  -->
        <h2 style="margin-top:40px;">Статистика по языкам программирования</h2>
        <table>
            <tr>
                <th>Язык</th>
                <th>Количество пользователей</th>
            </tr>
            <?php foreach ($stats as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td><strong><?= $s['count'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div style="text-align:center;margin-top:40px;">
            <a href="index.php" style="color:#b87333;font-size:1.1em;">← Вернуться к главной форме</a>
        </div>
    </div>
</body>
</html>