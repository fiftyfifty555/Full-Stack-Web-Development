<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'session_timeout.php';

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

// Для ссылки "Главная"
$home_link = ($_SESSION['role'] === 'admin') ? 'admin.php' : 'auth.php';

// Получение данных пользователя
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT 
        fullname, login, date_of_birth, phone, email, 
        passport_series, passport_number, account_number,
        account_balance, INN, address_id
    FROM users
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($fullname, $login, $date_of_birth, $phone, $email, 
                   $passport_series, $passport_number, $account_number,
                   $account_balance, $user_INN, $address_id);
$stmt->fetch();
$stmt->close();

// Получаем адрес и management_company_id
$stmt2 = $conn->prepare("
    SELECT 
        CONCAT(
            region, ', ', city, ', ', street, ', дом ', home,
            IF(corpus IS NOT NULL AND corpus <> '', CONCAT(', корпус ', corpus), ''),
            IF(flat_number IS NOT NULL AND flat_number <> '', CONCAT(', кв. ', flat_number), '')
        ) AS full_address, 
        post_index, management_company_id
    FROM address
    WHERE address_id = ?
");
$stmt2->bind_param("i", $address_id);
$stmt2->execute();
$stmt2->bind_result($full_address, $post_index, $management_company_id);
$stmt2->fetch();
$stmt2->close();

// Данные об управляющей компании
$stmt3 = $conn->prepare("
    SELECT 
        management_company_name, management_company_phone, management_company_email,
        management_company_address, management_company_workhours, management_company_INN,
        management_company_KPP, management_company_payacc, management_company_BIK,
        management_company_coracc
    FROM management_company
    WHERE management_company_id = ?
");
$stmt3->bind_param("i", $management_company_id);
$stmt3->execute();
$stmt3->bind_result($uk_name, $uk_phone, $uk_email, $uk_address, $uk_workhours,
                    $uk_INN, $uk_KPP, $uk_payacc, $uk_BIK, $uk_coracc);
$stmt3->fetch();
$stmt3->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Личный кабинет</title>
  <style>
   body {
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff;
      margin: 0; 
      padding: 0;
      display: flex; 
      flex-direction: column;
      min-height: 100vh;
    }
    
    /* Фиксированный хедер */
    header {
      background: rgba(0,0,0,0.6);
      padding: 25px;
      position: fixed;
      top: 0; 
      left: 0;
      width: 100%;
      z-index: 1000;
      text-align: center;
    }
    
    header h1 {
      margin: 0;
      font-size: 2em;
    }
    
    /* Блок баланса */
    header .balance {
      position: absolute;
      top: 20px;
      left: 20px;
      font-size: 1.5em;
      color: #fff;
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    /* Кнопка пополнения */
    header .balance a.deposit-btn {
      background: #4CAF50;
      color: #fff;
      text-decoration: none;
      padding: 10px 15px;
      border-radius: 5px;
      font-size: 1.1em;
      transition: background 0.3s;
      white-space: nowrap;
    }
    
    header .balance a.deposit-btn:hover {
      background: #45a049;
    }
    
    /* Навигационное меню */
    nav {
      display: flex;
      justify-content: center;
      gap: 30px;
      margin-top: 25px;
    }
    
    nav a {
      color: #fff;
      text-decoration: none;
      font-size: 1.3em;
      padding: 12px 30px;
      border: 2px solid #fff;
      border-radius: 25px;
      transition: 0.3s;
      white-space: nowrap;
    }
    
    nav a:hover, 
    nav a.active {
      background: #fff;
      color: #2193b0;
    }
    
    /* Кнопка выхода */
    .logout-btn {
      position: absolute;
      top: 20px;
      right: 20px;
      background: red;
      color: #fff;
      border: none;
      padding: 12px 20px;
      border-radius: 5px;
      cursor: pointer;
      font-size: 1em;
    }
    
    .logout-btn:hover {
      background: darkred;
    }
    
    /* Основной контент */
    .content {
      padding: 180px 20px 50px; 
      flex: 1; 
      text-align: center;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
      box-sizing: border-box;
    }
    
    /* Блоки с информацией */
    .info-container {
      display: flex;
      justify-content: space-between;
      gap: 40px;
      margin: 30px auto 0;
      text-align: left;
    }
    
    .personal-info, 
    .uk-info {
      background: rgba(255,255,255,0.98);
      color: #000;
      padding: 40px;
      border-radius: 10px;
      width: 48%;
      font-size: 1.2em;
      line-height: 1.6;
      box-sizing: border-box;
    }
    
    footer {
      text-align: center;
      padding: 20px;
      background: rgba(0,0,0,0.8);
      color: #fff;
      width: 100%;
    }
  </style>
</head>
<body>
  <header>
    <?php if ($_SESSION['role'] === 'user'): ?>
      <div class="balance">
        Баланс: <?php echo htmlspecialchars($account_balance); ?>
        <a href="deposit.php" class="deposit-btn">Пополнить</a>
      </div>
    <?php endif; ?>
    <h1>Личный кабинет</h1>
    <?php if (isset($_SESSION['user_id'])): ?>
      <form method="POST">
        <button type="submit" name="logout" class="logout-btn">Выйти</button>
      </form>
    <?php endif; ?>
    <nav>
      <a href="<?php echo ($_SESSION['role'] === 'admin') ? 'admin.php' : 'auth.php'; ?>" 
         class="<?php echo (basename($_SERVER['PHP_SELF'])=='auth.php' || basename($_SERVER['PHP_SELF'])=='admin.php') ? 'active' : ''; ?>">
         Главная
      </a>
      <a href="tariffs.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='tariffs.php' ? 'active' : ''; ?>">
        Тарифы
      </a>
      <a href="pokazaniya.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='pokazaniya.php' ? 'active' : ''; ?>">
        Показания
      </a>
      <a href="bills.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='bills.php' ? 'active' : ''; ?>">
        Начисления
      </a>
      <a href="payments.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='payments.php' ? 'active' : ''; ?>">
        Платежи
      </a>
      <a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='contact.php' ? 'active' : ''; ?>">
        Контакты
      </a>
      <a href="news.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='news.php' ? 'active' : ''; ?>">
        Новости
      </a>
    </nav>
  </header>

  <section class="content">
    <h2>Добро пожаловать, <?php echo htmlspecialchars($fullname); ?>!</h2>
    <div class="info-container">
      <div class="personal-info">
        <p><strong>Баланс:</strong> <?php echo htmlspecialchars($account_balance); ?></p>
        <p><strong>Лицевой счет:</strong> <?php echo htmlspecialchars($account_number); ?></p>
        <p><strong>Логин:</strong> <?php echo htmlspecialchars($login); ?></p>
        <p><strong>Дата рождения:</strong> <?php echo date('d-m-Y', strtotime($date_of_birth)); ?></p>
        <p><strong>Телефон:</strong> <?php echo htmlspecialchars($phone); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
        <p><strong>Адрес:</strong> <?php echo htmlspecialchars($full_address); ?></p>
        <p><strong>Почтовый индекс:</strong> <?php echo htmlspecialchars($post_index); ?></p>
      </div>
      <div class="uk-info">
        <h3>Информация об управляющей компании</h3>
        <p><strong>Название:</strong> <?php echo htmlspecialchars($uk_name); ?></p>
        <p><strong>Телефон:</strong> <?php echo htmlspecialchars($uk_phone); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($uk_email); ?></p>
        <p><strong>Адрес:</strong> <?php echo htmlspecialchars($uk_address); ?></p>
        <p><strong>Рабочие часы:</strong> <?php echo htmlspecialchars($uk_workhours); ?></p>
        <p><strong>ИНН:</strong> <?php echo htmlspecialchars($uk_INN); ?></p>
        <p><strong>КПП:</strong> <?php echo htmlspecialchars($uk_KPP); ?></p>
        <p><strong>Расчетный счет:</strong> <?php echo htmlspecialchars($uk_payacc); ?></p>
        <p><strong>БИК:</strong> <?php echo htmlspecialchars($uk_BIK); ?></p>
        <p><strong>Корр. счет:</strong> <?php echo htmlspecialchars($uk_coracc); ?></p>
      </div>
    </div>
  </section>

  <footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
  </footer>
</body>
</html>
