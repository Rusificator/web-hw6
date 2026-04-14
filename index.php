<?php

header('Content-Type: text/html; charset=UTF-8');
session_start();

// === АВТОРИЗАЦИЯ ===
$is_logged_in = isset($_SESSION['application_id']);
$user_id = $is_logged_in ? $_SESSION['application_id'] : null;

// Обработка выхода
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Функция для подключения к БД (оставлена без изменений)
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

// Генерация уникального логина и пароля (только для первой отправки)
function generate_unique_login($pdo) {
    do {
        $login = 'user_' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $stmt = $pdo->prepare("SELECT id FROM application WHERE login = ?");
        $stmt->execute([$login]);
    } while ($stmt->fetch());
    return $login;
}

function generate_password($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
}

// Массив допустимых языков и пола 
$allowed_languages = [
    'Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python',
    'Java', 'Haskell', 'Clojure', 'Prolog', 'Scala', 'Go'
];
$allowed_genders = ['male', 'female'];

// ====================== GET (отображение формы) ======================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $messages = [];
    $errors = [];
    $values = [];

    $fields = ['full_name', 'phone', 'email', 'birth_date', 'gender', 'biography', 'contract_accepted', 'languages'];

    // Ошибки из cookies
    foreach ($fields as $field) {
        $errors[$field] = !empty($_COOKIE[$field . '_error']);
    }

    // Сообщения об ошибках
    if ($errors['full_name']) $messages[] = '<div class="error-message">ФИО должно содержать только буквы и пробелы (макс. 150 символов).</div>';
    if ($errors['phone']) $messages[] = '<div class="error-message">Телефон должен содержать от 6 до 12 цифр, допускаются символы +, -, (, ), пробел.</div>';
    if ($errors['email']) $messages[] = '<div class="error-message">Введите корректный email.</div>';
    if ($errors['birth_date']) $messages[] = '<div class="error-message">Дата рождения должна быть в формате ГГГГ-ММ-ДД и не позже сегодняшнего дня.</div>';
    if ($errors['gender']) $messages[] = '<div class="error-message">Выберите пол.</div>';
    if ($errors['biography']) $messages[] = '<div class="error-message">Биография не должна превышать 10000 символов.</div>';
    if ($errors['contract_accepted']) $messages[] = '<div class="error-message">Необходимо подтвердить согласие.</div>';
    if ($errors['languages']) $messages[] = '<div class="error-message">Выберите хотя бы один язык программирования из списка.</div>';

    // Значения из cookies (по умолчанию)
    foreach ($fields as $field) {
        $values[$field] = empty($_COOKIE[$field . '_value']) ? '' : $_COOKIE[$field . '_value'];
    }
    if (!empty($_COOKIE['languages_value'])) {
        $values['languages'] = explode(',', $_COOKIE['languages_value']);
    } else {
        $values['languages'] = [];
    }
    $values['contract_accepted'] = !empty($_COOKIE['contract_accepted_value']) ? true : false;

    // === ЗАГРУЗКА ДАННЫХ АВТОРИЗОВАННОГО ПОЛЬЗОВАТЕЛЯ ===
    if ($is_logged_in) {
        $has_validation_errors = false;
        foreach ($fields as $field) {
            if (!empty($_COOKIE[$field . '_error'])) $has_validation_errors = true;
        }
        if (!$has_validation_errors) {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM application WHERE id = ?");
            $stmt->execute([$user_id]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values['full_name'] = $row['full_name'];
                $values['phone'] = $row['phone'];
                $values['email'] = $row['email'];
                $values['birth_date'] = $row['birth_date'];
                $values['gender'] = $row['gender'];
                $values['biography'] = $row['biography'];
                $values['contract_accepted'] = $row['contract_accepted'] == 1;

                // Языки
                $values['languages'] = [];
                $lang_stmt = $pdo->prepare("
                    SELECT l.name 
                    FROM application_language al 
                    JOIN language l ON al.language_id = l.id 
                    WHERE al.application_id = ?
                ");
                $lang_stmt->execute([$user_id]);
                while ($l = $lang_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $values['languages'][] = $l['name'];
                }
            }
        }
    }

    // Успешное сохранение 
    if (!empty($_COOKIE['save'])) {
        setcookie('save', '', 1);
        $messages[] = '<div class="success-message">Данные успешно сохранены!</div>';
    }

    // Обновление данных авторизованным пользователем
    if (!empty($_COOKIE['updated'])) {
        setcookie('updated', '', 1);
        $messages[] = '<div class="success-message">Данные успешно обновлены!</div>';
    }

    // Показ логина и пароля (только один раз при первой отправке)
    if (!empty($_COOKIE['generated_login'])) {
        $generated_login = $_COOKIE['generated_login'];
        $generated_password = $_COOKIE['generated_password'];
        setcookie('generated_login', '', 1);
        setcookie('generated_password', '', 1);
        $messages[] = '<div class="success-message credentials">
            <strong>Форма успешно отправлена!</strong><br>
            Ваш логин: <strong>' . htmlspecialchars($generated_login) . '</strong><br>
            Ваш пароль: <strong>' . htmlspecialchars($generated_password) . '</strong><br>
            <small>Сохраните их! Они больше никогда не будут показаны.</small>
        </div>';
    }

    // Языки из БД
    $pdo = getDB();
    $languages_from_db = [];
    $stmt = $pdo->query("SELECT name FROM language ORDER BY name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $languages_from_db[] = $row['name'];
    }
    if (empty($languages_from_db)) {
        $languages_from_db = $allowed_languages;
    }

    // Подключаем форму
    include 'form.php';
    exit();
}

// ====================== POST (обработка формы) ======================
else {
    $errors = false;

    // Данные из POST
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $biography = trim($_POST['biography'] ?? '');
    $contract_accepted = isset($_POST['contract_accepted']) ? 1 : 0;
    $languages = $_POST['languages'] ?? [];

    // === ВАЛИДАЦИЯ (одинаковая для первой отправки и редактирования) ===
    // ФИО
    if (empty($full_name) || !preg_match('/^[а-яА-Яa-zA-Z\s]+$/u', $full_name) || strlen($full_name) > 150) {
        setcookie('full_name_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('full_name_value', $full_name, time() + 30*24*3600);

    // Телефон
    if (empty($phone) || !preg_match('/^[\d\s\-\+\(\)]{6,12}$/', $phone)) {
        setcookie('phone_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('phone_value', $phone, time() + 30*24*3600);

    // Email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setcookie('email_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('email_value', $email, time() + 30*24*3600);

    // Дата рождения
    if (empty($birth_date)) {
        setcookie('birth_date_error', '1', time() + 24*3600);
        $errors = true;
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $birth_date);
        if (!$date || $date->format('Y-m-d') !== $birth_date || $date > new DateTime('today')) {
            setcookie('birth_date_error', '1', time() + 24*3600);
            $errors = true;
        }
    }
    setcookie('birth_date_value', $birth_date, time() + 30*24*3600);

    // Пол
    if (empty($gender) || !in_array($gender, $allowed_genders)) {
        setcookie('gender_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('gender_value', $gender, time() + 30*24*3600);

    // Биография
    if (strlen($biography) > 10000) {
        setcookie('biography_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('biography_value', $biography, time() + 30*24*3600);

    // Чекбокс
    if (!$contract_accepted) {
        setcookie('contract_accepted_error', '1', time() + 24*3600);
        $errors = true;
    }
    setcookie('contract_accepted_value', $contract_accepted ? '1' : '0', time() + 30*24*3600);

    // Языки
    if (empty($languages)) {
        setcookie('languages_error', '1', time() + 24*3600);
        $errors = true;
    } else {
        foreach ($languages as $lang) {
            if (!in_array($lang, $allowed_languages)) {
                setcookie('languages_error', '1', time() + 24*3600);
                $errors = true;
                break;
            }
        }
    }
    setcookie('languages_value', implode(',', $languages), time() + 30*24*3600);

    if ($errors) {
        header('Location: index.php');
        exit();
    }

    // === СОХРАНЕНИЕ В БД ===
    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        if ($is_logged_in) {
            // Редактирование авторизованным пользователем
            $stmt = $pdo->prepare("
                UPDATE application 
                SET full_name = :full_name, phone = :phone, email = :email,
                    birth_date = :birth_date, gender = :gender, 
                    biography = :biography, contract_accepted = :contract_accepted
                WHERE id = :id
            ");
            $stmt->execute([
                ':full_name' => $full_name,
                ':phone' => $phone,
                ':email' => $email,
                ':birth_date' => $birth_date,
                ':gender' => $gender,
                ':biography' => $biography,
                ':contract_accepted' => $contract_accepted,
                ':id' => $user_id
            ]);
            $application_id = $user_id;

            // Удаляем старые языки
            $pdo->prepare("DELETE FROM application_language WHERE application_id = ?")
                 ->execute([$application_id]);

            setcookie('updated', '1', time() + 24*3600);

        } else {
            // Первая отправка — генерация логина и пароля
            $login = generate_unique_login($pdo);
            $plain_password = generate_password();
            $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO application 
                (full_name, phone, email, birth_date, gender, biography, 
                 contract_accepted, login, password_hash)
                VALUES 
                (:full_name, :phone, :email, :birth_date, :gender, :biography, 
                 :contract_accepted, :login, :password_hash)
            ");
            $stmt->execute([
                ':full_name' => $full_name,
                ':phone' => $phone,
                ':email' => $email,
                ':birth_date' => $birth_date,
                ':gender' => $gender,
                ':biography' => $biography,
                ':contract_accepted' => $contract_accepted,
                ':login' => $login,
                ':password_hash' => $password_hash
            ]);
            $application_id = $pdo->lastInsertId();

            // Показываем логин/пароль один раз
            setcookie('generated_login', $login, time() + 3600);
            setcookie('generated_password', $plain_password, time() + 3600);
            setcookie('save', '1', time() + 24*3600);
        }

        // Сохранение языков (для обоих случаев)
        $lang_map = [];
        $stmt = $pdo->query("SELECT id, name FROM language");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lang_map[$row['name']] = $row['id'];
        }
        $stmt = $pdo->prepare("INSERT INTO application_language (application_id, language_id) VALUES (?, ?)");
        foreach ($languages as $lang_name) {
            if (isset($lang_map[$lang_name])) {
                $stmt->execute([$application_id, $lang_map[$lang_name]]);
            }
        }

        $pdo->commit();

        // Удаляем куки ошибок при успешной отправке
        $fields = ['full_name', 'phone', 'email', 'birth_date', 'gender', 'biography', 'contract_accepted', 'languages'];
        foreach ($fields as $field) {
            setcookie($field . '_error', '', 1);
        }

        header('Location: index.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        setcookie('db_error', '1', time() + 24*3600);
        header('Location: index.php');
        exit();
    }
}
