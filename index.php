<?php
session_start();

// 1) Если пользователь уже авторизован — перенаправить
if (isset($_SESSION['user_id'])) {
    $home_link = ($_SESSION['role'] === 'admin') ? 'admin.php' : 'auth.php';
    header("Location: $home_link");
    exit();
}

// 2) Показ предупреждения об истечении сессии
if (isset($_GET['session_expired']) && !isset($_SESSION['session_expired_shown'])) {
    echo "<script>alert('Ваша сессия была завершена из-за 2 минут неактивности.');</script>";
    $_SESSION['session_expired_shown'] = true;
}

// 3) Читаем куки для автозаполнения логина/пароля (если пользователь отметил «Запомнить меня»)
$login_saved    = $_COOKIE['login_saved']    ?? '';
$password_saved = $_COOKIE['password_saved'] ?? '';

// Подключение к базе
$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// 4) Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input    = trim($_POST['username'] ?? '');
    $password_input = trim($_POST['password'] ?? '');

    // Проверка на пустые поля
    if (empty($login_input) || empty($password_input)) {
        $_SESSION['error_message'] = "Поле логина или пароля не должно быть пустым!";
        header("Location: index.php");
        exit();
    }

    // Проверяем в таблице users
    $sql = "SELECT * FROM users WHERE (login=? OR account_number=?) AND password=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $login_input, $login_input, $password_input);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role']    = 'user';

        // Cookie "sitePref" — пример (на 5 минут)
        setcookie("sitePref", "user-style=light;language=ru", time()+300, "/");

        // Запоминаем логин/пароль в cookie (если стоит «Запомнить меня»)
        if (!empty($_POST['remember_me'])) {
            setcookie("login_saved",    $login_input,    time() + 300, "/");
            setcookie("password_saved", $password_input, time() + 300, "/");
        } else {
            // Если чекбокс не установлен — стираем возможные старые куки
            setcookie("login_saved",    "", time() - 3600, "/");
            setcookie("password_saved", "", time() - 3600, "/");
        }

        header("Location: auth.php");
        exit();
    }
    $stmt->close();

    // Проверяем в таблице management_company (админ)
    $sql = "SELECT * FROM management_company WHERE (management_company_login=? OR management_company_coracc=?) AND management_company_password=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $login_input, $login_input, $password_input);
    $stmt->execute();
    $res2 = $stmt->get_result();
    if ($res2 && $res2->num_rows > 0) {
        $company = $res2->fetch_assoc();
        $_SESSION['user_id'] = $company['management_company_id'];
        $_SESSION['role']    = 'admin';

        // Cookie "sitePref" — пример (на 5 минут)
        setcookie("sitePref", "user-style=dark;language=ru", time()+300, "/");

        // Запоминаем логин/пароль в cookie (если стоит «Запомнить меня»)
        if (!empty($_POST['remember_me'])) {
            setcookie("login_saved",    $login_input,    time() + 300, "/");
            setcookie("password_saved", $password_input, time() + 300, "/");
        } else {
            // Стираем старые куки
            setcookie("login_saved",    "", time() - 3600, "/");
            setcookie("password_saved", "", time() - 3600, "/");
        }

        header("Location: admin.php");
        exit();
    }
    $stmt->close();

    // Если не нашли ни там, ни там — ошибка
    $_SESSION['error_message'] = "Неверный логин или пароль!";
    header("Location: index.php");
    exit();
}

// Сообщение об ошибке
$error_message = $_SESSION['error_message'] ?? "";
unset($_SESSION['error_message']);

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>ЖКХ Город - Авторизация</title>
  <!-- Подключаем стили -->
  <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div id="leaf-container"></div>
    <header>
        <h1>ЖКХ Город</h1>
        <nav>
            <a href="index.php" class="active">Главная</a>
            <a href="tariffs.php">Тарифы</a>
            <a href="pokazaniya.php">Показания</a>
            <a href="bills.php">Начисления</a>
            <a href="payments.php">Платежи</a>
            <a href="contact.php">Контакты</a>
            <a href="news.php">Новости</a>
        </nav>
    </header>
    <section class="content">
        <div class="auth-box">
            <div class="login-form">
                <h3>Авторизация</h3>
                <form action="index.php" method="POST" onsubmit="return validateForm()">
                    <label for="login">Логин или Лицевой счёт</label>
                    <input type="text" id="login" name="username" 
                           placeholder="Лицевой счет или Логин"
                           value="<?php echo htmlspecialchars($login_saved, ENT_QUOTES); ?>"
                           required>

                    <label for="password">Пароль</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password"
                               placeholder="Пароль"
                               value="<?php echo htmlspecialchars($password_saved, ENT_QUOTES); ?>"
                               required>
                        <button type="button" onclick="togglePassword()">
                            <span id="eye-icon">👁️</span>
                        </button>
                    </div>

                    <div class="remember-me">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <label for="remember_me">Запомнить меня (5 минут)</label>
                    </div>

                    <button type="submit">Войти</button>
                </form>
                <?php if (!empty($error_message)): ?>
                    <div class="error-message">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="info-box">
                <h3>Внимание! Уважаемый пользователь!</h3>
                <p>Вы можете пользоваться <b>Личным кабинетом</b> для передачи показаний счетчиков и оплаты услуг ЖКХ после регистрации.</p>
                <p>При возникновении вопросов, свяжитесь с нашей горячей линией.</p>
            </div>
        </div>
    </section>
    <footer>
        <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
    </footer>

    <!-- Подключаем JS в конце body -->
    <script src="script.js"></script>
</body>
</html>
