<?php
header('Content-Type: text/html; charset=UTF-8');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// === ПОДКЛЮЧЕНИЕ К БД ===
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
              error_log("Database error: " . $e->getMessage());
            die("Внутренняя ошибка сервера. Пожалуйста, попробуйте позже.");
        }
    }
    return $pdo;
}

$pdo = getDB();

// === HTTP-АВТОРИЗАЦИЯ ===
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="Админ-панель Задание 6"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<div class="container"><h1 style="text-align:center;">Доступ запрещён</h1><p>Введите логин и пароль администратора.</p></div>';
    exit;
}

$auth_login = $_SERVER['PHP_AUTH_USER'];
$auth_pass  = $_SERVER['PHP_AUTH_PW'];

$stmt = $pdo->prepare("SELECT password_hash FROM admin WHERE login = ?");
$stmt->execute([$auth_login]);
$admin_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin_row || !password_verify($auth_pass, $admin_row['password_hash'])) {
    header('WWW-Authenticate: Basic realm="Админ-панель Задание 6"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<div class="container"><h1 style="text-align:center;">Неверный логин или пароль!</h1><p>Попробуйте ещё раз.</p></div>';
    exit;
}

// === ОБРАБОТКА ДЕЙСТВИЙ АДМИНА ===
$messages = [];
$edit_errors = [];

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

// Обработка сохранения редактирования (POST)
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

    $allowed_languages = [
        'Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python',
        'Java', 'Haskell', 'Clojure', 'Prolog', 'Scala', 'Go'
    ];
    $allowed_genders = ['male', 'female'];

    $has_error = false;


    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Ошибка CSRF. Пожалуйста, обновите страницу и повторите попытку.');
}

    // ФИО
    if (empty($full_name)) {
        $edit_errors['full_name'] = 'ФИО обязательно для заполнения.';
        $has_error = true;
    } elseif (!preg_match('/^[а-яА-Яa-zA-Z\s]+$/u', $full_name)) {
        $edit_errors['full_name'] = 'ФИО должно содержать только буквы и пробелы.';
        $has_error = true;
    } elseif (strlen($full_name) > 150) {
        $edit_errors['full_name'] = 'ФИО не должно превышать 150 символов.';
        $has_error = true;
    }

    // Телефон
    if (empty($phone)) {
        $edit_errors['phone'] = 'Телефон обязателен.';
        $has_error = true;
    } elseif (!preg_match('/^[\d\s\-\+\(\)]{6,12}$/', $phone)) {
        $edit_errors['phone'] = 'Телефон должен содержать от 6 до 12 символов, разрешены цифры, +, -, (, ), пробел.';
        $has_error = true;
    }

    // Email
    if (empty($email)) {
        $edit_errors['email'] = 'Email обязателен.';
        $has_error = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $edit_errors['email'] = 'Некорректный формат email.';
        $has_error = true;
    }

    // Дата рождения
    if (empty($birth_date)) {
        $edit_errors['birth_date'] = 'Дата рождения обязательна.';
        $has_error = true;
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $birth_date);
        if (!$date || $date->format('Y-m-d') !== $birth_date) {
            $edit_errors['birth_date'] = 'Используйте формат ГГГГ-ММ-ДД.';
            $has_error = true;
        } elseif ($date > new DateTime('today')) {
            $edit_errors['birth_date'] = 'Дата не может быть позже сегодняшнего дня.';
            $has_error = true;
        }
    }

    // Пол
    if (empty($gender)) {
        $edit_errors['gender'] = 'Выберите пол.';
        $has_error = true;
    } elseif (!in_array($gender, $allowed_genders)) {
        $edit_errors['gender'] = 'Недопустимое значение пола.';
        $has_error = true;
    }

    // Биография
    if (strlen($biography) > 10000) {
        $edit_errors['biography'] = 'Биография не должна превышать 10000 символов.';
        $has_error = true;
    }

    // Чекбокс
    if (!$contract_accepted) {
        $edit_errors['contract_accepted'] = 'Необходимо подтвердить согласие.';
        $has_error = true;
    }

    // Языки
    if (empty($languages)) {
        $edit_errors['languages'] = 'Выберите хотя бы один язык программирования.';
        $has_error = true;
    } else {
        foreach ($languages as $lang) {
            if (!in_array($lang, $allowed_languages)) {
                $edit_errors['languages'] = 'Выбран недопустимый язык.';
                $has_error = true;
                break;
            }
        }
    }

    if ($has_error) {
        // Сохраняем введённые значения для повторного отображения
        $edit_values = [
            'id' => $id,
            'full_name' => $full_name,
            'phone' => $phone,
            'email' => $email,
            'birth_date' => $birth_date,
            'gender' => $gender,
            'biography' => $biography,
            'contract_accepted' => $contract_accepted,
            'languages' => $languages
        ];
        $edit_id = $id;
        $messages[] = '<div class="error-message">Исправьте ошибки в форме.</div>';
    } else {
        // Валидация пройдена – сохраняем в БД
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE application 
                SET full_name = ?, phone = ?, email = ?, birth_date = ?, 
                    gender = ?, biography = ?, contract_accepted = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $phone, $email, $birth_date, $gender, $biography, $contract_accepted, $id]);

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
        } catch (Exception $e) {
            $pdo->rollBack();
            $messages[] = '<div class="error-message">Ошибка при сохранении: ' . $e->getMessage() . '</div>';
        }
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

// === СТАТИСТИКА ===
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

// Список всех языков для select
$all_languages = $pdo->query("SELECT name FROM language ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель — Задание 7</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .field-error { color: #e67e22; font-size: 0.85rem; margin-top: 4px; display: block; }
        .error-field { border: 2px solid #e67e22 !important; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Админ-панель</h1>
    <p style="text-align:center;">Авторизован как <strong><?= htmlspecialchars($auth_login) ?></strong></p>

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
                <input type="text" name="full_name" value="<?= htmlspecialchars($edit_values['full_name'] ?? '') ?>"
                       class="<?= isset($edit_errors['full_name']) ? 'error-field' : '' ?>">
                <?php if (isset($edit_errors['full_name'])): ?>
                    <span class="field-error"><?= $edit_errors['full_name'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Телефон</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($edit_values['phone'] ?? '') ?>"
                       class="<?= isset($edit_errors['phone']) ? 'error-field' : '' ?>">
                <?php if (isset($edit_errors['phone'])): ?>
                    <span class="field-error"><?= $edit_errors['phone'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" value="<?= htmlspecialchars($edit_values['email'] ?? '') ?>"
                       class="<?= isset($edit_errors['email']) ? 'error-field' : '' ?>">
                <?php if (isset($edit_errors['email'])): ?>
                    <span class="field-error"><?= $edit_errors['email'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Дата рождения</label>
                <input type="date" name="birth_date" value="<?= htmlspecialchars($edit_values['birth_date'] ?? '') ?>"
                       class="<?= isset($edit_errors['birth_date']) ? 'error-field' : '' ?>">
                <?php if (isset($edit_errors['birth_date'])): ?>
                    <span class="field-error"><?= $edit_errors['birth_date'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Пол</label>
                <select name="gender" class="<?= isset($edit_errors['gender']) ? 'error-field' : '' ?>">
                    <option value="male" <?= ($edit_values['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Мужской</option>
                    <option value="female" <?= ($edit_values['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Женский</option>
                </select>
                <?php if (isset($edit_errors['gender'])): ?>
                    <span class="field-error"><?= $edit_errors['gender'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Любимые языки программирования</label>
                <select name="languages[]" multiple size="6" class="<?= isset($edit_errors['languages']) ? 'error-field' : '' ?>">
                    <?php foreach ($all_languages as $lang): ?>
                        <option value="<?= htmlspecialchars($lang) ?>" <?= in_array($lang, $edit_values['languages'] ?? []) ? 'selected' : '' ?>><?= htmlspecialchars($lang) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($edit_errors['languages'])): ?>
                    <span class="field-error"><?= $edit_errors['languages'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Биография</label>
                <textarea name="biography" rows="5" class="<?= isset($edit_errors['biography']) ? 'error-field' : '' ?>"><?= htmlspecialchars($edit_values['biography'] ?? '') ?></textarea>
                <?php if (isset($edit_errors['biography'])): ?>
                    <span class="field-error"><?= $edit_errors['biography'] ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group checkbox">
                <label>
                    <input type="checkbox" name="contract_accepted" value="1" <?= !empty($edit_values['contract_accepted']) ? 'checked' : '' ?>
                           class="<?= isset($edit_errors['contract_accepted']) ? 'error-field' : '' ?>">
                    Я ознакомлен(а) с контрактом
                </label>
                <?php if (isset($edit_errors['contract_accepted'])): ?>
                    <span class="field-error"><?= $edit_errors['contract_accepted'] ?></span>
                <?php endif; ?>
            </div>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <button type="submit">Сохранить изменения</button>
            <a href="admin.php" style="display:block;text-align:center;margin-top:15px;">Отмена</a>
        </form>
    <?php endif; ?>

    <!-- ТАБЛИЦА ВСЕХ АНКЕТ -->
    <h2>Все анкеты пользователей</h2>
    <table>
        <thead>
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
        </thead>
        <tbody>
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
                <a href="admin.php?edit=<?= $app['id'] ?>">✏️ Ред.</a> |
                <a href="admin.php?delete=<?= $app['id'] ?>" onclick="return confirm('Удалить анкету №<?= $app['id'] ?>?')">🗑 Удалить</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($applications)): ?>
        <tr><td colspan="8" style="text-align:center;">Пока нет ни одной анкеты</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- СТАТИСТИКА -->
    <h2 style="margin-top:40px;">Статистика по языкам программирования</h2>
    <table>
        <thead><tr><th>Язык</th><th>Количество пользователей</th></tr></thead>
        <tbody>
        <?php foreach ($stats as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><strong><?= $s['count'] ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="text-align:center;margin-top:40px;">
        <a href="index.php">← Вернуться к главной форме</a>
    </div>
</div>
</body>
</html>